<?php
/**
 * The cart.
 *
 * Only product IDs and quantities are stored. Names, prices and stock are
 * read fresh from the product on every request, so a price change or a
 * tampered request can never change what a customer is charged.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Cart;

use SidcraftCommerce\Data\Coupon;
use SidcraftCommerce\Data\Gift_Card;
use SidcraftCommerce\Data\Product;
use SidcraftCommerce\Data\Product_Options;
use SidcraftCommerce\Locations;
use SidcraftCommerce\Shipping;
use SidcraftCommerce\Tax;
use function SidcraftCommerce\round_amount;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Cart
 */
class Cart {

	/**
	 * Session.
	 *
	 * @var Session
	 */
	protected $session;

	/**
	 * Singleton.
	 *
	 * @var Cart|null
	 */
	protected static $instance = null;

	/**
	 * Get the cart for this request.
	 *
	 * @return Cart
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self( new Session() );
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @param Session $session Session.
	 */
	public function __construct( Session $session ) {
		$this->session = $session;
	}

	/**
	 * Session accessor.
	 *
	 * @return Session
	 */
	public function session() {
		return $this->session;
	}

	/**
	 * Raw stored items: product_id => quantity.
	 *
	 * @return array<int,int>
	 */
	protected function raw() {
		return array_map( 'intval', (array) $this->session->get( 'cart', array() ) );
	}

	/**
	 * Save items and refresh the JS-readable cookies.
	 *
	 * @param array $items product_id => quantity.
	 */
	protected function store( array $items ) {
		$items = array_filter( $items );
		$this->session->set( 'cart', $items );
		$this->changed();
	}

	/**
	 * After any change: drop cached totals, refresh the JS-readable cookies.
	 */
	protected function changed() {
		$this->totals = null;
		$has          = $this->raw() || $this->gift_raw() || $this->option_raw();
		$this->session->set_cookie( \SidcraftCommerce\cookie_name( 'sidccofo_items_in_cart' ), $has ? '1' : '' );
		$this->session->set_cookie( \SidcraftCommerce\cookie_name( 'sidccofo_cart_hash' ), $has ? $this->get_hash() : '' );
		do_action( 'sidcraft_commerce_cart_updated', $this );
	}

	/*
	------------------------------------------------------------------
	 * Gift card lines: each has its own amount and recipient, so they are
	 * stored separately from the product_id => quantity list.
	 * ----------------------------------------------------------------
	 */

	/**
	 * Stored gift card lines: key => [ product_id, amount, quantity, recipient_name,
	 * recipient_email, sender, message, send_date ].
	 *
	 * @return array
	 */
	protected function gift_raw() {
		$lines = $this->session->get( 'gift_lines', array() );
		return is_array( $lines ) ? $lines : array();
	}

	/**
	 * Add a gift card to the cart.
	 *
	 * @param int   $product_id Gift card product.
	 * @param array $data       amount, quantity, recipient_name, recipient_email, sender, message, send_date.
	 * @return true|\WP_Error  Errors carry data.fields.
	 */
	public function add_gift_card( $product_id, array $data ) {
		$product_id = \SidcraftCommerce\Multilingual::original_id( absint( $product_id ) ); // Translations share the original's cart line.
		$product = Product::get( $product_id );
		if ( ! $product || ! $product->is_gift_card() || ! $product->is_purchasable() ) {
			return new \WP_Error( 'sidccofo_unavailable', __( 'This product is not available.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		$amount = round_amount( str_replace( ',', '.', (string) ( $data['amount'] ?? '' ) ) );
		$line   = array(
			'product_id'      => $product->get_id(),
			// The card's value, in the store currency (cards are kept in it).
			'amount'          => $product->gift_card_amount_to_base( $amount ),
			'quantity'        => max( 1, min( 99, absint( $data['quantity'] ?? 1 ) ) ),
			'recipient_name'  => mb_substr( sanitize_text_field( (string) ( $data['recipient_name'] ?? '' ) ), 0, 80 ),
			'recipient_email' => sanitize_email( (string) ( $data['recipient_email'] ?? '' ) ),
			'sender'          => mb_substr( sanitize_text_field( (string) ( $data['sender'] ?? '' ) ), 0, 80 ),
			'message'         => mb_substr( sanitize_textarea_field( (string) ( $data['message'] ?? '' ) ), 0, 500 ),
			'send_date'       => (string) ( $data['send_date'] ?? '' ),
		);
		$errors = array();
		if ( ! $product->accepts_gift_card_amount( $amount ) ) {
			$errors['amount'] = __( 'Choose one of the amounts, or enter an amount within the limits.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		if ( ! is_email( $line['recipient_email'] ) ) {
			$errors['recipient_email'] = __( 'Enter the recipient’s email address.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		if ( '' !== $line['send_date'] && ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $line['send_date'] ) || $line['send_date'] < wp_date( 'Y-m-d' ) ) ) {
			$errors['send_date'] = __( 'Choose today or a future date, or leave it empty to send right away.', 'sidcraft-commerce-for-sidcraft-page-builder' );
		}
		if ( $errors ) {
			return new \WP_Error(
				'sidccofo_giftcard_invalid',
				reset( $errors ),
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}
		$lines                                = $this->gift_raw();
		$lines[ wp_generate_password( 8, false, false ) ] = $line;
		$this->session->set( 'gift_lines', $lines );
		$this->changed();
		do_action( 'sidcraft_commerce_added_gift_card_to_cart', $line, $this );
		return true;
	}

	/**
	 * Change a gift card line's quantity (0 removes it).
	 *
	 * @param string $key      Line key (without the "gc_" prefix).
	 * @param int    $quantity Quantity.
	 */
	public function set_gift_quantity( $key, $quantity ) {
		$lines = $this->gift_raw();
		if ( ! isset( $lines[ $key ] ) ) {
			return;
		}
		$quantity = max( 0, min( 99, (int) $quantity ) );
		if ( $quantity ) {
			$lines[ $key ]['quantity'] = $quantity;
		} else {
			unset( $lines[ $key ] );
		}
		$this->session->set( 'gift_lines', $lines );
		$this->changed();
	}

	/*
	------------------------------------------------------------------
	 * Lines with item options (size, extras, instructions). The same product
	 * with different choices is a separate line; the same choices again
	 * add to that line's quantity.
	 * ----------------------------------------------------------------
	 */

	/**
	 * Stored option lines: key => [ product_id, quantity, options ].
	 *
	 * @return array
	 */
	protected function option_raw() {
		$lines = $this->session->get( 'option_lines', array() );
		return is_array( $lines ) ? $lines : array();
	}

	/**
	 * Add a product with the customer's option choices.
	 *
	 * @param int   $product_id Product.
	 * @param int   $quantity   Units.
	 * @param array $selection  group id => choice id | choice ids | text (raw).
	 * @return true|\WP_Error  Errors carry data.fields (group id => message).
	 */
	public function add_with_options( $product_id, $quantity, array $selection ) {
		$product_id = \SidcraftCommerce\Multilingual::original_id( absint( $product_id ) ); // Translations share the original's cart line.
		$product  = Product::get( $product_id );
		$quantity = max( 1, (int) $quantity );
		if ( ! $product || ! $product->is_purchasable() ) {
			return new \WP_Error( 'sidccofo_unavailable', __( 'This product is not available.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		$resolved = Product_Options::resolve( $product->get_options(), Product_Options::from_request( $selection ) );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$check = $this->validate( $product_id, $this->product_quantity( $product_id ) + $quantity );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$check = $this->validate_choices( $product_id, $resolved['selection'], $quantity );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		// No choices made (all optional): an ordinary line.
		if ( ! $resolved['selection'] ) {
			$items                = $this->raw();
			$items[ $product_id ] = ( $items[ $product_id ] ?? 0 ) + $quantity;
			$this->store( $items );
		} else {
			$lines = $this->option_raw();
			$key   = substr( md5( $product_id . '|' . wp_json_encode( $resolved['selection'] ) ), 0, 12 );
			if ( isset( $lines[ $key ] ) ) {
				$lines[ $key ]['quantity'] = min( 999, (int) $lines[ $key ]['quantity'] + $quantity );
			} else {
				$lines[ $key ] = array(
					'product_id' => (int) $product_id,
					'quantity'   => $quantity,
					'options'    => $resolved['selection'],
				);
			}
			$this->session->set( 'option_lines', $lines );
			$this->changed();
		}

		/**
		 * Fires after a product (with or without options) was added.
		 *
		 * @param int   $product_id Product.
		 * @param int   $quantity   Units added.
		 * @param Cart  $cart       Cart.
		 * @param array $selection  Clean option choices (empty for none).
		 */
		do_action( 'sidcraft_commerce_added_to_cart', $product_id, $quantity, $this, $resolved['selection'] );
		return true;
	}

	/**
	 * Change an option line's quantity (0 removes it).
	 *
	 * @param string $key      Line key (without the "op_" prefix).
	 * @param int    $quantity Quantity.
	 * @return true|\WP_Error
	 */
	public function set_option_quantity( $key, $quantity ) {
		$lines = $this->option_raw();
		if ( ! isset( $lines[ $key ] ) ) {
			return true;
		}
		$quantity = max( 0, min( 999, (int) $quantity ) );
		if ( $quantity ) {
			$product_id = (int) $lines[ $key ]['product_id'];
			$others     = $this->product_quantity( $product_id ) - (int) $lines[ $key ]['quantity'];
			$check      = $this->validate( $product_id, $others + $quantity );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			$check = $this->validate_choices( $product_id, (array) ( $lines[ $key ]['options'] ?? array() ), $quantity, $key );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			$lines[ $key ]['quantity'] = $quantity;
		} else {
			unset( $lines[ $key ] );
		}
		$this->session->set( 'option_lines', $lines );
		$this->changed();
		return true;
	}

	/**
	 * Units of a product in the cart across its plain and option lines.
	 *
	 * @param int $product_id Product.
	 * @return int
	 */
	public function product_quantity( $product_id ) {
		$product_id = \SidcraftCommerce\Multilingual::original_id( absint( $product_id ) ); // Translations share the original's cart line.
		$qty = (int) ( $this->raw()[ $product_id ] ?? 0 );
		foreach ( $this->option_raw() as $line ) {
			if ( (int) ( $line['product_id'] ?? 0 ) === (int) $product_id ) {
				$qty += (int) $line['quantity'];
			}
		}
		return $qty;
	}

	/**
	 * product_id => total units, for real products (not gift cards).
	 *
	 * @return array<int,int>
	 */
	public function product_quantities() {
		$out = array();
		foreach ( $this->get_items() as $line ) {
			if ( empty( $line['gift_card'] ) ) {
				$out[ $line['product_id'] ] = ( $out[ $line['product_id'] ] ?? 0 ) + (int) $line['quantity'];
			}
		}
		return $out;
	}

	/**
	 * Priced line items. Unpurchasable products are dropped silently.
	 *
	 * @return array[] Each: key, product, product_id, quantity, price (per unit, options included),
	 *                 total, gift_card (array|null), options (rows: label, value, price).
	 */
	public function get_items() {
		$lines = array();
		$this->prime_products();
		// Quantity discounts count every unit of a product that is really in
		// the cart, whatever its options: collect the lines first, price after.
		$units   = array();
		$pending = array();
		foreach ( $this->raw() as $id => $qty ) {
			$product = Product::get( $id );
			if ( ! $product || ! $product->is_purchasable() || $qty < 1 ) {
				continue;
			}
			if ( $product->is_gift_card() ) {
				continue; // Gift cards are bought through their own form.
			}
			$pending[] = array( (string) $product->get_id(), $product, (int) $qty, null );
			$units[ $product->get_id() ] = ( $units[ $product->get_id() ] ?? 0 ) + (int) $qty;
		}
		foreach ( $this->option_raw() as $key => $stored ) {
			$product = Product::get( (int) ( $stored['product_id'] ?? 0 ) );
			$qty     = (int) ( $stored['quantity'] ?? 0 );
			if ( ! $product || ! $product->is_purchasable() || $product->is_gift_card() || $qty < 1 ) {
				continue;
			}
			// Re-checked against the product's current options on every request:
			// prices follow the product, and lines whose choices no longer exist drop out.
			$resolved = Product_Options::resolve( $product->get_options(), (array) ( $stored['options'] ?? array() ) );
			if ( is_wp_error( $resolved ) || ! $resolved['rows'] ) {
				continue;
			}
			$pending[] = array( 'op_' . $key, $product, $qty, $resolved );
			$units[ $product->get_id() ] = ( $units[ $product->get_id() ] ?? 0 ) + $qty;
		}
		foreach ( $pending as list( $key, $product, $qty, $resolved ) ) {
			$price = $product->get_price( $units[ $product->get_id() ] );
			if ( null !== $resolved ) {
				// Choices can lower the price too, but never below zero.
				$price = round_amount( max( 0.0, $price + $resolved['extra'] ) );
			}
			$line = array(
				'key'        => $key,
				'product'    => $product,
				'product_id' => $product->get_id(),
				'quantity'   => $qty,
				'price'      => $price,
				'total'      => round_amount( $price * $qty ),
				'gift_card'  => null,
				'options'    => null !== $resolved ? $resolved['rows'] : array(),
			);
			if ( null !== $resolved ) {
				$line['selection'] = $resolved['selection'];
			}
			if ( $product->is_subscription() ) {
				$line = \SidcraftCommerce\Subscriptions::price_line( $line, $product );
			}
			$lines[] = $line;
		}
		foreach ( $this->gift_raw() as $key => $gift ) {
			$product = Product::get( (int) ( $gift['product_id'] ?? 0 ) );
			if ( ! $product || ! $product->is_gift_card() || ! $product->is_purchasable() || ! $product->accepts_gift_card_amount_base( (float) $gift['amount'] ) ) {
				continue;
			}
			$qty     = max( 1, (int) $gift['quantity'] );
			$price   = round_amount( \SidcraftCommerce\Currencies::to_active( (float) $gift['amount'] ) );
			$lines[] = array(
				'key'        => 'gc_' . $key,
				'product'    => $product,
				'product_id' => $product->get_id(),
				'quantity'   => $qty,
				'price'      => $price,
				'total'      => round_amount( $price * $qty ),
				'gift_card'  => $gift,
				'options'    => array(),
			);
		}
		return apply_filters( 'sidcraft_commerce_cart_items', $lines, $this );
	}

	/**
	 * Add units of a product.
	 *
	 * @param int $product_id Product.
	 * @param int $quantity   Units.
	 * @return true|\WP_Error
	 */
	public function add( $product_id, $quantity = 1 ) {
		$product_id = \SidcraftCommerce\Multilingual::original_id( absint( $product_id ) ); // Translations share the original's cart line.
		$product = Product::get( $product_id );
		if ( $product && $product->has_options() ) {
			return $this->add_with_options( $product_id, $quantity, array() );
		}
		$items   = $this->raw();
		$current = $items[ $product_id ] ?? 0;
		$result  = $this->validate( $product_id, $this->product_quantity( $product_id ) + max( 1, (int) $quantity ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$items[ $product_id ] = $current + max( 1, (int) $quantity );
		$this->store( $items );
		do_action( 'sidcraft_commerce_added_to_cart', $product_id, $quantity, $this, array() );
		return true;
	}

	/**
	 * Set a line's quantity (0 removes it).
	 *
	 * @param int $product_id Product.
	 * @param int $quantity   Units.
	 * @return true|\WP_Error
	 */
	public function set_quantity( $product_id, $quantity ) {
		$product_id = \SidcraftCommerce\Multilingual::original_id( absint( $product_id ) ); // Translations share the original's cart line.
		$items    = $this->raw();
		$quantity = max( 0, (int) $quantity );
		if ( $quantity > 0 ) {
			$result = $this->validate( $product_id, $this->product_quantity( $product_id ) - ( $items[ $product_id ] ?? 0 ) + $quantity );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$items[ $product_id ] = $quantity;
		} else {
			unset( $items[ $product_id ] );
		}
		$this->store( $items );
		return true;
	}

	/**
	 * Check a product can be bought in this quantity.
	 *
	 * @param int $product_id Product.
	 * @param int $quantity   Total units wanted (all lines of this product together).
	 * @return true|\WP_Error
	 */
	public function validate( $product_id, $quantity ) {
		$product_id = \SidcraftCommerce\Multilingual::original_id( absint( $product_id ) ); // Translations share the original's cart line.
		$product = Product::get( $product_id );

		if ( ! $product || ! $product->is_purchasable() ) {
			$error = new \WP_Error( 'sidccofo_unavailable', __( 'This product is not available.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		} elseif ( $product->is_gift_card() ) {
			$error = new \WP_Error( 'sidccofo_giftcard_form', __( 'Choose an amount and a recipient for this gift card on its product page.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		} elseif ( ! $product->is_in_stock() ) {
			/* translators: %s: product name. */
			$error = new \WP_Error( 'sidccofo_out_of_stock', sprintf( __( '“%s” is out of stock.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $product->get_name() ) );
		} elseif ( $product->managing_stock() && $quantity > $product->get_stock_quantity() ) {
			$error = new \WP_Error(
				'sidccofo_not_enough_stock',
				/* translators: 1: product name, 2: units left. */
				sprintf( __( 'Only %2$d of “%1$s” left in stock.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $product->get_name(), $product->get_stock_quantity() )
			);
		} else {
			$error = true;
		}

		/**
		 * Filter cart validation. Return a WP_Error to block (e.g. max per order).
		 *
		 * @param true|\WP_Error $error      Result.
		 * @param Product|null   $product    Product.
		 * @param int            $quantity   Units wanted.
		 */
		return apply_filters( 'sidcraft_commerce_validate_cart_item', $error, $product, $quantity );
	}

	/**
	 * Check option choices that have their own stock count: the units in the
	 * cart that use each choice, plus the ones being added, must fit.
	 *
	 * @param int         $product_id Product.
	 * @param array       $selection  Clean selection for the new units.
	 * @param int         $quantity   Units with that selection.
	 * @param string|null $skip_key   Option line being replaced (quantity change).
	 * @return true|\WP_Error
	 */
	public function validate_choices( $product_id, array $selection, $quantity, $skip_key = null ) {
		$product_id = \SidcraftCommerce\Multilingual::original_id( absint( $product_id ) ); // Translations share the original's cart line.
		$product = Product::get( $product_id );
		if ( ! $product || ! $selection || ! $product->has_choice_stock() ) {
			return true;
		}
		$groups  = $product->get_options();
		$tracked = \SidcraftCommerce\Data\Choice_Stock::tracked_in( $product_id, $groups, $selection );
		foreach ( $tracked as $id => $row ) {
			$need = (int) $quantity;
			foreach ( $this->option_raw() as $key => $line ) {
				if ( (string) $key === (string) $skip_key || (int) ( $line['product_id'] ?? 0 ) !== (int) $product_id ) {
					continue;
				}
				if ( isset( \SidcraftCommerce\Data\Choice_Stock::tracked_in( $product_id, $groups, (array) ( $line['options'] ?? array() ) )[ $id ] ) ) {
					$need += (int) $line['quantity'];
				}
			}
			if ( $need > $row['left'] ) {
				return new \WP_Error(
					$row['left'] > 0 ? 'sidccofo_not_enough_stock' : 'sidccofo_out_of_stock',
					$row['left'] > 0
						/* translators: 1: units left, 2: choice ("Size: Large"), 3: product name. */
						? sprintf( _n( 'Only %1$d of “%2$s” is left for %3$s.', 'Only %1$d of “%2$s” are left for %3$s.', $row['left'], 'sidcraft-commerce-for-sidcraft-page-builder' ), $row['left'], $row['label'], $product->get_name() )
						/* translators: 1: choice ("Size: Large"), 2: product name. */
						: sprintf( __( '“%1$s” is sold out for %2$s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $row['label'], $product->get_name() )
				);
			}
		}
		return true;
	}

	/**
	 * Check every option line's choices (at checkout: stock may have changed).
	 *
	 * @return true|\WP_Error
	 */
	public function validate_all_choices() {
		foreach ( $this->option_raw() as $key => $line ) {
			$check = $this->validate_choices( (int) ( $line['product_id'] ?? 0 ), (array) ( $line['options'] ?? array() ), (int) ( $line['quantity'] ?? 0 ), $key );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
		}
		return true;
	}

	/**
	 * Empty the cart.
	 */
	public function clear() {
		if ( $this->session->has_session() ) {
			$this->session->set( 'coupon', '' );
			$this->session->set( 'gift_lines', array() );
			$this->session->set( 'gift_cards', array() );
		}
		$this->store( array() );
	}

	/**
	 * Units in cart.
	 *
	 * @return int Units in cart.
	 */
	/**
	 * Load every product in the cart (post and meta) in one batch. The cart is
	 * read on every page a shopper with a cart views (mini cart count), and
	 * each product otherwise costs two queries of its own.
	 */
	protected function prime_products() {
		if ( ! function_exists( '_prime_post_caches' ) ) {
			return;
		}
		$ids = array();
		foreach ( array_keys( $this->raw() ) as $id ) {
			$ids[ (int) $id ] = (int) $id;
		}
		foreach ( $this->option_raw() as $stored ) {
			$id = (int) ( $stored['product_id'] ?? 0 );
			$ids[ $id ] = $id;
		}
		unset( $ids[0] );
		if ( $ids ) {
			_prime_post_caches( array_values( $ids ), false, true );
		}
	}

	public function get_count() {
		return array_sum( wp_list_pluck( $this->get_items(), 'quantity' ) );
	}

	/**
	 * Whether empty.
	 *
	 * @return bool
	 */
	public function is_empty() {
		return 0 === $this->get_count();
	}

	/**
	 * Subtotal.
	 *
	 * @return float
	 */
	public function get_subtotal() {
		return round_amount( array_sum( wp_list_pluck( $this->get_items(), 'total' ) ) );
	}

	/**
	 * Subtotal of real products (gift cards excluded): what coupons, tax and
	 * shipping apply to.
	 *
	 * @return float
	 */
	public function get_goods_subtotal() {
		$sum = 0.0;
		foreach ( $this->get_items() as $line ) {
			if ( empty( $line['gift_card'] ) ) {
				$sum += $line['total'];
			}
		}
		return round_amount( $sum );
	}

	/**
	 * Does the cart hold anything to ship (besides gift cards and virtual
	 * products)?
	 *
	 * @return bool
	 */
	public function needs_shipping() {
		foreach ( $this->get_items() as $line ) {
			if ( empty( $line['gift_card'] ) && ! \SidcraftCommerce\Downloads\Downloads::is_virtual( (int) $line['product_id'] ) ) {
				return true;
			}
		}
		return false;
	}

	/*
	------------------------------------------------------------------
	 * Coupons (one per cart). Only the code is stored in the session; the
	 * coupon is re-validated and the discount recalculated on every request.
	 * ----------------------------------------------------------------
	 */

	/**
	 * Apply a coupon code.
	 *
	 * @param string $code Code as typed.
	 * @return Coupon|\WP_Error
	 */
	public function apply_coupon( $code ) {
		$code = Coupon::normalize( $code );
		if ( '' === $code ) {
			return new \WP_Error( 'sidccofo_coupon_empty', __( 'Enter a coupon code.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		$coupon = Coupon::get_by_code( $code );
		if ( ! $coupon ) {
			/* translators: %s: coupon code. */
			return new \WP_Error( 'sidccofo_coupon_invalid', sprintf( __( 'Coupon “%s” does not exist.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $code ) );
		}
		if ( $this->is_empty() ) {
			return new \WP_Error( 'sidccofo_empty_cart', __( 'Your cart is empty.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		$valid = $coupon->validate_for_cart( $this );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$this->session->set( 'coupon', $coupon->get_code() );
		$this->totals = null;
		do_action( 'sidcraft_commerce_coupon_applied', $coupon, $this );
		return $coupon;
	}

	/**
	 * Remove the coupon.
	 */
	public function remove_coupon() {
		if ( $this->session->has_session() ) {
			$this->session->set( 'coupon', '' );
		}
		$this->totals = null;
		do_action( 'sidcraft_commerce_coupon_removed', $this );
	}

	/**
	 * Code stored in the session ('' = none), valid or not.
	 *
	 * @return string
	 */
	public function get_coupon_code() {
		return Coupon::normalize( (string) $this->session->get( 'coupon', '' ) );
	}

	/**
	 * The applied coupon when it is still valid for this cart, else null.
	 *
	 * @return Coupon|null
	 */
	public function get_coupon() {
		$code = $this->get_coupon_code();
		if ( '' === $code ) {
			return null;
		}
		$coupon = Coupon::get_by_code( $code );
		return ( $coupon && true === $coupon->validate_for_cart( $this ) ) ? $coupon : null;
	}

	/**
	 * Why the stored coupon doesn't apply right now ('' when it does or none).
	 *
	 * @return string
	 */
	public function get_coupon_error() {
		$code = $this->get_coupon_code();
		if ( '' === $code ) {
			return '';
		}
		$coupon = Coupon::get_by_code( $code );
		if ( ! $coupon ) {
			/* translators: %s: coupon code. */
			return sprintf( __( 'Coupon “%s” is no longer available.', 'sidcraft-commerce-for-sidcraft-page-builder' ), $code );
		}
		$valid = $coupon->validate_for_cart( $this );
		return is_wp_error( $valid ) ? $valid->get_error_message() : '';
	}

	/**
	 * Coupon discount on the subtotal.
	 *
	 * @return float
	 */
	public function get_discount() {
		$coupon = $this->get_coupon();
		return $coupon ? $coupon->discount_for( $this->get_coupon_base( $coupon ) ) : 0.0;
	}

	/**
	 * What a coupon's discount is worked out on: the goods subtotal, or only
	 * the lines it applies to when it is limited to products or categories.
	 *
	 * @param Coupon $coupon Coupon.
	 * @return float
	 */
	public function get_coupon_base( Coupon $coupon ) {
		if ( ! $coupon->is_restricted() ) {
			return $this->get_goods_subtotal();
		}
		$sum = 0.0;
		foreach ( $this->get_items() as $line ) {
			if ( empty( $line['gift_card'] ) && $coupon->applies_to( (int) $line['product_id'] ) ) {
				$sum += $line['total'];
			}
		}
		return round_amount( $sum );
	}

	/**
	 * Does the applied coupon make shipping free?
	 *
	 * @return bool
	 */
	public function has_free_shipping_coupon() {
		$coupon = $this->get_coupon();
		return $coupon && $coupon->gives_free_shipping();
	}

	/**
	 * Line totals with the discount spread across them in proportion (used
	 * for tax, so tax is charged on what the customer actually pays).
	 *
	 * @return float[]
	 */
	protected function discounted_line_totals() {
		$lines    = array();
		$eligible = array();
		$coupon   = $this->get_coupon();
		foreach ( $this->get_items() as $line ) {
			if ( empty( $line['gift_card'] ) ) {
				$lines[]    = $line['total']; // Gift cards are not taxed.
				$eligible[] = ! $coupon || $coupon->applies_to( (int) $line['product_id'] );
			}
		}
		$discount = $this->get_discount();
		// The discount is spread over the lines it was worked out on.
		$subtotal = 0.0;
		foreach ( $lines as $k => $total ) {
			$subtotal += $eligible[ $k ] ? (float) $total : 0.0;
		}
		if ( $discount <= 0 || $subtotal <= 0 ) {
			return $lines;
		}
		$left = $discount;
		$last = null;
		foreach ( $lines as $k => $total ) {
			if ( $eligible[ $k ] ) {
				$last = $k;
			}
		}
		foreach ( $lines as $k => $total ) {
			if ( ! $eligible[ $k ] ) {
				continue;
			}
			$share       = $k === $last ? $left : round_amount( $discount * (float) $total / $subtotal );
			$share       = min( (float) $total, $share );
			$left        = round_amount( $left - $share );
			$lines[ $k ] = round_amount( (float) $total - $share );
		}
		return $lines;
	}

	/**
	 * Where the order is going: the country/state chosen at checkout, else
	 * the customer's last checkout, else the store's default country.
	 *
	 * @return array [ country, state, known (bool: chosen by the customer) ].
	 */
	public function get_location() {
		$loc   = (array) $this->session->get( 'location', array() );
		$known = ! empty( $loc['country'] );
		if ( ! $known ) {
			$saved = (array) $this->session->get( 'customer', array() );
			$loc   = array(
				'country' => $saved['country'] ?? '',
				'state'   => $saved['state'] ?? '',
			);
			$known = ! empty( $loc['country'] );
		}
		$country = Locations::normalize_country( $loc['country'] ?? '' );
		if ( '' === $country ) {
			$country = Locations::normalize_country( (string) setting( 'default_country', 'US' ) );
			$known   = false;
		}
		return array(
			'country' => $country,
			'state'   => Locations::normalize_state( $country, (string) ( $loc['state'] ?? '' ) ),
			'known'   => $known,
		);
	}

	/**
	 * Set the destination (checkout country/state changes).
	 *
	 * @param string $country Country (code or name).
	 * @param string $state   State (code or name).
	 * @return bool False for an unknown country.
	 */
	public function set_location( $country, $state = '' ) {
		$country = Locations::normalize_country( $country );
		if ( '' === $country ) {
			return false;
		}
		$this->session->set(
			'location',
			array(
				'country' => $country,
				'state'   => Locations::normalize_state( $country, sanitize_text_field( (string) $state ) ),
			)
		);
		$this->totals = null;
		return true;
	}

	/**
	 * Per-request totals cache.
	 *
	 * @var array|null
	 */
	protected $totals = null;

	/**
	 * Work the totals out again on next use (after something they depend on,
	 * such as a coupon's usage, may have changed).
	 */
	public function forget_totals() {
		$this->totals = null;
	}

	/**
	 * Shipping quote + tax for the current cart and destination.
	 *
	 * @return array [ shipping (quote), tax (Tax::calculate result) ].
	 */
	protected function totals() {
		$key = md5( wp_json_encode( array( $this->raw(), $this->gift_raw(), $this->session->get( 'location', array() ), $this->get_coupon_code(), \SidcraftCommerce\currency() ) ) );
		if ( null !== $this->totals && $this->totals['key'] === $key ) {
			return $this->totals;
		}
		$location = $this->get_location();
		$subtotal = round_amount( $this->get_goods_subtotal() - $this->get_discount() ); // Free-shipping threshold uses what the customer pays.
		if ( setting( 'collect_address', 1 ) && $this->needs_shipping() ) {
			$quote = Shipping::quote( $subtotal, $location['country'], \SidcraftCommerce\Catalog::cart_weight( $this ) );
		} else {
			$quote = array(
				'available' => true,
				'cost'      => 0.0,
				'zone'      => '',
				'free'      => false,
				'free_over' => 0.0,
				'remaining' => 0.0,
			);
		}

		// A free-shipping coupon.
		if ( $quote['available'] && $this->has_free_shipping_coupon() ) {
			$quote['cost']      = 0.0;
			$quote['free']      = true;
			$quote['remaining'] = 0.0;
		}

		/**
		 * Filter the shipping cost.
		 *
		 * @param float $cost Shipping cost.
		 * @param Cart  $cart Cart.
		 */
		$quote['cost'] = round_amount( (float) apply_filters( 'sidcraft_commerce_cart_shipping', $quote['cost'], $this ) );

		$this->totals = array(
			'key'      => $key,
			'location' => $location,
			'shipping' => $quote,
			'tax'      => Tax::calculate( $this->discounted_line_totals(), $quote['cost'], $location ),
		);
		return $this->totals;
	}

	/**
	 * Shipping quote (available, zone, free, remaining for free shipping…).
	 *
	 * @return array
	 */
	public function get_shipping_quote() {
		return $this->totals()['shipping'];
	}

	/**
	 * The store ships to the current destination.
	 *
	 * @return bool The store ships to the current destination.
	 */
	public function can_ship() {
		return (bool) $this->totals()['shipping']['available'];
	}

	/**
	 * Shipping cost.
	 *
	 * @return float Shipping cost.
	 */
	public function get_shipping() {
		return (float) $this->totals()['shipping']['cost'];
	}

	/**
	 * Tax details.
	 *
	 * @return array [ items, shipping, total, rate, label, inclusive ].
	 */
	public function get_tax_details() {
		return $this->totals()['tax'];
	}

	/**
	 * Tax total (included in prices or added, see prices_include_tax()).
	 *
	 * @return float Tax total (included in prices or added, see prices_include_tax()).
	 */
	public function get_tax() {
		return (float) $this->totals()['tax']['total'];
	}

	/**
	 * Prices include tax.
	 *
	 * @return bool
	 */
	public function prices_include_tax() {
		return Tax::prices_include_tax();
	}

	/**
	 * Total.
	 *
	 * @return float
	 */
	public function get_total() {
		return round_amount( max( 0.0, $this->get_total_before_gift_cards() - $this->get_gift_card_total() ) );
	}

	/**
	 * Order total before gift cards are applied.
	 *
	 * @return float
	 */
	public function get_total_before_gift_cards() {
		$tax = $this->prices_include_tax() ? 0.0 : $this->get_tax();
		return round_amount( apply_filters( 'sidcraft_commerce_cart_total', max( 0.0, $this->get_subtotal() - $this->get_discount() ) + $this->get_shipping() + $tax, $this ) );
	}

	/*
	------------------------------------------------------------------
	 * Paying with gift cards. Codes are stored; balances and amounts are
	 * re-read on every request.
	 * ----------------------------------------------------------------
	 */

	/**
	 * Applied codes (normalised).
	 *
	 * @return string[]
	 */
	public function get_gift_card_codes() {
		$codes = $this->session->get( 'gift_cards', array() );
		return is_array( $codes ) ? array_values( array_unique( array_map( array( Gift_Card::class, 'normalize' ), $codes ) ) ) : array();
	}

	/**
	 * Apply a gift card.
	 *
	 * @param string $code Code as typed.
	 * @return Gift_Card|\WP_Error
	 */
	public function apply_gift_card( $code ) {
		$norm = Gift_Card::normalize( $code );
		if ( '' === $norm ) {
			return new \WP_Error( 'sidccofo_giftcard_empty_code', __( 'Enter a gift card code.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		if ( in_array( $norm, $this->get_gift_card_codes(), true ) ) {
			return new \WP_Error( 'sidccofo_giftcard_applied', __( 'This gift card is already applied.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		$card = Gift_Card::get_by_code( $norm );
		if ( ! $card ) {
			return new \WP_Error( 'sidccofo_giftcard_invalid', __( 'This gift card code is not valid.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		$valid = $card->validate();
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( $this->is_empty() ) {
			return new \WP_Error( 'sidccofo_empty_cart', __( 'Your cart is empty.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		}
		$codes   = $this->get_gift_card_codes();
		$codes[] = $norm;
		$this->session->set( 'gift_cards', $codes );
		$this->totals = null;
		return $card;
	}

	/**
	 * Remove an applied gift card.
	 *
	 * @param string $code Code.
	 */
	public function remove_gift_card( $code ) {
		$norm = Gift_Card::normalize( $code );
		$this->session->set( 'gift_cards', array_values( array_diff( $this->get_gift_card_codes(), array( $norm ) ) ) );
		$this->totals = null;
	}

	/**
	 * Applied, usable cards with the amount each pays, in the order applied.
	 * Cards that are no longer valid are skipped (and reported).
	 *
	 * @return array[] [ card => Gift_Card, amount => float, error => string ].
	 */
	public function get_applied_gift_cards() {
		$left = $this->get_total_before_gift_cards();
		$out  = array();
		foreach ( $this->get_gift_card_codes() as $code ) {
			$card = Gift_Card::get_by_code( $code );
			if ( ! $card ) {
				continue;
			}
			$valid  = $card->validate();
			$amount = is_wp_error( $valid ) ? 0.0 : round_amount( min( \SidcraftCommerce\Currencies::to_active( $card->get_balance() ), $left ) );
			$left   = round_amount( $left - $amount );
			$out[]  = array(
				'card'   => $card,
				'amount' => $amount,
				'error'  => is_wp_error( $valid ) ? $valid->get_error_message() : '',
			);
		}
		return $out;
	}

	/**
	 * Total paid by gift cards.
	 *
	 * @return float
	 */
	public function get_gift_card_total() {
		return round_amount( array_sum( wp_list_pluck( $this->get_applied_gift_cards(), 'amount' ) ) );
	}

	/**
	 * Fingerprint of the cart contents (for cached fragments).
	 *
	 * @return string
	 */
	public function get_hash() {
		$raw  = $this->raw();
		$gift = $this->gift_raw();
		$opts = $this->option_raw();
		return ( $raw || $gift || $opts ) ? md5( wp_json_encode( array( $raw, $gift, $opts ) ) . \SidcraftCommerce\Data\Product::query_version() ) : '';
	}
}
