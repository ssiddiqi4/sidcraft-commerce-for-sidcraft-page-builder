<?php
/**
 * EXAMPLE — a restaurant add-on for Sidcraft Commerce.
 *
 * This file is documentation: Sidcraft Commerce never loads it. Copy it into
 * your own plugin folder, give that plugin its own header (name, text domain,
 * "Requires Plugins: sidcraft-commerce-for-sidcraft-page-builder"), and change
 * the "rst" prefix, the namespace and the text domain to your own.
 * See docs/ADDONS.md for the full guide.
 *
 * Strings are left untranslated here to keep the example short; in your
 * plugin wrap them in __( '…', 'your-text-domain' ).
 *
 * What it shows:
 *   1. Order statuses: Preparing, Ready for pickup, Out for delivery, each
 *      with its own customer email.
 *   2. Item options added in code (a "Make it a meal" choice on mains).
 *   3. Checkout fields: pickup or delivery, and a time slot.
 *   4. Opening hours: ordering is blocked while the kitchen is closed.
 *   5. A kitchen ticket email when an order is paid.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Examples\Restaurant;

use SidcraftCommerce\Data\Order;
use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Data\Product;

defined( 'ABSPATH' ) || exit;

/*
 * Boot only when Sidcraft Commerce is active and has finished loading.
 * sidcraft_commerce_loaded fires on plugins_loaded (priority 20), before
 * init, which is where order statuses must be registered.
 */
add_action( 'sidcraft_commerce_loaded', __NAMESPACE__ . '\\boot' );

/**
 * Attach everything.
 */
function boot() {
	register_statuses();
	add_filter( 'sidcraft_commerce_product_options', __NAMESPACE__ . '\\meal_option', 10, 2 );
	add_filter( 'sidcraft_commerce_checkout_fields', __NAMESPACE__ . '\\checkout_fields' );
	add_filter( 'sidcraft_commerce_checkout_errors', __NAMESPACE__ . '\\checkout_errors', 10, 2 );
	add_filter( 'sidcraft_commerce_validate_cart_item', __NAMESPACE__ . '\\opening_hours', 10, 3 );
	add_action( 'sidcraft_commerce_payment_complete', __NAMESPACE__ . '\\kitchen_ticket' );
}

/*
 * ---------------------------------------------------------------------------
 * 1. Order statuses
 * ---------------------------------------------------------------------------
 * "paid" => true means the status counts as paid, like Processing: stock
 * stays taken, sales are recorded, gift cards are issued and the customer
 * can review. Slugs are at most 20 characters; prefix them.
 */

/**
 * Register the kitchen statuses after Processing.
 */
function register_statuses() {
	Post_Types::register_order_status(
		'rst-preparing',
		array(
			'label'          => 'Preparing',
			'paid'           => true,
			'after'          => 'sidccofo-processing',
			'color'          => '#b45309',
			'customer_email' => array(
				'heading' => 'We’re preparing your order',
				'message' => 'The kitchen has started on your order.',
			),
		)
	);
	Post_Types::register_order_status(
		'rst-ready',
		array(
			'label'          => 'Ready for pickup',
			'paid'           => true,
			'after'          => 'rst-preparing',
			'color'          => '#047857',
			'customer_email' => array(
				'heading' => 'Your order is ready for pickup',
				'message' => 'Come on in — your order is waiting at the counter.',
			),
		)
	);
	Post_Types::register_order_status(
		'rst-delivery',
		array(
			'label'          => 'Out for delivery',
			'paid'           => true,
			'after'          => 'rst-ready',
			'color'          => '#1d4ed8',
			'customer_email' => array(
				'heading' => 'Your order is on its way',
				'message' => 'Our driver has your order and is heading to you.',
			),
		)
	);
}

/*
 * ---------------------------------------------------------------------------
 * 2. Item options in code
 * ---------------------------------------------------------------------------
 * Store owners can type options on each product (Product data → Item
 * options). Add-ons can add groups in code too; the customer's choices are
 * priced, validated, kept on the cart line and saved on the order.
 */

/**
 * Offer "Make it a meal" on every product in the "mains" category.
 *
 * @param array[] $groups  Option groups.
 * @param Product $product Product.
 * @return array[]
 */
function meal_option( $groups, $product ) {
	if ( has_term( 'mains', Post_Types::CATEGORY, $product->get_id() ) ) {
		$groups[] = array(
			'id'      => 'meal',
			'label'   => 'Make it a meal',
			'type'    => 'radio', // select, radio, checkbox or text.
			'choices' => array(
				array(
					'id'    => 'no',
					'label' => 'No thanks',
				),
				array(
					'id'    => 'fries-drink',
					'label' => 'Fries + drink',
					'price' => 3.5,
				),
			),
		);
	}
	return $groups;
}

/*
 * ---------------------------------------------------------------------------
 * 3. Checkout fields
 * ---------------------------------------------------------------------------
 * Fields are [ label, type, required, autocomplete, width, choices ]. Their
 * values are saved on the order and shown in the admin, the emails and on
 * the order-received page automatically.
 */

/**
 * Pickup or delivery, and a time slot.
 *
 * @param array $fields Checkout fields.
 * @return array
 */
function checkout_fields( $fields ) {
	$fields['rst_fulfilment'] = array(
		'Pickup or delivery',
		'select',
		true,
		'off',
		'half',
		array(
			'pickup'   => 'Pickup',
			'delivery' => 'Delivery',
		),
	);
	$fields['rst_time']       = array( 'Time', 'select', true, 'off', 'half', time_slots() );
	return $fields;
}

/**
 * Today's remaining 30-minute slots, starting 30 minutes from now.
 *
 * @return array "18:30" => "6:30 pm"
 */
function time_slots() {
	$hours = opening_hours_today();
	$slots = array();
	$now   = current_datetime();
	$start = max( $now->getTimestamp() + 30 * MINUTE_IN_SECONDS, $hours[0] );
	$start = (int) ( ceil( $start / ( 30 * MINUTE_IN_SECONDS ) ) * 30 * MINUTE_IN_SECONDS );
	for ( $t = $start; $t <= $hours[1]; $t += 30 * MINUTE_IN_SECONDS ) {
		$slots[ wp_date( 'H:i', $t ) ] = wp_date( get_option( 'time_format' ), $t );
	}
	return $slots;
}

/**
 * Extra rules: delivery needs an address.
 *
 * @param array $errors  field => message.
 * @param array $billing Sanitized checkout fields.
 * @return array
 */
function checkout_errors( $errors, $billing ) {
	if ( 'delivery' === ( $billing['rst_fulfilment'] ?? '' ) && empty( $billing['address_1'] ) ) {
		$errors['address_1'] = 'Enter the delivery address.';
	}
	return $errors;
}

/*
 * ---------------------------------------------------------------------------
 * 4. Opening hours
 * ---------------------------------------------------------------------------
 */

/**
 * Today's opening and closing time as timestamps (11:00 to 22:00 here;
 * a real add-on would read these from its settings).
 *
 * @return int[] [ open, close ]
 */
function opening_hours_today() {
	$today = current_datetime()->setTime( 0, 0 );
	return array(
		$today->setTime( 11, 0 )->getTimestamp(),
		$today->setTime( 22, 0 )->getTimestamp(),
	);
}

/**
 * Block adding to the cart (and checking out) while the kitchen is closed.
 *
 * @param true|\WP_Error $result   Result so far.
 * @param Product|null   $product  Product.
 * @param int            $quantity Units wanted.
 * @return true|\WP_Error
 */
function opening_hours( $result, $product, $quantity ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	list( $open, $close ) = opening_hours_today();
	$now                  = time();
	if ( $now < $open || $now > $close ) {
		return new \WP_Error(
			'rst_closed',
			sprintf( 'Sorry, the kitchen is closed. We take orders from %s.', wp_date( get_option( 'time_format' ), $open ) )
		);
	}
	return $result;
}

/*
 * ---------------------------------------------------------------------------
 * 5. Kitchen ticket
 * ---------------------------------------------------------------------------
 */

/**
 * Email a plain-text ticket to the kitchen when an order is paid.
 *
 * @param Order $order Order.
 */
function kitchen_ticket( Order $order ) {
	$lines = array();
	foreach ( $order->get_items() as $item ) {
		$lines[] = $item['quantity'] . ' × ' . $item['name'];
		$chosen  = \SidcraftCommerce\item_options_text( $item['options'] ?? array() );
		if ( '' !== $chosen ) {
			$lines[] = '    ' . $chosen;
		}
	}
	foreach ( \SidcraftCommerce\Checkout\Checkout::extra_fields( $order ) as $field ) {
		$lines[] = $field['label'] . ': ' . $field['value'];
	}
	wp_mail(
		get_option( 'admin_email' ), // A real add-on would have a "kitchen email" setting.
		sprintf( 'Kitchen ticket #%d', $order->get_id() ),
		implode( "\n", $lines )
	);
}
