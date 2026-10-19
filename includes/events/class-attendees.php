<?php
/**
 * Attendee details at checkout: name / email / phone per ticket plus the
 * event's custom fields (text, choices, dates, file uploads…).
 *
 * Inputs are flat names so the checkout script can send them as JSON and show
 * errors under each field: scev_{product}_{n}_{key}, where key is name,
 * email, phone or f_{field id} (checkbox choices add __{index}). Uploads go
 * to REST events/upload first; the form then carries the returned token.
 *
 * Saved on the order as _sidccofo_ev_attendees:
 *   product ID => [ n => [ name, email, phone, fields => [ id => value | [ file, name, type ] ] ] ].
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Events;

use SidcraftCommerce\Cart\Cart;
use SidcraftCommerce\Checkout\Checkout;
use SidcraftCommerce\Data\Order;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Attendees
 */
class Attendees {

	const DIR = 'sidccofo-attendee-files';

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'sidcraft_commerce_checkout_after_fields', array( $this, 'render' ) );
		add_filter( 'sidcraft_commerce_checkout_errors', array( $this, 'validate' ), 10, 3 );
		add_action( 'sidcraft_commerce_order_created', array( $this, 'save' ), 10 );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'admin_post_sidccofo_attendee_file', array( $this, 'serve_file' ) );
		add_filter( 'sidcraft_commerce_render_sidccofo-checkout', array( $this, 'enqueue_on_checkout' ) );
	}

	/**
	 * Load the events script on the checkout (uploads, copy-my-details).
	 *
	 * @param string $html Checkout Unit HTML.
	 * @return string
	 */
	public function enqueue_on_checkout( $html ) {
		if ( self::blocks( Cart::instance() ) ) {
			\SidcraftCommerce\Plugin::instance()->assets->require_assets(
				array(
					'styles'  => array( 'sidccofo-events' ),
					'scripts' => array( 'sidccofo-events' ),
				)
			);
		}
		return $html;
	}

	/**
	 * The ticket blocks a cart (or order items) needs:
	 * [ [ product, quantity, event, type ], … ] for events that collect details.
	 *
	 * @param Cart|array $source Cart, or order items.
	 * @return array[]
	 */
	public static function blocks( $source ) {
		$lines = $source instanceof Cart ? $source->get_items() : (array) $source;
		$out   = array();
		foreach ( $lines as $line ) {
			$pid = (int) ( $line['product_id'] ?? 0 );
			if ( ! $pid || ! Placeholders::is_placeholder( $pid ) ) {
				continue;
			}
			list( $event, $type ) = Placeholders::resolve( $pid );
			if ( ! $event ) {
				continue;
			}
			if ( ! $type ) {
				$type = array(
					'id'   => (string) get_post_meta( $pid, Placeholders::TYPE, true ),
					'name' => (string) ( $line['name'] ?? '' ),
				);
			}
			$key = (string) $pid;
			if ( isset( $out[ $key ] ) ) {
				$out[ $key ]['quantity'] += (int) $line['quantity'];
				continue;
			}
			$out[ $key ] = array(
				'product'  => $pid,
				'quantity' => (int) $line['quantity'],
				'event'    => $event,
				'type'     => $type,
			);
		}
		return array_values(
			array_filter(
				$out,
				static function ( $b ) {
					return $b['event']->collects_attendees();
				}
			)
		);
	}

	/**
	 * Input name for one attendee field.
	 *
	 * @param int    $pid Product.
	 * @param int    $n   Ticket index.
	 * @param string $key Key.
	 * @return string
	 */
	public static function name( $pid, $n, $key ) {
		return 'scev_' . (int) $pid . '_' . (int) $n . '_' . $key;
	}

	/**
	 * Checkout section.
	 *
	 * @param Cart $cart Cart.
	 */
	public function render( $cart ) {
		$blocks = self::blocks( $cart );
		if ( ! $blocks ) {
			return;
		}
		$saved = (array) $cart->session()->get( 'ev_attendees', array() );
		$html = Events::template(
			'events/checkout-attendees.php',
			array(
				'blocks' => $blocks,
				'saved'  => $saved,
				'upload' => array(
					'types'  => self::allowed_extensions(),
					'max_mb' => self::max_mb(),
				),
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the template.
	}

	/**
	 * Read one attendee from raw input.
	 *
	 * @param array $input  Raw input.
	 * @param array $block  Block.
	 * @param int   $n      Ticket index.
	 * @param array $errors Errors (by reference).
	 * @return array name, email, phone, fields.
	 */
	protected static function read( array $input, array $block, $n, array &$errors ) {
		$event = $block['event'];
		$mode  = $event->get_attendee_info();
		$pid   = $block['product'];
		$get   = static function ( $key ) use ( $input, $pid, $n ) {
			$name = self::name( $pid, $n, $key );
			return isset( $input[ $name ] ) && is_scalar( $input[ $name ] ) ? trim( (string) $input[ $name ] ) : '';
		};
		$row   = array(
			'name'   => '',
			'email'  => '',
			'phone'  => '',
			'fields' => array(),
		);
		if ( 'buyer' !== $mode ) {
			$row['name'] = mb_substr( sanitize_text_field( $get( 'name' ) ), 0, 120 );
			if ( '' === $row['name'] ) {
				$errors[ self::name( $pid, $n, 'name' ) ] = __( 'Enter the attendee’s name.', 'sidcraft-commerce-for-sidcraft-page-builder' );
			}
		}
		if ( in_array( $mode, array( 'name_email', 'name_email_phone' ), true ) ) {
			$email = sanitize_email( $get( 'email' ) );
			if ( ! is_email( $email ) ) {
				$errors[ self::name( $pid, $n, 'email' ) ] = __( 'Enter the attendee’s email address.', 'sidcraft-commerce-for-sidcraft-page-builder' );
			}
			$row['email'] = $email;
		}
		if ( 'name_email_phone' === $mode ) {
			$row['phone'] = mb_substr( preg_replace( '/[^\d+\-\s().]/', '', $get( 'phone' ) ), 0, 30 );
			if ( strlen( preg_replace( '/\D/', '', $row['phone'] ) ) < 6 ) {
				$errors[ self::name( $pid, $n, 'phone' ) ] = __( 'Enter the attendee’s phone number.', 'sidcraft-commerce-for-sidcraft-page-builder' );
			}
		}
		foreach ( $event->get_fields() as $field ) {
			$key   = 'f_' . $field['id'];
			$input_name = self::name( $pid, $n, $key );
			$value = '';
			switch ( $field['type'] ) {
				case 'checkbox':
					if ( $field['options'] ) {
						$picked = array();
						foreach ( $field['options'] as $i => $label ) {
							if ( '' !== $get( $key . '__' . $i ) ) {
								$picked[] = $label;
							}
						}
						$value      = implode( ', ', $picked );
						$input_name = self::name( $pid, $n, $key . '__0' );
					} else {
						$value = '' !== $get( $key ) ? __( 'Yes', 'sidcraft-commerce-for-sidcraft-page-builder' ) : '';
					}
					break;
				case 'select':
				case 'radio':
					$value = sanitize_text_field( $get( $key ) );
					if ( '' !== $value && ! in_array( $value, $field['options'], true ) ) {
						/* translators: %s: field label. */
						$errors[ $input_name ] = sprintf( __( 'Choose an option for %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $field['label'] );
						$value                 = '';
					}
					break;
				case 'textarea':
					$value = mb_substr( sanitize_textarea_field( $get( $key ) ), 0, 2000 );
					break;
				case 'email':
					$value = sanitize_email( $get( $key ) );
					if ( '' !== $get( $key ) && ! is_email( $value ) ) {
						$errors[ $input_name ] = __( 'Enter a valid email address.', 'sidcraft-commerce-for-sidcraft-page-builder' );
					}
					break;
				case 'number':
					$value = $get( $key );
					if ( '' !== $value && ! is_numeric( $value ) ) {
						/* translators: %s: field label. */
						$errors[ $input_name ] = sprintf( __( '%s must be a number.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $field['label'] );
					}
					break;
				case 'date':
					$value = $get( $key );
					if ( '' !== $value && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
						$errors[ $input_name ] = __( 'Choose a date.', 'sidcraft-commerce-for-sidcraft-page-builder' );
					}
					break;
				case 'file':
					$token = sanitize_key( $get( $key ) );
					$file  = '' !== $token ? get_transient( 'sidccofo_evup_' . $token ) : false;
					if ( '' !== $token && ! is_array( $file ) ) {
						$errors[ $input_name ] = __( 'The upload has expired. Please choose the file again.', 'sidcraft-commerce-for-sidcraft-page-builder' );
					}
					$value = is_array( $file ) ? array_merge( $file, array( 'token' => $token ) ) : '';
					break;
				default:
					$value = mb_substr( sanitize_text_field( $get( $key ) ), 0, 500 );
			}
			if ( $field['required'] && ( '' === $value || array() === $value ) && empty( $errors[ $input_name ] ) ) {
				/* translators: %s: field label. */
				$errors[ $input_name ] = sprintf( __( '%s is required.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $field['label'] );
			}
			if ( '' !== $value ) {
				$row['fields'][ $field['id'] ] = $value;
			}
		}
		return $row;
	}

	/**
	 * Validate the attendee section with the rest of the checkout.
	 *
	 * @param array $errors  Errors.
	 * @param array $billing Billing.
	 * @param array $input   Raw input.
	 * @return array
	 */
	public function validate( $errors, $billing, $input ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed
		$cart   = Cart::instance();
		$keep   = array();
		$errors = (array) $errors;
		foreach ( self::blocks( $cart ) as $block ) {
			for ( $n = 0; $n < $block['quantity']; $n++ ) {
				$row = self::read( (array) $input, $block, $n, $errors );
				unset( $row['fields'] ); // Keep the light part to prefill after an error.
				$keep[ $block['product'] ][ $n ] = $row;
			}
		}
		if ( $keep ) {
			$cart->session()->set( 'ev_attendees', $keep );
		}
		return $errors;
	}

	/**
	 * Save attendee details on the new order and claim the uploaded files.
	 *
	 * @param Order $order Order.
	 */
	public function save( $order ) {
		if ( ! $order instanceof Order ) {
			return;
		}
		$input  = Checkout::input();
		$data   = array();
		$errors = array();
		foreach ( self::blocks( $order->get_items() ) as $block ) {
			for ( $n = 0; $n < $block['quantity']; $n++ ) {
				$row = self::read( $input, $block, $n, $errors );
				foreach ( $row['fields'] as $id => $value ) {
					if ( is_array( $value ) && ! empty( $value['token'] ) ) {
						delete_transient( 'sidccofo_evup_' . $value['token'] );
						unset( $value['token'] );
						$row['fields'][ $id ] = $value;
					}
				}
				$data[ $block['product'] ][ $n ] = $row;
			}
		}
		if ( $data ) {
			update_post_meta( $order->get_id(), '_sidccofo_ev_attendees', $data );
			Cart::instance()->session()->set( 'ev_attendees', array() );
		}
	}

	/**
	 * Saved attendees of an order.
	 *
	 * @param int $order_id Order.
	 * @return array
	 */
	public static function for_order( $order_id ) {
		$data = get_post_meta( (int) $order_id, '_sidccofo_ev_attendees', true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Field answers as display rows: [ id, label, type, value, file ].
	 *
	 * @param Event $event  Event.
	 * @param array $values field id => value.
	 * @return array[]
	 */
	public static function rows( Event $event, array $values ) {
		$rows = array();
		foreach ( $event->get_fields() as $field ) {
			if ( ! isset( $values[ $field['id'] ] ) ) {
				continue;
			}
			$value = $values[ $field['id'] ];
			$rows[] = array(
				'id'    => $field['id'],
				'label' => $field['label'],
				'type'  => $field['type'],
				'value' => is_array( $value ) ? (string) ( $value['name'] ?? '' ) : (string) $value,
				'file'  => is_array( $value ) ? (string) ( $value['file'] ?? '' ) : '',
			);
		}
		return $rows;
	}

	/*
	------------------------------------------------------------------
	 * Uploads
	 * ----------------------------------------------------------------
	 */

	/**
	 * Allowed upload extensions.
	 *
	 * @return string[]
	 */
	public static function allowed_extensions() {
		$raw = (string) setting( 'ev_upload_types', 'jpg, jpeg, png, gif, webp, pdf, doc, docx' );
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', strtolower( $raw ) ) as $ext ) {
			$ext = preg_replace( '/[^a-z0-9]/', '', $ext );
			if ( '' !== $ext && ! in_array( $ext, array( 'php', 'phtml', 'phar', 'js', 'html', 'htm', 'svg', 'exe', 'sh' ), true ) ) {
				$out[] = $ext;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Max upload size in MB.
	 *
	 * @return float
	 */
	public static function max_mb() {
		$mb = (float) setting( 'ev_upload_max_mb', 5 );
		return $mb > 0 ? min( $mb, wp_max_upload_size() / MB_IN_BYTES ) : 5.0;
	}

	/**
	 * Protected folder for attendee files.
	 *
	 * @return string Absolute path with trailing slash.
	 */
	public static function dir() {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . self::DIR . '/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Small guard files in our own folder.
			file_put_contents( $dir . '.htaccess', "Require all denied\nDeny from all\n" );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
		}
		return $dir;
	}

	/**
	 * REST route for uploads.
	 */
	public function routes() {
		register_rest_route(
			\SidcraftCommerce\Rest::NS,
			'/events/upload',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true', // Checkout is public; limited by type, size and rate.
				'callback'            => array( $this, 'rest_upload' ),
			)
		);
	}

	/**
	 * POST events/upload (multipart: file).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_upload( \WP_REST_Request $request ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'sidccofo_evup_rate_' . md5( $ip . wp_salt( 'nonce' ) );
		if ( (int) get_transient( $key ) >= 40 ) {
			return new \WP_Error( 'sidccofo_upload_rate', __( 'Too many uploads. Please try again later.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 429 ) );
		}
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || ! is_array( $files['file'] ) || ! empty( $files['file']['error'] ) ) {
			return new \WP_Error( 'sidccofo_upload_missing', __( 'Choose a file to upload.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
		}
		$file = $files['file'];
		if ( (int) $file['size'] > self::max_mb() * MB_IN_BYTES ) {
			/* translators: %s: size in MB. */
			return new \WP_Error( 'sidccofo_upload_size', sprintf( __( 'Files can be up to %s MB.', 'sidcraft-commerce-for-sidcraft-page-builder' ), number_format_i18n( self::max_mb(), 1 ) ), array( 'status' => 400 ) );
		}
		$allowed = array();
		foreach ( get_allowed_mime_types() as $exts => $mime ) {
			foreach ( explode( '|', $exts ) as $ext ) {
				if ( in_array( $ext, self::allowed_extensions(), true ) ) {
					$allowed[ $exts ] = $mime;
				}
			}
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			/* translators: %s: allowed extensions. */
			return new \WP_Error( 'sidccofo_upload_type', sprintf( __( 'Allowed file types: %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), implode( ', ', self::allowed_extensions() ) ), array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$dir    = self::dir();
		$filter = static function ( $paths ) use ( $dir ) {
			$paths['path']   = untrailingslashit( $dir ) . '/' . gmdate( 'Y/m' );
			$paths['url']    = '';
			$paths['subdir'] = '/' . self::DIR . '/' . gmdate( 'Y/m' );
			return $paths;
		};
		$rename = static function ( $dir_path, $name, $ext ) {
			return wp_generate_password( 20, false ) . $ext;
		};
		add_filter( 'upload_dir', $filter );
		$moved = wp_handle_upload(
			$file,
			array(
				'test_form'                => false,
				'mimes'                    => $allowed,
				'unique_filename_callback' => $rename,
			)
		);
		remove_filter( 'upload_dir', $filter );
		if ( empty( $moved['file'] ) ) {
			return new \WP_Error( 'sidccofo_upload_failed', ! empty( $moved['error'] ) ? (string) $moved['error'] : __( 'The file could not be saved.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 400 ) );
		}
		set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );

		$token = strtolower( wp_generate_password( 24, false ) );
		set_transient(
			'sidccofo_evup_' . $token,
			array(
				'file' => ltrim( str_replace( wp_normalize_path( $dir ), '', wp_normalize_path( $moved['file'] ) ), '/' ),
				'name' => sanitize_file_name( (string) $file['name'] ),
				'type' => (string) $moved['type'],
			),
			DAY_IN_SECONDS
		);
		return rest_ensure_response(
			array(
				'token' => $token,
				'name'  => sanitize_file_name( (string) $file['name'] ),
			)
		);
	}

	/**
	 * Admin download of an attendee file (admin-post.php?action=sidccofo_attendee_file).
	 */
	public function serve_file() {
		$path = isset( $_GET['f'] ) ? sanitize_text_field( wp_unslash( $_GET['f'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked below.
		if ( ! current_user_can( \SidcraftCommerce\manage_cap() ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'sidccofo_attendee_file_' . $path ) ) {
			wp_die( esc_html__( 'You are not allowed to download this file.', 'sidcraft-commerce-for-sidcraft-page-builder' ), 403 );
		}
		$base = wp_normalize_path( realpath( self::dir() ) );
		$full = wp_normalize_path( (string) realpath( self::dir() . $path ) );
		if ( '' === $path || '' === $full || 0 !== strpos( $full, $base . '/' ) || ! is_file( $full ) ) {
			wp_die( esc_html__( 'File not found.', 'sidcraft-commerce-for-sidcraft-page-builder' ), 404 );
		}
		$type = wp_check_filetype( $full );
		nocache_headers();
		header( 'Content-Type: ' . ( $type['type'] ? $type['type'] : 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . basename( $full ) . '"' );
		header( 'Content-Length: ' . filesize( $full ) );
		readfile( $full ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a protected file to an admin.
		exit;
	}

	/**
	 * Admin link to an attendee file.
	 *
	 * @param string $path Path relative to the protected folder.
	 * @return string
	 */
	public static function file_url( $path ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'sidccofo_attendee_file', 'f' => rawurlencode( $path ) ), admin_url( 'admin-post.php' ) ), 'sidccofo_attendee_file_' . $path );
	}

	/**
	 * Delete unclaimed uploads older than two days (hourly cron).
	 */
	public static function cleanup() {
		$dir = self::dir();
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		$keep = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Paths of claimed files, read once a day at most.
		foreach ( $GLOBALS['wpdb']->get_col( $GLOBALS['wpdb']->prepare( "SELECT meta_value FROM {$GLOBALS['wpdb']->postmeta} WHERE meta_key = %s", '_sidccofo_ev_attendees' ) ) as $raw ) {
			if ( preg_match_all( '/"file";s:\d+:"([^"]+)"/', (string) $raw, $m ) ) {
				foreach ( $m[1] as $p ) {
					$keep[ $p ] = true;
				}
			}
		}
		foreach ( $it as $file ) {
			if ( ! $file->isFile() || in_array( $file->getFilename(), array( '.htaccess', 'index.php' ), true ) ) {
				continue;
			}
			$rel = ltrim( str_replace( wp_normalize_path( $dir ), '', wp_normalize_path( $file->getPathname() ) ), '/' );
			if ( ! isset( $keep[ $rel ] ) && $file->getMTime() < time() - 2 * DAY_IN_SECONDS ) {
				wp_delete_file( $file->getPathname() );
			}
		}
	}
}
