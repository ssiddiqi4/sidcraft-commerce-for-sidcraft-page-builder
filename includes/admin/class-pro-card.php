<?php
/**
 * "More payment gateways in Sidcraft Builder Pro" card, shown at the top of
 * Store → Settings → Payments and on the Store's main screen (the Products
 * list, dismissible). Hidden while Sidcraft Builder Pro's gateways are active.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Admin;

use SidcraftCommerce\Checkout\Checkout;
use SidcraftCommerce\Data\Post_Types;
use function SidcraftCommerce\manage_cap;
use function SidcraftCommerce\setting;

defined( 'ABSPATH' ) || exit;

/**
 * Class Pro_Card
 */
class Pro_Card {

	const URL  = 'https://canvasly.pro';
	const META = 'sidccofo_pro_card_dismissed';

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'admin_notices', array( $this, 'dashboard' ) );
		add_action( 'admin_post_sidccofo_dismiss_pro_card', array( $this, 'dismiss' ) );
	}

	/**
	 * Should the card show at all?
	 *
	 * @return bool
	 */
	public static function enabled() {
		/**
		 * Show the Sidcraft Builder Pro gateways card. Sidcraft Builder Pro
		 * turns it off while its gateways are active.
		 *
		 * @param bool $show Show.
		 */
		return (bool) apply_filters( 'sidcraft_commerce_show_pro_gateways_card', true ) && current_user_can( manage_cap() );
	}

	/**
	 * Payment methods this store had turned on before they moved to Sidcraft
	 * Builder Pro (Sidcraft Commerce 1.3.0) and that are not available now.
	 *
	 * @return string[] Names.
	 */
	public static function moved_and_missing() {
		$moved = array(
			'paypal'          => __( 'PayPal Commerce (Pay Later, Venmo)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'stripe'          => __( 'Stripe', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'stripe_klarna'   => __( 'Klarna', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'stripe_afterpay' => __( 'Afterpay / Clearpay', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'stripe_affirm'   => __( 'Affirm', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'razorpay'        => __( 'Razorpay', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'payu'            => __( 'PayU', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'mercadopago'     => __( 'Mercado Pago', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
		$out = array();
		foreach ( $moved as $id => $name ) {
			if ( Checkout::gateway( $id ) ) {
				continue;
			}
			if ( 'paypal' === $id ) {
				// PayPal was on by default once credentials were entered.
				$on = '' !== trim( (string) setting( 'paypal_client_id', '' ) ) && (bool) setting( 'paypal_enabled', 1 );
			} else {
				$on = (bool) setting( $id . '_enabled', 0 );
			}
			if ( $on ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Print the card.
	 *
	 * @param string $context payments | dashboard.
	 */
	public static function render( $context = 'payments' ) {
		if ( ! self::enabled() ) {
			return;
		}
		$missing = self::moved_and_missing();
		?>
		<div class="sc-pro-card<?php echo 'dashboard' === $context ? ' notice' : ''; ?>">
			<div class="sc-pro-card__body">
				<p class="sc-pro-card__title"><?php esc_html_e( 'More payment gateways are available in Sidcraft Builder Pro', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
				<p><?php esc_html_e( 'Sidcraft Commerce includes PayPal Standard. Sidcraft Builder Pro adds PayPal Commerce (Pay Later, Venmo and card buttons), Stripe (cards, Apple Pay, Google Pay, Klarna, Afterpay / Clearpay, Affirm), Razorpay, PayU, Mercado Pago, Square, Mollie, Authorize.net and offline payments.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
				<?php if ( $missing ) : ?>
					<p class="sc-pro-card__warn">
						<?php
						/* translators: %s: payment method names. */
						echo esc_html( sprintf( __( 'Your store had %s turned on. These now come with Sidcraft Builder Pro: your settings are kept and they return as soon as Pro is active. Until then, customers can pay with PayPal Standard.', 'sidcraft-commerce-for-sidcraft-page-builder' ), implode( ', ', $missing ) ) );
						?>
					</p>
				<?php endif; ?>
			</div>
			<p class="sc-pro-card__actions">
				<a class="button button-primary" href="<?php echo esc_url( self::URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get Sidcraft Builder Pro', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
				<?php if ( 'dashboard' === $context ) : ?>
					<a class="sc-pro-card__dismiss" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sidccofo_dismiss_pro_card' ), 'sidccofo_dismiss_pro_card' ) ); ?>"><?php esc_html_e( 'Dismiss', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Card on the Store's main screen (Store → Products). Dismissible, but it
	 * comes back while a payment method the store used is missing.
	 */
	public function dashboard() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . Post_Types::PRODUCT !== $screen->id || ! self::enabled() ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), self::META, true ) && ! self::moved_and_missing() ) {
			return;
		}
		self::render( 'dashboard' );
	}

	/**
	 * Dismiss the Store screen card for this user.
	 */
	public function dismiss() {
		check_admin_referer( 'sidccofo_dismiss_pro_card' );
		if ( current_user_can( manage_cap() ) ) {
			update_user_meta( get_current_user_id(), self::META, 1 );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=' . Post_Types::PRODUCT ) );
		exit;
	}
}
