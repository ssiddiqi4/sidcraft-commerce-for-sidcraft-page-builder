<?php
/**
 * Uninstall: drop the cart-session, email-log, remembered-cart and live chat
 * tables, cached queries, cron and settings.
 *
 * Products, categories, orders, coupons, events, tickets and the store pages are site content and are
 * kept, so reinstalling restores the store. Delete them from the admin (or
 * define SIDCRAFT_COMMERCE_REMOVE_ALL_DATA as true in wp-config.php first to
 * remove them here too).
 *
 * @package SidcraftCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the add-on's own data.
 */
function sidcraft_commerce_uninstall() {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-off cleanup of our own data.
	foreach ( array( 'sessions', 'email_log', 'abandoned', 'chats', 'chat_messages' ) as $table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'sidccofo_' . $table ) );
	}
	// Every transient this plugin sets starts with sidccofo_ (product grid cache, PayPal token, notices, review rate limit).
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_sidccofo_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_sidccofo_' ) . '%'
		)
	);

	foreach ( array( 'sidcraft_commerce_settings', 'sidcraft_commerce_db_version', 'sidcraft_commerce_query_version', 'sidcraft_commerce_flush_rewrites', 'sidcraft_commerce_version', 'sidcraft_commerce_downloads_protected', 'sidcraft_commerce_events_slug', 'sidcraft_commerce_events_defaults', 'sidcraft_commerce_winback_day', 'sidcraft_commerce_carts_recovered_clicks', 'sidcraft_commerce_groups_hash' ) as $option ) {
		delete_option( $option );
	}
	foreach ( array( 'sidcraft_commerce_cleanup', 'sidcraft_commerce_marketing_batch', 'sidcraft_commerce_marketing_followup' ) as $hook ) {
		wp_unschedule_hook( $hook );
	}

	if ( defined( 'SIDCRAFT_COMMERCE_REMOVE_ALL_DATA' ) && SIDCRAFT_COMMERCE_REMOVE_ALL_DATA ) {
		// The mailing list is the store's own record of who agreed to emails: kept unless everything goes.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'sidccofo_subscribers' ) );
		foreach ( array( 'sidccofo_product', 'sidccofo_order', 'sidccofo_coupon', 'sidccofo_giftcard', 'sidccofo_sub', 'sidccofo_event', 'sidccofo_ticket', 'sidccofo_campaign' ) as $type ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $type ) );
			foreach ( $ids as $id ) {
				wp_delete_post( (int) $id, true );
			}
		}
		foreach ( array( 'sidccofo_product_cat', 'sidccofo_brand', 'sidccofo_product_tag', 'sidccofo_event_cat' ) as $taxonomy ) {
			$terms = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $taxonomy ) );
			foreach ( $terms as $term_id ) {
				wp_delete_term( (int) $term_id, $taxonomy );
			}
		}
		// Attendee uploads.
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'sidccofo-attendee-files';
		if ( is_dir( $dir ) ) {
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $files as $file ) {
				$file->isDir() ? rmdir( $file->getPathname() ) : wp_delete_file( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			}
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		foreach ( (array) get_option( 'sidcraft_commerce_pages', array() ) as $page_id ) {
			wp_delete_post( (int) $page_id, true );
		}
		delete_option( 'sidcraft_commerce_pages' );
	}
	// phpcs:enable
}

if ( is_multisite() ) {
	// Every store of the network (each keeps its own table and settings).
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $sidcraft_commerce_blog ) {
		switch_to_blog( (int) $sidcraft_commerce_blog );
		sidcraft_commerce_uninstall();
		restore_current_blog();
	}
	delete_site_option( 'sidcraft_commerce_network_copy' );
} else {
	sidcraft_commerce_uninstall();
}
