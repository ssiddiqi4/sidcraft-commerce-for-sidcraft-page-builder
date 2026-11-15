<?php
/**
 * Presents one commerce Unit to Sidcraft Page Builder as a native Unit
 * (extends \SidcraftPageBuilder\Units\Unit, registered on
 * `sidcraft_page_builder_units_register`).
 *
 * Loaded only from inside that action, so the parent class always exists.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Integrations;

use SidcraftCommerce\Abstracts\Unit as Commerce_Unit;
use SidcraftCommerce\Data\Post_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Class Sidcraft_Unit_Adapter
 */
class Sidcraft_Unit_Adapter extends \SidcraftPageBuilder\Units\Unit {

	/** Sidcraft panel category for every commerce Unit. */
	const CATEGORY = 'commerce';

	/**
	 * Wrapped commerce Unit.
	 *
	 * @var Commerce_Unit
	 */
	protected $unit;

	/**
	 * Constructor.
	 *
	 * @param Commerce_Unit $unit Commerce Unit.
	 */
	public function __construct( Commerce_Unit $unit ) {
		$this->unit = $unit;
	}

	/**
	 * Commerce unit.
	 *
	 * @return Commerce_Unit
	 */
	public function commerce_unit() {
		return $this->unit;
	}

	/** {@inheritDoc} */
	public function type() {
		return $this->unit->get_name();
	}

	/** {@inheritDoc} */
	public function title() {
		return $this->unit->get_title();
	}

	/** {@inheritDoc} Sidcraft panel icons are single glyphs. */
	public function icon() {
		$glyphs = array(
			'grid-view'   => "\u{25A6}", // ▦
			'cart'        => "\u{1F6D2}", // 🛒
			'tag'         => '$',
			'archive'     => "\u{25A3}", // ▣
			'star-filled' => "\u{2605}", // ★
			'money-alt'   => "\u{1F4B3}", // 💳
			'yes-alt'     => "\u{2714}", // ✔
		);
		$icon = (string) $this->unit->get_icon();
		if ( isset( $glyphs[ $icon ] ) ) {
			return $glyphs[ $icon ];
		}
		// Add-on Units may give a glyph or emoji directly.
		return '' !== $icon && mb_strlen( $icon ) <= 2 ? $icon : "\u{25C7}";
	}

	/** {@inheritDoc} */
	public function category() {
		return self::CATEGORY;
	}

	/** {@inheritDoc} */
	public function keywords() {
		return array_merge( array( $this->type(), $this->title(), 'commerce' ), $this->unit->get_keywords() );
	}

	/** {@inheritDoc} */
	public function defaults() {
		$out = array();
		foreach ( $this->unit->get_controls() as $key => $control ) {
			$out[ $key ] = $control['default'] ?? '';
		}
		return $out;
	}

	/**
	 * Translate the commerce control schema into Sidcraft's.
	 *
	 * Commerce types → Sidcraft types: text, number, select, switch, color map
	 * 1:1; `product` becomes a select of products; `terms` a text field.
	 * `section: style` goes to the Style tab, everything else to Content.
	 *
	 * @return array
	 */
	public function controls() {
		$sections = array(
			'query'   => __( 'Products', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'layout'  => __( 'Layout', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'content' => __( 'Content', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'style'   => __( 'Commerce style', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);

		$out = array();
		foreach ( $this->unit->get_controls() as $key => $c ) {
			$section = (string) ( $c['section'] ?? 'content' );
			$def     = array(
				'type'    => $c['type'] ?? 'text',
				'label'   => $c['label'] ?? '',
				'tab'     => 'style' === $section ? 'style' : 'content',
				'section' => $sections[ $section ] ?? ucfirst( $section ),
				'default' => $c['default'] ?? '',
				'dynamic' => false,
			);

			switch ( $def['type'] ) {
				case 'product':
					$def['type']    = 'select';
					$def['options'] = self::product_options();
					break;
				case 'terms':
					$def['type']        = 'text';
					$def['placeholder'] = 'shirts, hoodies';
					break;
				case 'number':
					if ( isset( $c['min'] ) || isset( $c['max'] ) ) {
						$def['range'] = array(
							'min'  => $c['min'] ?? 0,
							'max'  => $c['max'] ?? 100,
							'step' => 1,
						);
					}
					break;
			}

			foreach ( array( 'options', 'condition', 'description' ) as $pass ) {
				if ( isset( $c[ $pass ] ) && ! isset( $def[ $pass ] ) ) {
					$def[ $pass ] = $c[ $pass ];
				}
			}
			$out[ $key ] = $def;
		}

		/**
		 * Filter the Sidcraft control schema for one commerce Unit.
		 *
		 * @param array         $out  Sidcraft controls.
		 * @param Commerce_Unit $unit Commerce Unit.
		 */
		return (array) apply_filters( 'sidcraft_commerce_sidcraft_controls', $out, $this->unit );
	}

	/**
	 * Product picker options: '' (current product page) + up to 200 products.
	 *
	 * @return array
	 */
	protected static function product_options() {
		static $options = null;
		if ( null === $options ) {
			$options = array( '' => __( '— Current product page —', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
			// Whole posts in one query (titles need them), without their meta and terms.
			$posts   = get_posts(
				array(
					'post_type'              => Post_Types::PRODUCT,
					'post_status'            => array( 'publish', 'draft', 'private' ),
					'posts_per_page'         => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Editor product picker, admin only.
					'orderby'                => 'title',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			foreach ( $posts as $post ) {
				$options[ (string) $post->ID ] = get_the_title( $post ) . ' (#' . $post->ID . ')';
			}
		}
		return $options;
	}

	/**
	 * Flatten responsive values ({desktop: …}) to the desktop value.
	 *
	 * @param array $settings Sidcraft settings.
	 * @return array
	 */
	public static function flatten( $settings ) {
		$out = array();
		foreach ( (array) $settings as $key => $value ) {
			if ( is_array( $value ) && array_key_exists( 'desktop', $value ) ) {
				$value = $value['desktop'];
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * {@inheritDoc} Same HTML on the live page and in the editor preview.
	 *
	 * @param mixed $settings Settings.
	 * @param mixed $children Children.
	 */
	public function render( $settings, $children = '' ) {
		return $this->unit->render( self::flatten( $settings ) );
	}

	/**
	 * {@inheritDoc} Sidcraft enqueues these in <head> for pages using the Unit.
	 *
	 * @param mixed $settings Settings.
	 */
	public function styles( $settings = array() ) {
		$assets = $this->unit->get_assets();
		return (array) ( $assets['styles'] ?? array() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $settings Settings.
	 */
	public function scripts( $settings = array() ) {
		$assets = $this->unit->get_assets();
		return (array) ( $assets['scripts'] ?? array() );
	}
}
