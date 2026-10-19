<?php
/**
 * Digital downloads in the admin: the Downloads part of the product data box,
 * the Downloads box on orders (edit limits and expiry, revoke, grant, email
 * the links, history) and the Store → Download log screen.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Admin;

use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Downloads\Downloads;
use SidcraftCommerce\Downloads\Files;
use function SidcraftCommerce\manage_cap;

defined( 'ABSPATH' ) || exit;

/**
 * Class Downloads_Admin
 */
class Downloads_Admin {

	/** Log screen slug. */
	const LOG_PAGE = 'sidccofo-download-log';

	/** Admin-post action: email the links. */
	const SEND = 'sidccofo_send_download_links';

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'add_meta_boxes_' . Post_Types::ORDER, array( $this, 'order_box' ) );
		add_action( 'save_post_' . Post_Types::ORDER, array( $this, 'save_order' ), 30 );
		add_action( 'admin_post_' . self::SEND, array( $this, 'send_links' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_menu', array( $this, 'menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'display_post_states', array( $this, 'post_states' ), 10, 2 );
	}

	/**
	 * Media library and the files script on product screens.
	 *
	 * @param string $hook Screen hook.
	 */
	public function assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || Post_Types::PRODUCT !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'sidccofo-downloads-admin', SIDCRAFT_COMMERCE_URL . 'assets/js/sc-downloads-admin.js', array(), SIDCRAFT_COMMERCE_VERSION, true );
		wp_localize_script(
			'sidccofo-downloads-admin',
			'sidccofoDownloads',
			array(
				'title'  => __( 'Choose a downloadable file', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'button' => __( 'Use this file', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			)
		);
	}

	/*
	------------------------------------------------------------------
	 * Product data box (called by Admin)
	 * ----------------------------------------------------------------
	 */

	/**
	 * "Virtual" and "Downloadable" checkboxes, files, limit and expiry.
	 *
	 * @param \WP_Post $post Product.
	 */
	public static function render_product_section( $post ) {
		$downloadable = (bool) get_post_meta( $post->ID, Downloads::DOWNLOADABLE, true );
		$virtual      = (bool) get_post_meta( $post->ID, Downloads::VIRTUAL, true );
		$files        = array_values( Downloads::stored_files( $post->ID ) );
		$limit        = (string) get_post_meta( $post->ID, Downloads::LIMIT, true );
		$expiry       = (string) get_post_meta( $post->ID, Downloads::EXPIRY, true );

		echo '<h4 class="sc-pd__heading">' . esc_html__( 'Digital product', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</h4>';
		echo '<div class="sc-pd sc-dl" data-sc-downloads>';
		echo '<p><label class="sc-pd__check"><input type="checkbox" name="sidccofo_virtual" value="1" ' . checked( $virtual, true, false ) . '> ' . esc_html__( 'Virtual — nothing to ship (no shipping cost or address at checkout)', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label></p>';
		echo '<p><label class="sc-pd__check"><input type="checkbox" name="sidccofo_downloadable" value="1" data-sc-dl-toggle ' . checked( $downloadable, true, false ) . '> ' . esc_html__( 'Downloadable — buyers get access to files', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label></p>';

		echo '<div class="sc-pd__wide sc-dl__body"' . ( $downloadable ? '' : ' hidden' ) . ' data-sc-dl-body>';
		echo '<table class="widefat sc-dl__files"><thead><tr><th class="sc-dl__name">' . esc_html__( 'File name', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'File URL', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th class="sc-dl__tools"><span class="screen-reader-text">' . esc_html__( 'Remove', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</span></th></tr></thead><tbody data-rows>';
		foreach ( $files as $i => $file ) {
			self::file_row( $i, $file );
		}
		echo '</tbody></table><template data-template>';
		self::file_row(
			'__i__',
			array(
				'id'   => '',
				'name' => '',
				'file' => '',
			)
		);
		echo '</template>';
		echo '<p><button type="button" class="button" data-add-file>' . esc_html__( 'Add file', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</button> ';
		echo '<span class="description">' . esc_html__( 'Files you upload here go to a protected folder and are only handed out through each buyer’s private link. You can also paste a link to a file in your media library or hosted elsewhere.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</span></p>';

		echo '<div class="sc-dl__limits"><p><label for="sc-dl-limit">' . esc_html__( 'Download limit', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label><input type="number" min="0" step="1" id="sc-dl-limit" name="sidccofo_download_limit" value="' . esc_attr( $limit ) . '" placeholder="∞"><span class="description">' . esc_html__( 'Per file and per unit bought. Empty: unlimited.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</span></p>';
		echo '<p><label for="sc-dl-expiry">' . esc_html__( 'Download expiry (days)', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label><input type="number" min="0" step="1" id="sc-dl-expiry" name="sidccofo_download_expiry" value="' . esc_attr( $expiry ) . '" placeholder="∞"><span class="description">' . esc_html__( 'Links stop working this many days after purchase. Empty: never.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</span></p></div>';
		echo '</div></div>';
	}

	/**
	 * One file row.
	 *
	 * @param int|string $i    Index.
	 * @param array      $file id, name, file.
	 */
	protected static function file_row( $i, array $file ) {
		$base    = 'sidccofo_files[' . $i . ']';
		$problem = '' !== $file['file'] ? Files::problem( $file['file'] ) : '';
		echo '<tr data-row><td class="sc-dl__name"><input type="hidden" name="' . esc_attr( $base ) . '[id]" value="' . esc_attr( $file['id'] ) . '">';
		echo '<input type="text" class="widefat" name="' . esc_attr( $base ) . '[name]" value="' . esc_attr( $file['name'] ) . '" placeholder="' . esc_attr__( 'e.g. User guide (PDF)', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '" aria-label="' . esc_attr__( 'File name', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '" data-file-name></td>';
		echo '<td><div class="sc-dl__url"><input type="text" class="widefat code" name="' . esc_attr( $base ) . '[file]" value="' . esc_attr( $file['file'] ) . '" placeholder="https://" aria-label="' . esc_attr__( 'File URL', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '" data-file-url> <button type="button" class="button" data-choose-file>' . esc_html__( 'Upload / choose file', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</button></div>';
		if ( '' !== $problem ) {
			echo '<p class="sc-dl__problem">' . esc_html( $problem ) . '</p>';
		}
		echo '</td><td class="sc-dl__tools"><button type="button" class="button-link sc-dl__remove" data-remove-file aria-label="' . esc_attr__( 'Remove file', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '">✕</button></td></tr>';
	}

	/**
	 * Save the section. The caller has checked the product box nonce and
	 * the edit_post capability.
	 *
	 * @param int $post_id Product ID.
	 */
	public static function save_product_section( $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by Admin::save_product() before calling this.
		$before = array_keys( Downloads::stored_files( $post_id ) );
		$files  = array();
		foreach ( isset( $_POST['sidccofo_files'] ) ? (array) wp_unslash( $_POST['sidccofo_files'] ) : array() as $row ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field below.
			if ( ! is_array( $row ) ) {
				continue;
			}
			$file = self::clean_file( (string) ( $row['file'] ?? '' ) );
			if ( '' === $file ) {
				continue;
			}
			$id = strtolower( (string) preg_replace( '/[^a-z0-9]/i', '', (string) ( $row['id'] ?? '' ) ) );
			if ( '' === $id || strlen( $id ) > 20 || isset( $files[ $id ] ) ) {
				$id = strtolower( wp_generate_password( 8, false ) );
			}
			$files[ $id ] = array(
				'id'   => $id,
				'name' => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
				'file' => $file,
			);
		}

		$limit        = isset( $_POST['sidccofo_download_limit'] ) ? sanitize_text_field( wp_unslash( $_POST['sidccofo_download_limit'] ) ) : '';
		$expiry       = isset( $_POST['sidccofo_download_expiry'] ) ? sanitize_text_field( wp_unslash( $_POST['sidccofo_download_expiry'] ) ) : '';
		$downloadable = ! empty( $_POST['sidccofo_downloadable'] );
		update_post_meta( $post_id, Downloads::DOWNLOADABLE, $downloadable ? 1 : 0 );
		update_post_meta( $post_id, Downloads::VIRTUAL, empty( $_POST['sidccofo_virtual'] ) ? 0 : 1 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		update_post_meta( $post_id, Downloads::FILES, array_values( $files ) );
		update_post_meta( $post_id, Downloads::LIMIT, '' === $limit || (int) $limit < 1 ? '' : (string) absint( $limit ) );
		update_post_meta( $post_id, Downloads::EXPIRY, '' === $expiry || (int) $expiry < 1 ? '' : (string) absint( $expiry ) );

		// Files added later reach everyone who already has this product's downloads.
		$new = array_values( array_diff( array_keys( $files ), $before ) );
		if ( $new && $downloadable ) {
			Downloads::grant_new_files( $post_id, $new );
		}
	}

	/**
	 * Clean a file URL or server path.
	 *
	 * @param string $raw Raw value.
	 * @return string
	 */
	protected static function clean_file( $raw ) {
		$raw = trim( sanitize_text_field( $raw ) );
		if ( '' === $raw ) {
			return '';
		}
		if ( preg_match( '#^(https?:)?//#i', $raw ) ) {
			return esc_url_raw( $raw, array( 'http', 'https' ) );
		}
		return wp_normalize_path( $raw );
	}

	/**
	 * "Downloadable" / "Virtual" next to product titles in the list.
	 *
	 * @param string[] $states States.
	 * @param \WP_Post $post   Post.
	 * @return string[]
	 */
	public function post_states( $states, $post ) {
		if ( ! $post instanceof \WP_Post || Post_Types::PRODUCT !== $post->post_type ) {
			return $states;
		}
		if ( get_post_meta( $post->ID, Downloads::DOWNLOADABLE, true ) ) {
			$states['sidccofo-downloadable'] = __( 'Downloadable', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		if ( get_post_meta( $post->ID, Downloads::VIRTUAL, true ) ) {
			$states['sidccofo-virtual'] = __( 'Virtual', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		return $states;
	}

	/*
	------------------------------------------------------------------
	 * Order box
	 * ----------------------------------------------------------------
	 */

	/**
	 * Add the box to orders with downloads (or downloadable products).
	 *
	 * @param \WP_Post $post Order post.
	 */
	public function order_box( $post ) {
		$order = Order::get( $post->ID );
		if ( ! $order || ! Downloads::order_has_downloads( $order ) ) {
			return;
		}
		add_meta_box( 'sidccofo-order-downloads', __( 'Downloads', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_order' ), Post_Types::ORDER, 'normal', 'default' );
	}

	/**
	 * Order box contents.
	 *
	 * @param \WP_Post $post Order post.
	 */
	public function render_order( $post ) {
		$order = Order::get( $post->ID );
		if ( ! $order ) {
			return;
		}
		wp_nonce_field( 'sidccofo_order_downloads_' . $order->get_id(), '_sidccofo_order_dl_nonce' );
		$perms   = Downloads::permissions( $order );
		$dt_form = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		if ( ! $perms ) {
			echo '<p class="description">' . esc_html(
				'completed' === Downloads::get( 'grant_on' )
					? __( 'Download links are created when the order is marked Completed.', 'sidcraft-commerce-for-sidcraft-page-builder' )
					: __( 'Download links are created when the order is paid.', 'sidcraft-commerce-for-sidcraft-page-builder' )
			) . '</p>';
		} else {
			echo '<table class="widefat striped sc-dl__perms"><thead><tr>';
			echo '<th>' . esc_html__( 'File', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Downloaded', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Downloads left', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Expires', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Status', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Revoke', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $perms as $key => $perm ) {
				$base   = 'sidccofo_perm[' . $key . ']';
				$reason = Downloads::blocked_reason( $perm, $order );
				$status = '' === $reason ? __( 'Active', 'sidcraft-commerce-for-sidcraft-page-builder' ) : ( ! empty( $perm['revoked'] ) ? __( 'Revoked', 'sidcraft-commerce-for-sidcraft-page-builder' ) : $reason );
				echo '<tr><td><strong>' . esc_html( $perm['name'] ) . '</strong><br><span class="description">' . esc_html( $perm['product'] ) . '</span>';
				if ( '' === $reason ) {
					echo '<br><input type="text" class="widefat code sc-dl__link" readonly value="' . esc_attr( Downloads::url( $order, $key, $perm ) ) . '" onfocus="this.select()" aria-label="' . esc_attr__( 'Download link', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '">';
				}
				echo '</td><td>' . esc_html( number_format_i18n( (int) $perm['count'] ) );
				if ( ! empty( $perm['last'] ) ) {
					/* translators: %s: date. */
					echo '<br><span class="description">' . esc_html( sprintf( __( 'Last: %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), wp_date( $dt_form, (int) $perm['last'] ) ) ) . '</span>';
				}
				echo '</td>';
				echo '<td><input type="number" min="0" step="1" class="small-text" name="' . esc_attr( $base ) . '[remaining]" value="' . esc_attr( null === $perm['remaining'] ? '' : (string) (int) $perm['remaining'] ) . '" placeholder="∞" aria-label="' . esc_attr__( 'Downloads left', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '"></td>';
				echo '<td><input type="date" name="' . esc_attr( $base ) . '[expires]" value="' . esc_attr( empty( $perm['expires'] ) ? '' : wp_date( 'Y-m-d', (int) $perm['expires'] ) ) . '" aria-label="' . esc_attr__( 'Expires', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '"></td>';
				echo '<td>' . esc_html( $status ) . '</td>';
				echo '<td><input type="checkbox" name="' . esc_attr( $base ) . '[revoked]" value="1" ' . checked( ! empty( $perm['revoked'] ), true, false ) . ' aria-label="' . esc_attr__( 'Revoke access', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '"></td></tr>';
			}
			echo '</tbody></table>';
			echo '<p class="description">' . esc_html__( 'Change the downloads left or the expiry date, or tick Revoke, then update the order. Empty downloads left: unlimited. Empty date: never expires.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>';
		}

		// Grant a product's files by hand.
		$products = get_posts(
			array(
				'post_type'      => Post_Types::PRODUCT,
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Admin picker of downloadable products.
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_key'       => Downloads::DOWNLOADABLE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin picker, bounded.
				'meta_value'     => 1, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Admin picker, bounded.
			)
		);
		echo '<div class="sc-dl__actions">';
		if ( $products ) {
			echo '<p><label for="sc-dl-grant">' . esc_html__( 'Give access to the files of', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label> <select id="sc-dl-grant" name="sidccofo_dl_grant"><option value="">' . esc_html__( '— Choose a product —', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</option>';
			foreach ( $products as $product ) {
				echo '<option value="' . esc_attr( (string) $product->ID ) . '">' . esc_html( $product->post_title ) . '</option>';
			}
			echo '</select> <span class="description">' . esc_html__( 'Saved when you update the order.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</span></p>';
		}
		if ( $perms && $order->is_paid() && is_email( $order->get_email() ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::SEND . '&order=' . $order->get_id() ), self::SEND . '_' . $order->get_id() );
			/* translators: %s: email address. */
			echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html( sprintf( __( 'Email the download links to %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_email() ) ) . '</a></p>';
		}
		echo '</div>';

		$log = array_slice( Downloads::get_log( $order ), 0, 20 );
		if ( $log ) {
			echo '<details class="sc-dl__log"><summary>' . esc_html__( 'Download history', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</summary><ul>';
			foreach ( $log as $entry ) {
				$name = isset( $perms[ $entry['key'] ] ) ? $perms[ $entry['key'] ]['name'] : $entry['key'];
				$line = wp_date( $dt_form, (int) $entry['time'] ) . ' — ' . $name;
				if ( ! empty( $entry['ip'] ) ) {
					$line .= ' — ' . $entry['ip'];
				}
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul></details>';
		}
	}

	/**
	 * Save the order box.
	 *
	 * @param int $post_id Order ID.
	 */
	public function save_order( $post_id ) {
		if ( ! isset( $_POST['_sidccofo_order_dl_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_order_dl_nonce'] ) ), 'sidccofo_order_downloads_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$order = Order::get( $post_id );
		if ( ! $order ) {
			return;
		}
		$perms   = Downloads::permissions( $order );
		$posted  = isset( $_POST['sidccofo_perm'] ) ? (array) wp_unslash( $_POST['sidccofo_perm'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field below.
		$changed = false;
		foreach ( $perms as $key => $perm ) {
			if ( ! isset( $posted[ $key ] ) || ! is_array( $posted[ $key ] ) ) {
				continue;
			}
			$row       = $posted[ $key ];
			$remaining = sanitize_text_field( (string) ( $row['remaining'] ?? '' ) );
			$remaining = '' === $remaining ? null : absint( $remaining );
			$date      = sanitize_text_field( (string) ( $row['expires'] ?? '' ) );
			$expires   = 0;
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				$day     = date_create_immutable( $date . ' 23:59:59', wp_timezone() );
				$expires = $day ? $day->getTimestamp() : 0;
			}
			$revoked = empty( $row['revoked'] ) ? 0 : 1;
			if ( $remaining !== $perm['remaining'] || $expires !== (int) $perm['expires'] || $revoked !== (int) $perm['revoked'] ) {
				$perms[ $key ]['remaining'] = $remaining;
				$perms[ $key ]['expires']   = $expires;
				$perms[ $key ]['revoked']   = $revoked;
				$changed                    = true;
				if ( $revoked !== (int) $perm['revoked'] ) {
					$order->add_note(
						sprintf(
							/* translators: %s: file name. */
							$revoked ? __( 'Download access to “%s” revoked.', 'sidcraft-commerce-for-sidcraft-page-builder' ) : __( 'Download access to “%s” restored.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
							$perm['name']
						)
					);
				}
			}
		}

		$grant = isset( $_POST['sidccofo_dl_grant'] ) ? absint( $_POST['sidccofo_dl_grant'] ) : 0;
		if ( $grant && Downloads::is_downloadable( $grant ) ) {
			$added = 0;
			foreach ( Downloads::files( $grant ) as $file ) {
				$key = $grant . '-' . $file['id'];
				if ( ! isset( $perms[ $key ] ) ) {
					$perms[ $key ] = Downloads::new_permission( $grant, $file, 1 );
					++$added;
				} elseif ( ! empty( $perms[ $key ]['revoked'] ) ) {
					$perms[ $key ]['revoked'] = 0;
					++$added;
				}
			}
			if ( $added ) {
				$changed = true;
				/* translators: 1: number of files, 2: product. */
				$order->add_note( sprintf( _n( 'Download access to %1$d file of %2$s granted by hand.', 'Download access to %1$d files of %2$s granted by hand.', $added, 'sidcraft-commerce-for-sidcraft-page-builder' ), $added, get_the_title( $grant ) ) );
			}
		}

		if ( $changed ) {
			Downloads::save_permissions( $order, $perms );
		}
	}

	/**
	 * Email the links (button on the order box).
	 */
	public function send_links() {
		$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		check_admin_referer( self::SEND . '_' . $order_id );
		if ( ! current_user_can( 'edit_post', $order_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'sidcraft-commerce-for-sidcraft-page-builder' ), '', array( 'response' => 403 ) );
		}
		$order = Order::get( $order_id );
		$sent  = $order && Downloads::send_links( $order );
		if ( $sent ) {
			/* translators: %s: email. */
			$order->add_note( sprintf( __( 'Download links emailed to %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $order->get_email() ) );
		}
		wp_safe_redirect( add_query_arg( 'sidccofo_dl_sent', $sent ? 1 : 0, admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ) );
		exit;
	}

	/**
	 * Notice after emailing the links.
	 */
	public function notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag.
		if ( ! isset( $_GET['sidccofo_dl_sent'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flag.
		$ok = '1' === sanitize_text_field( wp_unslash( $_GET['sidccofo_dl_sent'] ) );
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			$ok ? 'success' : 'error',
			esc_html( $ok ? __( 'The download links were emailed to the customer.', 'sidcraft-commerce-for-sidcraft-page-builder' ) : __( 'The email could not be sent. Check that the order has active download links and a valid email address.', 'sidcraft-commerce-for-sidcraft-page-builder' ) )
		);
	}

	/*
	------------------------------------------------------------------
	 * Download log screen
	 * ----------------------------------------------------------------
	 */

	/**
	 * Submenu under Store.
	 */
	public function menu() {
		add_submenu_page(
			'edit.php?post_type=' . Post_Types::PRODUCT,
			__( 'Download log', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			__( 'Download log', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			manage_cap(),
			self::LOG_PAGE,
			array( $this, 'render_log' )
		);
	}

	/**
	 * Orders with downloads, most recently changed first.
	 */
	public function render_log() {
		if ( ! current_user_can( manage_cap() ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only paging.
		$page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$query   = new \WP_Query(
			array(
				'post_type'      => Post_Types::ORDER,
				'post_status'    => 'any',
				'posts_per_page' => 25,
				'paged'          => $page,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_key'       => Downloads::PERMS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin report, paged.
				'meta_compare'   => 'EXISTS',
			)
		);
		$dt_form = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		echo '<div class="wrap"><h1>' . esc_html__( 'Download log', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</h1>';
		echo '<p>' . esc_html__( 'Orders with digital downloads, most recently changed first. Open an order to change limits, revoke access or email the links again.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Order', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Customer', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Files', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Downloads', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Last download', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th></tr></thead><tbody>';
		if ( ! $query->posts ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No downloads yet.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</td></tr>';
		}
		foreach ( $query->posts as $order_id ) {
			$order = Order::get( (int) $order_id );
			if ( ! $order ) {
				continue;
			}
			$perms   = Downloads::permissions( $order );
			$billing = $order->get_billing();
			$names   = array();
			foreach ( $perms as $perm ) {
				$names[] = $perm['name'] . ( ! empty( $perm['revoked'] ) ? ' (' . __( 'revoked', 'sidcraft-commerce-for-sidcraft-page-builder' ) . ')' : '' );
			}
			$count = array_sum( array_map( 'intval', wp_list_pluck( $perms, 'count' ) ) );
			$last  = max( array_merge( array( 0 ), array_map( 'intval', wp_list_pluck( $perms, 'last' ) ) ) );
			echo '<tr><td><a href="' . esc_url( admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ) ) . '">#' . esc_html( (string) $order->get_id() ) . '</a><br><span class="description">' . esc_html( $order->get_status_label() ) . '</span></td>';
			echo '<td>' . esc_html( trim( ( $billing['first_name'] ?? '' ) . ' ' . ( $billing['last_name'] ?? '' ) ) ) . '<br><span class="description">' . esc_html( $order->get_email() ) . '</span></td>';
			echo '<td>' . esc_html( implode( ', ', $names ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $count ) ) . '</td>';
			echo '<td>' . esc_html( $last ? wp_date( $dt_form, $last ) : '—' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$links = paginate_links(
			array(
				'base'    => add_query_arg( 'paged', '%#%' ),
				'format'  => '',
				'current' => $page,
				'total'   => max( 1, (int) $query->max_num_pages ),
			)
		);
		if ( $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
		}
		echo '</div>';
	}

	/*
	------------------------------------------------------------------
	 * Settings (Store → Settings → Downloads)
	 * ----------------------------------------------------------------
	 */

	/**
	 * Settings sections for the Downloads tab.
	 *
	 * @return array
	 */
	public static function settings_sections() {
		$uploads  = wp_upload_dir( null, false );
		$location = (string) wp_parse_url( trailingslashit( $uploads['baseurl'] ) . Files::DIR, PHP_URL_PATH );
		return array(
			'downloads'          => array(
				'tab'    => 'downloads',
				'title'  => __( 'Digital downloads', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'intro'  => __( 'Sell files — e-books, music, software, courses, printables. On a product, tick “Downloadable” and add files; tick “Virtual” too when there is nothing to ship. Buyers find their files on the order-received page, in the order emails and on the My downloads page.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'fields' => array(
					'download_method'            => array(
						__( 'File download method', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						array(
							'force'     => __( 'Force download (recommended)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
							'xsendfile' => __( 'X-Sendfile / X-Accel-Redirect', 'sidcraft-commerce-for-sidcraft-page-builder' ),
							'redirect'  => __( 'Redirect only (insecure)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						),
						__( '“Force download” sends files through WordPress and keeps their location secret, with resumable downloads. “X-Sendfile / X-Accel-Redirect” is faster for big files but needs the server module (mod_xsendfile on Apache, X-Accel on nginx, or LiteSpeed); without it files go through WordPress. “Redirect only” sends customers to the file’s address, which can then be shared.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					),
					'download_redirect_fallback' => array( __( 'Redirect as a last resort', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'If WordPress can’t read a file, send the customer to its address instead of an error (never for files in the protected folder).', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
					'download_inline'            => array( __( 'Open files in the browser', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'PDFs, images and videos open in a browser tab instead of downloading.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
					'download_grant_on'          => array(
						__( 'Give access', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						array(
							'paid'      => __( 'As soon as the order is paid', 'sidcraft-commerce-for-sidcraft-page-builder' ),
							'completed' => __( 'When the order is marked Completed', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						),
					),
					'download_email_links'       => array( __( 'Download links in order emails', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox' ),
					'download_grant_new_files'   => array( __( 'Past buyers get new files', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'Files added to a product later (a new version, a bonus chapter) become available to everyone who bought it.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
					'download_revoke_on_refund'  => array( __( 'Pause links of cancelled or refunded orders', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox' ),
				),
			),
			'downloads_access'   => array(
				'tab'    => 'downloads',
				'title'  => __( 'Who can download', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'fields' => array(
					'download_require_login' => array( __( 'Customers must be logged in', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'Links only work for the account that placed the order. Guest orders are attached to an account when the customer registers or logs in with the order email.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
					'download_verify_email'  => array( __( 'Guests confirm their email', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'Before a guest’s first download, ask for the email the order was placed with, so forwarded links don’t work for others.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
					'download_log_ip'        => array( __( 'Record IP addresses in the download history', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'Helps spot shared links. IP addresses are personal data: mention it in your privacy policy. They are included in WordPress’s personal data export and erase tools.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				),
			),
			'downloads_security' => array(
				'tab'    => 'downloads',
				'title'  => __( 'File storage', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				/* translators: 1: folder path, 2: nginx rule. */
				'intro'  => sprintf( __( 'Files uploaded from a product’s Digital product section are stored in %1$s, which Apache and LiteSpeed close to direct visits. On nginx, add this rule to your server block (it also lets X-Accel-Redirect serve the files): %2$s', 'sidcraft-commerce-for-sidcraft-page-builder' ), wp_normalize_path( trailingslashit( $uploads['basedir'] ) . Files::DIR ), 'location ^~ ' . $location . '/ { internal; }' ),
				'fields' => array(
					'download_unique_names'  => array( __( 'Add a random string to uploaded file names', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'Makes files in the protected folder impossible to guess. Customers still get the file under its own name.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
					'download_allow_remote'  => array( __( 'Allow files hosted elsewhere', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'checkbox', __( 'Links to files on other sites (cloud storage, a CDN). Customers are redirected there after the checks.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
					'download_approved_dirs' => array( __( 'More approved folders', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'textarea', __( 'One folder per line (server path or a URL on this site). Files in your uploads folder are always allowed; any other file on the server is refused.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
				),
			),
		);
	}
}
