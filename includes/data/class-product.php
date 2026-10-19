<?php
/**
 * Product model: a thin, typed wrapper around an sidccofo_product post.
 *
 * Stored meta:
 *   _sidccofo_regular_price, _sidccofo_sale_price, _sidccofo_sku, _sidccofo_manage_stock, _sidccofo_stock,
 *   _sidccofo_stock_status (instock|outofstock), _sidccofo_featured,
 *   _sidccofo_badge (''|hot|popular|sale|clearance),
 *   _sidccofo_gift_card (0|1), _sidccofo_gc_amounts ("25, 50, 100"),
 *   _sidccofo_gc_custom (0|1), _sidccofo_gc_min, _sidccofo_gc_max,
 *   _sidccofo_total_sales, _sidccofo_rating_avg, _sidccofo_rating_count,
 *   _sidccofo_price_rules (quantity discounts, dated specials, group prices; see Price_Rules),
 *   _sidccofo_attributes ("Label: value" lines, "[Group]" lines start a group),
 *   _sidccofo_weight, _sidccofo_length, _sidccofo_width, _sidccofo_height (store units),
 *   _sidccofo_reward_points (points earned per unit; '' = the store's rate),
 *   _sidccofo_currency_prices ("EUR 18" lines: the regular price in another currency),
 *   _sidccofo_product_url, _sidccofo_button_text (external / affiliate product: bought elsewhere),
 *   _sidccofo_upsells, _sidccofo_cross_sells (product ids: "You may also like" on the
 *   product page, and suggestions in the cart),
 *   _sidccofo_tax_class (tax class slug, '' = standard),
 *   _sidccofo_choice_extra ("group|choice" => [ sku, image ]: a choice's own SKU and image),
 *   _sidccofo_variants (per-combination SKU, price, stock and image; see Variants).
 * Amounts are in the store currency; get_price() and the other shopper-facing
 * getters return them in the shopper's currency (see Currencies).
 * Derived meta kept in sync on save (for fast sorting / filtering):
 *   _sidccofo_price (active price for everyone, at quantity 1), _sidccofo_on_sale (0|1),
 *   _sidccofo_dated_rules (0|1: refreshed hourly while specials start and end).
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Data;

use SidcraftCommerce\Currencies;
use SidcraftCommerce\Multilingual;
use function SidcraftCommerce\format_price;
use function SidcraftCommerce\page_url;
use function SidcraftCommerce\round_amount;

defined( 'ABSPATH' ) || exit;

/**
 * Class Product
 */
class Product {

	/**
	 * Post.
	 *
	 * @var \WP_Post
	 */
	protected $post;

	/**
	 * Per-request instance cache.
	 *
	 * @var Product[]
	 */
	protected static $cache = array();

	/**
	 * Get a product by ID.
	 *
	 * @param int $id Post ID.
	 * @return Product|null
	 */
	public static function get( $id ) {
		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}
		// A translation is the original product, shown in the shopper's language.
		$id  = Multilingual::original_id( $id );
		$key = get_current_blog_id() . ':' . $id . '|' . Multilingual::current_language();
		if ( ! isset( self::$cache[ $key ] ) ) {
			$post = get_post( $id );
			if ( ! $post || Post_Types::PRODUCT !== $post->post_type ) {
				return null;
			}
			self::$cache[ $key ] = new static( $post );
		}
		return self::$cache[ $key ];
	}

	/**
	 * Drop a product from the per-request cache (after stock changes).
	 *
	 * @param int $id Post ID.
	 */
	public static function forget( $id ) {
		$id = absint( $id );
		foreach ( array_unique( array( $id, Multilingual::original_id( $id ) ) ) as $one ) {
			foreach ( array_keys( self::$cache ) as $key ) {
				if ( 0 === strpos( $key, get_current_blog_id() . ':' . $one . '|' ) ) {
					unset( self::$cache[ $key ] );
				}
			}
			clean_post_cache( $one );
		}
	}

	/**
	 * Constructor.
	 *
	 * @param \WP_Post $post Post (the original-language one when translated).
	 */
	public function __construct( \WP_Post $post ) {
		$this->post = $post;
	}

	/**
	 * The post shown to the shopper: the translation in the current
	 * language, else the product itself.
	 *
	 * @return \WP_Post
	 */
	public function get_display_post() {
		if ( null === $this->display ) {
			$this->display = $this->post;
			$id            = Multilingual::current_id( $this->post->ID );
			if ( $id !== (int) $this->post->ID ) {
				$tr = get_post( $id );
				if ( $tr && Post_Types::PRODUCT === $tr->post_type ) {
					$this->display = $tr;
				}
			}
		}
		return $this->display;
	}

	/**
	 * Translation shown to the shopper (see get_display_post()).
	 *
	 * @var \WP_Post|null
	 */
	protected $display = null;

	/**
	 * A text field from the translation, else from the product.
	 *
	 * @param string $key Meta key.
	 * @return string
	 */
	protected function display_meta( $key ) {
		$display = $this->get_display_post();
		if ( $display !== $this->post ) {
			$own = (string) get_post_meta( $display->ID, $key, true );
			if ( '' !== trim( $own ) ) {
				return $own;
			}
		}
		return (string) $this->meta( $key );
	}

	/**
	 * Read a meta value.
	 *
	 * @param string $key Meta key.
	 * @return mixed
	 */
	protected function meta( $key ) {
		return get_post_meta( $this->post->ID, $key, true );
	}

	/**
	 * Id.
	 *
	 * @return int
	 */
	public function get_id() {
		return (int) $this->post->ID;
	}

	/**
	 * Post.
	 *
	 * @return \WP_Post
	 */
	public function get_post() {
		return $this->post;
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name() {
		return get_the_title( $this->get_display_post() );
	}

	/**
	 * Permalink.
	 *
	 * @return string
	 */
	public function get_permalink() {
		return get_permalink( $this->get_display_post() );
	}

	/**
	 * Sku.
	 *
	 * @return string
	 */
	public function get_sku() {
		return (string) $this->meta( '_sidccofo_sku' );
	}

	/**
	 * Short description.
	 *
	 * @return string
	 */
	public function get_short_description() {
		$own = (string) $this->get_display_post()->post_excerpt;
		return '' !== $own ? $own : (string) $this->post->post_excerpt;
	}

	/**
	 * Whether published.
	 *
	 * @return bool
	 */
	public function is_published() {
		return 'publish' === $this->post->post_status;
	}

	/**
	 * Regular price or ''.
	 *
	 * @return string Regular price or ''.
	 */
	public function get_regular_price() {
		return (string) $this->meta( '_sidccofo_regular_price' );
	}

	/**
	 * Sale price or ''.
	 *
	 * @return string Sale price or ''.
	 */
	public function get_sale_price() {
		return (string) $this->meta( '_sidccofo_sale_price' );
	}

	/**
	 * A sale price is set and lower than the regular price.
	 *
	 * @return bool
	 */
	public function has_sale_price() {
		$sale = $this->get_sale_price();
		return '' !== $sale && (float) $sale < (float) $this->get_regular_price();
	}

	/**
	 * The price before price rules: the sale price when there is one, else
	 * the regular price.
	 *
	 * @return float
	 */
	public function get_base_price() {
		return (float) ( $this->has_sale_price() ? $this->get_sale_price() : $this->get_regular_price() );
	}

	/**
	 * Typed price rules.
	 *
	 * @return string
	 */
	public function get_price_rules_text() {
		return (string) $this->meta( '_sidccofo_price_rules' );
	}

	/**
	 * Parsed price rules (none for gift cards).
	 *
	 * @return array[]
	 */
	public function get_price_rules() {
		if ( null === $this->rules ) {
			$this->rules = $this->is_gift_card() ? array() : Price_Rules::parse( $this->get_price_rules_text() );
		}
		return $this->rules;
	}

	/**
	 * Parsed rules, memoised per instance.
	 *
	 * @var array[]|null
	 */
	protected $rules = null;

	/**
	 * Price after sale price and price rules, before the
	 * sidcraft_commerce_product_price filter.
	 *
	 * @param int           $qty    Units of this product in the cart.
	 * @param string[]|null $groups Customer groups (null = the current shopper, [] = everyone).
	 * @return array [ price, rule ] (rule: the winning price rule or null).
	 */
	public function get_rule_price( $qty = 1, $groups = null ) {
		$base  = $this->get_base_price();
		$rules = $this->get_price_rules();
		if ( ! $rules || '' === $this->get_regular_price() ) {
			return array(
				'price' => round_amount( $base ),
				'rule'  => null,
			);
		}
		return Price_Rules::evaluate( $rules, $base, $qty, $groups );
	}

	/**
	 * Cheaper than the regular price right now (sale price, special or
	 * group price), for this shopper at quantity 1.
	 *
	 * @return bool
	 */
	public function is_on_sale() {
		if ( '' === $this->get_regular_price() ) {
			return false;
		}
		return Currencies::in_base(
			function () {
				return $this->get_rule_price( 1 )['price'] < round_amount( (float) $this->get_regular_price() );
			}
		);
	}

	/**
	 * Quantity discount steps for this shopper: [ qty, price ] ascending.
	 *
	 * @return array[]
	 */
	public function get_quantity_tiers() {
		$rules = $this->get_price_rules();
		if ( ! $rules || '' === $this->get_regular_price() ) {
			return array();
		}
		$tiers = Currencies::in_base(
			function () use ( $rules ) {
				return Price_Rules::tiers( $rules, $this->get_base_price() );
			}
		);
		foreach ( $tiers as $i => $tier ) {
			$tiers[ $i ]['price'] = $this->to_shopper_price( (float) $tier['price'] );
		}
		return $tiers;
	}

	/**
	 * Regular prices typed for other currencies: code => amount.
	 *
	 * @return float[]
	 */
	public function get_currency_prices() {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $this->meta( '_sidccofo_currency_prices' ) ) as $line ) {
			if ( preg_match( '/^\s*([A-Za-z]{3})\s*[:=]?\s*([0-9]+(?:[.,][0-9]+)?)\s*$/', $line, $m ) ) {
				$out[ strtoupper( $m[1] ) ] = (float) str_replace( ',', '.', $m[2] );
			}
		}
		return $out;
	}

	/**
	 * A store-currency price of this product in the shopper's currency. With
	 * a regular price typed for that currency, sale prices, specials and
	 * quantity discounts take the same share off it; otherwise the amount is
	 * converted at the store's rate.
	 *
	 * @param float $amount Store-currency price.
	 * @return float
	 */
	public function to_shopper_price( $amount ) {
		$code = Currencies::active();
		if ( Currencies::base() === $code ) {
			return round_amount( $amount );
		}
		$fixed   = $this->get_currency_prices()[ $code ] ?? null;
		$regular = (float) $this->get_regular_price();
		if ( null !== $fixed && $regular > 0 ) {
			return round_amount( $fixed * ( (float) $amount / $regular ) );
		}
		return round_amount( Currencies::to_active( $amount ) );
	}

	/**
	 * The regular (crossed-out) price in the shopper's currency.
	 *
	 * @return float
	 */
	public function get_display_regular_price() {
		return $this->to_shopper_price( (float) $this->get_regular_price() );
	}

	/**
	 * Price a customer pays now.
	 *
	 * @param int $qty Units of this product in the cart (quantity discounts).
	 * @return float
	 */
	public function get_price( $qty = 1 ) {
		if ( $this->is_gift_card() ) {
			// Lowest amount: used for sorting and the "From" price.
			$amounts = $this->get_gift_card_amounts();
			return $amounts ? $amounts[0] : round_amount( $this->get_custom_amount_range()[0] );
		}
		$base = Currencies::in_base(
			function () use ( $qty ) {
				$price = $this->get_rule_price( $qty )['price'];

				/**
				 * Filter the active price (e.g. member discounts), in the store
				 * currency. Cart and checkout always use this, so a filter here
				 * applies everywhere.
				 *
				 * @param float   $price   Price.
				 * @param Product $product Product.
				 * @param int     $qty     Units of this product in the cart.
				 */
				return round_amount( apply_filters( 'sidcraft_commerce_product_price', (float) $price, $this, max( 1, (int) $qty ) ) );
			}
		);
		return $this->to_shopper_price( $base );
	}

	/*
	------------------------------------------------------------------
	 * Gift card products
	 * ----------------------------------------------------------------
	 */

	/**
	 * Is this a gift card product?
	 *
	 * @return bool
	 */
	public function is_gift_card() {
		return (bool) $this->meta( '_sidccofo_gift_card' );
	}

	/**
	 * Is this a subscription, billed every period (see Subscriptions)?
	 *
	 * @return bool
	 */
	public function is_subscription() {
		return ! $this->is_gift_card() && (bool) $this->meta( '_sidccofo_subscription' );
	}

	/**
	 * Virtual product (nothing to ship)?
	 *
	 * @return bool
	 */
	public function is_virtual() {
		return \SidcraftCommerce\Downloads\Downloads::is_virtual( $this->get_id() );
	}

	/**
	 * Downloadable product (with at least one file)?
	 *
	 * @return bool
	 */
	public function is_downloadable() {
		return \SidcraftCommerce\Downloads\Downloads::is_downloadable( $this->get_id() );
	}

	/**
	 * Downloadable files.
	 *
	 * @return array[] file id => [ id, name, file ].
	 */
	public function get_downloads() {
		return \SidcraftCommerce\Downloads\Downloads::files( $this->get_id() );
	}

	/**
	 * Item options the customer chooses when adding to the cart (size,
	 * extras, instructions). Gift cards have none.
	 *
	 * @return array[] Groups, see Product_Options::normalize().
	 */
	public function get_options() {
		if ( $this->is_gift_card() ) {
			return array();
		}
		$groups = Currencies::in_base(
			function () {
				return $this->options_in_base();
			}
		);
		if ( Currencies::base() !== Currencies::active() ) {
			foreach ( $groups as $g => $group ) {
				foreach ( $group['choices'] as $c => $choice ) {
					$groups[ $g ]['choices'][ $c ]['price'] = round_amount( Currencies::to_active( (float) $choice['price'] ) );
				}
			}
		}
		return $groups;
	}

	/**
	 * Item options with their extras in the store currency.
	 *
	 * @return array[]
	 */
	protected function options_in_base() {
		$groups = Product_Options::parse( (string) $this->meta( '_sidccofo_options' ) );

		/**
		 * Filter a product's item options. Add-ons can add groups in code:
		 *
		 *     add_filter( 'sidcraft_commerce_product_options', function ( $groups, $product ) {
		 *         $groups[] = array(
		 *             'label'    => 'Size',
		 *             'type'     => 'radio', // select, radio, checkbox or text.
		 *             'required' => true,
		 *             'choices'  => array(
		 *                 array( 'label' => 'Regular' ),
		 *                 array( 'label' => 'Large', 'price' => 3 ),
		 *             ),
		 *         );
		 *         return $groups;
		 *     }, 10, 2 );
		 *
		 * @param array[] $groups  Groups.
		 * @param Product $product Product.
		 */
		return $this->translate_option_labels( Product_Options::normalize( apply_filters( 'sidcraft_commerce_product_options', $groups, $this ) ) );
	}

	/**
	 * Labels from the translation's options, when it lists the same groups
	 * and choices in the same order (ids, prices and stock stay the
	 * original's, so carts and orders match in every language).
	 *
	 * @param array[] $groups Normalized original groups.
	 * @return array[]
	 */
	protected function translate_option_labels( array $groups ) {
		$display = $this->get_display_post();
		if ( $display === $this->post ) {
			return $groups;
		}
		$text = (string) get_post_meta( $display->ID, '_sidccofo_options', true );
		if ( '' === trim( $text ) ) {
			return $groups;
		}
		$tr = Product_Options::normalize( Product_Options::parse( $text ) );
		if ( count( $tr ) !== count( $groups ) ) {
			return $groups;
		}
		foreach ( $groups as $g => $group ) {
			$choices    = $group['choices'] ?? array();
			$tr_choices = $tr[ $g ]['choices'] ?? array();
			if ( count( $choices ) !== count( $tr_choices ) ) {
				return $groups;
			}
		}
		foreach ( $groups as $g => $group ) {
			$groups[ $g ]['label'] = $tr[ $g ]['label'];
			if ( isset( $tr[ $g ]['placeholder'] ) ) {
				$groups[ $g ]['placeholder'] = $tr[ $g ]['placeholder'];
			}
			foreach ( $group['choices'] ?? array() as $c => $choice ) {
				$groups[ $g ]['choices'][ $c ]['label'] = $tr[ $g ]['choices'][ $c ]['label'];
			}
		}
		return $groups;
	}

	/**
	 * Does the customer choose options before adding to the cart?
	 *
	 * @return bool
	 */
	public function has_options() {
		return (bool) $this->get_options();
	}

	/**
	 * Preset amounts, ascending.
	 *
	 * @return float[]
	 */
	public function get_gift_card_amounts() {
		$amounts = array();
		foreach ( $this->get_gift_card_amounts_base() as $base ) {
			$amounts[] = round_amount( Currencies::to_active( $base ) );
		}
		return $amounts;
	}

	/**
	 * Preset amounts in the store currency, ascending.
	 *
	 * @return float[]
	 */
	public function get_gift_card_amounts_base() {
		$dec     = \SidcraftCommerce\decimals_for( Currencies::base() );
		$amounts = array();
		foreach ( preg_split( '/[\s;,]+/', (string) $this->meta( '_sidccofo_gc_amounts' ) ) as $part ) {
			if ( is_numeric( $part ) && (float) $part > 0 ) {
				$amounts[] = round( (float) $part, $dec );
			}
		}
		$amounts = array_values( array_unique( $amounts ) );
		sort( $amounts );
		return $amounts;
	}

	/**
	 * Buyers may type their own amount.
	 *
	 * @return bool
	 */
	public function allows_custom_amount() {
		return (bool) $this->meta( '_sidccofo_gc_custom' );
	}

	/**
	 * Custom amount limits: [ min, max ] (max 0 = no limit).
	 *
	 * @return float[]
	 */
	public function get_custom_amount_range() {
		list( $min, $max ) = $this->get_custom_amount_range_base();
		return array( Currencies::to_active( $min ), $max > 0 ? Currencies::to_active( $max ) : 0.0 );
	}

	/**
	 * Custom amount limits in the store currency: [ min, max ] (max 0 = no limit).
	 *
	 * @return float[]
	 */
	public function get_custom_amount_range_base() {
		$min = (float) $this->meta( '_sidccofo_gc_min' );
		$max = (float) $this->meta( '_sidccofo_gc_max' );
		return array( $min > 0 ? $min : 1.0, $max > 0 ? $max : 0.0 );
	}

	/**
	 * Is this store-currency amount one the gift card can be bought for?
	 *
	 * @param float $amount Store-currency amount.
	 * @return bool
	 */
	public function accepts_gift_card_amount_base( $amount ) {
		$amount = (float) $amount;
		if ( $amount <= 0 ) {
			return false;
		}
		foreach ( $this->get_gift_card_amounts_base() as $preset ) {
			if ( abs( $preset - $amount ) < 0.005 ) {
				return true;
			}
		}
		if ( ! $this->allows_custom_amount() ) {
			return false;
		}
		list( $min, $max ) = $this->get_custom_amount_range_base();
		return $amount + 0.005 >= $min && ( ! $max || $amount - 0.005 <= $max );
	}

	/**
	 * The store-currency value of a gift card amount chosen in the shopper's
	 * currency: a preset maps to its exact store amount, a custom amount is
	 * rounded and kept within the limits, so it never drifts out of them.
	 *
	 * @param float $amount Amount in the shopper's currency.
	 * @return float
	 */
	public function gift_card_amount_to_base( $amount ) {
		$amount = round_amount( $amount );
		$base   = $this->get_gift_card_amounts_base();
		$index  = array_search( $amount, $this->get_gift_card_amounts(), true );
		if ( false !== $index && isset( $base[ $index ] ) ) {
			return $base[ $index ];
		}
		$value             = \SidcraftCommerce\round_base( Currencies::to_base( $amount ) );
		list( $min, $max ) = $this->get_custom_amount_range_base();
		$value             = max( $min, $value );
		return $max > 0 ? min( $max, $value ) : $value;
	}

	/**
	 * Is this amount allowed for this gift card product?
	 *
	 * @param float $amount Amount.
	 * @return bool
	 */
	public function accepts_gift_card_amount( $amount ) {
		$amount = round_amount( $amount );
		if ( $amount <= 0 ) {
			return false;
		}
		if ( in_array( $amount, $this->get_gift_card_amounts(), true ) ) {
			return true;
		}
		if ( ! $this->allows_custom_amount() ) {
			return false;
		}
		list( $min, $max ) = $this->get_custom_amount_range();
		return $amount >= $min && ( ! $max || $amount <= $max );
	}

	/**
	 * A product can be bought when published and a price is set (gift cards:
	 * when there is at least one amount to choose).
	 *
	 * @return bool
	 */
	public function is_purchasable() {
		if ( $this->is_external() ) {
			return (bool) apply_filters( 'sidcraft_commerce_is_purchasable', false, $this ); // Bought on another site.
		}
		$priced = $this->is_gift_card() ? ( $this->get_gift_card_amounts() || $this->allows_custom_amount() ) : '' !== $this->get_regular_price();
		$ok     = $this->is_published() && $priced;
		return (bool) apply_filters( 'sidcraft_commerce_is_purchasable', $ok, $this );
	}

	/**
	 * Managing stock.
	 *
	 * @return bool
	 */
	public function managing_stock() {
		return (bool) $this->meta( '_sidccofo_manage_stock' );
	}

	/**
	 * Units in stock, or null when stock isn't tracked.
	 *
	 * @return int|null Units in stock, or null when stock isn't tracked.
	 */
	public function get_stock_quantity() {
		return $this->managing_stock() ? (int) $this->meta( '_sidccofo_stock' ) : null;
	}

	/**
	 * Whether in stock.
	 *
	 * @return bool
	 */
	public function is_in_stock() {
		if ( $this->managing_stock() ) {
			$in = $this->get_stock_quantity() > 0;
		} else {
			$in = 'outofstock' !== $this->meta( '_sidccofo_stock_status' );
		}
		// A required choice whose every option is sold out stops the sale too.
		if ( $in && $this->has_choice_stock() && Choice_Stock::blocks_purchase( $this->get_id(), $this->get_options() ) ) {
			$in = false;
		}
		// Every combination sold out or not sold.
		if ( $in && Variants::none_available( $this ) ) {
			$in = false;
		}
		return $in;
	}

	/**
	 * Does any option choice have its own stock count?
	 *
	 * @return bool
	 */
	public function has_choice_stock() {
		foreach ( array_keys( (array) get_post_meta( $this->get_id() ) ) as $key ) {
			if ( 0 === strpos( (string) $key, '_sidccofo_cstock_' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Units left of a choice, or null when it isn't tracked.
	 *
	 * @param string $group  Group id.
	 * @param string $choice Choice id.
	 * @return int|null
	 */
	public function get_choice_stock( $group, $choice ) {
		return Choice_Stock::get( $this->get_id(), $group, $choice );
	}

	/**
	 * Most units one order may contain (-1 = unlimited).
	 *
	 * @return int
	 */
	public function get_max_purchase_quantity() {
		return $this->managing_stock() ? max( 0, (int) $this->get_stock_quantity() ) : -1;
	}

	/**
	 * Average rating.
	 *
	 * @return float
	 */
	public function get_average_rating() {
		return (float) $this->meta( '_sidccofo_rating_avg' );
	}

	/**
	 * Review count.
	 *
	 * @return int
	 */
	public function get_review_count() {
		return (int) $this->meta( '_sidccofo_rating_count' );
	}

	/**
	 * Price HTML. On sale, the sale price sits on top and the original price
	 * is struck through underneath it:
	 *   <span class="sc-price-stack"><ins>$99.99</ins><del>$129.99</del></span>
	 *
	 * @return string Escaped HTML.
	 */
	public function get_price_html() {
		if ( $this->is_gift_card() ) {
			if ( ! $this->is_purchasable() ) {
				return '';
			}
			/* translators: %s: lowest amount. */
			return (string) apply_filters( 'sidcraft_commerce_price_html', esc_html( sprintf( __( 'From %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), format_price( $this->get_price() ) ) ), $this );
		}
		if ( '' === $this->get_regular_price() ) {
			return '';
		}
		$range = Variants::price_range( $this );
		if ( $range ) {
			/* translators: 1: lowest price, 2: highest price. */
			$html = esc_html( sprintf( __( '%1$s – %2$s', 'sidcraft-commerce-for-sidcraft-page-builder' ), format_price( $range[0] ), format_price( $range[1] ) ) );
		} elseif ( $this->is_on_sale() ) {
			$html = sprintf(
				'<span class="sc-price-stack"><ins class="sc-price__sale">%2$s</ins><del class="sc-price__regular" aria-hidden="true">%1$s</del></span><span class="sc-sr-only screen-reader-text">%3$s</span>',
				esc_html( format_price( $this->get_display_regular_price() ) ),
				esc_html( format_price( $this->get_price() ) ),
				/* translators: 1: original price, 2: sale price. */
				esc_html( sprintf( __( 'Original price %1$s, now %2$s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), format_price( $this->get_display_regular_price() ), format_price( $this->get_price() ) ) )
			);
		} else {
			$html = esc_html( format_price( $this->get_price() ) );
		}
		return (string) apply_filters( 'sidcraft_commerce_price_html', $html, $this );
	}

	/**
	 * Badges an admin can pin to a product: key => label.
	 *
	 * @return array
	 */
	public static function badge_options() {
		/**
		 * Filter the badges offered on the product screen. Keys are used in the
		 * CSS class sc-badge--{key}.
		 *
		 * @param array $options key => label.
		 */
		return (array) apply_filters(
			'sidcraft_commerce_badge_options',
			array(
				'hot'       => _x( 'HOT', 'product badge', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'popular'   => _x( 'POPULAR', 'product badge', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'sale'      => _x( 'Sale', 'product badge', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'clearance' => _x( 'Clearance', 'product badge', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			)
		);
	}

	/**
	 * Badge chosen by the admin ('' = none).
	 *
	 * @return string
	 */
	public function get_badge() {
		$badge = (string) $this->meta( '_sidccofo_badge' );
		return array_key_exists( $badge, self::badge_options() ) ? $badge : '';
	}

	/**
	 * Badges for the top-right corner of the product image: class => label.
	 * The admin's badge wins; otherwise a product on sale gets "Sale".
	 * Sold-out is shown separately (see get_image_overlay_html()).
	 *
	 * @return array
	 */
	public function get_image_badges() {
		$badges  = array();
		$options = self::badge_options();
		$chosen  = $this->get_badge();
		if ( '' !== $chosen ) {
			$badges[ $chosen ] = $options[ $chosen ];
		} elseif ( $this->is_on_sale() ) {
			$badges['sale'] = $options['sale'] ?? __( 'Sale', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}

		/**
		 * Filter the corner badges on a product image. Return [ 'class' => 'Label' ].
		 *
		 * @param array   $badges  Badges.
		 * @param Product $product Product.
		 */
		return (array) apply_filters( 'sidcraft_commerce_image_badges', $badges, $this );
	}

	/**
	 * Badge + "Sold Out" markup to place inside a position:relative image box.
	 *
	 * @return string Escaped HTML.
	 */
	public function get_image_overlay_html() {
		$html   = '';
		$badges = $this->get_image_badges();
		if ( $badges ) {
			$html .= '<span class="sc-card__badges">';
			foreach ( $badges as $class => $label ) {
				$html .= '<span class="sc-badge sc-badge--' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
			}
			$html .= '</span>';
		}
		if ( ! $this->is_in_stock() ) {
			$html .= '<span class="sc-soldout"><span class="sc-soldout__label">' . esc_html__( 'Sold Out', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</span></span>';
		}
		return $html;
	}

	/**
	 * Featured image HTML or a neutral placeholder.
	 *
	 * @param string $size  Image size.
	 * @param array  $attrs Attributes.
	 * @return string
	 */
	public function get_image( $size = 'medium_large', array $attrs = array() ) {
		foreach ( array( $this->get_display_post(), $this->post ) as $post ) {
			if ( has_post_thumbnail( $post ) ) {
				return get_the_post_thumbnail( $post, $size, $attrs );
			}
		}
		return '<span class="sc-placeholder" aria-hidden="true"></span>';
	}

	/**
	 * Brands (terms of sidccofo_brand).
	 *
	 * @return \WP_Term[]
	 */
	public function get_brands() {
		$terms = get_the_terms( $this->post, Post_Types::BRAND );
		return ( $terms && ! is_wp_error( $terms ) ) ? $terms : array();
	}

	/**
	 * Attributes: rows of group, label, value. Typed as "Label: value" lines;
	 * a "[Group]" line starts a group for the lines below it.
	 *
	 * @return array[]
	 */
	public function get_attributes() {
		$rows  = array();
		$group = '';
		foreach ( preg_split( '/\R/', $this->display_meta( '_sidccofo_attributes' ) ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^\[(.+)\]$/', $line, $m ) ) {
				$group = trim( $m[1] );
				continue;
			}
			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				continue;
			}
			$label = trim( substr( $line, 0, $colon ) );
			$value = trim( substr( $line, $colon + 1 ) );
			if ( '' !== $label && '' !== $value ) {
				$rows[] = array(
					'group' => $group,
					'label' => $label,
					'value' => $value,
				);
			}
		}
		return (array) apply_filters( 'sidcraft_commerce_product_attributes', $rows, $this );
	}

	/**
	 * Weight in the store's unit, or 0 when not set.
	 *
	 * @return float
	 */
	public function get_weight() {
		return max( 0.0, (float) $this->meta( '_sidccofo_weight' ) );
	}

	/**
	 * Length, width and height in the store's unit (0 when not set).
	 *
	 * @return float[] [ length, width, height ].
	 */
	public function get_dimensions() {
		return array(
			max( 0.0, (float) $this->meta( '_sidccofo_length' ) ),
			max( 0.0, (float) $this->meta( '_sidccofo_width' ) ),
			max( 0.0, (float) $this->meta( '_sidccofo_height' ) ),
		);
	}

	/**
	 * Specification rows for the product page: brand, attributes, weight,
	 * dimensions. Each: group, label, value, url (brand link or '').
	 *
	 * @return array[]
	 */
	public function get_specs() {
		$rows   = array();
		$brands = $this->get_brands();
		if ( $brands ) {
			$links = array();
			foreach ( $brands as $brand ) {
				$link    = get_term_link( $brand );
				$links[] = array(
					'name' => $brand->name,
					'url'  => is_wp_error( $link ) ? '' : (string) $link,
				);
			}
			$rows[] = array(
				'group'  => '',
				'label'  => _n( 'Brand', 'Brands', count( $brands ), 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'value'  => implode( ', ', wp_list_pluck( $links, 'name' ) ),
				'brands' => $links,
			);
		}
		foreach ( $this->get_attributes() as $row ) {
			$rows[] = $row;
		}
		if ( $this->get_weight() > 0 ) {
			$rows[] = array(
				'group' => '',
				'label' => __( 'Weight', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'value' => \SidcraftCommerce\format_measure( $this->get_weight(), (string) \SidcraftCommerce\setting( 'weight_unit', 'kg' ) ),
			);
		}
		$dims = array_filter( $this->get_dimensions() );
		if ( $dims ) {
			$unit   = (string) \SidcraftCommerce\setting( 'dimension_unit', 'cm' );
			$rows[] = array(
				'group' => '',
				/* translators: Length × width × height. */
				'label' => __( 'Dimensions', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'value' => implode( ' × ', array_map( array( \SidcraftCommerce\Catalog::class, 'number' ), $this->get_dimensions() ) ) . ' ' . $unit,
			);
		}
		$tags = $this->get_tags();
		if ( $tags ) {
			$links = array();
			foreach ( $tags as $tag ) {
				$link    = get_term_link( $tag );
				$links[] = array(
					'name' => $tag->name,
					'url'  => is_wp_error( $link ) ? '' : (string) $link,
				);
			}
			$rows[] = array(
				'group'  => '',
				'label'  => _n( 'Tag', 'Tags', count( $tags ), 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'value'  => implode( ', ', wp_list_pluck( $links, 'name' ) ),
				'brands' => $links, // Linked names, shown like brands.
			);
		}
		return (array) apply_filters( 'sidcraft_commerce_product_specs', $rows, $this );
	}

	/**
	 * First category name.
	 *
	 * @return string
	 */
	public function get_category_name() {
		$terms = get_the_terms( $this->post, Post_Types::CATEGORY );
		return ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : '';
	}

	/**
	 * Button text for this product's state.
	 *
	 * @return string
	 */
	public function add_to_cart_text() {
		if ( $this->is_external() ) {
			return $this->get_button_text();
		}
		if ( ! $this->is_purchasable() ) {
			return __( 'View product', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		if ( $this->is_gift_card() ) {
			return __( 'Choose amount', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		if ( $this->is_in_stock() && $this->has_options() ) {
			return __( 'Choose options', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		return $this->is_in_stock() ? __( 'Add to cart', 'sidcraft-commerce-for-sidcraft-page-builder' ) : __( 'Out of stock', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/**
	 * Can be added with one click (used to decide button vs link).
	 *
	 * @return bool
	 */
	public function can_add_to_cart() {
		// Gift cards need an amount and a recipient, and products with options
		// need choices: both are bought from the form on the product page.
		return $this->is_purchasable() && $this->is_in_stock() && ! $this->is_gift_card() && ! $this->has_options();
	}

	/**
	 * Change stock by a delta (negative = sell) and keep derived meta in sync.
	 *
	 * A sale only succeeds when the stored quantity is still at least the
	 * amount being taken, so two checkouts cannot both take the last unit.
	 *
	 * @param int $delta Units.
	 * @return bool False when a sale would take more than the remaining stock.
	 */
	public function adjust_stock( $delta ) {
		if ( ! $this->managing_stock() ) {
			return true;
		}
		$delta = (int) $delta;
		if ( 0 === $delta ) {
			return true;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Conditional stock update; update_post_meta cannot express "only if enough remains".
		if ( $delta < 0 ) {
			$need    = abs( $delta );
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS SIGNED) - %d WHERE post_id = %d AND meta_key = %s AND CAST(meta_value AS SIGNED) >= %d",
					$need,
					$this->get_id(),
					'_sidccofo_stock',
					$need
				)
			);
			if ( ! $updated ) {
				return false;
			}
		} else {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS SIGNED) + %d WHERE post_id = %d AND meta_key = %s",
					$delta,
					$this->get_id(),
					'_sidccofo_stock'
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		clean_post_cache( $this->get_id() );
		$new = (int) get_post_meta( $this->get_id(), '_sidccofo_stock', true );
		self::sync_derived_meta( $this->get_id() );
		self::forget( $this->get_id() );
		do_action( 'sidcraft_commerce_stock_changed', $this->get_id(), $new, $delta );
		return true;
	}

	/**
	 * Record units sold.
	 *
	 * @param int $qty Units.
	 */
	public function record_sale( $qty ) {
		update_post_meta( $this->get_id(), '_sidccofo_total_sales', (int) $this->meta( '_sidccofo_total_sales' ) + (int) $qty );
		self::copy_to_translations( $this->get_id(), array( '_sidccofo_total_sales' ) );
	}

	/**
	 * Copy derived values to a product's translations (best-selling and
	 * top-rated grids in every language).
	 *
	 * @param int      $id   Original product.
	 * @param string[] $keys Meta keys.
	 */
	public static function copy_to_translations( $id, array $keys ) {
		foreach ( Multilingual::translations( (int) $id ) as $tr ) {
			if ( (int) $tr !== (int) $id ) {
				foreach ( $keys as $key ) {
					update_post_meta( (int) $tr, $key, get_post_meta( (int) $id, $key, true ) );
				}
			}
		}
	}

	/**
	 * Recompute _sidccofo_price, _sidccofo_on_sale and _sidccofo_stock_status, then bump the
	 * product query cache version so cached grids refresh.
	 *
	 * @param int $id Product ID.
	 */
	public static function sync_derived_meta( $id ) {
		Currencies::in_base(
			static function () use ( $id ) {
				self::sync_derived_meta_in_base( $id );
			}
		);
	}

	/**
	 * sync_derived_meta() in the store currency.
	 *
	 * @param int $id Product ID.
	 */
	protected static function sync_derived_meta_in_base( $id ) {
		$id = Multilingual::original_id( $id ); // The original holds the store data.
		clean_post_cache( $id );
		$product = new static( get_post( $id ) );

		// Stored for sorting and filters: the price everyone pays at quantity 1
		// (group prices depend on who is looking, so they are left out).
		$plain = $product->get_rule_price( 1, array() )['price'];
		if ( $product->is_gift_card() ) {
			$stored  = $product->get_price();
			$on_sale = $product->has_sale_price();
		} else {
			/** This filter is documented in get_price(). */
			$stored  = round_amount( apply_filters( 'sidcraft_commerce_product_price', (float) $plain, $product, 1 ) );
			$on_sale = '' !== $product->get_regular_price() && $plain < round_amount( (float) $product->get_regular_price() );
		}
		update_post_meta( $id, '_sidccofo_price', '' === $product->get_regular_price() ? '' : (string) $stored );
		update_post_meta( $id, '_sidccofo_on_sale', $on_sale ? 1 : 0 );
		update_post_meta( $id, '_sidccofo_dated_rules', Price_Rules::has_dates( $product->get_price_rules() ) ? 1 : 0 );
		if ( $product->managing_stock() ) {
			update_post_meta( $id, '_sidccofo_stock_status', $product->get_stock_quantity() > 0 ? 'instock' : 'outofstock' );
		} elseif ( '' === $product->meta( '_sidccofo_stock_status' ) ) {
			update_post_meta( $id, '_sidccofo_stock_status', 'instock' );
		}
		foreach ( array( '_sidccofo_total_sales', '_sidccofo_rating_avg', '_sidccofo_rating_count' ) as $key ) {
			if ( '' === get_post_meta( $id, $key, true ) ) {
				update_post_meta( $id, $key, 0 );
			}
		}

		// Translations sort and filter in their own language's grids: give
		// them the original's values.
		self::copy_to_translations( $id, array( '_sidccofo_price', '_sidccofo_on_sale', '_sidccofo_dated_rules', '_sidccofo_stock_status', '_sidccofo_total_sales', '_sidccofo_rating_avg', '_sidccofo_rating_count', '_sidccofo_featured' ) );

		self::bump_query_version();
	}

	/**
	 * Invalidate every cached product query.
	 */
	public static function bump_query_version() {
		update_option( 'sidcraft_commerce_query_version', (string) microtime( true ), false );
	}

	/**
	 * Current product query cache version.
	 *
	 * @return string
	 */
	public static function query_version() {
		return (string) get_option( 'sidcraft_commerce_query_version', '1' );
	}

	/*
	------------------------------------------------------------------
	 * External / affiliate products
	 * ----------------------------------------------------------------
	 */

	/**
	 * Where an external product is bought ('' = sold here).
	 *
	 * @return string
	 */
	public function get_external_url() {
		return esc_url_raw( (string) $this->meta( '_sidccofo_product_url' ) );
	}

	/**
	 * Sold on another site (affiliate link)?
	 *
	 * @return bool
	 */
	public function is_external() {
		return '' !== $this->get_external_url();
	}

	/**
	 * Button text of an external product.
	 *
	 * @return string
	 */
	public function get_button_text() {
		$text = trim( $this->display_meta( '_sidccofo_button_text' ) );
		return '' !== $text ? $text : __( 'Buy product', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/*
	------------------------------------------------------------------
	 * Upsells, cross-sells, tags and tax class
	 * ----------------------------------------------------------------
	 */

	/**
	 * Published products from an id list meta.
	 *
	 * @param string $key Meta key.
	 * @return int[]
	 */
	protected function linked_ids( $key ) {
		$out = array();
		foreach ( array_map( 'absint', (array) $this->meta( $key ) ) as $id ) {
			if ( $id && $id !== $this->get_id() && 'publish' === get_post_status( $id ) && Post_Types::PRODUCT === get_post_type( $id ) ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Products suggested on this product's page ("You may also like").
	 *
	 * @return int[]
	 */
	public function get_upsell_ids() {
		return $this->linked_ids( '_sidccofo_upsells' );
	}

	/**
	 * Products suggested in the cart when this product is in it.
	 *
	 * @return int[]
	 */
	public function get_cross_sell_ids() {
		return $this->linked_ids( '_sidccofo_cross_sells' );
	}

	/**
	 * Tags (terms of sidccofo_product_tag).
	 *
	 * @return \WP_Term[]
	 */
	public function get_tags() {
		$terms = get_the_terms( $this->post, Post_Types::TAG );
		return ( $terms && ! is_wp_error( $terms ) ) ? $terms : array();
	}

	/**
	 * Tax class slug ('' = standard rate).
	 *
	 * @return string
	 */
	public function get_tax_class() {
		$class = sanitize_title( (string) $this->meta( '_sidccofo_tax_class' ) );
		return isset( \SidcraftCommerce\Tax::classes()[ $class ] ) ? $class : '';
	}

	/*
	------------------------------------------------------------------
	 * A choice's own SKU and image (like a variation's)
	 * ----------------------------------------------------------------
	 */

	/**
	 * SKU and image of one choice.
	 *
	 * @param string $group  Group id.
	 * @param string $choice Choice id.
	 * @return array [ sku => string, image => int ].
	 */
	public function get_choice_extra( $group, $choice ) {
		$all = (array) $this->meta( '_sidccofo_choice_extra' );
		$row = (array) ( $all[ $group . '|' . $choice ] ?? array() );
		return array(
			'sku'   => (string) ( $row['sku'] ?? '' ),
			'image' => (int) ( $row['image'] ?? 0 ),
		);
	}

	/**
	 * Choices of a selection, in option order: [ [ group id, choice id ], … ].
	 *
	 * @param array $selection Group id => choice id (or ids for checkboxes).
	 * @return array[]
	 */
	protected function picked_choices( array $selection ) {
		$out = array();
		foreach ( $this->get_options() as $group ) {
			if ( 'text' === $group['type'] || ! isset( $selection[ $group['id'] ] ) ) {
				continue;
			}
			foreach ( (array) $selection[ $group['id'] ] as $cid ) {
				$out[] = array( $group['id'], (string) $cid );
			}
		}
		return $out;
	}

	/**
	 * SKU for chosen options: the chosen choices' own SKUs (joined with
	 * " / " when several have one), else the product's SKU.
	 *
	 * @param array $selection Group id => choice id(s).
	 * @return string
	 */
	public function sku_for_selection( array $selection ) {
		$combo = Variants::key_for( $this->get_options(), $selection );
		if ( '' !== $combo && '' !== Variants::row( $this->get_id(), $combo )['sku'] ) {
			return Variants::row( $this->get_id(), $combo )['sku']; // The combination's own SKU.
		}
		$skus = array();
		foreach ( $this->picked_choices( $selection ) as list( $group, $choice ) ) {
			$sku = $this->get_choice_extra( $group, $choice )['sku'];
			if ( '' !== $sku ) {
				$skus[] = $sku;
			}
		}
		return $skus ? implode( ' / ', $skus ) : $this->get_sku();
	}

	/**
	 * Image for chosen options: the last chosen choice with its own image,
	 * else 0 (the product image).
	 *
	 * @param array $selection Group id => choice id(s).
	 * @return int Attachment id.
	 */
	public function image_for_selection( array $selection ) {
		$combo = Variants::key_for( $this->get_options(), $selection );
		if ( '' !== $combo && Variants::row( $this->get_id(), $combo )['image'] ) {
			return Variants::row( $this->get_id(), $combo )['image']; // The combination's own image.
		}
		$image = 0;
		foreach ( $this->picked_choices( $selection ) as list( $group, $choice ) ) {
			$own   = $this->get_choice_extra( $group, $choice )['image'];
			$image = $own ? $own : $image;
		}
		return $image;
	}

	/**
	 * Link that adds this product without JavaScript.
	 *
	 * @return string
	 */
	public function add_to_cart_url() {
		if ( $this->is_external() ) {
			return $this->get_external_url();
		}
		return $this->can_add_to_cart() ? add_query_arg( 'sidccofo-add-to-cart', $this->get_id(), page_url( 'cart' ) ) : $this->get_permalink();
	}
}
