<?php
/**
 * Base class of the optional store tools.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Extras;

use SidcraftCommerce\Data\Post_Types;
use function SidcraftCommerce\manage_cap;

defined( 'ABSPATH' ) || exit;

/**
 * Class Tool
 */
abstract class Tool {

	/**
	 * Attach hooks.
	 */
	abstract public function hooks();

	/**
	 * Add a settings section on the Store tools tab.
	 *
	 * @param string $id     Section id.
	 * @param string $title  Heading.
	 * @param array  $fields key => [ label, type, help, columns ].
	 * @param string $intro  Intro text.
	 */
	protected function settings( $id, $title, array $fields, $intro = '' ) {
		add_filter(
			'sidcraft_commerce_settings_sections',
			static function ( $sections ) use ( $id, $title, $fields, $intro ) {
				$sections[ 'tool_' . $id ] = array(
					'title'  => $title,
					'intro'  => $intro,
					'tab'    => 'tools',
					'fields' => $fields,
				);
				return $sections;
			}
		);
	}

	/**
	 * Add a submenu page under Store.
	 *
	 * @param string   $slug     Page slug (sidccofo-…).
	 * @param string   $title    Title.
	 * @param callable $callback Render callback.
	 * @param string   $cap      Capability (default: store manager).
	 */
	protected function admin_page( $slug, $title, $callback, $cap = '' ) {
		add_action(
			'admin_menu',
			static function () use ( $slug, $title, $callback, $cap ) {
				add_submenu_page( 'edit.php?post_type=' . Post_Types::PRODUCT, $title, $title, '' !== $cap ? $cap : manage_cap(), $slug, $callback );
			},
			30
		);
	}

	/**
	 * URL of an admin page of this plugin.
	 *
	 * @param string $slug Slug.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	protected function page_url( $slug, array $args = array() ) {
		return add_query_arg( array_merge( array( 'post_type' => Post_Types::PRODUCT, 'page' => $slug ), $args ), admin_url( 'edit.php' ) );
	}
}
