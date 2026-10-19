<?php
/**
 * Translated stores with WPML or Polylang.
 *
 * Products, product categories, brands, events and event categories can be
 * translated (wpml-config.xml; in Polylang, tick them under Languages →
 * Settings → Custom post types and Taxonomies). A translation is another
 * post, so to keep one stock count, one set of sales and one set of reviews,
 * the original-language post is the product everywhere that counts:
 * Product::get( a translation ) returns the original product, showing the
 * translation's title, link, description, image, attribute and option
 * labels in the shopper's language. Carts, orders, stock, price rules,
 * reviews and event tickets all use the original's id. The same goes for
 * events (Event::get()).
 *
 * Shop pages (cart, checkout…) open in the shopper's language, orders keep
 * the language they were placed in (_sidccofo_language) and their emails
 * are sent in it, and a currency can be picked per language.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

use SidcraftCommerce\Data\Post_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Class Multilingual
 */
class Multilingual {

	/**
	 * Languages pushed by switch_language().
	 *
	 * @var array[]
	 */
	protected static $switched = array();

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		// Polylang: offer the store's types for translation, never the records.
		add_filter( 'pll_get_post_types', array( $this, 'pll_post_types' ), 10, 2 );
		add_filter( 'pll_get_taxonomies', array( $this, 'pll_taxonomies' ), 10, 2 );
		add_filter( 'pll_copy_post_metas', array( $this, 'pll_copy_metas' ), 10, 2 );
		add_action( 'sidcraft_commerce_order_created', array( $this, 'order_created' ), 1 );
		add_filter( 'sidcraft_commerce_shopper_currency', array( $this, 'language_currency' ), 5 );
		add_filter( 'sidcraft_commerce_settings_sections', array( $this, 'settings' ), 20 );
		add_filter( 'rest_pre_dispatch', array( $this, 'rest_language' ), 5, 3 );
	}

	/**
	 * Store REST requests carry the page's language (X-Sidccofo-Lang, or
	 * ?lang=): work in it, so cart fragments, order item names, the
	 * order-received link and the order's language follow the shopper.
	 *
	 * @param mixed            $result  Response so far.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function rest_language( $result, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Filter signature.
		if ( ! self::active() || 0 !== strpos( (string) $request->get_route(), '/' . Rest::NS ) ) {
			return $result;
		}
		$lang = sanitize_key( (string) ( $request->get_header( 'x_sidccofo_lang' ) ?? $request->get_param( 'lang' ) ?? '' ) );
		if ( '' === $lang || '' === self::locale_of( $lang ) || $lang === self::current_language() ) {
			return $result;
		}
		if ( 'polylang' === self::plugin() && function_exists( 'PLL' ) && isset( PLL()->model ) ) {
			$language = PLL()->model->get_language( $lang );
			if ( $language ) {
				PLL()->curlang = $language; // Polylang's own current language: page links follow.
			}
		}
		self::switch_language( $lang ); // Kept for the rest of the request.
		return $result;
	}

	/**
	 * Which plugin translates the site: 'wpml', 'polylang' or ''.
	 *
	 * @return string
	 */
	public static function plugin() {
		// Polylang first: it also answers some WPML filters for compatibility.
		if ( function_exists( 'pll_get_post' ) && function_exists( 'pll_current_language' ) ) {
			return 'polylang';
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress' ) ) {
			return 'wpml';
		}
		/**
		 * Filter the translation plugin in use: 'wpml', 'polylang' or ''.
		 *
		 * @param string $plugin Plugin.
		 */
		return (string) apply_filters( 'sidcraft_commerce_translation_plugin', '' );
	}

	/**
	 * Is a translation plugin active?
	 *
	 * @return bool
	 */
	public static function active() {
		return '' !== self::plugin();
	}

	/**
	 * Current language code ('' when not translated).
	 *
	 * @return string
	 */
	public static function current_language() {
		if ( self::$switched ) {
			return (string) end( self::$switched )['lang'];
		}
		switch ( self::plugin() ) {
			case 'wpml':
				return (string) apply_filters( 'wpml_current_language', null );
			case 'polylang':
				return (string) pll_current_language();
		}
		return '';
	}

	/**
	 * Default (original) language code.
	 *
	 * @return string
	 */
	public static function default_language() {
		switch ( self::plugin() ) {
			case 'wpml':
				return (string) apply_filters( 'wpml_default_language', null );
			case 'polylang':
				return function_exists( 'pll_default_language' ) ? (string) pll_default_language() : '';
		}
		return '';
	}

	/**
	 * Language of a post ('' when unknown).
	 *
	 * @param int $id Post.
	 * @return string
	 */
	public static function post_language( $id ) {
		switch ( self::plugin() ) {
			case 'wpml':
				$info = apply_filters( 'wpml_post_language_details', null, (int) $id );
				return is_array( $info ) ? (string) ( $info['language_code'] ?? '' ) : '';
			case 'polylang':
				return function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( (int) $id ) : '';
		}
		return '';
	}

	/**
	 * A post's translation in a language, or 0.
	 *
	 * @param int    $id   Post.
	 * @param string $lang Language code.
	 * @return int
	 */
	public static function translation( $id, $lang ) {
		$id = (int) $id;
		if ( ! $id || '' === (string) $lang ) {
			return 0;
		}
		switch ( self::plugin() ) {
			case 'wpml':
				return (int) apply_filters( 'wpml_object_id', $id, get_post_type( $id ), false, $lang );
			case 'polylang':
				return (int) pll_get_post( $id, $lang );
		}
		return 0;
	}

	/**
	 * The original-language post of a translation (itself when it is the
	 * original, untranslated, or no plugin is active).
	 *
	 * @param int $id Post.
	 * @return int
	 */
	public static function original_id( $id ) {
		$id = (int) $id;
		if ( ! $id || ! self::active() ) {
			return $id;
		}
		$orig = self::translation( $id, self::default_language() );
		return $orig ? $orig : $id;
	}

	/**
	 * The post in the current language (itself when there is no translation).
	 *
	 * @param int $id Post.
	 * @return int
	 */
	public static function current_id( $id ) {
		$id = (int) $id;
		if ( ! $id || ! self::active() ) {
			return $id;
		}
		$tr = self::translation( $id, self::current_language() );
		return $tr ? $tr : $id;
	}

	/**
	 * Every translation of a post: language => id (the post itself included).
	 *
	 * @param int $id Post.
	 * @return int[]
	 */
	public static function translations( $id ) {
		$id = (int) $id;
		switch ( self::plugin() ) {
			case 'wpml':
				$type  = 'post_' . get_post_type( $id );
				$trid  = apply_filters( 'wpml_element_trid', null, $id, $type );
				$items = $trid ? apply_filters( 'wpml_get_element_translations', null, $trid, $type ) : array();
				$out   = array();
				foreach ( (array) $items as $lang => $row ) {
					$out[ (string) $lang ] = (int) ( is_object( $row ) ? $row->element_id : ( $row['element_id'] ?? 0 ) );
				}
				return array_filter( $out );
			case 'polylang':
				return function_exists( 'pll_get_post_translations' ) ? array_map( 'intval', (array) pll_get_post_translations( $id ) ) : array();
		}
		return array();
	}

	/**
	 * Is this post a translation (not the original)?
	 *
	 * @param int $id Post.
	 * @return bool
	 */
	public static function is_translation( $id ) {
		return self::active() && self::original_id( $id ) !== (int) $id;
	}

	/**
	 * The site's language codes (the default first).
	 *
	 * @return string[]
	 */
	public static function languages() {
		$out = array();
		switch ( self::plugin() ) {
			case 'wpml':
				$out = array_keys( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) );
				break;
			case 'polylang':
				$out = function_exists( 'pll_languages_list' ) ? (array) pll_languages_list( array( 'fields' => 'slug' ) ) : array();
				break;
		}
		$default = self::default_language();
		$out     = array_values( array_unique( array_merge( '' !== $default ? array( $default ) : array(), array_map( 'strval', $out ) ) ) );
		return array_values( array_filter( $out ) );
	}

	/**
	 * Give a post a language.
	 *
	 * @param int    $id   Post.
	 * @param string $lang Language code.
	 */
	public static function set_post_language( $id, $lang ) {
		switch ( self::plugin() ) {
			case 'polylang':
				pll_set_post_language( (int) $id, $lang );
				break;
			case 'wpml':
				$type = 'post_' . get_post_type( (int) $id );
				do_action(
					'wpml_set_element_language_details',
					array(
						'element_id'    => (int) $id,
						'element_type'  => $type,
						'trid'          => apply_filters( 'wpml_element_trid', null, (int) $id, $type ),
						'language_code' => $lang,
					)
				);
				break;
		}
	}

	/**
	 * Make posts translations of each other.
	 *
	 * @param int[] $ids language => post id (the original-language one included).
	 */
	public static function link_posts( array $ids ) {
		$ids = array_filter( array_map( 'intval', $ids ) );
		switch ( self::plugin() ) {
			case 'polylang':
				pll_save_post_translations( $ids );
				break;
			case 'wpml':
				$default = self::default_language();
				$orig    = $ids[ $default ] ?? reset( $ids );
				$type    = 'post_' . get_post_type( $orig );
				$trid    = apply_filters( 'wpml_element_trid', null, $orig, $type );
				foreach ( $ids as $lang => $id ) {
					do_action(
						'wpml_set_element_language_details',
						array(
							'element_id'           => $id,
							'element_type'         => $type,
							'trid'                 => $trid,
							'language_code'        => $lang,
							'source_language_code' => $id === $orig ? null : $default,
						)
					);
				}
				break;
		}
	}

	/**
	 * Give a term a language.
	 *
	 * @param int    $term_id  Term.
	 * @param string $taxonomy Taxonomy.
	 * @param string $lang     Language code.
	 */
	public static function set_term_language( $term_id, $taxonomy, $lang ) {
		switch ( self::plugin() ) {
			case 'polylang':
				pll_set_term_language( (int) $term_id, $lang );
				break;
			case 'wpml':
				$term = get_term( (int) $term_id, $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					$type = 'tax_' . $taxonomy;
					do_action(
						'wpml_set_element_language_details',
						array(
							'element_id'    => (int) $term->term_taxonomy_id,
							'element_type'  => $type,
							'trid'          => apply_filters( 'wpml_element_trid', null, (int) $term->term_taxonomy_id, $type ),
							'language_code' => $lang,
						)
					);
				}
				break;
		}
	}

	/**
	 * Make terms translations of each other.
	 *
	 * @param int[]  $ids      language => term id.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function link_terms( array $ids, $taxonomy ) {
		$ids = array_filter( array_map( 'intval', $ids ) );
		switch ( self::plugin() ) {
			case 'polylang':
				pll_save_term_translations( $ids );
				break;
			case 'wpml':
				$default = self::default_language();
				$tt      = array();
				foreach ( $ids as $lang => $id ) {
					$term = get_term( $id, $taxonomy );
					if ( $term && ! is_wp_error( $term ) ) {
						$tt[ $lang ] = (int) $term->term_taxonomy_id;
					}
				}
				if ( ! $tt ) {
					return;
				}
				$orig = $tt[ $default ] ?? reset( $tt );
				$type = 'tax_' . $taxonomy;
				$trid = apply_filters( 'wpml_element_trid', null, $orig, $type );
				foreach ( $tt as $lang => $id ) {
					do_action(
						'wpml_set_element_language_details',
						array(
							'element_id'           => $id,
							'element_type'         => $type,
							'trid'                 => $trid,
							'language_code'        => $lang,
							'source_language_code' => $id === $orig ? null : $default,
						)
					);
				}
				break;
		}
	}

	/**
	 * Are a post type's posts translated?
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	public static function is_translated_post_type( $type ) {
		switch ( self::plugin() ) {
			case 'polylang':
				return function_exists( 'pll_is_translated_post_type' ) && pll_is_translated_post_type( $type );
			case 'wpml':
				return (bool) apply_filters( 'wpml_is_translated_post_type', null, $type );
		}
		return false;
	}

	/**
	 * Are a taxonomy's terms translated (one term per language)?
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	public static function is_translated_taxonomy( $taxonomy ) {
		switch ( self::plugin() ) {
			case 'polylang':
				return function_exists( 'pll_is_translated_taxonomy' ) && pll_is_translated_taxonomy( $taxonomy );
			case 'wpml':
				return (bool) apply_filters( 'wpml_is_translated_taxonomy', null, $taxonomy );
		}
		return false;
	}

	/**
	 * A term's translation in a language, or 0.
	 *
	 * @param int    $term_id  Term.
	 * @param string $taxonomy Taxonomy.
	 * @param string $lang     Language code.
	 * @return int
	 */
	public static function term_translation( $term_id, $taxonomy, $lang ) {
		switch ( self::plugin() ) {
			case 'polylang':
				return function_exists( 'pll_get_term' ) ? (int) pll_get_term( (int) $term_id, $lang ) : 0;
			case 'wpml':
				return (int) apply_filters( 'wpml_object_id', (int) $term_id, $taxonomy, false, $lang );
		}
		return 0;
	}

	/*
	------------------------------------------------------------------
	 * Languages for emails
	 * ----------------------------------------------------------------
	 */

	/**
	 * Locale of a language code ('' when unknown).
	 *
	 * @param string $lang Language code.
	 * @return string
	 */
	public static function locale_of( $lang ) {
		switch ( self::plugin() ) {
			case 'wpml':
				$langs = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
				return is_array( $langs ) && isset( $langs[ $lang ] ) ? (string) ( $langs[ $lang ]['default_locale'] ?? '' ) : '';
			case 'polylang':
				if ( function_exists( 'PLL' ) && isset( PLL()->model ) ) {
					$language = PLL()->model->get_language( $lang );
					return $language ? (string) $language->locale : '';
				}
		}
		return '';
	}

	/**
	 * Work in a language until restore_language() (emails for an order).
	 *
	 * @param string $lang Language code ('' changes nothing).
	 */
	public static function switch_language( $lang ) {
		$lang   = (string) $lang;
		$locale = '' !== $lang ? self::locale_of( $lang ) : '';
		$state  = array(
			'lang'     => '' !== $lang ? $lang : self::current_language(),
			'locale'   => $locale && function_exists( 'switch_to_locale' ) ? switch_to_locale( $locale ) : false,
			'previous' => '',
		);
		if ( '' !== $lang && 'wpml' === self::plugin() ) {
			$state['previous'] = (string) apply_filters( 'wpml_current_language', null );
			do_action( 'wpml_switch_language', $lang );
		}
		self::$switched[] = $state;
	}

	/**
	 * Undo the last switch_language().
	 */
	public static function restore_language() {
		$state = array_pop( self::$switched );
		if ( ! $state ) {
			return;
		}
		if ( $state['locale'] && function_exists( 'restore_previous_locale' ) ) {
			restore_previous_locale();
		}
		if ( '' !== $state['previous'] ) {
			do_action( 'wpml_switch_language', $state['previous'] );
		}
	}

	/**
	 * Run a function in an order's language.
	 *
	 * @param Data\Order $order Order.
	 * @param callable   $fn    Function.
	 * @return mixed
	 */
	public static function for_order( Data\Order $order, callable $fn ) {
		self::switch_language( (string) get_post_meta( $order->get_id(), '_sidccofo_language', true ) );
		try {
			return $fn();
		} finally {
			self::restore_language();
		}
	}

	/**
	 * New order: remember the shopper's language.
	 *
	 * @param Data\Order $order Order.
	 */
	public function order_created( $order ) {
		if ( self::active() ) {
			update_post_meta( $order->get_id(), '_sidccofo_language', self::current_language() );
		}
	}

	/*
	------------------------------------------------------------------
	 * Currency per language
	 * ----------------------------------------------------------------
	 */

	/**
	 * A shopper who hasn't picked a currency gets their language's.
	 *
	 * @param string $code Currency so far.
	 * @return string
	 */
	public function language_currency( $code ) {
		// A choice or a country match wins; this only replaces the store default.
		if ( ! self::active() || ! empty( $_COOKIE[ cookie_name( Currencies::COOKIE ) ] ) || Currencies::base() !== $code ) {
			return $code;
		}
		$lang = self::current_language();
		foreach ( (array) setting( 'language_currencies', array() ) as $row ) {
			if ( '' !== $lang && strtolower( (string) ( $row['language'] ?? '' ) ) === strtolower( $lang ) ) {
				return strtoupper( (string) ( $row['currency'] ?? $code ) );
			}
		}
		return $code;
	}

	/**
	 * Store → Settings: currency per language (only with a translation plugin).
	 *
	 * @param array $sections Sections.
	 * @return array
	 */
	public function settings( $sections ) {
		if ( ! self::active() || ! isset( $sections['currencies'] ) ) {
			return $sections;
		}
		$sections['currencies']['fields']['language_currencies'] = array(
			__( 'Currency per language', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'table',
			__( 'Shoppers who haven’t chosen a currency get this one when they browse in that language. Language: the code your translation plugin uses, e.g. de.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			array(
				'language' => array( __( 'Language', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'text', 'de' ),
				'currency' => array( __( 'Currency', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'currency', 'EUR' ),
			),
		);
		return $sections;
	}

	/*
	------------------------------------------------------------------
	 * Polylang
	 * ----------------------------------------------------------------
	 */

	/**
	 * Types Polylang may translate: products and events; never orders,
	 * coupons, gift cards, subscriptions or tickets (records shared by every language).
	 *
	 * @param array $types       Post types.
	 * @param bool  $is_settings Building the settings list.
	 * @return array
	 */
	public function pll_post_types( $types, $is_settings ) {
		foreach ( array( Post_Types::ORDER, Post_Types::COUPON, Post_Types::GIFTCARD, Post_Types::SUBSCRIPTION, 'sidccofo_ticket' ) as $record ) {
			unset( $types[ $record ] );
		}
		if ( $is_settings ) {
			$types[ Post_Types::PRODUCT ] = Post_Types::PRODUCT;
			$types['sidccofo_event']      = 'sidccofo_event';
		}
		return $types;
	}

	/**
	 * Taxonomies Polylang may translate.
	 *
	 * @param array $taxonomies  Taxonomies.
	 * @param bool  $is_settings Building the settings list.
	 * @return array
	 */
	public function pll_taxonomies( $taxonomies, $is_settings ) {
		if ( $is_settings ) {
			foreach ( array( Post_Types::CATEGORY, Post_Types::BRAND, Post_Types::TAG, 'sidccofo_event_cat' ) as $tax ) {
				$taxonomies[ $tax ] = $tax;
			}
		}
		return $taxonomies;
	}

	/**
	 * New translations start with the original's store data.
	 *
	 * @param string[] $metas Keys Polylang copies.
	 * @param bool     $sync  True when synchronizing, false when creating.
	 * @return string[]
	 */
	public function pll_copy_metas( $metas, $sync ) {
		if ( $sync ) {
			return $metas; // Shared data is read from the original; nothing to keep in step.
		}
		foreach ( array( '_sidccofo_options', '_sidccofo_attributes', '_sidccofo_ev_venue', '_sidccofo_ev_address', '_sidccofo_ev_organizer' ) as $key ) {
			$metas[] = $key;
		}
		return array_values( array_unique( $metas ) );
	}
}
