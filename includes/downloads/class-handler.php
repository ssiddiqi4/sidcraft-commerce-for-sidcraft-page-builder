<?php
/**
 * Serves download links: ?sidccofo_download={order}.{record}&key={token}.
 *
 * Checks the token, the order, the limits and (optionally) who is asking,
 * then counts the download and sends the file by the method chosen in the
 * settings: through PHP (with resumable range requests), X-Sendfile /
 * X-Accel-Redirect / X-LiteSpeed-Location, or a redirect.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Downloads;

use SidcraftCommerce\Data\Order;

defined( 'ABSPATH' ) || exit;

/**
 * Class Handler
 */
class Handler {

	/** Cookie that remembers a guest confirmed their order email. */
	const COOKIE = 'sidccofo_dl_verified';

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'maybe_serve' ), 50 );
	}

	/**
	 * Handle a download request.
	 */
	public function maybe_serve() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Download links carry their own secret token (checked below).
		if ( ! isset( $_GET[ Downloads::QUERY_VAR ] ) ) {
			return;
		}
		$ref   = sanitize_text_field( wp_unslash( $_GET[ Downloads::QUERY_VAR ] ) );
		$token = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! preg_match( '/^(\d+)\.(\d+-[a-z0-9]+)$/', $ref, $m ) || '' === $token ) {
			self::fail( __( 'This download link is not valid.', 'sidcraft-commerce-for-sidcraft-page-builder' ), 404 );
		}
		$order = Order::get( (int) $m[1] );
		$key   = $m[2];
		$perms = $order ? Downloads::permissions( $order ) : array();
		$perm  = $perms[ $key ] ?? null;
		if ( ! $order || ! $perm || ! hash_equals( (string) $perm['token'], $token ) ) {
			self::fail( __( 'This download link is not valid.', 'sidcraft-commerce-for-sidcraft-page-builder' ), 404 );
		}

		$this->check_customer( $order, Downloads::url( $order, $key, $perm ) );

		$reason = Downloads::blocked_reason( $perm, $order );
		if ( '' !== $reason ) {
			self::fail( $reason, 403 );
		}

		$files = Downloads::files( (int) $perm['product_id'] );
		$file  = $files[ $perm['file_id'] ] ?? null;
		if ( ! $file ) {
			self::fail( __( 'This file is no longer available. Please contact us.', 'sidcraft-commerce-for-sidcraft-page-builder' ), 410 );
		}
		$target = Files::resolve( $file['file'] );
		if ( is_wp_error( $target ) ) {
			$order->add_note(
				/* translators: 1: file name, 2: problem. */
				sprintf( __( 'A download of “%1$s” failed: %2$s', 'sidcraft-commerce-for-sidcraft-page-builder' ), Downloads::file_name( $file ), $target->get_error_message() )
			);
			self::fail( __( 'This file can’t be downloaded right now. Please contact us.', 'sidcraft-commerce-for-sidcraft-page-builder' ), 500 );
		}

		/**
		 * Last check before a file is sent. Return a WP_Error to stop it.
		 *
		 * @param true|\WP_Error $allowed Allowed.
		 * @param Order          $order   Order.
		 * @param array          $perm    Access record.
		 * @param array          $file    File row.
		 */
		$allowed = apply_filters( 'sidcraft_commerce_download_allowed', true, $order, $perm, $file );
		if ( is_wp_error( $allowed ) ) {
			self::fail( $allowed->get_error_message(), 403 );
		}

		// Count it (a resumed download within the hour counts once).
		if ( ! ( self::is_continuation() && (int) $perm['last'] > time() - HOUR_IN_SECONDS ) ) {
			$perms[ $key ]['count'] = (int) $perm['count'] + 1;
			$perms[ $key ]['last']  = time();
			if ( null !== $perm['remaining'] ) {
				$perms[ $key ]['remaining'] = max( 0, (int) $perm['remaining'] - 1 );
			}
			update_post_meta( $order->get_id(), Downloads::PERMS, $perms );
			Downloads::log( $order, $key );

			/**
			 * Fires when a customer starts a download.
			 *
			 * @param Order $order Order.
			 * @param array $perm  Access record (before counting).
			 * @param array $file  File row.
			 */
			do_action( 'sidcraft_commerce_download_started', $order, $perm, $file );
		}

		$this->deliver( $target, Downloads::file_name( $file ) );
	}

	/**
	 * A follow-up range request of a download already counted (resuming, or a
	 * browser fetching the rest of a file).
	 *
	 * @return bool
	 */
	protected static function is_continuation() {
		if ( empty( $_SERVER['HTTP_RANGE'] ) ) {
			return false;
		}
		$range = sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) );
		return (bool) preg_match( '/^bytes=\s*[1-9]\d*-/', $range );
	}

	/**
	 * Login and email checks from the settings.
	 *
	 * @param Order  $order Order.
	 * @param string $link  This download link.
	 */
	protected function check_customer( Order $order, $link ) {
		$customer = (int) get_post_meta( $order->get_id(), '_sidccofo_customer_id', true );
		$staff    = current_user_can( 'edit_post', $order->get_id() );

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			$mine = ( $customer && $customer === (int) $user->ID ) || ( ! $customer && \SidcraftCommerce\Accounts::email_verified( (int) $user->ID ) && strtolower( (string) $user->user_email ) === strtolower( $order->get_email() ) );
			if ( ! $mine && ! $staff && Downloads::get( 'require_login' ) ) {
				self::fail( __( 'This download belongs to another account.', 'sidcraft-commerce-for-sidcraft-page-builder' ), 403 );
			}
			// Someone else's link: being logged in doesn't replace the email check guests get.
			if ( ! $mine && ! $staff && Downloads::get( 'verify_email' ) ) {
				$this->verify_email( $order );
			}
			return;
		}

		if ( Downloads::get( 'require_login' ) ) {
			wp_safe_redirect( wp_login_url( $link ) );
			exit;
		}

		if ( Downloads::get( 'verify_email' ) ) {
			$this->verify_email( $order );
		}
	}

	/**
	 * Hash that ties the verification cookie to an order and its email.
	 *
	 * @param Order $order Order.
	 * @return string
	 */
	protected static function proof( Order $order ) {
		return wp_hash( 'sidccofo-dl|' . $order->get_id() . '|' . strtolower( $order->get_email() ) );
	}

	/**
	 * Guests confirm the email the order was placed with (once per order per
	 * browser, remembered for a day).
	 *
	 * @param Order $order Order.
	 */
	protected function verify_email( Order $order ) {
		$cookie = \SidcraftCommerce\cookie_name( self::COOKIE ) . '_' . $order->get_id();
		if ( isset( $_COOKIE[ $cookie ] ) && hash_equals( self::proof( $order ), sanitize_text_field( wp_unslash( $_COOKIE[ $cookie ] ) ) ) ) {
			return;
		}

		$error = '';
		if ( isset( $_POST['sidccofo_dl_email'], $_POST['_sidccofo_dl_nonce'] ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_dl_nonce'] ) ), 'sidccofo_dl_verify_' . $order->get_id() ) ) {
				$error = __( 'The form expired. Please try again.', 'sidcraft-commerce-for-sidcraft-page-builder' );
			} else {
				$email = strtolower( sanitize_email( wp_unslash( $_POST['sidccofo_dl_email'] ) ) );
				if ( '' !== $email && hash_equals( strtolower( $order->get_email() ), $email ) ) {
					setcookie(
						$cookie,
						self::proof( $order ),
						array(
							'expires'  => time() + DAY_IN_SECONDS,
							'path'     => COOKIEPATH ? COOKIEPATH : '/',
							'domain'   => COOKIE_DOMAIN,
							'secure'   => is_ssl(),
							'httponly' => true,
							'samesite' => 'Lax',
						)
					);
					return;
				}
				$error = __( 'That email doesn’t match the order.', 'sidcraft-commerce-for-sidcraft-page-builder' );
			}
		}

		$form  = '<h1>' . esc_html__( 'Confirm your email', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</h1>';
		$form .= '<p>' . esc_html__( 'To download this file, enter the email address you used for the order.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>';
		if ( '' !== $error ) {
			$form .= '<p style="color:#b32d2e">' . esc_html( $error ) . '</p>';
		}
		$form .= '<form method="post" action="' . esc_url( add_query_arg( array() ) ) . '">';
		$form .= wp_nonce_field( 'sidccofo_dl_verify_' . $order->get_id(), '_sidccofo_dl_nonce', false, false );
		$form .= '<p><label for="sidccofo-dl-email">' . esc_html__( 'Email', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label><br><input type="email" id="sidccofo-dl-email" name="sidccofo_dl_email" required autocomplete="email" style="width:100%;max-width:360px;padding:8px"></p>';
		$form .= '<p><button type="submit" class="button">' . esc_html__( 'Continue', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</button></p></form>';

		wp_die(
			wp_kses(
				$form,
				array(
					'h1'     => array(),
					'p'      => array( 'style' => true ),
					'form'   => array(
						'method' => true,
						'action' => true,
					),
					'label'  => array( 'for' => true ),
					'br'     => array(),
					'input'  => array(
						'type'         => true,
						'id'           => true,
						'name'         => true,
						'value'        => true,
						'required'     => true,
						'autocomplete' => true,
						'style'        => true,
					),
					'button' => array(
						'type'  => true,
						'class' => true,
					),
				)
			),
			esc_html__( 'Confirm your email', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			array( 'response' => 200 )
		);
	}

	/**
	 * Stop with a message.
	 *
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 */
	protected static function fail( $message, $status = 403 ) {
		wp_die(
			esc_html( $message ),
			esc_html__( 'Download', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			array(
				'response'  => (int) $status,
				'back_link' => true,
			)
		);
	}

	/**
	 * Send the file.
	 *
	 * @param array  $target From Files::resolve().
	 * @param string $name   File name shown to the customer.
	 */
	protected function deliver( array $target, $name ) {
		if ( isset( $target['remote'] ) ) {
			wp_redirect( $target['remote'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The shop owner's file host (an external URL by design).
			exit;
		}

		$method = (string) Downloads::get( 'method' );

		/**
		 * Filter how a file is delivered: force, xsendfile or redirect.
		 *
		 * @param string $method Method.
		 * @param array  $target Path, url, mime.
		 */
		$method = (string) apply_filters( 'sidcraft_commerce_download_method', $method, $target );

		if ( 'redirect' === $method && '' !== $target['url'] && ! Files::in_protected_dir( $target['path'] ) ) {
			wp_redirect( $target['url'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- A file on this site.
			exit;
		}

		$filename = self::filename( $name, $target['path'] );
		if ( 'xsendfile' === $method && $this->x_sendfile( $target, $filename ) ) {
			exit;
		}
		$this->stream( $target, $filename );
	}

	/**
	 * The file name customers get: the file's display name with the real
	 * extension, else the file's own name.
	 *
	 * @param string $name Display name.
	 * @param string $path Path.
	 * @return string
	 */
	protected static function filename( $name, $path ) {
		$ext  = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		$base = sanitize_file_name( preg_replace( '/\.' . preg_quote( $ext, '/' ) . '$/i', '', (string) $name ) );
		if ( '' === $base ) {
			return wp_basename( $path );
		}
		return '' !== $ext ? $base . '.' . $ext : $base;
	}

	/**
	 * Common headers.
	 *
	 * @param array  $target   Path, url, mime.
	 * @param string $filename File name.
	 */
	protected static function headers( array $target, $filename ) {
		nocache_headers();
		$inline      = Downloads::get( 'inline' ) ? 'inline' : 'attachment';
		$ascii       = preg_replace( '/[^\x20-\x7E]/', '_', $filename );
		$ascii       = str_replace( array( '"', '\\' ), '_', (string) $ascii );
		$disposition = $inline . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode( $filename );

		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: ' . ( '' !== $target['mime'] ? $target['mime'] : 'application/octet-stream' ) );
		header( 'Content-Disposition: ' . $disposition );
		header( 'Content-Transfer-Encoding: binary' );
	}

	/**
	 * Hand the file to the web server.
	 *
	 * @param array  $target   Path, url, mime.
	 * @param string $filename File name.
	 * @return bool False when no server module is available.
	 */
	protected function x_sendfile( array $target, $filename ) {
		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
		$header = '';
		$value  = $target['path'];

		if ( function_exists( 'apache_get_modules' ) && in_array( 'mod_xsendfile', (array) apache_get_modules(), true ) ) {
			$header = 'X-Sendfile';
		} elseif ( false !== strpos( $server, 'litespeed' ) ) {
			$header = 'X-LiteSpeed-Location';
			$value  = self::server_relative( $target['path'] );
		} elseif ( false !== strpos( $server, 'nginx' ) || false !== strpos( $server, 'cherokee' ) ) {
			$header = 'X-Accel-Redirect';
			$value  = self::server_relative( $target['path'] );
		}

		/**
		 * Filter the X-Sendfile style header: [ header name, value ].
		 *
		 * @param array $header [ name, value ] (name '' = not available).
		 * @param array $target Path, url, mime.
		 */
		list( $header, $value ) = array_pad( (array) apply_filters( 'sidcraft_commerce_download_xsendfile', array( $header, $value ), $target ), 2, '' );
		if ( '' === $header || '' === $value ) {
			return false; // Stream through PHP instead.
		}
		self::clean_buffers();
		self::headers( $target, $filename );
		header( $header . ': ' . $value );
		return true;
	}

	/**
	 * Path relative to the site root, for nginx and LiteSpeed.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	protected static function server_relative( $path ) {
		$root = wp_normalize_path( untrailingslashit( ABSPATH ) );
		$path = wp_normalize_path( $path );
		return 0 === strpos( $path, $root . '/' ) ? substr( $path, strlen( $root ) ) : $path;
	}

	/**
	 * Drop any output buffered so far.
	 */
	protected static function clean_buffers() {
		while ( ob_get_level() > 0 ) {
			if ( ! @ob_end_clean() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A buffer that can't be removed is left alone.
				break;
			}
		}
	}

	/**
	 * Stream the file through PHP, honouring a single byte range.
	 *
	 * @param array  $target   Path, url, mime.
	 * @param string $filename File name.
	 */
	protected function stream( array $target, $filename ) {
		$path = $target['path'];
		$size = (int) filesize( $path );

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming a large file in chunks; WP_Filesystem would load it all into memory.
		$handle = fopen( $path, 'rb' );
		if ( ! $handle && Downloads::get( 'redirect_fallback' ) && '' !== $target['url'] && ! Files::in_protected_dir( $path ) ) {
			wp_redirect( $target['url'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- A file on this site (last resort).
			exit;
		}
		if ( ! $handle ) {
			self::fail( __( 'This file can’t be downloaded right now. Please contact us.', 'sidcraft-commerce-for-sidcraft-page-builder' ), 500 );
		}

		$start = 0;
		$end   = max( 0, $size - 1 );
		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		$part  = false;
		if ( $size > 0 && preg_match( '/^bytes=\s*(\d*)-(\d*)$/', $range, $m ) && ( '' !== $m[1] || '' !== $m[2] ) ) {
			if ( '' === $m[1] ) {
				$start = max( 0, $size - (int) $m[2] ); // Last N bytes.
			} else {
				$start = (int) $m[1];
				$end   = '' !== $m[2] ? min( (int) $m[2], $size - 1 ) : $size - 1;
			}
			if ( $start > $end || $start >= $size ) {
				fclose( $handle );
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			$part = true;
		}

		self::clean_buffers();
		self::headers( $target, $filename );
		header( 'Accept-Ranges: bytes' );
		if ( $part ) {
			status_header( 206 );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}
		header( 'Content-Length: ' . ( $size > 0 ? $end - $start + 1 : 0 ) );
		if ( 0 === $size ) {
			fclose( $handle );
			exit; // An empty file: nothing to send.
		}

		if ( $start > 0 ) {
			fseek( $handle, $start );
		}
		$left = $end - $start + 1;
		while ( $left > 0 && ! feof( $handle ) && ! connection_aborted() ) {
			$chunk = fread( $handle, (int) min( 1048576, $left ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$left -= strlen( $chunk );
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file contents.
			flush();
		}
		fclose( $handle );
		// phpcs:enable
		exit;
	}
}
