<?php
/**
 * Currency Switcher Unit: lets shoppers pick the currency they see and pay in.
 *
 * Shortcode fallback: [sidccofo_currency_switcher style="links"]
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Units;

use SidcraftCommerce\Abstracts\Unit;
use SidcraftCommerce\Currencies;

defined( 'ABSPATH' ) || exit;

/**
 * Class Currency_Switcher
 */
class Currency_Switcher extends Unit {

	/** {@inheritDoc} */
	public function get_name() {
		return 'sidccofo-currency-switcher';
	}

	/** {@inheritDoc} */
	public function get_title() {
		return __( 'Currency Switcher', 'sidcraft-commerce-for-sidcraft-page-builder' );
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return 'globe';
	}

	/** {@inheritDoc} */
	protected function controls() {
		return array(
			'style' => array(
				'type'    => 'select',
				'label'   => __( 'Style', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => 'select',
				'options' => array(
					'select' => __( 'Drop-down', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'links'  => __( 'Links', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				),
			),
			'label' => array(
				'type'    => 'text',
				'label'   => __( 'Label', 'sidcraft-commerce-for-sidcraft-page-builder' ),
				'section' => 'content',
				'default' => __( 'Currency', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $settings Settings.
	 */
	protected function render_html( array $settings ) {
		if ( ! Currencies::is_multi() ) {
			return '';
		}
		return $this->template(
			'units/currency-switcher.php',
			array(
				'settings' => $settings,
				'codes'    => Currencies::codes(),
				'active'   => Currencies::active(),
			)
		);
	}
}
