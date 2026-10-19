<?php
/**
 * Bridge between Sidcraft Commerce and Sidcraft Page Builder (0.15+).
 *
 * The only file that talks to Sidcraft's internals. Uses Sidcraft's public
 * extension points:
 *
 *   sidcraft_page_builder_units_register      register each commerce Unit
 *                                             (wrapped in Sidcraft_Unit_Adapter)
 *   sidcraft_page_builder_unit_cacheable      opt commerce Units out of
 *                                             Sidcraft's fragment cache
 *   sidcraft_page_builder_optimize_live_types (same, by type list)
 *   sidcraft_page_builder_frontend_enqueue    make sure our handles exist
 *                                             before Sidcraft enqueues them
 *   sidcraft_page_builder_editor_enqueue      editor script: canvas previews
 *                                             via the editor/node/body_html/<type> JS filter
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Integrations;

use SidcraftCommerce\Plugin;
use SidcraftCommerce\Rest;
use SidcraftCommerce\Unit_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Class Sidcraft_Bridge
 */
class Sidcraft_Bridge {

	/**
	 * Our registry.
	 *
	 * @var Unit_Registry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param Unit_Registry $registry Registry.
	 */
	public function __construct( Unit_Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'sidcraft_page_builder_units_register', array( $this, 'register_with_sidcraft' ) );
		add_filter( 'sidcraft_page_builder_unit_cacheable', array( $this, 'not_cacheable' ), 10, 2 );
		add_filter( 'sidcraft_page_builder_optimize_live_types', array( $this, 'live_types' ) );
		add_action( 'sidcraft_page_builder_frontend_enqueue', array( Plugin::instance()->assets, 'register' ) );
		add_action( 'sidcraft_page_builder_editor_enqueue', array( $this, 'editor_assets' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'sidcraft_page_builder_frontend_before_render', array( $this, 'prime_products' ), 10, 2 );
	}

	/**
	 * Load every product a document's commerce Units point at in one batch,
	 * before they render. Each Unit otherwise loads its product's post and meta
	 * with two queries of its own.
	 *
	 * @param int   $post_id Document post ID.
	 * @param array $doc     Document.
	 */
	public function prime_products( $post_id, $doc ) {
		unset( $post_id );
		if ( ! is_array( $doc ) || ! function_exists( '_prime_post_caches' ) ) {
			return;
		}
		$names = array_flip( $this->names() );
		$ids   = array();
		$walk  = static function ( $nodes ) use ( &$walk, &$ids, $names ) {
			foreach ( (array) $nodes as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				if ( isset( $names[ (string) ( $node['type'] ?? '' ) ] ) ) {
					$id = Sidcraft_Unit_Adapter::flatten( (array) ( $node['settings'] ?? array() ) )['product_id'] ?? 0;
					$id = is_scalar( $id ) ? absint( $id ) : 0;
					if ( $id ) {
						$ids[ $id ] = $id;
					}
				}
				if ( ! empty( $node['children'] ) ) {
					$walk( $node['children'] );
				}
			}
		};
		foreach ( array( 'header', 'root', 'footer' ) as $part ) {
			$walk( $doc[ $part ] ?? array() );
		}
		if ( $ids ) {
			_prime_post_caches( array_values( $ids ), false, true );
		}
	}

	/**
	 * Commerce Unit names.
	 *
	 * @return string[]
	 */
	protected function names() {
		return array_keys( $this->registry->all() );
	}

	/**
	 * Register every commerce Unit with Sidcraft's UnitRegistry.
	 *
	 * Sidcraft fires this after its core Units exist. Our Units register on
	 * init (priority 5); if Sidcraft boots its registry earlier, register them
	 * now so none are missed.
	 *
	 * @param object $sidcraft_registry \SidcraftPageBuilder\Units\UnitRegistry.
	 */
	public function register_with_sidcraft( $sidcraft_registry ) {
		if ( ! class_exists( '\SidcraftPageBuilder\Units\Unit' ) || ! is_object( $sidcraft_registry ) || ! method_exists( $sidcraft_registry, 'register' ) ) {
			return;
		}
		require_once __DIR__ . '/class-sidcraft-unit-adapter.php';

		if ( ! $this->registry->all() ) {
			Plugin::instance()->register_core_units();
		}

		foreach ( $this->registry->all() as $unit ) {
			$adapter = new Sidcraft_Unit_Adapter( $unit );

			/**
			 * Filter the Sidcraft Unit object for one commerce Unit (return a
			 * subclass to customise how it appears in the builder).
			 *
			 * @param Sidcraft_Unit_Adapter $adapter Adapter.
			 * @param object                $unit    Commerce Unit.
			 */
			$sidcraft_registry->register( apply_filters( 'sidcraft_commerce_sidcraft_definition', $adapter, $unit ) );
		}
	}

	/**
	 * Commerce output depends on stock, prices and the visitor's cart: never
	 * let Sidcraft cache it.
	 *
	 * @param bool  $ok   Cacheable.
	 * @param array $node Sidcraft node.
	 * @return bool
	 */
	public function not_cacheable( $ok, $node ) {
		return in_array( (string) ( $node['type'] ?? '' ), $this->names(), true ) ? false : $ok;
	}

	/**
	 * Add commerce Units to Sidcraft's "live" (never cached) types.
	 *
	 * @param string[] $types Types.
	 * @return string[]
	 */
	public function live_types( $types ) {
		return array_values( array_unique( array_merge( (array) $types, $this->names() ) ) );
	}

	/**
	 * Editor: front-end styles for previews + the preview script.
	 */
	public function editor_assets() {
		$assets = Plugin::instance()->assets;
		$assets->register();

		wp_enqueue_script(
			'sidccofo-editor',
			SIDCRAFT_COMMERCE_URL . 'assets/js/sc-editor.js',
			array( 'sidcraft-page-builder-editor' ),
			SIDCRAFT_COMMERCE_VERSION,
			true
		);

		$styles = array();
		foreach ( array( 'sidccofo-commerce', 'sidccofo-quick-view', 'sidccofo-checkout', 'sidccofo-reviews' ) as $handle ) {
			$registered = wp_styles()->registered[ $handle ] ?? null;
			if ( $registered ) {
				$styles[] = add_query_arg( 'ver', $registered->ver, $registered->src );
			}
		}
		// Canvas-only rules (loading placeholder, inert previews).
		$styles[] = add_query_arg( 'ver', SIDCRAFT_COMMERCE_VERSION, SIDCRAFT_COMMERCE_URL . 'assets/css/sc-editor.css' );

		/**
		 * Stylesheet URLs loaded into the builder canvas for Unit previews.
		 * Add-ons with their own Units add their front-end CSS here.
		 *
		 * @param string[] $styles URLs.
		 */
		$styles = array_values( array_filter( (array) apply_filters( 'sidcraft_commerce_editor_styles', $styles ) ) );

		wp_localize_script(
			'sidccofo-editor',
			'sidccofoEditor',
			array(
				'types'   => $this->names(),
				'preview' => esc_url_raw( rest_url( Rest::NS . '/editor/preview' ) ),
				'styles'  => $styles,
				'loading' => __( 'Loading preview…', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			)
		);
	}

	/**
	 * May the current user see this product in the editor? Published and
	 * not password-protected: yes. Anything else: only if they may edit it.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	protected static function can_preview_product( $product_id ) {
		$post = get_post( $product_id );
		if ( ! $post || \SidcraftCommerce\Data\Post_Types::PRODUCT !== $post->post_type ) {
			return false;
		}
		if ( 'publish' === $post->post_status && '' === $post->post_password ) {
			return true;
		}
		return current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Editor REST routes: product search and server-rendered Unit previews.
	 */
	public function register_rest_routes() {
		// Editors only. Each product is then checked individually below, so
		// drafts and private products are listed only for users who may edit them.
		$can_edit = static function () {
			$type = get_post_type_object( \SidcraftCommerce\Data\Post_Types::PRODUCT );
			return $type && current_user_can( $type->cap->edit_posts );
		};

		register_rest_route(
			Rest::NS,
			'/products',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => $can_edit,
				'args'                => array(
					'search' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
				),
				'callback'            => static function ( \WP_REST_Request $request ) {
					$query = new \WP_Query(
						array(
							'post_type'      => \SidcraftCommerce\Data\Post_Types::PRODUCT,
							'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
							'posts_per_page' => 20,
							's'              => $request['search'],
							'no_found_rows'  => true,
						)
					);
					$out   = array();
					foreach ( $query->posts as $post ) {
						if ( ! self::can_preview_product( $post->ID ) ) {
							continue;
						}
						$p     = \SidcraftCommerce\Data\Product::get( $post->ID );
						$out[] = array(
							'id'    => $post->ID,
							'label' => get_the_title( $post ),
							'sku'   => $p ? $p->get_sku() : '',
						);
					}
					return rest_ensure_response( $out );
				},
			)
		);

		register_rest_route(
			Rest::NS,
			'/editor/preview',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can_edit,
				'callback'            => function ( \WP_REST_Request $request ) {
					$unit = $this->registry->get( sanitize_key( (string) $request['type'] ) );
					if ( ! $unit ) {
						return new \WP_Error( 'sidccofo_unknown_unit', __( 'Unknown Unit.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => 404 ) );
					}
					$settings = is_array( $request['settings'] ) ? $request['settings'] : array();
					$post_id  = absint( $request['post_id'] );

					// The page being edited: only one this user may edit.
					if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
						return new \WP_Error( 'sidccofo_forbidden', __( 'Sorry, you are not allowed to preview this content.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => rest_authorization_required_code() ) );
					}

					// Product Units placed on a product page preview that product.
					if ( $post_id && \SidcraftCommerce\Data\Post_Types::PRODUCT === get_post_type( $post_id ) && empty( $settings['product_id'] ) ) {
						$settings['product_id'] = $post_id;
					}
					require_once __DIR__ . '/class-sidcraft-unit-adapter.php';
					$flat = Sidcraft_Unit_Adapter::flatten( $settings );

					// A chosen product must be published, or one this user may edit.
					if ( ! empty( $flat['product_id'] ) && ! self::can_preview_product( absint( $flat['product_id'] ) ) ) {
						return new \WP_Error( 'sidccofo_forbidden', __( 'Sorry, you are not allowed to preview this product.', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( 'status' => rest_authorization_required_code() ) );
					}

					$html = $unit->render( $flat );
					if ( '' === trim( $html ) ) {
						/* translators: %s: Unit title. */
						$html = '<p class="sc-empty">' . esc_html( sprintf( __( '%s — nothing to show yet with these settings.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $unit->get_title() ) ) . '</p>';
					}
					return rest_ensure_response( array( 'html' => $html ) );
				},
			)
		);
	}
}
