<?php
/**
 * Optional modules a store can switch off under Store → Settings → Modules:
 * Events, Subscriptions, Gift cards, Coupons, Reward points, Affiliates,
 * Email marketing and Live chat.
 *
 * A module that is off loses its menus, settings, Units, blocks and cart and
 * checkout fields, and its products (event tickets, subscription and gift
 * card products) can't be bought. Nothing is deleted: switching it back on
 * brings everything back as it was. Orders placed while a module was on keep
 * being handled (gift cards bought are still issued, tickets sold still
 * open), and Subscriptions can't be switched off while subscriptions are
 * still running.
 *
 * Loaded from the main plugin file, before Sidcraft Commerce boots, so it
 * reads the option directly and needs nothing else.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Class Modules
 */
class Modules {

	/**
	 * Module id => [ setting key, on by default ].
	 *
	 * Events, Subscriptions, Gift cards and Coupons were always on before
	 * they could be switched off, so they default to on; the two programs
	 * keep their existing settings, which default to off.
	 *
	 * @return array
	 */
	public static function keys() {
		return array(
			'events'        => array( 'module_events', 1 ),
			'subscriptions' => array( 'module_subscriptions', 1 ),
			'giftcards'     => array( 'module_giftcards', 1 ),
			'coupons'       => array( 'module_coupons', 1 ),
			'rewards'       => array( 'rewards_enabled', 0 ),
			'affiliates'    => array( 'affiliates_enabled', 0 ),
			'marketing'     => array( 'module_marketing', 1 ),
			'livechat'      => array( 'module_livechat', 0 ),
		);
	}

	/**
	 * Is a module on?
	 *
	 * @param string $id Module id (see keys()).
	 * @return bool
	 */
	public static function enabled( $id ) {
		$keys = self::keys();
		if ( ! isset( $keys[ $id ] ) ) {
			return true;
		}
		list( $key, $default ) = $keys[ $id ];
		if ( function_exists( __NAMESPACE__ . '\setting' ) ) {
			$on = setting( $key, $default );
		} else {
			$settings = (array) get_option( 'sidcraft_commerce_settings', array() );
			$on       = array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
		}

		/**
		 * Filter whether a module is on.
		 *
		 * @param bool   $on Whether it is on.
		 * @param string $id Module id: events, subscriptions, giftcards, coupons, rewards, affiliates.
		 */
		return (bool) apply_filters( 'sidcraft_commerce_module_enabled', (bool) $on, $id );
	}

	/**
	 * Default for a module's setting key, or null when the key isn't one.
	 *
	 * @param string $key Setting key.
	 * @return int|null
	 */
	public static function default_for( $key ) {
		foreach ( self::keys() as $module ) {
			if ( $module[0] === $key ) {
				return $module[1];
			}
		}
		return null;
	}

	/**
	 * Settings fields for the Modules tab: setting key => [ label, 'checkbox', help ].
	 *
	 * @return array
	 */
	public static function settings_fields() {
		$labels = array(
			'events'        => array( __( 'Events', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Sell tickets to events, with calendars, attendee lists and check-in. When off, tickets already sold still open, but events can’t be seen or booked.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'subscriptions' => array( __( 'Subscriptions', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Sell products that renew every week, month or year. Can only be turned off once no subscription is waiting for payment, active, on hold or cancelled at period end.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'giftcards'     => array( __( 'Gift cards', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Sell and redeem gift cards. When off, customers can’t use the cards they hold, so check your local rules before turning this off.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'coupons'       => array( __( 'Coupons', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Discount codes customers enter in the cart or at checkout.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'rewards'       => array( __( 'Reward points', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Customers earn points on paid orders and spend them at checkout. Set it up under Rewards & affiliates.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'affiliates'    => array( __( 'Affiliates', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'Pay commission on orders from visitors who arrive through an affiliate’s link. Set it up under Rewards & affiliates.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'marketing'     => array( __( 'Email marketing', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'A mailing list with signup forms and a checkout opt-in, email campaigns to groups of subscribers, and automatic welcome, abandoned-cart, follow-up and win-back emails. When off, nothing is sent and the list is kept.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'livechat'      => array( __( 'Live chat', 'sidcraft-commerce-for-sidcraft-page-builder' ), __( 'A chat button on your store pages; you answer under Store → Live chat. While nobody has that page open, visitors leave a message that is emailed to you.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
		);
		$fields = array();
		foreach ( self::keys() as $id => $module ) {
			$fields[ $module[0] ] = array( $labels[ $id ][0], 'checkbox', $labels[ $id ][1] );
		}
		return $fields;
	}

	/**
	 * Attach hooks (once Sidcraft Commerce boots).
	 */
	public function hooks() {
		add_filter( 'sidcraft_commerce_is_purchasable', array( $this, 'purchasable' ), 50, 2 );
		add_filter( 'sidcraft_commerce_core_units', array( $this, 'units' ), 50 );
		add_filter( 'sidcraft_commerce_settings_sections', array( $this, 'settings_sections' ), 50 );
		add_filter( 'register_post_type_args', array( $this, 'post_type_args' ), 20, 2 );
		add_filter( 'register_taxonomy_args', array( $this, 'taxonomy_args' ), 20, 2 );
	}

	/**
	 * Products of a module that is off can't be bought.
	 *
	 * @param bool                                $ok      Purchasable.
	 * @param \SidcraftCommerce\Data\Product|null $product Product.
	 * @return bool
	 */
	public function purchasable( $ok, $product ) {
		if ( ! $ok || ! $product instanceof Data\Product ) {
			return $ok;
		}
		if ( $product->is_gift_card() ) {
			return self::enabled( 'giftcards' );
		}
		if ( $product->is_subscription() ) {
			return self::enabled( 'subscriptions' );
		}
		if ( ! self::enabled( 'events' ) && Events\Placeholders::is_placeholder( $product->get_id() ) ) {
			return false; // A ticket type's hidden product.
		}
		return $ok;
	}

	/**
	 * Drop the Units of modules that are off.
	 *
	 * @param array $units Unit instances.
	 * @return array
	 */
	public function units( $units ) {
		$kept = array();
		foreach ( (array) $units as $unit ) {
			$off = ( $unit instanceof Units\Subscriptions && ! self::enabled( 'subscriptions' ) )
				|| ( $unit instanceof Units\Newsletter && ! self::enabled( 'marketing' ) )
				|| ( is_object( $unit ) && 0 === strpos( get_class( $unit ), __NAMESPACE__ . '\\Events\\' ) && ! self::enabled( 'events' ) );
			if ( ! $off ) {
				$kept[] = $unit;
				continue;
			}
			// Pages that still hold the Unit's shortcode show nothing rather than the code.
			add_shortcode( str_replace( '-', '_', $unit->get_name() ), '__return_empty_string' );
		}
		return $kept;
	}

	/**
	 * Hide the settings of modules that are off.
	 *
	 * @param array $sections Sections.
	 * @return array
	 */
	public function settings_sections( $sections ) {
		if ( ! self::enabled( 'giftcards' ) ) {
			unset( $sections['giftcards'] );
		}
		if ( ! self::enabled( 'marketing' ) ) {
			unset( $sections['marketing'], $sections['marketing_auto'] );
		}
		if ( ! self::enabled( 'livechat' ) ) {
			unset( $sections['livechat'] );
		}
		return $sections;
	}

	/**
	 * Post type => module.
	 *
	 * @return array
	 */
	protected static function post_types() {
		return array(
			'sidccofo_event'    => 'events',
			'sidccofo_ticket'   => 'events',
			'sidccofo_sub'      => 'subscriptions',
			'sidccofo_giftcard' => 'giftcards',
			'sidccofo_coupon'   => 'coupons',
		);
	}

	/**
	 * Post types of modules that are off stay registered, so their data
	 * stays readable, but leave the menus, the front end and search.
	 *
	 * @param array  $args Post type args.
	 * @param string $type Post type.
	 * @return array
	 */
	public function post_type_args( $args, $type ) {
		$types = self::post_types();
		if ( ! isset( $types[ $type ] ) || self::enabled( $types[ $type ] ) ) {
			return $args;
		}
		return array_merge(
			(array) $args,
			array(
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'rewrite'             => false,
				'query_var'           => false,
			)
		);
	}

	/**
	 * Event categories leave the menus and the front end with Events.
	 *
	 * @param array  $args     Taxonomy args.
	 * @param string $taxonomy Taxonomy.
	 * @return array
	 */
	public function taxonomy_args( $args, $taxonomy ) {
		if ( 'sidccofo_event_cat' !== $taxonomy || self::enabled( 'events' ) ) {
			return $args;
		}
		return array_merge(
			(array) $args,
			array(
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => false,
				'show_tagcloud'      => false,
				'rewrite'            => false,
				'query_var'          => false,
			)
		);
	}

	/**
	 * Subscriptions still running (they renew, start or end on their own):
	 * waiting for the first payment, active, payment due, or cancelled at the
	 * end of a paid period. Unpaid ones end when their order is cancelled.
	 *
	 * @return bool
	 */
	public static function has_live_subscriptions() {
		return (bool) Data\Subscription::find( array( 'pending', 'active', 'on-hold', 'pending-cancel' ), '', 0, 1 );
	}
}
