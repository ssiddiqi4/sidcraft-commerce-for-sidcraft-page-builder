<?php
/**
 * Theme-overridable template loader.
 *
 * Lookup order for templates/units/product-grid.php:
 *   1. {child-theme}/sidcraft-commerce/units/product-grid.php
 *   2. {parent-theme}/sidcraft-commerce/units/product-grid.php
 *   3. {plugin}/templates/units/product-grid.php
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Class Template_Loader
 */
class Template_Loader {

	/**
	 * Template paths found this request, keyed by theme-relative path.
	 *
	 * @var array<string,string>
	 */
	protected $found = array();

	/**
	 * Folder name themes use for overrides.
	 *
	 * @return string
	 */
	public function theme_dir() {
		/**
		 * Filter the folder inside a theme that holds template overrides.
		 *
		 * @param string $dir Default 'sidcraft-commerce'.
		 */
		return trailingslashit( apply_filters( 'sidcraft_commerce_template_dir', 'sidcraft-commerce' ) );
	}

	/**
	 * Find a template file.
	 *
	 * @param string $name Relative path, e.g. 'units/product-grid.php'.
	 * @return string Absolute path, or '' when not found.
	 */
	public function locate( $name ) {
		$name = ltrim( str_replace( array( '..', '\\' ), array( '', '/' ), $name ), '/' );
		$key  = $this->theme_dir() . $name;
		// Grids render the card template once per product: check the theme once.
		if ( ! isset( $this->found[ $key ] ) ) {
			$found = locate_template( array( $key ) );
			if ( ! $found ) {
				$default = SIDCRAFT_COMMERCE_PATH . 'templates/' . $name;
				$found   = file_exists( $default ) ? $default : '';
			}
			$this->found[ $key ] = $found;
		}
		$found = $this->found[ $key ];

		/**
		 * Filter the resolved template path. Return any readable file.
		 *
		 * @param string $found Absolute path or ''.
		 * @param string $name  Requested relative path.
		 */
		return (string) apply_filters( 'sidcraft_commerce_locate_template', $found, $name );
	}

	/**
	 * Render a template and return its HTML.
	 *
	 * Variables in $args are exposed to the template as $args['key'] and,
	 * for convenience, as local variables (never overwriting $this/$args).
	 *
	 * @param string $name Relative path.
	 * @param array  $args Template variables.
	 * @return string
	 */
	public function render( $name, array $args = array() ) {
		$file = $this->locate( $name );
		if ( ! $file ) {
			return '';
		}

		/**
		 * Filter template variables before rendering.
		 *
		 * @param array  $args Variables.
		 * @param string $name Template name.
		 */
		$args = apply_filters( 'sidcraft_commerce_template_args', $args, $name );

		// An order's amounts are shown in the currency it was placed in.
		$order = isset( $args['order'] ) && $args['order'] instanceof Data\Order ? $args['order'] : null;
		if ( $order ) {
			Currencies::switch_to( $order->get_currency() );
		}
		ob_start();
		try {
			do_action( 'sidcraft_commerce_before_template', $name, $args );
			( static function ( $sidccofo_template_file, $args ) {
				extract( $args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template convenience, keys controlled by us.
				include $sidccofo_template_file;
			} )( $file, $args );
			do_action( 'sidcraft_commerce_after_template', $name, $args );
		} finally {
			if ( $order ) {
				Currencies::restore();
			}
		}
		return (string) ob_get_clean();
	}
}
