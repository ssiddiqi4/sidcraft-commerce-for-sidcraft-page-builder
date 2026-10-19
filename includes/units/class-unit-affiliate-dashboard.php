<?php
/**
 * Affiliate Dashboard Unit: join the affiliate program, then see the
 * referral link, commissions and payouts.
 *
 * Shortcode fallback: [sidccofo_affiliate_dashboard]
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Units;

use SidcraftCommerce\Abstracts\Unit;
use SidcraftCommerce\Affiliates;

defined( 'ABSPATH' ) || exit;

/**
 * Class Affiliate_Dashboard
 */
class Affiliate_Dashboard extends Unit {

	/** {@inheritDoc} */
	public function get_name() {
		return 'sidccofo-affiliate-dashboard';
	}

	/** {@inheritDoc} */
	public function get_title() {
		return __( 'Affiliate Dashboard', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return 'share';
	}

	/** {@inheritDoc} */
	protected function controls() {
		return array(
			'intro' => array(
				'type'    => 'text',
				'label'   => __( 'Text for people who haven’t joined', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => __( 'Share your link and earn a commission on every order it brings in.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $settings Settings.
	 */
	protected function render_html( array $settings ) {
		if ( ! Affiliates::enabled() ) {
			return current_user_can( 'manage_options' ) ? '<p class="sc-empty">' . esc_html__( 'Turn affiliates on under Store → Settings → Modules.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>' : '';
		}
		$user = get_current_user_id();
		return $this->template(
			'units/affiliate-dashboard.php',
			array(
				'settings' => $settings,
				'user_id'  => $user,
				'status'   => $user ? Affiliates::status( $user ) : '',
			)
		);
	}
}
