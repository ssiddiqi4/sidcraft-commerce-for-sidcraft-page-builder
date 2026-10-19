<?php
/**
 * Gutenberg blocks for the event Units (server-rendered, so blocks,
 * shortcodes and Sidcraft Page Builder Units always look the same).
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Events;

use SidcraftCommerce\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Class Blocks
 */
class Blocks {

	/**
	 * Unit name => block slug.
	 *
	 * @var string[]
	 */
	const MAP = array(
		'sidccofo-event-list'     => 'event-list',
		'sidccofo-event-calendar' => 'event-calendar',
		'sidccofo-event-details'  => 'event-details',
		'sidccofo-event-tickets'  => 'event-tickets',
		'sidccofo-event-map'      => 'event-map',
		'sidccofo-my-tickets'     => 'my-tickets',
	);

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'register' ), 20 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_assets' ) );
		add_filter( 'block_categories_all', array( $this, 'category' ) );
	}

	/**
	 * "Sidcraft Commerce" block category.
	 *
	 * @param array $cats Categories.
	 * @return array
	 */
	public function category( $cats ) {
		foreach ( $cats as $cat ) {
			if ( 'sidcraft-commerce' === ( $cat['slug'] ?? '' ) ) {
				return $cats;
			}
		}
		$cats[] = array(
			'slug'  => 'sidcraft-commerce',
			'title' => __( 'Sidcraft Commerce', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'icon'  => null,
		);
		return $cats;
	}

	/**
	 * Block attributes from a Unit's controls.
	 *
	 * @param array $controls Controls.
	 * @return array
	 */
	protected static function attributes( array $controls ) {
		$attrs = array();
		foreach ( $controls as $key => $control ) {
			$type          = $control['type'] ?? 'text';
			$attrs[ $key ] = array(
				'type'    => 'number' === $type ? 'number' : ( 'switch' === $type ? 'boolean' : 'string' ),
				'default' => 'switch' === $type ? (bool) ( $control['default'] ?? false ) : ( 'number' === $type ? (float) ( $control['default'] ?? 0 ) : (string) ( $control['default'] ?? '' ) ),
			);
		}
		$attrs['className'] = array(
			'type'    => 'string',
			'default' => '',
		);
		return $attrs;
	}

	/**
	 * Register the blocks.
	 */
	public function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			'sidccofo-events-blocks',
			SIDCRAFT_COMMERCE_URL . 'assets/js/sc-events-blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
			SIDCRAFT_COMMERCE_VERSION,
			true
		);
		$registry = Plugin::instance()->units;
		foreach ( self::MAP as $unit_name => $slug ) {
			$unit = $registry->get( $unit_name );
			if ( ! $unit ) {
				continue;
			}
			register_block_type(
				'sidcraft-commerce/' . $slug,
				array(
					'api_version'     => 3,
					'title'           => $unit->get_title(),
					'category'        => 'sidcraft-commerce',
					'editor_script'   => 'sidccofo-events-blocks',
					'attributes'      => self::attributes( $unit->get_controls() ),
					'supports'        => array(
						'html'  => false,
						'align' => array( 'wide', 'full' ),
					),
					'render_callback' => static function ( $attributes ) use ( $unit ) {
						$class = trim( (string) ( $attributes['className'] ?? '' ) );
						unset( $attributes['className'] );
						$html = $unit->render( (array) $attributes );
						return '' !== $class ? '<div class="' . esc_attr( $class ) . '">' . $html . '</div>' : $html;
					},
				)
			);
		}
	}

	/**
	 * Editor data: Unit controls and recent events for the pickers.
	 */
	public function editor_assets() {
		Plugin::instance()->assets->register();
		wp_enqueue_style( 'sidccofo-commerce' );
		wp_enqueue_style( 'sidccofo-events' );

		$blocks   = array();
		$registry = Plugin::instance()->units;
		foreach ( self::MAP as $unit_name => $slug ) {
			$unit = $registry->get( $unit_name );
			if ( ! $unit ) {
				continue;
			}
			$blocks[] = array(
				'name'     => 'sidcraft-commerce/' . $slug,
				'title'    => $unit->get_title(),
				'icon'     => $unit->get_icon(),
				'keywords' => $unit->get_keywords(),
				'controls' => $unit->get_controls(),
			);
		}
		$events = array();
		foreach ( get_posts(
			array(
				'post_type'      => Events::EVENT,
				'post_status'    => array( 'publish', 'future', 'draft' ),
				'posts_per_page' => 100,
				'meta_key'       => '_sidccofo_ev_start', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
			)
		) as $post ) {
			$events[] = array(
				'id'    => (int) $post->ID,
				'title' => get_the_title( $post ),
			);
		}
		wp_add_inline_script(
			'sidccofo-events-blocks',
			'window.sidccofoBlocks = ' . wp_json_encode(
				array(
					'blocks' => $blocks,
					'events' => $events,
					'i18n'   => array(
						'current' => __( 'The event being viewed', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'event'   => __( 'Event', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'content' => __( 'Content', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'style'   => __( 'Style', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					),
				)
			) . ';',
			'before'
		);
	}
}
