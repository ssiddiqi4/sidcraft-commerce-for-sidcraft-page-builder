<?php
/**
 * My Downloads Unit: the logged-in customer's files from all their orders.
 *
 * Shortcode fallback: [sidccofo_downloads] or [sidccofo_downloads show_expired="1"]
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Units;

use SidcraftCommerce\Abstracts\Unit;
use SidcraftCommerce\Downloads\Downloads as Store_Downloads;

defined( 'ABSPATH' ) || exit;

/**
 * Class Downloads
 */
class Downloads extends Unit {

	/** {@inheritDoc} */
	public function get_name() {
		return 'sidccofo-downloads';
	}

	/** {@inheritDoc} */
	public function get_title() {
		return __( 'My Downloads', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return 'download';
	}

	/** {@inheritDoc} */
	public function get_keywords() {
		return array( 'downloads', 'digital', 'files', 'account', 'orders' );
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
			'heading'      => array(
				'type'    => 'text',
				'label'   => __( 'Heading', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => __( 'My downloads', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			),
			'show_expired' => array(
				'type'    => 'switch',
				'label'   => __( 'Also list used-up and expired files', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => false,
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
			return '<section class="sc-downloads">' . ( '' !== $heading ? '<h3 class="sc-downloads__title">' . esc_html( $heading ) . '</h3>' : '' )
				. '<p class="sc-empty">' . esc_html__( 'Log in to see your downloads.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . ' <a href="' . esc_url( wp_login_url( $here ? $here : home_url( '/' ) ) ) . '">' . esc_html__( 'Log in', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a></p></section>';
		}

		$rows = array();
		foreach ( Store_Downloads::customer_orders( get_current_user_id() ) as $order ) {
			$rows = array_merge( $rows, Store_Downloads::rows( $order, ! empty( $settings['show_expired'] ) ) );
		}
		return $this->template(
			'units/downloads.php',
			array(
				'rows'       => $rows,
				'show_order' => true,
				'heading'    => $heading,
				'empty'      => __( 'You have no downloads yet.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			)
		);
	}
}
