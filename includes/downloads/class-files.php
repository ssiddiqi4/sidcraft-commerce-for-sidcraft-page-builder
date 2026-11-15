<?php
/**
 * Downloadable files on disk: the protected uploads folder, where files may
 * be served from (approved folders), and turning a file URL into a path.
 *
 * Files chosen with "Upload / choose file" on a product go to
 * wp-content/uploads/sidccofo-downloads/, which is closed to direct visits
 * (.htaccess on Apache and LiteSpeed; see the settings for nginx). Files are
 * only handed out by the download handler after the order is checked.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Downloads;

defined( 'ABSPATH' ) || exit;

/**
 * Class Files
 */
class Files {

	/** Folder inside wp-content/uploads. */
	const DIR = 'sidccofo-downloads';

	/** Option: version the folder protection was last written for. */
	const PROTECTED_OPTION = 'sidcraft_commerce_downloads_protected';

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_protect' ) );
		add_filter( 'upload_dir', array( $this, 'upload_dir' ) );
		add_filter( 'wp_handle_upload_prefilter', array( $this, 'unique_name' ) );
	}

	/**
	 * Is the current request an upload from the "Downloadable files" box?
	 *
	 * The media uploader posts to async-upload.php, which has already checked
	 * its own nonce and the upload_files capability.
	 *
	 * @return bool
	 */
	protected static function is_download_upload() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- async-upload.php verifies the media-form nonce; this only picks the folder.
		return isset( $_POST['sidccofo_download_upload'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['sidccofo_download_upload'] ) ) && current_user_can( 'upload_files' );
	}

	/**
	 * Absolute path of the protected folder.
	 *
	 * @return string
	 */
	public static function protected_dir() {
		$uploads = wp_upload_dir( null, false );
		return wp_normalize_path( trailingslashit( $uploads['basedir'] ) . self::DIR );
	}

	/**
	 * Put uploads from the "Downloadable files" box in the protected folder.
	 *
	 * @param array $dirs Upload dirs.
	 * @return array
	 */
	public function upload_dir( $dirs ) {
		static $busy = false;
		if ( $busy || ! self::is_download_upload() ) {
			return $dirs;
		}
		$busy = true; // protect() asks for the upload dir too.
		if ( get_option( self::PROTECTED_OPTION ) !== SIDCRAFT_COMMERCE_VERSION || ! file_exists( trailingslashit( $dirs['basedir'] ) . self::DIR . '/.htaccess' ) ) {
			self::protect();
		}
		$busy           = false;
		$dirs['subdir'] = '/' . self::DIR;
		$dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
		$dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
		return $dirs;
	}

	/**
	 * Add a random string to downloadable file names (harder to guess).
	 *
	 * @param array $file Uploaded file.
	 * @return array
	 */
	public function unique_name( $file ) {
		if ( ! self::is_download_upload() || ! Downloads::get( 'unique_names' ) || empty( $file['name'] ) ) {
			return $file;
		}
		$info         = pathinfo( (string) $file['name'] );
		$ext          = isset( $info['extension'] ) ? '.' . $info['extension'] : '';
		$file['name'] = $info['filename'] . '-' . strtolower( wp_generate_password( 10, false ) ) . $ext;
		return $file;
	}

	/**
	 * Write the protection files once per plugin version.
	 */
	public static function maybe_protect() {
		// Autoloaded (read with the other options); a failure waits an hour before trying again.
		if ( get_option( self::PROTECTED_OPTION ) !== SIDCRAFT_COMMERCE_VERSION && ! get_transient( 'sidccofo_protect_retry' ) ) {
			if ( ! self::protect() ) {
				set_transient( 'sidccofo_protect_retry', 1, HOUR_IN_SECONDS );
			}
		}
	}

	/**
	 * Create the folder with index.html and a deny-all .htaccess.
	 *
	 * @return bool
	 */
	public static function protect() {
		$dir = self::protected_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			return false;
		}
		$files = array(
			'index.html' => '',
			'.htaccess'  => "# Sidcraft Commerce: files here are only served through download links.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
		);
		$ok    = true;
		foreach ( $files as $name => $content ) {
			$path = trailingslashit( $dir ) . $name;
			if ( ! $wp_filesystem->exists( $path ) || ( '.htaccess' === $name && $wp_filesystem->get_contents( $path ) !== $content ) ) {
				$ok = $wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE ) && $ok;
			}
		}
		if ( $ok ) {
			update_option( self::PROTECTED_OPTION, SIDCRAFT_COMMERCE_VERSION, true );
		}
		return $ok;
	}

	/**
	 * Folders files may be served from: uploads (which includes the protected
	 * folder) plus the folders listed in the settings.
	 *
	 * @return string[] Normalized absolute paths with a trailing slash.
	 */
	public static function approved_dirs() {
		$uploads = wp_upload_dir( null, false );
		$dirs    = array( $uploads['basedir'] );
		foreach ( preg_split( '/\r\n|\r|\n/', (string) Downloads::get( 'approved_dirs' ) ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$local = self::local_path( $line );
			if ( '' !== $local ) {
				$dirs[] = $local;
			}
		}

		/**
		 * Filter the folders downloadable files may be served from.
		 *
		 * @param string[] $dirs Absolute paths.
		 */
		$dirs = (array) apply_filters( 'sidcraft_commerce_download_approved_dirs', $dirs );
		$out  = array();
		foreach ( $dirs as $dir ) {
			$real = realpath( (string) $dir );
			if ( $real ) {
				$out[] = trailingslashit( wp_normalize_path( $real ) );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * A file URL or path on this site as an absolute path ('' when the value
	 * is a remote URL or not recognised).
	 *
	 * @param string $file URL or path.
	 * @return string
	 */
	public static function local_path( $file ) {
		$file = trim( (string) $file );
		if ( '' === $file ) {
			return '';
		}
		$uploads = wp_upload_dir( null, false );
		$plain   = strtok( $file, '?#' );
		$plain   = false === $plain ? '' : rawurldecode( $plain );
		$map     = array(
			$uploads['baseurl'] => $uploads['basedir'],
			content_url()       => WP_CONTENT_DIR,
			site_url()          => ABSPATH,
			home_url()          => ABSPATH,
		);
		foreach ( $map as $url => $dir ) {
			$bare_url  = preg_replace( '#^https?:#i', '', untrailingslashit( $url ) );
			$bare_file = preg_replace( '#^https?:#i', '', $plain );
			if ( '' !== $bare_url && 0 === stripos( (string) $bare_file, $bare_url . '/' ) ) {
				return wp_normalize_path( untrailingslashit( $dir ) . substr( (string) $bare_file, strlen( $bare_url ) ) );
			}
		}
		// Multisite: a store's uploads have two URLs (…/shop2/wp-content/uploads/sites/2/
		// and …/wp-content/uploads/sites/2/, as built while another site's code runs).
		if ( is_multisite() && preg_match( '#^(?:https?:)?//[^/]+/#i', $plain ) ) {
			$host   = wp_parse_url( home_url(), PHP_URL_HOST );
			$marker = '/' . basename( WP_CONTENT_DIR ) . '/uploads/';
			$pos    = strpos( (string) $plain, $marker );
			if ( $host && false !== $pos && strtolower( (string) wp_parse_url( $plain, PHP_URL_HOST ) ) === strtolower( $host ) ) {
				$rest = substr( (string) $plain, $pos + strlen( $marker ) );
				$own  = is_main_site() ? '' : 'sites/' . get_current_blog_id() . '/';
				if ( ( '' === $own && 0 !== strpos( $rest, 'sites/' ) ) || ( '' !== $own && 0 === strpos( $rest, $own ) ) ) {
					return wp_normalize_path( untrailingslashit( $uploads['basedir'] ) . '/' . substr( $rest, strlen( $own ) ) );
				}
			}
		}
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $file ) || 0 === strpos( $file, '//' ) ) {
			return ''; // Remote.
		}
		if ( 0 === strpos( $file, '/' ) || preg_match( '#^[a-zA-Z]:[\\\\/]#', $file ) ) {
			return wp_normalize_path( $file ); // Absolute path typed by the shop owner.
		}
		return '';
	}

	/**
	 * Is this a remote URL (http or https on another site)?
	 *
	 * @param string $file File.
	 * @return bool
	 */
	public static function is_remote( $file ) {
		$file = (string) $file;
		return '' === self::local_path( $file ) && (bool) preg_match( '#^https?://#i', $file ) && '' !== (string) wp_parse_url( $file, PHP_URL_HOST );
	}

	/**
	 * Work out how to deliver a file, checking it is allowed.
	 *
	 * @param string $file File URL or path as entered on the product.
	 * @return array|\WP_Error [ 'remote' => url ] or [ 'path' => absolute path, 'url' => url or '' ].
	 */
	public static function resolve( $file ) {
		$file = trim( (string) $file );
		if ( '' === $file ) {
			return new \WP_Error( 'sidccofo_no_file', __( 'No file is set for this download.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}

		$local = self::local_path( $file );
		if ( '' === $local ) {
			if ( self::is_remote( $file ) && Downloads::get( 'allow_remote' ) ) {
				return array( 'remote' => esc_url_raw( $file, array( 'http', 'https' ) ) );
			}
			return new \WP_Error( 'sidccofo_not_allowed', __( 'Files hosted on other sites are switched off for downloads.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}

		$real = realpath( $local );
		if ( ! $real || ! is_file( $real ) || ! is_readable( $real ) ) {
			return new \WP_Error( 'sidccofo_missing_file', __( 'The file could not be found.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		$real = wp_normalize_path( $real );

		$approved = false;
		foreach ( self::approved_dirs() as $dir ) {
			if ( 0 === strpos( $real, $dir ) ) {
				$approved = true;
				break;
			}
		}
		if ( ! $approved ) {
			return new \WP_Error( 'sidccofo_not_approved', __( 'The file is outside the approved download folders.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}

		$type = wp_check_filetype( $real );
		if ( empty( $type['ext'] ) ) {
			return new \WP_Error( 'sidccofo_bad_type', __( 'This type of file can’t be downloaded.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}

		return array(
			'path' => $real,
			'url'  => self::path_to_url( $real ),
			'mime' => (string) $type['type'],
		);
	}

	/**
	 * Public URL of a path inside the site ('' when there is none).
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	public static function path_to_url( $path ) {
		$path    = wp_normalize_path( $path );
		$uploads = wp_upload_dir( null, false );
		$map     = array(
			wp_normalize_path( $uploads['basedir'] ) => $uploads['baseurl'],
			wp_normalize_path( WP_CONTENT_DIR )      => content_url(),
			wp_normalize_path( ABSPATH )             => site_url(),
		);
		foreach ( $map as $dir => $url ) {
			$dir = untrailingslashit( $dir );
			if ( 0 === strpos( $path, $dir . '/' ) ) {
				return untrailingslashit( $url ) . str_replace( '%2F', '/', rawurlencode( substr( $path, strlen( $dir ) ) ) );
			}
		}
		return '';
	}

	/**
	 * Is a path inside the protected folder?
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public static function in_protected_dir( $path ) {
		$dir = realpath( self::protected_dir() );
		return $dir && 0 === strpos( wp_normalize_path( $path ), trailingslashit( wp_normalize_path( $dir ) ) );
	}

	/**
	 * A problem with a file the shop owner entered ('' when it is fine).
	 *
	 * @param string $file File URL or path.
	 * @return string
	 */
	public static function problem( $file ) {
		$check = self::resolve( $file );
		return is_wp_error( $check ) ? $check->get_error_message() : '';
	}
}
