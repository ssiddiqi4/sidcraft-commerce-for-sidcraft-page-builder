=== Sidcraft Commerce for Sidcraft Page Builder ===
Contributors: ssiddiqi4
Donate link: https://paypal.me/SSiddiqi275
Tags: e-commerce, shop, checkout, cart, digital-products
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Online store for Sidcraft Page Builder: cart, checkout, coupons, reviews; PayPal, Stripe, Apple Pay, Klarna, Razorpay and more.

== Description ==

Sidcraft Commerce turns [Sidcraft Page Builder](https://canvasly.pro) into a complete store. It needs only [Sidcraft Page Builder](https://wordpress.org/plugins/sidcraft-page-builder) — no WooCommerce or other e-commerce plugin.

* Design products in [Sidcraft Page Builder](https://wordpress.org/plugins/sidcraft-page-builder): Add New Product opens the builder, and its Product data panel sets the price, sale price, SKU, stock, badge, categories, image and short description. Existing products get "Edit with Sidcraft Page Builder".
* Products with prices, sale prices (shown above the struck-through regular price), SKU, stock tracking, featured flag and categories.
* Verified-customer ratings and reviews: shoppers click a star, enter their order number and order email, and write a review. Reviews show with a "Verified purchase" badge, a rating breakdown and the average on the product page.
* Image badges per product (HOT, POPULAR, Sale, Clearance) and an automatic "Sold Out" label on out-of-stock products.
* Item options: sizes, extras, spice levels or special instructions on any product, each choice optionally adding to the price (for example "Large +4", "Extra cheese +1.50"). Choices show in the cart, checkout, emails and the order screen.
* Coupons: a percentage off or a fixed amount off, with optional minimum spend, expiry date and usage limit.
* Cart stored server-side per visitor; prices are always recalculated from the product.
* Payments: PayPal with Pay in 4 (Pay Later) and Venmo; Stripe for cards, Apple Pay, Google Pay, Klarna, Afterpay / Clearpay and Affirm; Razorpay and PayU for India (UPI, cards, netbanking, wallets); Mercado Pago for Latin America (cards, Pix, boleto, OXXO, installments). Customers pay on each provider's secure page or buttons; card numbers never touch your site.
* Digital downloads: sell e-books, music, software, courses or printables. Tick "Downloadable" on a product and add one or more files (upload them, pick them from the media library or link to files hosted elsewhere), with an optional download limit and expiry. Tick "Virtual" for products with nothing to ship: a cart of virtual products skips the shipping cost and the address fields.
* Each buyer gets a private link per file on the order-received page, in the order emails and on a My downloads page (My Downloads Unit or `[sidccofo_downloads]`). Access starts when the order is paid (or when it is marked Completed), pauses when it is cancelled or refunded, and past buyers get files you add later. On the order screen, change downloads left and expiry, revoke or restore access, give access to another product's files, see the download history and email the links again. Store → Download log lists every order with downloads.
* Download security: uploads go to a protected folder with random file names; files are only served from approved folders and in file types WordPress allows; force download (resumable), X-Sendfile / X-Accel-Redirect / X-LiteSpeed-Location or redirect; optional login or email confirmation before downloading; optional IP logging, included in WordPress's personal data export and erase tools.
* Modules: turn off what your store doesn't use under Store → Settings → Modules — Events, Subscriptions, Gift cards, Coupons, Reward points and Affiliates. Their menus, settings, Units and checkout fields go away; nothing is deleted.
* Gift cards: sell them as products (preset amounts and/or a custom amount, recipient, message, optional send date) or issue them by hand. Recipients get an email with the code; customers redeem one or more cards in the cart or at checkout, and unused balance stays on the card.
* Shipment tracking: add tracking numbers on the order screen. Links are built automatically for Australia Post, Canada Post, City-Link Express, DHL, DPD, Fastway South Africa, FedEx, OnTrac, Parcelforce, Polish carriers (Poczta Polska, InPost, DPD Polska, DHL Parcel Polska, GLS), Royal Mail, SAPO, TNT Express (consignment and reference), UPS and USPS, or use any carrier with a custom link.
* Developers can add any payment method (a local bank, a regional wallet, a proprietary system) by extending one PHP class; settings, return and webhook URLs are provided. See docs/example-gateway.php.
* Orders in the admin with statuses, history and stock reserved when the order is placed. Two buyers cannot both take the last unit. Cancelled and Refunded put the remaining units back. A dispute keeps them reserved until it is lost.
* Partial refunds record the amount and return only the quantities you name. A refund that covers the order total marks the order Refunded. Stripe and PayPal refund and dispute notifications are applied once.
* Guest checkout can create an account, and a later login with the same email attaches guest orders placed on 1.0.9 or newer.
* Store → Export catalog downloads products, categories, prices, SKU, stock and item options as JSON, without the page-builder layout.
* Order emails to the customer and the shop owner, including a “Your order has shipped” email with tracking links.
* Events and tickets (new in 1.2.0): Events menu with date and time, venue, address and map, online details (Zoom, Google Meet, any link), organiser, WhatsApp chat and group links, and any number of ticket types.
* Ticket and inventory control: event-wide capacity plus per-ticket limits, minimum and maximum per order, sales start and end, and tiered prices by attendee type (Adult, Child, Student, VIP…). Dynamic pricing rules per ticket: early-bird and late prices by date, demand prices ("after 75%"), and group rates ("qty 5").
* Tickets are sold through hidden product placeholders, so the store's checkout, currency, taxes, coupons, gift cards, stock locking, refunds and every payment method work for tickets.
* Attendee details at checkout: name, email and phone per ticket, plus custom fields (text, long text, email, phone, number, date, dropdown, choose one, checkboxes and file uploads, stored privately).
* Automated delivery: paid orders issue tickets with unique codes, QR codes and/or Code 128 barcodes. Buyers get an email with embedded QR codes, PDF tickets and a calendar file; attendees can get their own copy; reminder emails go out before the event; optional WhatsApp Cloud API message.
* Check-in: scan the QR code with any phone camera (staff see a Check in button), use the Check-in screen with the device camera or a USB scanner, or check in from the Attendees list. Cancelled or refunded orders cancel their tickets; partial refunds cancel just the refunded tickets.
* Calendars: "Add to Google Calendar" and .ics links, plus a subscription feed that Google Calendar, Apple Calendar and Outlook keep in sync. Zoom meetings can be created automatically with a Zoom Server-to-Server OAuth app.
* Display events with Units for Sidcraft Page Builder, Gutenberg blocks or shortcodes: Event List, Event Calendar, Event Details, Event Tickets, Event Map and My Tickets (`[sidccofo_event_list]`, `[sidccofo_event_calendar]`, `[sidccofo_event_tickets event_id="123"]`…). Attendees list with CSV export.

* Several currencies: list more currencies with your rates (Store → Settings → Currencies). Shoppers pick one with the Currency Switcher Unit, or get the one for their country or language; prices, options, shipping, coupons, gift cards and reward points are shown and charged in it, and each order keeps its currency and rate. A product can have its own price per currency.
* Translated stores with WPML or Polylang: products, categories, brands and events can be translated. Every language sells the same stock, shares reviews and fills the same cart line; shop pages open in the shopper's language and order emails go out in it.
* Several stores on WordPress Multisite: network-activate it and each site is its own store, with its own cookies. Network Admin → Stores shows every store's orders and sales, the latest orders across stores, and copies settings, products (with images and downloadable files) and coupons between stores. WP-CLI: `wp sidcraft-commerce stores` and `wp sidcraft-commerce copy`.
* Subscriptions: bill any product every day, week, month or year, for a set number of payments or until cancelled, with an optional free trial and sign-up fee. Each renewal is an order. With Stripe in Sidcraft Pro the saved card is charged automatically; otherwise (or when a card is declined) the customer is emailed a link to pay with any payment method. Customers see, cancel and keep their subscriptions on a My subscriptions page (My Subscriptions Unit or `[sidccofo_subscriptions]`); Store → Subscriptions lists them, with the orders, history, next payment date and price of each.

= Sell event tickets, deliver QR tickets, and check in guests =
https://www.youtube.com/watch?v=aO_uLuGiDO8

[Download Opencart to Sidcraft Importer](https://canvasly.pro/Sidcraft-Commerce-vs-WooCommerce-vs-OpenCart.html)
[Download WooCommerce to Sidcraft Commerce Migrator](https://canvasly.pro/sidcraft-commerce-woo-migrator-1.0.0.zip)

Commerce Units for [Sidcraft](https://wordpress.org/plugins/sidcraft-page-builder): Product Grid, Add to Cart, Product Price, Stock Status, Product Rating, Product Reviews, Mini Cart, Cart, Checkout and Order Confirmation, plus a quick-view popup. Every Unit also works as a shortcode, e.g. `[sidccofo_product_grid source="on_sale" limit="8"]`.

Pages stay cacheable: assets load only where a Unit is used, the cart badge fills in after load, and Cart/Checkout pages are marked no-cache automatically.

Themes can override any template by copying it from `templates/` into `yourtheme/sidcraft-commerce/`

== External services ==

Payment providers are contacted only when a store owner enables them under Store → Settings → Payments and a customer chooses them at checkout (or when the provider sends a notification about a payment). Each receives what it needs to take the payment: the order ID, total and currency, and the customer's name, email and, where the provider requires it, phone and address.

* PayPal - when PayPal is enabled, the plugin sends order line items (product names, SKUs, prices, quantities), the order total, shipping, coupon discount, currency and the order ID to PayPal's REST API (api-m.paypal.com, or api-m.sandbox.paypal.com in sandbox mode) to create and capture the payment, and to verify PayPal webhooks. This happens only when a customer chooses PayPal at checkout, or when PayPal sends a webhook. With "On-page buttons" on, the checkout page also loads PayPal's JavaScript SDK from www.paypal.com to show the PayPal, Pay Later and Venmo buttons and the Pay in 4 message. PayPal terms: https://www.paypal.com/legalhub/useragreement-full — privacy: https://www.paypal.com/myaccount/privacy/privacyhub
* Stripe (cards, Apple Pay, Google Pay, Klarna, Afterpay / Clearpay, Affirm) — [When Sidcraft Builder Pro installed](https://canvasly.pro) api.stripe.com creates a Checkout Session for the order total and is asked again to confirm the payment; Stripe sends signed webhooks back. The customer pays on checkout.stripe.com. Terms: https://stripe.com/legal/ssa — privacy: https://stripe.com/privacy
* Razorpay - [When Sidcraft Builder Pro installed](https://canvasly.pro) api.razorpay.com creates a payment link for the order total and is asked again to confirm it; Razorpay sends signed webhooks back. Terms: https://razorpay.com/terms/ — privacy: https://razorpay.com/privacy/
* PayU India - [When Sidcraft Builder Pro installed](https://canvasly.pro) the checkout form is posted to secure.payu.in (test.payu.in in test mode); payments are confirmed with PayU's verify_payment API on info.payu.in. Terms: https://payu.in/tnc-new/ — privacy: https://payu.in/payu-payments-privacy-statement
* Mercado Pago - [When Sidcraft Builder Pro installed](https://canvasly.pro) api.mercadopago.com creates a Checkout Pro preference for the order total; payments are read back from the same API when the customer returns or a notification arrives. Terms: https://www.mercadopago.com.ar/developers/en/docs/resources/legal/terms-and-conditions — privacy: https://www.mercadopago.com/privacy/privacy-notice/1.1

Events (optional; used only when the store owner sets them up):

* Google Maps - event pages with a venue embed a map from maps.google.com (or the Google Maps Embed API at www.google.com/maps/embed when a key is entered), which receives the venue address or coordinates and the visitor's IP address. Terms: https://cloud.google.com/maps-platform/terms — privacy: https://policies.google.com/privacy
* OpenStreetMap - when chosen instead, the map is embedded from www.openstreetmap.org with the venue coordinates. Terms: https://wiki.osmfoundation.org/wiki/Terms_of_Use — privacy: https://osmfoundation.org/wiki/Privacy_Policy
* Google Calendar - "Add to Google Calendar" and "Subscribe" are plain links to calendar.google.com, opened only when a visitor clicks them; they carry the event name, time, place and description.
* Zoom - only when Zoom credentials are entered under Events → Settings and "Create the Zoom meeting for me" is ticked, the event name, start time, time zone, duration and summary are sent to zoom.us (OAuth) and api.zoom.us to create or update the meeting. Terms: https://www.zoom.com/en/trust/terms/ — privacy: https://www.zoom.com/en/trust/privacy/
* WhatsApp Cloud API (Meta) - only when enabled under Events → Settings, the buyer's phone number, first name, event name, date and ticket link are sent to graph.facebook.com when tickets are issued. Terms: https://www.whatsapp.com/legal/business-terms — privacy: https://www.whatsapp.com/legal/privacy-policy
* WhatsApp links - "Chat on WhatsApp", "Share on WhatsApp" and group links are plain links to wa.me / chat.whatsapp.com, opened only when clicked.

== Developers: add-ons ==

Sidcraft Commerce is built to be extended by other plugins: a restaurant menu, bookings, a print shop. Add-ons can add item options in code, register their own order statuses (for example Preparing, Ready for pickup, Out for delivery, each with its own customer email), add checkout fields (pickup time, delivery notes), block ordering outside opening hours, add Units for Sidcraft Page Builder, settings sections and emails. The guide is in `docs/ADDONS.md`, with a complete restaurant example in `docs/example-addon.php`.

== Developers: custom payment gateways ==

Extend `\SidcraftCommerce\Payments\Gateway` (the equivalent of WooCommerce's WC_Payment_Gateway) and add it with the `sidcraft_commerce_gateways` filter. Implement `get_id()`, `get_default_title()` and `process_payment()`; return fields from `get_settings_fields()` and they appear on Store → Settings → Payments. `return_url()` and `webhook_url()` point at ready-made REST routes that call your `handle_return()` and `handle_webhook()`; `remote_json()` calls JSON APIs; `$order->payment_complete()` marks an order paid. A complete, commented example is in `docs/example-gateway.php`.

== Installation ==

1. Install and activate Sidcraft Page Builder 0.15 or newer.
2. Install and activate Sidcraft Commerce. Shop, Cart, Checkout and Order-received pages are created for you.
3. Go to Store → Settings to set your currency, shipping and payment methods.
4. Under Store → Settings → Payments, enable the payment methods you want and paste their API keys (test keys first). Each section shows its webhook URL.

== Frequently Asked Questions ==

= How do I sell a digital product? =

Edit the product, tick "Downloadable" under Digital product in the Product data box, add your files and save. Tick "Virtual" too if there is nothing to ship. Options are under Store → Settings → Downloads.

= Where do customers find their files? =

On the order-received page and in the order emails once the order is paid, and on the My downloads page (created on activation, with the `[sidccofo_downloads]` shortcode) where logged-in customers see the files from all their orders.

= Are the files protected? =

Files uploaded from the product screen are stored in `wp-content/uploads/sidccofo-downloads/`, closed to direct visits on Apache and LiteSpeed. On nginx, add the rule shown under Store → Settings → Downloads. Customers only get files through their own private links, after the order, limits and expiry are checked.

= Do I need WooCommerce? =

No. Sidcraft Commerce needs only [Sidcraft Page Builder](https://wordpress.org/plugins/sidcraft-page-builder). Products, orders, coupons and gift cards are stored in your own WordPress database.

= Which payment methods can I accept? =

PayPal (with Pay in 4 and Venmo), Stripe (cards, Apple Pay, Google Pay, Klarna, Afterpay / Clearpay, Affirm), Razorpay, PayU India and Mercado Pago. Turn on the ones you want under Store → Settings → Payments and paste the API keys from your provider account.

= Does the plugin charge a fee on sales? =

No. Only your payment provider's normal fees apply.

= Do card numbers pass through my site? =

No. Customers pay on the provider's own secure page or buttons, so card details never reach your server.

= Can I test payments before going live? =

Yes. Use your provider's test or sandbox keys (PayPal sandbox, Stripe test keys, PayU test mode and so on), place a test order, then switch to live keys.

= How do gift cards work? =

Tick "This product is a gift card" on a product and set preset amounts, a custom amount range, or both. Buyers enter a recipient, a message and an optional send date. The recipient gets the code by email once the order is paid, or on the chosen date. Codes can be used in the cart or at checkout, and any unused balance stays on the card. You can also issue cards by hand under Store → Gift cards. Cards never expire unless you set an expiry under Store → Settings → Payments.

= How do I add tracking numbers? =

Open the order, choose the carrier and enter the tracking number in the Shipment tracking box. Links are built automatically for major carriers, or you can enter any carrier name with a custom link. The customer gets a "Your order has shipped" email with the tracking link.

= Can I add my own payment gateway? =

Yes. Extend `\SidcraftCommerce\Payments\Gateway` and register it with the `sidcraft_commerce_gateways` filter. See the "Developers: custom payment gateways" section above and `docs/example-gateway.php`.

= How do I add a product with Sidcraft Page Builder? =

Click Store → Add New Product. The product opens in Sidcraft Page Builder with the Product data panel open: enter the price (and stock, SKU, image and so on), design the page with any Units, then click Publish. Add the Add to Cart and Product Price Units where you want them; if your layout has no Add to Cart Unit, the standard buy box is added above it. To use the WordPress editor instead, turn off "Design products with Sidcraft Page Builder" under Store → Settings.

= How do item options work? =

On a product, under Product data → Item options (or in the Product data panel in Sidcraft Page Builder), type one group per line, for example `Size*: Small | Medium +2 | Large +4` or `Extras [checkbox]: Cheese +1 | Bacon +2`. `*` makes a group required; `[radio]`, `[checkbox]` and `[text]` change how it is shown; `+2` adds 2 to the price. Customers choose on the product page, and product grids show "Choose options".

= Can developers build add-ons, for example for a restaurant? =

Yes. See the "Developers: add-ons" section above, `docs/ADDONS.md` and the restaurant example in `docs/example-addon.php`.

= Can I change how the store looks? =

Yes. Style the Commerce Units in Sidcraft Page Builder, or copy any template from the plugin's `templates/` folder into `yourtheme/sidcraft-commerce/` and edit it there.

= Does it work with page caching? =

Yes. Shop and product pages stay cacheable, the cart badge fills in after the page loads, and Cart and Checkout pages are marked no-cache automatically.

= What if two people buy the last item? =

The order reserves stock as it is created, and the reservation only succeeds while the stored quantity is still at least the amount being bought. The other buyer is told there is not enough stock. A pending order that is still unpaid after 24 hours is cancelled and the units go back. Cancelled and Refunded do the same for the units that order still holds.

= How do refunds and chargebacks work? =

Stripe `charge.refunded` and PayPal `PAYMENT.CAPTURE.REFUNDED` record the amount once. If that amount covers the order, the order is marked Refunded and the remaining stock, coupon use and gift-card balance are returned. A smaller amount stays a partial refund and returns stock only for quantities named on the order screen (Refund box) or in the provider payload. Stripe `charge.dispute.created` / `charge.dispute.closed` and PayPal customer-dispute events set the order to Disputed. Stock stays reserved. A dispute the buyer wins marks the order Refunded. A dispute the store wins returns the order to its previous status.

= Can a guest attach the order to an account? =

Yes. Checkout has “Create an account with this email”. WordPress emails a link to set the password. If that email already has an account, the buyer is asked to log in. Logging in or registering later with the same email attaches guest orders placed on version 1.0.9 or newer.

= How do I export products? =

Open Store → Export catalog and download the JSON file. It contains the product title, description, categories, regular and sale price, SKU, stock and item options. It does not contain the Sidcraft Page Builder layout. Tools → Export also includes the product post type.

= Which currencies use three decimal places? =

BHD, JOD, KWD, OMR and TND are sent to payment providers in thousandths and displayed with 3 decimals. Zero-decimal currencies (JPY, KRW, VND and the rest of that set) use no decimals. Other currencies use the decimal setting under Store → Settings.

= What happens to my data if I delete the plugin? =

Settings, sessions and temporary data are removed. Products, orders, coupons and gift cards are kept, unless you add `define( 'SIDCRAFT_COMMERCE_REMOVE_ALL_DATA', true );` to wp-config.php before deleting the plugin.

== Screenshots ==

1. Product grid with sale prices, badges (HOT, POPULAR, Sale, Clearance) and Sold Out labels.
2. Verified-customer reviews with the rating breakdown.
3. Cart with a product and two gift cards, one scheduled for a later date.
4. Checkout order summary with a coupon, shipping and tax.
5. The "Your order has shipped" email with tracking links.
6. The gift card email the recipient receives.
7. Sidcraft Commerce Prudcts Dashboard.
8. Sidcraft Commerce in action.

== Changelog ==

= 1.9.0 =
* New: per-combination variants. A product with two or more options (dropdown or radio) gets a Combinations grid under Product data: each combination (e.g. Red / Large) has its own SKU, price, sale price, stock and image, and can be marked as not sold.
* The product page switches price, SKU and image when a combination is picked, and says when it is sold out or not sold; the Add to cart button is disabled for those.
* Stock is taken from the combination at checkout and given back if the order is cancelled or refunded. Prices show as a range (lowest – highest).
* Network copy: combination images are cleared on the target site, like choice images.

= 1.8.0 =
* New: a choice can have its own SKU and image (Product data → Stock, SKU and image per choice). Picking it shows its image and SKU on the product page and in quick view; the cart, checkout and order use them.
* New: upsells and cross-sells (Product data → Linked products). Upsells show as "You may also like" below the product; the cart page suggests the cross-sells of what's in the cart. Both can be turned off under Store → Settings.
* New: product tags, with /product-tag/… pages, a Tags column, a "By tag" source in the Product Grid Unit and a Tags row in the specifications table.
* New: coupon exclusions (products and categories that never get the discount), a maximum spend, and allowed emails (account or billing email; *@example.com allows a domain).
* New: external / affiliate products. With a product URL, the Add to cart button becomes a "Buy at…" button to that site (opened in a new tab, marked sponsored).
* New: category and brand images, shown on their pages and in the admin lists, and the Product Categories Unit ([sidccofo_product_categories]): a grid of category cards.
* New: tax classes (Store → Settings → Tax classes), e.g. a reduced rate for food or books. Rate rows take a class; products pick one. Where a class has no row for a place, the standard rate applies.
* New: a per-item rate for shipping zones (e.g. 5 plus 2 per item).
* Fixed: items with options (size, extras) stayed in the cart after checkout.
* Fixed: uninstalling with "delete data" left brands behind.

= 1.7.0 =
* New: Store → Settings → Modules. Turn Events, Subscriptions, Gift cards, Coupons, Reward points and Affiliates on or off. A module that is off loses its menus, settings tab, Units, blocks and cart / checkout fields, and its products (event tickets, subscription and gift card products) show as unavailable. Nothing is deleted: turning a module back on brings everything back.
* Events, Subscriptions, Gift cards and Coupons stay on after updating. The Reward points and Affiliates switches moved here from the Rewards & affiliates tab, which now shows only while one of them is on.
* Orders placed while a module was on keep being handled: bought gift cards are still issued and delivered, sold tickets still open, check in and give seats back on refunds, and points and commissions follow their orders as before.
* Subscriptions can't be turned off while any subscription is waiting for payment, active, on hold or cancelled at period end, so no renewal is skipped silently.
* Shortcodes of switched-off Units (for example [sidccofo_subscriptions]) print nothing instead of the shortcode.
* New filter: sidcraft_commerce_module_enabled ( $on, $module ).

= 1.6.0 =
* New: subscriptions. On a product's edit screen, tick "Bill this product every period" and set how often (every n days, weeks, months or years), the number of payments (or until cancelled), a free trial and a sign-up fee. The product price is what each period costs; product pages, the cart, checkout, emails and orders show it as "$10 / month" with the terms. Subscribers need an account (checkout asks them to create one).
* New: the first order pays the first period (or only the sign-up fee during a free trial) and starts the subscription when it is paid. Renewal orders are created when a payment is due, in the subscription's currency and language, with shipping and tax worked out again for physical products. Months keep their day (Jan 31 → Feb 28 → Mar 31).
* New: automatic renewals for payment methods that keep a card (Stripe in Sidcraft Pro, which saves the card at checkout, also during a free trial). Otherwise, or when the card is declined, the subscription shows "Payment due" and the customer gets an email with a link to pay the renewal with any payment method. A renewal still unpaid after Settings → Payments → Subscriptions → Days to pay a renewal (default 7) is cancelled, and so is its subscription.
* New: My Subscriptions Unit and `[sidccofo_subscriptions]` (a My subscriptions page is created): price, status, next payment, Pay now, Cancel (access lasts until the paid period ends) and Keep subscription. Customers are emailed when a subscription is cancelled or finishes.
* New: Store → Subscriptions: every subscription with its customer, price, status and next payment; on each, its first order and renewals, history, and changing the status, next payment date and price, creating the renewal now or no longer charging the saved card. Orders link to the subscription they start or renew.
* Refunding an order doesn't end its subscription: cancel the subscription under Store → Subscriptions. A payment that arrives after a subscription ended is flagged to the store by email.
* Developers: gateways can charge renewals by returning true from supports( 'subscriptions' ), saving their details with Subscriptions::save_token() and implementing charge_renewal(); hook sidcraft_commerce_subscription_status_changed.

= 1.5.0 =
* New: several currencies. Store → Settings → Currencies lists more currencies with your rate, symbol, position, decimals and countries. Shoppers switch with the Currency Switcher Unit (or [sidccofo_currency_switcher], or ?sidccofo_currency=EUR), or get their country's (CDN country header or the checkout country) or their language's currency. Prices, quantity discounts, option extras, shipping rates, coupon amounts and minimum spends, gift card balances and reward point values are converted from the store currency; a product can have a fixed price per currency, which sale prices, specials and quantity discounts take the same share off. Orders are charged and kept in the shopper's currency with the rate used, and are shown in it in emails, on the order-received page and in admin (with the store-currency value). Payment methods that can't take a currency are hidden for it, with a link to pay in the store currency. Points are earned and commissions owed on the store-currency value.
* New: WPML and Polylang. Products, product categories, brands, events and event categories can be translated (wpml-config.xml; Polylang lists them under Languages → Settings). A translation is the original product in another language: carts, stock, prices, price rules, downloads, reviews and event tickets are the original's, so every language sells the same stock; names, links, descriptions, images, attribute and option labels come from the translation. Shop pages open in the shopper's language, cart and checkout requests keep it, orders remember it and customer emails (order, shipping, downloads, tickets, gift cards) are sent in it. Optional currency per language.
* New: WordPress Multisite. Network activation sets up every site (and sites added later); each store has its own carts, cookies and settings. Network Admin → Stores: every store's orders and sales for 30 days, the latest orders across stores, and copying settings (without payment methods), products (categories, brands, images, downloadable files) and coupons (their product and category limits follow) between stores, updating earlier copies instead of duplicating. WP-CLI: wp sidcraft-commerce stores, wp sidcraft-commerce copy --from=1 --to=2,3.
* Fixed: a store's uploads have two web addresses on a subdirectory Multisite network; downloadable files are recognised by both.

= 1.4.2 =
* Fixed: two checkouts sent at the same moment could both use a coupon's last use (its usage limit or a customer's uses per customer). Checkouts using the same coupon now take turns, from checking its limits until the new order has counted its use (a second or two; the payment step isn't held up), and checkouts with different coupons or none don't wait at all.
* Fixed: the usage count could miss a use when two orders counted the coupon at the same moment; it is now updated in one step.

= 1.4.1 =
* New: coupons can limit uses per customer (Uses per customer on the coupon screen). A customer is their account or their billing email, so guests can't get round the limit by checking out without an account; logged-in customers are told when they apply the coupon, guests at checkout. Cancelled and refunded orders give the use back, and orders placed before this update count too.

= 1.4.0 =
* New: price rules on products (Product data → Price rules): quantity discounts ("10+: 18"), specials that start and end on a date ("from 2026-11-27 until 2026-11-30: 15% off"), and prices for customer groups ("group Wholesale: 70"). The cart counts every unit of a product for quantity discounts, the product page lists the steps and the running special's end date, and sorting and "on sale" follow scheduled specials hourly.
* New: customer groups (Store → Settings → Catalog). Each group is a WordPress role to give customers under Users.
* New: option choices can lower the price ("Thin -1.50"; never below zero) and have their own stock (Product data → Stock per choice). Sold-out choices are shown disabled; orders take choice stock with the product stock and cancellations and refunds put it back.
* New: coupon rules: free shipping, only some products or categories (the discount counts only those items), only for logged-in customers, and a start date.
* New: brands (Store → Brands), attributes ("Label: value" lines with [Group] headings), weight and dimensions, shown in a specifications table on product pages (or the Product Specifications Unit). Shipping zones can add a rate per unit of weight.
* New: reward points (Store → Settings → Rewards & affiliates): customers earn points on paid orders and spend them at checkout; cancelled and refunded orders give spent points back and take earned points away (partial refunds take back their share; points already spent leave a balance owed that new points pay off first). Adjust a customer's points on their user screen.
* New: affiliates: referral links (?ref=CODE), commissions on paid orders (void when cancelled or refunded, reduced by partial refunds; none on an affiliate's own orders), the Affiliate Dashboard Unit for joining and following earnings, and Store → Affiliates for approvals, personal rates and payouts.
* Fixed: logged-in customers got "Your session expired" when placing an order, because the store's requests didn't carry WordPress's REST nonce, so WordPress treated them as logged out. Logged-in shoppers now send it (guests still don't, so cached pages keep working).

= 1.3.2 =
* Fixed: Events → Add New Event opened an empty block editor; the event form (date, venue, address, map, capacity, tickets) sat in a collapsed panel below the canvas. Events now open in the classic event screen with Event details directly under the title. The sidcraft_commerce_event_block_editor filter switches back to the block editor.
* Fixed: an event page designed in Sidcraft Page Builder with an Event details Unit but no Event tickets Unit had no ticket selector, so "Get tickets" led to a page with nothing to buy. Each automatic part (details, tickets, map) is now skipped only when the layout places that Unit itself; "Hide the automatic details and tickets" still turns all of them off.
* Fixed: a ticket type's own "Sales end" date was ignored once the event started when "Stop selling when the event starts" was on (the default), so a multi-day event showed "Registration has closed" on its first day. The ticket type's date now wins; the event's end still closes sales.
* Fixed: the event's schema.org offers said InStock while registration was closed; they now say OutOfStock.
* New: Location preview box on the event screen. It shows the map the event page will show (Google Maps or OpenStreetMap, per Events → Settings) and updates as you type the venue, address or coordinates.

= 1.3.1 =
* Performance: the cart loads all its products in one batch instead of two queries per product. The cart is read on every page a shopper with items in the cart views (for the mini-cart count), so this saves those queries site-wide, not only on the cart and checkout (a 10-line cart: 63 queries to 40 on checkout).
* Performance: builder pages load every product their commerce Units show (price, add to cart, stock, rating) in one batch before rendering, instead of two queries per Unit.
* Performance: templates (product cards, cart, checkout) look for a theme override once per request instead of once per card.
* Performance: the rewrite-flush flag and the two Events options read on every request are autoloaded, so they no longer cost a database query each on every page.
* Fixed: the readme's Stable tag (1.2.0) did not match the plugin version.

= 1.2.0 =
* New: event management and ticketing (Events menu, ticket types with capacities, per-order limits, sales windows, attendee types and dynamic pricing).
* New: tickets sold through hidden product placeholders, so currency, taxes, coupons, gift cards, stock and all payment methods apply.
* New: attendee details at checkout, including custom fields and private file uploads.
* New: ticket emails with embedded QR codes, PDF tickets and .ics files; attendee copies; reminders; optional WhatsApp messages.
* New: QR / barcode check-in (any phone camera, Check-in screen with camera or USB scanner, Attendees list with bulk actions and CSV export).
* New: Google Calendar links and a subscription feed; Zoom meeting creation; Google Maps / OpenStreetMap venue maps.
* New: Event List, Event Calendar, Event Details, Event Tickets, Event Map and My Tickets as Sidcraft Units, Gutenberg blocks and shortcodes.
* New: `sidcraft_commerce_checkout_after_fields` action and `sidcraft_commerce_settings_tabs` filter.
* Fix: reopening a cancelled order now clears its old refunded-quantity record, so stock and reports match the units it holds again.

= 1.1.0 =
* New: Digital downloads. Downloadable products with several files, download limits (per unit bought) and expiry; private links per buyer on the order-received page, in order emails and on a new My downloads page (My Downloads Unit and `[sidccofo_downloads]`).
* New: Virtual products. A cart of virtual products and gift cards has no shipping cost or address fields.
* New: Downloads box on orders: edit downloads left and expiry, revoke or restore access, grant another product's files, email the links again, download history. Store → Download log.
* New: Store → Settings → Downloads: download method (force download with resumable downloads, X-Sendfile / X-Accel-Redirect, redirect), grant on payment or completion, login or email confirmation, approved folders, protected uploads folder, IP logging.
* New: Past buyers get files added to a product later; links pause on cancelled or refunded orders.
* New: The Product data panel in Sidcraft Page Builder has Virtual and Downloadable switches.
* New: Download history in WordPress's personal data export and erase tools.
* Templates updated (1.1.0): emails/order.php, units/order-confirmation.php; new units/downloads.php.

= 1.0.9 =
* Stock is reserved when the order is placed. The update only succeeds while enough units remain, so two buyers cannot both take the last item. A pending order unpaid after 24 hours is cancelled and its stock is returned.
* Partial refunds. The order screen records an amount and the quantities to put back. A Stripe or PayPal refund that covers the order total marks it Refunded. A smaller amount does not return stock unless quantities are named. Each provider event is applied once.
* Disputed status. Stripe `charge.dispute.created` and `charge.dispute.closed`, and PayPal customer-dispute events, keep stock reserved while a dispute is open. A lost dispute refunds the order. A won dispute restores the previous status.
* Guest checkout can create an account. Logging in or registering with the same email attaches guest orders placed on 1.0.9 or newer.
* Three-decimal currencies (BHD, JOD, KWD, OMR, TND) are charged in thousandths. Zero-decimal currencies stay on a factor of 1.
* Store → Export catalog writes products, categories, price, SKU, stock and item options to JSON, without the page-builder layout.
* Templates updated (1.0.9): units/checkout.php.

= 0.9.2 =
* Plugin Check: output escaping on the order screen's item list and in the Add to Cart template.

= 0.9.1 =
* Developers: new `sidcraft_commerce_editor_styles` filter so add-on Units are styled in the Sidcraft Page Builder canvas, and add-on Units can use any glyph or emoji as their builder icon.

= 0.9.0 =
* Item options. Type option groups on a product (Product data → Item options, or the builder's Product data panel): dropdowns, buttons, multiple choices and text boxes, each choice optionally adding to the price, and any group can be required. Customers choose on the product page with a running price; each combination is its own cart line; stock is checked across all lines of a product; prices are recalculated on every request. Choices show in the cart, checkout, order-received page, emails, the order screen and PayPal.
* Add-on support: `sidcraft_commerce_product_options` filter, `Cart::add_with_options()` and an `options` parameter on the cart/add REST route.
* Order statuses can be registered by add-ons with `Post_Types::register_order_status()`: label, position, colour, whether it counts as paid, and an optional customer email. Stock, sales, gift cards and reviews now follow each status's "paid" setting instead of a fixed list. New `sidcraft_commerce_order_status_definitions` filter; the older `sidcraft_commerce_order_statuses` filter still works.
* Checkout fields added by add-ons can be dropdowns (`select` with choices) or text areas, and their values now show on the order-received page and in emails as well as the admin.
* New developer guide (docs/ADDONS.md) and a complete restaurant add-on example (docs/example-addon.php).
* Templates updated (0.9.0): units/add-to-cart.php, units/cart-panel.php, units/checkout.php, units/checkout-summary.php, units/order-confirmation.php, emails/order.php. New: units/item-options.php.

= 0.8.0 =
* Products are designed in Sidcraft Page Builder. Add New Product (menu, Products list and admin bar) opens a new product in the builder, and products get "Edit with Sidcraft Page Builder" on the product screen, in the Products list and in the admin bar.
* New Product data panel inside the builder: regular and sale price, SKU, stock tracking and quantity or stock status, image badge, featured, categories, product image, short description and "hide the automatic buy box". It opens by itself on a new product without a price, and the builder's Save also saves it.
* The automatic buy box is skipped when the product layout already has an Add to Cart Unit, and it now sits above a Sidcraft layout instead of being replaced by it.
* New setting: Store → Settings → "Design products with Sidcraft Page Builder" (on by default). Turn it off to add and edit products in the WordPress editor.

= 0.7.4 =
* Plugin Check: order-screen shipment fields are sanitized field by field when read, and the shipment save no longer writes to $_POST.
* Fix: adding a shipment with "Mark the order Completed" no longer gets undone by the order status dropdown on the same save.
* Admin: gift card screen uses a stylesheet class instead of an inline style.

= 0.7.3 =
* Gift cards. Tick "This product is a gift card" on a product and set preset amounts and/or allow a custom amount (with a minimum and maximum). Buyers choose the amount, recipient, message and an optional send date; each card is emailed to the recipient once the order is paid, or on the chosen date.
* Store → Gift cards: issue cards by hand (store credit, prizes), see balances, recipients and history, change a balance, resend the email, disable a card.
* Redeeming: a gift card field in the cart and at checkout. Several cards per order; a card pays up to its balance and keeps the rest. When cards cover the whole order, no payment method is needed. Amounts are taken when the order is placed and returned if it is cancelled or refunded; cards bought in a cancelled or refunded order are disabled.
* Gift cards are not charged tax or shipping and coupons don't apply to them. Optional expiry for new cards (Store → Settings → Payments → Gift cards; default never).
* Orders with nothing left to pay (gift cards or a 100% coupon) no longer ask for a payment method.
* Codes are protected against guessing (10 failed attempts per hour per visitor).
* Templates updated (0.7.3): units/add-to-cart.php, units/cart-panel.php, units/checkout.php, units/checkout-summary.php. New: units/gift-card-form.php, units/gift-card-redeem.php, emails/gift-card.php.

= 0.7.2 =
* Shipment tracking: a "Shipment tracking" box on each order adds the carrier, tracking number and date. The customer gets a "Your order has shipped" email with a Track your package button, and the tracking shows on the order-received page. Several shipments per order are supported.
* Automatic tracking links for Australia Post, Canada Post, City-Link Express, DHL, DPD (UK and Europe), Fastway South Africa, FedEx, OnTrac, Parcelforce, Poczta Polska, InPost, DPD Polska, DHL Parcel Polska, GLS Poland, Royal Mail, SAPO, TNT Express (consignment and reference), UPS and USPS. Custom carriers with any link; Store → Settings → Shipping & tax → "Your carriers" saves your own carriers and can replace a built-in link.
* Adding a shipment can mark the order Completed (one email: the shipped email replaces the "order complete" email).
* Templates updated (0.7.2): emails/order.php. New: units/shipments.php.

= 0.7.1 =
* All payment gateways work for everyone; there is no licence check of any kind.
* Declares its dependency on Sidcraft Page Builder (Requires Plugins header).

= 0.7.0 =
* New payment methods: Stripe (cards, Apple Pay, Google Pay) with Klarna, Afterpay / Clearpay and Affirm; Razorpay; PayU India; Mercado Pago.
* PayPal: on-page buttons with Pay in 4 (Pay Later), Venmo and cards, plus a Pay in 4 message (the redirect flow remains as an option).
* Developer framework: the Gateway base class now provides settings fields, return and webhook routes (/gateway/{id}/return and /webhook), HTTP helpers and a POST-form checkout flow. Example in docs/example-gateway.php.
* Store → Settings is split into tabs: General, Shipping & tax, Payments, Emails.

= 0.6.2 =
* Plugin Check: coupon lookup no longer sets suppress_filters; tested up to WordPress 7.1.

= 0.6.1 =
* WordPress.org review: text domain is now sidcraft-commerce-for-sidcraft-page-builder; every stored name, post type, status, shortcode, script handle and JavaScript global uses the sidccofo prefix; admin CSS/JS are enqueued files; editor REST routes check per-product permissions.
* Shortcodes are now [sidccofo_product_grid], [sidccofo_cart], [sidccofo_checkout], [sidccofo_order_confirmation] and so on.
* wp-config.php constants renamed: SIDCRAFT_COMMERCE_PAYPAL_CLIENT_ID, SIDCRAFT_COMMERCE_PAYPAL_SECRET, SIDCRAFT_COMMERCE_PAYPAL_WEBHOOK_ID.

= 0.6.0 =
* Verified-customer reviews. Clicking a star on a product (Product Rating Unit, buy box or the reviews section) opens a popup with that rating picked; the customer enters their order number, the email used on the order and their review. The order must be paid and contain the product, and each order can review each product once. Reviews appear in the product details with a "Verified purchase" badge, the average, a 5-to-1 star breakdown and the review list.
* New Product Reviews Unit ([sidccofo_product_reviews]); added automatically below the product description (Store → Settings → "Show reviews on product pages").
* Store → Settings → "Publish verified reviews immediately" (on by default); turn off to approve each review under Comments first. The Comments screen shows the stars and a link to the verifying order.
* The open WordPress comment form is closed on products, so only verified customers can review. Existing reviews are kept and still count toward the average.
* Protection against guessing: the same message for a wrong order number or email, a limit of 10 failed attempts per hour per visitor, and a hidden spam-trap field.
* Product Rating Unit: stars are clickable (switch "Click a star to rate"), and it now shows "Be the first to review" instead of hiding when a product has no reviews.
* Templates updated (0.6.0): units/product-rating.php. New: single/reviews.php, single/no-comments.php.

= 0.5.0 =
* Coupons (Store → Coupons): the coupon title is the code; choose a percentage of the (sale-priced) subtotal or a fixed amount off, with optional minimum spend, expiry date and usage limit. Customers apply or remove one coupon in the cart or at checkout. The discount shows as its own line in the cart, checkout, confirmation, emails and admin order, reduces tax and counts toward free shipping, and is sent to PayPal as a discount. Usage is counted when an order is placed on hold / processing and given back on cancel / refund.
* Image badges: pick HOT, POPULAR, Sale or Clearance per product (Product data → Image badge). It shows top-right on the product image in grids, quick view and the product page. Without a chosen badge, products on sale still show "Sale".
* Sold Out: out-of-stock products get a highlighted "Sold Out" label over a dimmed image.
* Sale prices: the sale price now sits above the struck-through regular price. A sale price that isn't lower than the regular price is rejected with a notice. The cart shows the struck-through regular price next to sale-priced lines.
* Fix: the sidcraft_commerce_order_status_{status} action fired with an empty status.
* Templates updated (0.5.0): loop/product-card.php, quick-view.php, units/cart-panel.php, units/checkout-summary.php. New: units/coupon-form.php.

= 0.4.1 =
* Fix: no translations are loaded before init (WordPress 6.7+ "_load_textdomain_just_in_time was called incorrectly" notice when Sidcraft Page Builder was missing or inactive).
* Admin: "Add New Product" menu label; order screen shows country, state and payment method names; sale prices in the Products list no longer show screen-reader text.

= 0.4.0 =
* Tax by country and state: prices entered including or excluding tax, a rate table (country, optional state, rate, label), optional tax on shipping. Tax shows as its own line (or "Includes … tax") in the cart, checkout, confirmation, emails and admin, and is sent to the payment provider.
* Shipping zones: a flat rate per group of countries, a * zone for everywhere else, free shipping over an amount (store-wide or per zone). Countries outside every zone can't check out and aren't offered.
* Checkout: country and state are lists; shipping and tax update as soon as they change. "Add $X more for free shipping" in the cart.
* Templates updated (0.4.0): cart-panel.php, checkout.php, checkout-summary.php, order-confirmation.php, emails/order.php.
* Coding standards: passes WordPress Coding Standards (PHPCS) and Plugin Check.

= 0.3.3 =
* Readme text updates.

= 0.3.2 =
* Internal detection changes.

= 0.3.1 =
* Sidcraft integration wired to Sidcraft Page Builder's real extension API (tested with 0.15.4): Units register on sidcraft_page_builder_units_register, appear in the editor under Commerce, preview live on the editor canvas, and are excluded from Sidcraft's fragment cache.
* Fixed: Sidcraft Page Builder is detected by SIDCRAFT_PAGE_BUILDER_VERSION plus its own requirements check (0.15.0+ required).
* Fixed: Sidcraft layouts are read from _sidsyn_document_data (assets now load in <head> on Sidcraft-built pages).
* Fixed: product URLs could 404 after Sidcraft Page Builder was reactivated or updated; permalinks now repair themselves.

= 0.3.0 =
* Payments: PayPal (Orders v2, verified webhooks).

= 0.2.0 =
* Standalone store engine: no WooCommerce dependency. Products, categories, reviews, cart sessions, checkout, orders, emails, settings.
* Stripe Checkout gateway with signed webhooks and a return-page fallback. Offline payment gateway.
* New Units: Cart, Checkout, Order Confirmation. Cart/Checkout layouts adapt to the space they're given.

= 0.1.0 =
* First build (WooCommerce-based, replaced by 0.2.0).

== Upgrade Notice ==

= 1.9.0 =
Adds per-combination variants: a grid of option combinations, each with its own SKU, price, sale price, stock and image. Template changed: units/item-options.php; compare theme overrides of it.

= 1.8.0 =
Adds choice SKUs and images, upsells and cross-sells, product tags, coupon exclusions, external products, category images, tax classes and per-item shipping. Fixes items with options staying in the cart after checkout. Templates changed: units/item-options.php, units/add-to-cart.php, units/cart-panel.php, loop/product-card.php, quick-view.php; new units/product-categories.php. Compare theme overrides of those templates.

= 1.7.0 =
Adds Store → Settings → Modules to turn off Events, Subscriptions, Gift cards, Coupons, Reward points and Affiliates. Everything stays on as before. Templates changed: units/coupon-form.php and units/gift-card-redeem.php (hidden when their module is off); compare theme overrides of those templates.

= 1.6.0 =
Adds subscriptions (repeat billing) and a My subscriptions page. Templates changed: units/checkout.php (free-trial card choice), units/order-confirmation.php (pay a renewal), units/cart-panel.php, units/checkout-summary.php, emails/order.php; new units/subscriptions.php and emails/subscription.php. Compare theme overrides of those templates.

= 1.5.0 =
Adds several currencies, WPML / Polylang translations and Multisite stores. Templates changed: units/checkout.php (payment notice per currency) and the new units/currency-switcher.php; compare theme overrides of units/checkout.php.

= 1.2.0 =
Adds event management and ticketing with QR check-in, PDF tickets and calendar sync.

= 1.1.0 =
Adds digital downloads and virtual products. If your theme overrides emails/order.php or units/order-confirmation.php, compare them with this version so download links appear.

= 1.0.9 =
Reserves stock at order time, adds partial refunds, disputes and catalog export, and links guest orders to accounts. If your theme overrides units/checkout.php, compare it with this version's copy.

= 0.9.0 =
Adds item options (size, extras, instructions) and add-on support for order statuses. If your theme overrides templates, compare them with the updated ones in the changelog.

= 0.8.0 =
Add New Product now opens Sidcraft Page Builder with a Product data panel for price and stock. Turn this off under Store → Settings to keep the WordPress editor.

= 0.7.4 =
Plugin Check fixes for the order screen, and adding a shipment with "Mark the order Completed" now keeps the order Completed.

= 0.7.3 =
Adds gift cards. If you override templates in your theme, compare them with the updated templates listed in the changelog.

= 0.7.1 =
If you use Sidcraft Builder Pro, update Pro to 0.13.3 or newer before updating Sidcraft Commerce.
