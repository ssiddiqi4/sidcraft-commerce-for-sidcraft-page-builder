<?php
/**
 * My Subscriptions Unit: the logged-in customer's subscriptions, with their
 * next payment and Cancel / Keep subscription buttons.
 * Shortcode fallback: [sidccofo_subscriptions]
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Units;

use SidcraftCommerce\Abstracts\Unit;
use SidcraftCommerce\Data\Subscription;

defined( 'ABSPATH' ) || exit;

/**
 * Class Subscriptions
 */
class Subscriptions extends Unit {

	/** {@inheritDoc} */
	public function get_name() {
		return 'sidccofo-subscriptions';
	}

	/** {@inheritDoc} */
	public function get_title() {
		return __( 'My Subscriptions', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return 'update';
	}

	/** {@inheritDoc} */
	public function get_keywords() {
		return array( 'subscriptions', 'recurring', 'renewal', 'membership', 'account' );
	}

	/** {@inheritDoc} */
	public function get_assets() {
		return array(
			'styles'  => array( 'sidccofo-commerce', 'sidccofo-checkout' ),
			'scripts' => array(),
		);
	}

	/** {@inheritDoc} */
	protected function controls() {
		return array(
			'heading' => array(
				'type'    => 'text',
				'label'   => __( 'Heading', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => __( 'My subscriptions', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $settings Settings.
	 */
	protected function render_html( array $settings ) {
		$heading = trim( (string) ( $settings['heading'] ?? '' ) );
		if ( ! is_user_logged_in() ) {
			$here = get_permalink();
			return '<section class="sc-subscriptions">' . ( '' !== $heading ? '<h3 class="sc-subscriptions__title">' . esc_html( $heading ) . '</h3>' : '' )
				. '<p class="sc-empty">' . esc_html__( 'Log in to see your subscriptions.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . ' <a href="' . esc_url( wp_login_url( $here ? $here : home_url( '/' ) ) ) . '">' . esc_html__( 'Log in', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a></p></section>';
		}
		return $this->template(
			'units/subscriptions.php',
			array(
				'subscriptions' => Subscription::for_customer( get_current_user_id() ),
				'heading'       => $heading,
			)
		);
	}
}
