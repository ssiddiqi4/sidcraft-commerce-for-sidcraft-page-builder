<?php
/**
 * A Unit made from a name, a control list and a render callback, so the
 * store tools can offer Sidcraft Page Builder Units (and [shortcodes]) with
 * little code.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Abstracts\Unit;

defined( 'ABSPATH' ) || exit;

/**
 * Class Simple_Unit
 */
class Simple_Unit extends Unit {

	/**
	 * Machine name.
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * Title.
	 *
	 * @var string
	 */
	protected $title;

	/**
	 * Controls.
	 *
	 * @var array
	 */
	protected $schema;

	/**
	 * Render callback (array $settings): string.
	 *
	 * @var callable
	 */
	protected $callback;

	/**
	 * Icon.
	 *
	 * @var string
	 */
	protected $icon;

	/**
	 * Script handles the Unit needs.
	 *
	 * @var string[]
	 */
	protected $scripts;

	/**
	 * Constructor.
	 *
	 * @param string   $name     Unit name (sidccofo-…).
	 * @param string   $title    Title.
	 * @param array    $controls Controls.
	 * @param callable $callback Renderer.
	 * @param string   $icon     Icon.
	 * @param string[] $scripts  Script handles.
	 */
	public function __construct( $name, $title, array $controls, $callback, $icon = 'cart', array $scripts = array( 'sidccofo-cart', 'sidccofo-extras' ) ) {
		$this->name     = $name;
		$this->title    = $title;
		$this->schema   = $controls;
		$this->callback = $callback;
		$this->icon     = $icon;
		$this->scripts  = $scripts;
	}

	/** {@inheritDoc} */
	public function get_name() {
		return $this->name;
	}

	/** {@inheritDoc} */
	public function get_title() {
		return $this->title;
	}

	/** {@inheritDoc} */
	public function get_icon() {
		return $this->icon;
	}

	/** {@inheritDoc} */
	protected function controls() {
		return $this->schema;
	}

	/** {@inheritDoc} */
	public function get_assets() {
		Extras::register_assets();
		return array(
			'styles'  => array( 'sidccofo-commerce', 'sidccofo-extras' ),
			'scripts' => $this->scripts,
		);
	}

	/** {@inheritDoc} */
	protected function render_html( array $settings ) {
		return (string) call_user_func( $this->callback, $settings );
	}

	/**
	 * Register a Unit with the plugin (and its shortcode).
	 *
	 * @param string   $name     Name.
	 * @param string   $title    Title.
	 * @param array    $controls Controls.
	 * @param callable $callback Renderer.
	 * @param string   $icon     Icon.
	 */
	public static function add( $name, $title, array $controls, $callback, $icon = 'cart' ) {
		add_filter(
			'sidcraft_commerce_core_units',
			static function ( $units ) use ( $name, $title, $controls, $callback, $icon ) {
				$units[] = new self( $name, $title, $controls, $callback, $icon );
				return $units;
			}
		);
	}
}
