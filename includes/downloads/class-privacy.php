<?php
/**
 * Privacy: the download log in WordPress's personal data export and erase
 * tools (Tools → Export / Erase Personal Data), and a note for the privacy
 * policy guide.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Downloads;

use SidcraftCommerce\Data\Post_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Class Privacy
 */
class Privacy {

	/** Orders handled per page of an export or erase. */
	const PER_PAGE = 20;

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['sidccofo-downloads'] = array(
			'exporter_friendly_name' => __( 'Store downloads', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['sidccofo-downloads'] = array(
			'eraser_friendly_name' => __( 'Store download log', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Orders placed with an email that have a download log.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return int[]
	 */
	protected static function order_ids( $email, $page ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Post_Types::ORDER,
					'post_status'    => 'any',
					'posts_per_page' => self::PER_PAGE,
					'paged'          => max( 1, (int) $page ),
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Privacy request, paged.
						array(
							'key'   => '_sidccofo_billing_email',
							'value' => strtolower( (string) $email ),
						),
						array(
							'key'     => Downloads::LOG,
							'compare' => 'EXISTS',
						),
					),
				)
			)
		);
	}

	/**
	 * Export the download log.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public function export( $email, $page = 1 ) {
		$ids  = self::order_ids( $email, $page );
		$data = array();
		foreach ( $ids as $order_id ) {
			$perms = Downloads::permissions( $order_id );
			foreach ( Downloads::get_log( $order_id ) as $i => $entry ) {
				$file   = isset( $perms[ $entry['key'] ] ) ? (string) $perms[ $entry['key'] ]['name'] : (string) $entry['key'];
				$fields = array(
					array(
						'name'  => __( 'Order', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'value' => '#' . $order_id,
					),
					array(
						'name'  => __( 'File', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'value' => $file,
					),
					array(
						'name'  => __( 'Downloaded', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'value' => wp_date( 'Y-m-d H:i:s', (int) $entry['time'] ),
					),
				);
				if ( ! empty( $entry['ip'] ) ) {
					$fields[] = array(
						'name'  => __( 'IP address', 'sidcraft-commerce-for-sidcraft-page-builder' ),
						'value' => (string) $entry['ip'],
					);
				}
				$data[] = array(
					'group_id'    => 'sidccofo-downloads',
					'group_label' => __( 'Downloads', 'sidcraft-commerce-for-sidcraft-page-builder' ),
					'item_id'     => 'sidccofo-download-' . $order_id . '-' . $i,
					'data'        => $fields,
				);
			}
		}
		return array(
			'data' => $data,
			'done' => count( $ids ) < self::PER_PAGE,
		);
	}

	/**
	 * Erase IP addresses from the download log (the log itself is kept as
	 * the record of what was delivered for the order).
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public function erase( $email, $page = 1 ) {
		$ids     = self::order_ids( $email, $page );
		$removed = false;
		foreach ( $ids as $order_id ) {
			$log     = get_post_meta( $order_id, Downloads::LOG, true );
			$changed = false;
			foreach ( is_array( $log ) ? $log : array() as $i => $entry ) {
				if ( is_array( $entry ) && ! empty( $entry['ip'] ) ) {
					$log[ $i ]['ip'] = '';
					$changed         = true;
				}
			}
			if ( $changed ) {
				update_post_meta( $order_id, Downloads::LOG, $log );
				$removed = true;
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => (bool) $ids,
			'messages'       => $ids ? array( __( 'The download history of orders is kept as a record of the purchase; IP addresses were removed.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ) : array(),
			'done'           => count( $ids ) < self::PER_PAGE,
		);
	}

	/**
	 * Suggested privacy policy text.
	 */
	public function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'Sidcraft Commerce', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'<p>' . esc_html__( 'When you buy a digital download, we record when each file is downloaded so we can apply download limits and help with problems. If the store has switched it on, the IP address of the download is recorded too. This history is kept with your order.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>'
		);
	}
}
