<?php
/**
 * Dependency check. The only requirement is Sidcraft Page Builder.
 *
 * Not namespaced and free of Sidcraft calls so it runs safely before the
 * builder is known to exist.
 *
 * @package SidcraftCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sidcraft_Commerce_Dependencies
 */
final class Sidcraft_Commerce_Dependencies {

	/** Oldest Sidcraft Page Builder with the extension hooks this add-on uses. */
	const MIN_SIDCRAFT = '0.15.0';

	/**
	 * Unmet requirements, as codes. Runs on plugins_loaded, so it must not
	 * translate anything (WordPress 6.7+ warns about translations loaded
	 * before init); messages() turns the codes into text later.
	 *
	 * @return string[] Empty when everything is in place.
	 */
	public static function missing() {
		$missing = array();
		if ( ! self::is_sidcraft_active() ) {
			$missing[] = 'sidcraft';
		} elseif ( version_compare( SIDCRAFT_PAGE_BUILDER_VERSION, self::MIN_SIDCRAFT, '<' ) ) {
			$missing[] = 'sidcraft_version';
		}
		return $missing;
	}

	/**
	 * Human-readable reasons for the admin notice (call at admin_notices or
	 * later).
	 *
	 * @param string[] $codes Codes from missing().
	 * @return string[]
	 */
	public static function messages( array $codes ) {
		$messages = array();
		foreach ( $codes as $code ) {
			if ( 'sidcraft' === $code ) {
				$messages[] = __( 'Sidcraft Page Builder must be installed and active.', 'sidcraft-commerce-for-sidcraft-page-builder' );
			} elseif ( 'sidcraft_version' === $code ) {
				$messages[] = sprintf(
					/* translators: 1: required version, 2: installed version. */
					__( 'Sidcraft Page Builder %1$s or newer is required (you have %2$s).', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					self::MIN_SIDCRAFT,
					defined( 'SIDCRAFT_PAGE_BUILDER_VERSION' ) ? SIDCRAFT_PAGE_BUILDER_VERSION : '?'
				);
			}
		}

		/**
		 * Filter the list of unmet requirements shown in the admin notice.
		 *
		 * @param string[] $messages Reasons the add-on cannot load.
		 * @param string[] $codes    Requirement codes.
		 */
		return (array) apply_filters( 'sidcraft_commerce_missing_dependencies', $messages, $codes );
	}

	/**
	 * Detect Sidcraft Page Builder (free, "Lite").
	 *
	 * Sidcraft defines SIDCRAFT_PAGE_BUILDER_VERSION before its own PHP/WordPress
	 * version check, so the constant alone can be present while the builder has
	 * stopped itself. sidcraft_page_builder_requirements_met() tells the two apart.
	 *
	 * @return bool
	 */
	public static function is_sidcraft_active() {
		$active = defined( 'SIDCRAFT_PAGE_BUILDER_VERSION' )
			&& ( ! function_exists( 'sidcraft_page_builder_requirements_met' ) || sidcraft_page_builder_requirements_met() );

		/**
		 * Override Sidcraft detection (tests, white-label builds).
		 *
		 * @param bool $active Whether Sidcraft Page Builder is active.
		 */
		return (bool) apply_filters( 'sidcraft_commerce_is_builder_active', $active );
	}

	/**
	 * Queue an admin notice listing the unmet requirements.
	 *
	 * @param string[] $missing Codes from missing().
	 */
	public static function register_notice( array $missing ) {
		add_action(
			'admin_notices',
			static function () use ( $missing ) {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				echo '<div class="notice notice-error"><p><strong>';
				esc_html_e( 'Sidcraft Commerce is inactive.', 'sidcraft-commerce-for-sidcraft-page-builder' );
				echo '</strong></p><ul style="list-style:disc;padding-left:1.5em">';
				foreach ( self::messages( $missing ) as $reason ) {
					echo '<li>' . esc_html( $reason ) . '</li>';
				}
				echo '</ul></div>';
			}
		);
	}
}
