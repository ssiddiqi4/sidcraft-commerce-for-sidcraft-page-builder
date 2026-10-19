<?php
/**
 * Internal Unit registry. Holds every commerce Unit and exposes each one as a
 * shortcode, so Units work in Sidcraft (via the bridge), in classic content,
 * in theme templates (do_shortcode) and in automated tests.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

use SidcraftCommerce\Abstracts\Unit;

defined( 'ABSPATH' ) || exit;

/**
 * Class Unit_Registry
 */
class Unit_Registry {

	/**
	 * Registered Units keyed by name.
	 *
	 * @var Unit[]
	 */
	private $units = array();

	/**
	 * Register a Unit.
	 *
	 * @param Unit $unit Unit instance.
	 * @return bool False if the name is taken.
	 */
	public function register( Unit $unit ) {
		$name = $unit->get_name();
		if ( isset( $this->units[ $name ] ) ) {
			_doing_it_wrong( __METHOD__, esc_html( "Unit {$name} is already registered." ), '0.1.0' );
			return false;
		}

		$this->units[ $name ] = $unit;

		// Shortcode fallback: sc-product-grid → [sidccofo_product_grid].
		add_shortcode(
			str_replace( '-', '_', $name ),
			static function ( $atts ) use ( $unit ) {
				return $unit->render( (array) $atts );
			}
		);

		/**
		 * Fires when a Unit is registered.
		 *
		 * @param Unit          $unit     Unit.
		 * @param Unit_Registry $registry Registry.
		 */
		do_action( 'sidcraft_commerce_unit_registered', $unit, $this );
		return true;
	}

	/**
	 * Remove a Unit.
	 *
	 * @param string $name Unit name.
	 */
	public function unregister( $name ) {
		unset( $this->units[ $name ] );
		remove_shortcode( str_replace( '-', '_', $name ) );
	}

	/**
	 * Get one Unit.
	 *
	 * @param string $name Unit name.
	 * @return Unit|null
	 */
	public function get( $name ) {
		return $this->units[ $name ] ?? null;
	}

	/**
	 * All Units.
	 *
	 * @return Unit[]
	 */
	public function all() {
		return $this->units;
	}
}
