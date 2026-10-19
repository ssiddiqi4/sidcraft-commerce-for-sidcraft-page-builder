<?php
/**
 * Base class every commerce Unit extends.
 *
 * A Unit describes itself (name, title, icon, controls) and renders HTML from
 * settings. It knows nothing about Sidcraft's editor; the bridge translates
 * this description into whatever Sidcraft's registry expects.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Abstracts;

use SidcraftCommerce\Plugin;
use function SidcraftCommerce\sanitize_settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Unit
 */
abstract class Unit {

	/**
	 * Machine name, kebab-case, prefixed: 'sidccofo-product-grid'.
	 *
	 * @return string
	 */
	abstract public function get_name();

	/**
	 * Label shown in the Sidcraft Unit library.
	 *
	 * @return string
	 */
	abstract public function get_title();

	/**
	 * Control schema. Keys are setting names. Supported types:
	 * text, number, select, switch, color, product, terms.
	 *
	 * @return array
	 */
	abstract protected function controls();

	/**
	 * Produce the Unit's inner HTML from sanitized settings.
	 *
	 * @param array $settings Sanitized settings.
	 * @return string
	 */
	abstract protected function render_html( array $settings );

	/**
	 * Icon slug (Dashicons name or Sidcraft icon key).
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'cart';
	}

	/**
	 * Library category.
	 *
	 * @return string
	 */
	public function get_category() {
		return 'sidcraft-commerce';
	}

	/**
	 * Search keywords for the Unit library.
	 *
	 * @return string[]
	 */
	public function get_keywords() {
		return array( 'store', 'shop', 'product' );
	}

	/**
	 * Asset handles this Unit needs (registered in Assets).
	 *
	 * @return array{styles: string[], scripts: string[]}
	 */
	public function get_assets() {
		return array(
			'styles'  => array( 'sidccofo-commerce' ),
			'scripts' => array(),
		);
	}

	/**
	 * Public, filterable control schema.
	 *
	 * @return array
	 */
	final public function get_controls() {
		$controls = $this->controls();

		// Every Unit gets the ShopWave colour-scheme switch.
		$controls['scheme'] = array(
			'type'    => 'select',
			'label'   => __( 'Colour scheme', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'section' => 'style',
			'default' => 'inherit',
			'options' => array(
				'inherit' => __( 'Inherit from theme', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'dark'    => __( 'ShopWave dark', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'light'   => __( 'Light', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			),
		);
		$controls['accent'] = array(
			'type'    => 'color',
			'label'   => __( 'Accent colour', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'section' => 'style',
			'default' => '',
		);

		/**
		 * Filter a Unit's controls. Dynamic hook: sidcraft_commerce_controls_{name}.
		 *
		 * @param array $controls Control schema.
		 * @param Unit  $unit     Unit.
		 */
		$controls = apply_filters( 'sidcraft_commerce_controls', $controls, $this );
		return apply_filters( "sidcraft_commerce_controls_{$this->get_name()}", $controls, $this );
	}

	/**
	 * Render the Unit. Entry point for Sidcraft, shortcodes and AJAX previews.
	 *
	 * @param array $settings Raw settings (untrusted).
	 * @return string
	 */
	final public function render( array $settings = array() ) {
		$settings = sanitize_settings( $settings, $this->get_controls() );

		/**
		 * Filter settings before render.
		 *
		 * @param array $settings Sanitized settings.
		 * @param Unit  $unit     Unit.
		 */
		$settings = apply_filters( "sidcraft_commerce_settings_{$this->get_name()}", $settings, $this );

		// Enqueue on demand. Styles were usually already enqueued in <head>
		// by Assets::detect(); this is the safety net for dynamic content.
		Plugin::instance()->assets->require_assets( $this->get_assets() );

		$inner = $this->render_html( $settings );
		if ( '' === $inner ) {
			return '';
		}

		$classes = array( 'sc-unit', 'sc-unit--' . $this->get_name() );
		if ( 'inherit' !== $settings['scheme'] ) {
			$classes[] = 'sc-scheme-' . $settings['scheme'];
		}
		$style = $settings['accent'] ? '--sc-accent:' . $settings['accent'] . ';' : '';

		$html = sprintf(
			'<div class="%1$s"%2$s data-sc-unit="%3$s">%4$s</div>',
			esc_attr( implode( ' ', $classes ) ),
			$style ? ' style="' . esc_attr( $style ) . '"' : '',
			esc_attr( $this->get_name() ),
			$inner
		);

		/**
		 * Filter the final Unit HTML.
		 *
		 * @param string $html     HTML.
		 * @param array  $settings Settings.
		 * @param Unit   $unit     Unit.
		 */
		return (string) apply_filters( "sidcraft_commerce_render_{$this->get_name()}", $html, $settings, $this );
	}

	/**
	 * Shortcut to the template loader.
	 *
	 * @param string $name Template path.
	 * @param array  $args Variables.
	 * @return string
	 */
	protected function template( $name, array $args ) {
		return Plugin::instance()->templates->render( $name, $args );
	}
}
