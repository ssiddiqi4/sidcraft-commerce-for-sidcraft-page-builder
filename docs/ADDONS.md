# Building add-ons for Sidcraft Commerce

Sidcraft Commerce can be extended with your own WordPress plugin: a restaurant
menu, bookings, a print shop, a local payment method. This guide lists the
extension points. Two complete, commented examples sit next to it:

- `docs/example-addon.php`: a restaurant add-on with kitchen order statuses,
  item options added in code, pickup/delivery checkout fields, opening hours and
  a kitchen ticket email.
- `docs/example-gateway.php`: a custom payment gateway.

Neither file is loaded by Sidcraft Commerce. Copy them into your own plugin.

## 1. Plugin skeleton

```php
<?php
/**
 * Plugin Name:      My Restaurant for Sidcraft Commerce
 * Requires Plugins: sidcraft-commerce-for-sidcraft-page-builder
 * Text Domain:      my-restaurant
 */

defined( 'ABSPATH' ) || exit;

add_action( 'sidcraft_commerce_loaded', function ( $plugin ) {
	require __DIR__ . '/includes/boot.php';
} );
```

`sidcraft_commerce_loaded` fires on `plugins_loaded` (priority 20), and only
when Sidcraft Commerce and Sidcraft Page Builder are both active. Do your setup
there, so nothing runs when the store is missing. `$plugin` is
`\SidcraftCommerce\Plugin::instance()`, which holds `->units`, `->templates`,
`->assets` and `->emails`.

Use your own prefix for functions, options, post meta, statuses and handles,
and your own text domain.

## 2. Item options (size, extras, instructions)

Store owners type options on each product, under Product data → Item options
(also in the Sidcraft Page Builder "Product data" panel). Each line is one group:

```
Size*: Small | Medium +2 | Large +4
Extras [checkbox]: Extra cheese +1.50 | Mushrooms +1 | Olives
Spice level [radio]: Mild | Hot
Special instructions [text]: e.g. no onions
```

`*` marks a group as required. The types are `select` (the default when there
are choices), `radio`, `checkbox` and `text`. `+2` adds 2 to the unit price.

Add-ons can add groups in code:

```php
add_filter( 'sidcraft_commerce_product_options', function ( $groups, $product ) {
	$groups[] = array(
		'id'       => 'meal',          // Optional; made from the label otherwise.
		'label'    => 'Make it a meal',
		'type'     => 'radio',         // select, radio, checkbox, text.
		'required' => false,
		'max'      => 0,               // checkbox: most choices allowed (0 = any).
		'choices'  => array(
			array( 'id' => 'no', 'label' => 'No thanks' ),
			array( 'id' => 'combo', 'label' => 'Fries + drink', 'price' => 3.5 ),
		),
	);
	return $groups;
}, 10, 2 );
```

What Sidcraft Commerce does with the choices:

- **Product page:** the Add to Cart Unit shows the options form, with a running
  price. Product grids show "Choose options" and link to the product.
- **Cart:** choices are checked on the server, and the price is recalculated
  on every request. Each different combination is its own cart line, and stock
  is checked across all lines of the product.
- **Order:** each order item gets `options`, a list of rows with `label`,
  `value` and `price`. They show in the cart, checkout, order-received page,
  emails, the admin and PayPal.
- **Your own code:** `\SidcraftCommerce\item_options_html( $rows )` and
  `\SidcraftCommerce\item_options_text( $rows )` format the rows.

The REST call `POST /wp-json/sidcraft-commerce/v1/cart/add` takes
`{ product_id, quantity, options: { size: "large", extras: ["mushrooms"] } }`.
The PHP equivalent is
`\SidcraftCommerce\Cart\Cart::instance()->add_with_options( $id, $qty, $options )`.

## 3. Order statuses

```php
add_action( 'sidcraft_commerce_loaded', function () {
	\SidcraftCommerce\Data\Post_Types::register_order_status( 'rst-ready', array(
		'label'          => __( 'Ready for pickup', 'my-restaurant' ),
		'paid'           => true,                  // Counts as paid, like Processing.
		'after'          => 'sidccofo-processing', // Position in lists and dropdowns.
		'color'          => '#047857',             // Orders list.
		'customer_email' => array(                 // Optional.
			'heading' => __( 'Your order is ready for pickup', 'my-restaurant' ),
			'message' => __( 'Come on in — it’s waiting at the counter.', 'my-restaurant' ),
		),
	) );
} );
```

- Slugs are at most 20 characters, which is a WordPress limit. Prefix yours.
- Register before `init`; `sidcraft_commerce_loaded` is the right place.
- `paid => true` means the order keeps its stock, its sales are recorded, its
  gift cards are issued and the customer can review the products.
- `reserves_stock` defaults to the same value as `paid`.
- Change an order's status with `$order->set_status( 'rst-ready', 'Note for the history' )`.
- React to changes with the
  `sidcraft_commerce_order_status_changed( $order, $old, $new )` action.
- To change any status's behaviour, use the
  `sidcraft_commerce_order_status_definitions` filter. Helper lists:
  `Post_Types::paid_statuses()`, `Post_Types::stock_statuses()`.

## 4. Checkout fields

```php
add_filter( 'sidcraft_commerce_checkout_fields', function ( $fields ) {
	// key => [ label, type, required, autocomplete, width, choices ]
	$fields['rst_time'] = array( 'Pickup time', 'select', true, 'off', 'half',
		array( '18:00' => '6:00 pm', '18:30' => '6:30 pm' ) );
	$fields['rst_note'] = array( 'Allergies', 'textarea', false, 'off', 'full' );
	return $fields;
} );

add_filter( 'sidcraft_commerce_checkout_errors', function ( $errors, $billing, $input ) {
	if ( '18:00' === ( $billing['rst_time'] ?? '' ) && 'closed' === get_option( 'my_kitchen_state' ) ) {
		$errors['rst_time'] = 'That slot is full.';
	}
	return $errors;
}, 10, 3 );
```

- The types are `text`, `email`, `tel`, `number`, `date`, `time`, `textarea`,
  `select`, `country` and `state`. The width is `half` or `full`.
- Values are saved on the order and show in the admin, the emails and on the
  order-received page.
- Read them in your own code with `$order->get_billing()['rst_time']` or
  `\SidcraftCommerce\Checkout\Checkout::extra_fields( $order )`.

## 5. Cart and product rules

| Hook | Use it to |
| --- | --- |
| `sidcraft_commerce_validate_cart_item( $result, $product, $qty )` | Block adding or checking out (opening hours, limits per order). Return a `WP_Error`. |
| `sidcraft_commerce_is_purchasable( $ok, $product )` | Hide the buy button entirely. |
| `sidcraft_commerce_product_price( $price, $product )` | Change prices (happy hour). |
| `sidcraft_commerce_cart_items( $lines, $cart )` | Inspect or adjust priced cart lines. |
| `sidcraft_commerce_shipping_zones` / `sidcraft_commerce_cart_shipping` | Delivery zones and fees. |
| `sidcraft_commerce_totals_rows( $rows, $source )` | Add display rows to cart, checkout and order totals. |
| `sidcraft_commerce_tax_rates` / `sidcraft_commerce_tax` | Tax rules. |
| `sidcraft_commerce_validate_coupon` / `sidcraft_commerce_coupon_discount` | Coupon rules. |

## 6. Orders and emails

- **Actions:**
  - `sidcraft_commerce_order_created( $order )`
  - `sidcraft_commerce_payment_complete( $order )`
  - `sidcraft_commerce_order_status_changed( $order, $old, $new )`
  - `sidcraft_commerce_checkout_processed( $order, $gateway )`
- **The `Order` object:** `get_items()`, `get_billing()`, `get_email()`,
  `get_total()`, `get_status()`, `is_paid()`, `add_note()`, `set_status()`.
- **Emails:**
  - Filter or cancel any email with `sidcraft_commerce_email( $mail, $order, $recipient )`;
    return `false` to skip it.
  - Restyle them by copying `templates/emails/order.php` into
    `yourtheme/sidcraft-commerce/emails/`.

## 7. Units (for Sidcraft Page Builder) and templates

Register a Unit by extending `\SidcraftCommerce\Abstracts\Unit` (see
`includes/units/` for examples):

```php
add_action( 'sidcraft_commerce_register_units', function ( $units ) {
	$units->register( new My_Menu_Unit() );
} );
```

- **Where your Unit appears:** in the Sidcraft Page Builder panel with the
  other Commerce Units, and as a shortcode.
- **Front-end assets:** register them with `sidcraft_commerce_register_assets`.
  They load only on pages that use your Unit.
- **Templates:** themes and add-ons can replace any template in `templates/`
  with the `sidcraft_commerce_locate_template( $path, $name )` filter. Pass
  extra variables with `sidcraft_commerce_template_args`.
- **Product page markup:** `sidcraft_commerce_before_add_to_cart_button` and
  `sidcraft_commerce_after_add_to_cart_button` add markup inside the Add to Cart form.

## 8. Settings

```php
add_filter( 'sidcraft_commerce_settings_sections', function ( $sections ) {
	$sections['rst'] = array(
		'tab'    => 'general', // general, shipping, payments, emails.
		'title'  => 'Restaurant',
		'fields' => array(
			'rst_kitchen_email' => array( 'Kitchen email', 'email', 'Tickets go here.' ),
			'rst_open'          => array( 'Opening time', 'text' ),
		),
	);
	return $sections;
} );

$email = \SidcraftCommerce\setting( 'rst_kitchen_email', get_option( 'admin_email' ) );
```

The field types are `text`, `number`, `email`, `password`, `checkbox`,
`textarea`, an array of choices (shown as a dropdown), and `table`.

## 9. Payment gateways

Extend `\SidcraftCommerce\Payments\Gateway` and add it with the
`sidcraft_commerce_gateways` filter. See `docs/example-gateway.php`.

## 10. Good citizenship

- Check for Sidcraft Commerce by hooking `sidcraft_commerce_loaded`; don't
  test for files or classes at load time.
- Store your data in your own options and meta, with your prefix.
- Escape output, sanitize input, and check capabilities and nonces in admin
  screens. Sidcraft Commerce's manager capability is
  `\SidcraftCommerce\manage_cap()`.
- Keep the store's prices authoritative. Change prices through the hooks
  above, never in the browser.

## 11. Events and tickets

Each ticket type is a private placeholder product (`_sidccofo_ev_event`,
`_sidccofo_ev_type`), so every cart, checkout, order and tax hook above also
sees tickets. `\SidcraftCommerce\Events\Placeholders::is_placeholder( $id )`
tells them apart.

| Hook | Use it to |
| --- | --- |
| `sidcraft_commerce_event_ticket_types( $types, $event )` | Add or change ticket types in code. |
| `sidcraft_commerce_event_ticket_price( $result, $event, $type, $qty )` | Change ticket prices (member prices, promo windows). |
| `sidcraft_commerce_event_attendee_fields( $fields, $event )` | Add attendee fields in code. |
| `sidcraft_commerce_tickets_issued( $order, $tickets )` | React when tickets are issued (CRM, badges, SMS). |
| `sidcraft_commerce_ticket_checked_in( $ticket )` / `sidcraft_commerce_ticket_cancelled( $ticket )` | Door counts, access systems. |
| `sidcraft_commerce_event_holds_changed( $order, $now, $before )` | Seats an order holds changed. |
| `sidcraft_commerce_ticket_email( $mail, $order, $tickets, $kind )` | Change or cancel ticket emails (`tickets`, `attendee`, `reminder`). |
| `sidcraft_commerce_ticket_qr_payload( $payload, $ticket )` | Put something else in the QR code. |
| `sidcraft_commerce_user_can_check_in( $ok )` | Decide who may check in (default: store managers and the `sidccofo_check_in` capability). |
| `sidcraft_commerce_event_saved( $event, $changed_time )` | Sync events elsewhere. |
| `sidcraft_commerce_events_query_args( $args, $request )` | Change event lists and calendars. |
| `sidcraft_commerce_checkout_after_fields( $cart )` | Print checkout markup after the customer's details. |

Templates live in `templates/events/` and `templates/emails/tickets.php`.

## Store tools hooks (2.1.0)

- `sidcraft_commerce_cart_fees` (filter): add a charge or credit to the cart:
  `$fees[] = array( 'id' => 'wrap', 'label' => 'Gift wrap', 'amount' => 3.5 );` (negative = credit). Fees are saved on the order (`$order->get_fees()`), shown in totals and are not taxed.
- `sidcraft_commerce_extra_keys` (filter): whitelist a key for `POST /extras/cart`; value `key => callback( $value, $cart )` returns the sanitized value to keep in the cart session (read it with `$cart->extra( 'key' )`) or a `WP_Error`. Add `data-sc-extra="key"` to an input inside the checkout or cart and the totals refresh on change.
- `sidcraft_commerce_checkout_input` (action): fires first when a checkout is submitted, with the input and the cart.
- `sidcraft_commerce_quantity_rules`, `sidcraft_commerce_button_text` (filters): the quantity box limits and Add to cart text of a product.
- `sidcraft_commerce_tool_enabled` (filter): force a store tool on or off.
