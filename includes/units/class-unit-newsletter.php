<?php
/**
 * Newsletter Unit: a signup form for the store's mailing list.
 *
 * Shortcode fallback: [sidccofo_newsletter title="…" button="…" tags="vip"]
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Units;

use SidcraftCommerce\Abstracts\Unit;

defined( 'ABSPATH' ) || exit;

/**
 * Class Newsletter
 */
class Newsletter extends Unit {

	/** {@inheritDoc} */
	public function get_name() {
		return 'sidccofo-newsletter';
	}

	/** {@inheritDoc} */
	public function get_title() {
		return __( 'Newsletter Signup', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return 'email';
	}

	/** {@inheritDoc} */
	public function get_assets() {
		return array(
			'styles'  => array( 'sidccofo-commerce' ),
			'scripts' => array( 'sidccofo-newsletter' ),
		);
	}

	/** {@inheritDoc} */
	protected function controls() {
		return array(
			'title'      => array(
				'type'    => 'text',
				'label'   => __( 'Title', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => __( 'Get our news and offers', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			),
			'text'       => array(
				'type'    => 'text',
				'label'   => __( 'Text', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => __( 'New products and special offers, straight to your inbox.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			),
			'button'     => array(
				'type'    => 'text',
				'label'   => __( 'Button text', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => __( 'Subscribe', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			),
			'show_name'  => array(
				'type'    => 'switch',
				'label'   => __( 'Ask for a first name', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => true,
			),
			'tags'       => array(
				'type'    => 'text',
				'label'   => __( 'Tag new subscribers (comma-separated)', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => '',
			),
			'consent'    => array(
				'type'    => 'text',
				'label'   => __( 'Consent text', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => __( 'I agree to receive emails and can unsubscribe at any time.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $settings Settings.
	 */
	protected function render_html( array $settings ) {
		return $this->template( 'units/newsletter.php', array( 'settings' => $settings ) );
	}
}
