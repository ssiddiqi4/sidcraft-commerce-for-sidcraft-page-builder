<?php
/**
 * Email marketing admin: Store → Mailing list (subscribers, add, CSV import
 * and export, automation figures) and the campaign screen (subject, segment,
 * test, send now or later, results).
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Admin;

use SidcraftCommerce\Data\Post_Types;
use SidcraftCommerce\Marketing\Abandoned;
use SidcraftCommerce\Marketing\Mailer;
use SidcraftCommerce\Marketing\Marketing;
use SidcraftCommerce\Marketing\Subscribers;
use function SidcraftCommerce\manage_cap;

defined( 'ABSPATH' ) || exit;

/**
 * Class Marketing_Admin
 */
class Marketing_Admin {

	const PAGE = 'sidccofo-marketing';

	/**
	 * Attach hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_sidccofo_subscribers', array( $this, 'handle' ) );
		add_action( 'admin_post_sidccofo_subscribers_export', array( $this, 'export' ) );
		add_action( 'add_meta_boxes_' . Marketing::CAMPAIGN, array( $this, 'meta_boxes' ) );
		add_action( 'save_post_' . Marketing::CAMPAIGN, array( $this, 'save_campaign' ), 10, 2 );
		add_filter( 'manage_' . Marketing::CAMPAIGN . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . Marketing::CAMPAIGN . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
		add_filter( 'post_updated_messages', array( $this, 'messages' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Submenu under Store.
	 */
	public function menu() {
		add_submenu_page(
			'edit.php?post_type=' . Post_Types::PRODUCT,
			__( 'Mailing list', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			__( 'Mailing list', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			manage_cap(),
			self::PAGE,
			array( $this, 'page' )
		);
	}

	/**
	 * Page URL.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	protected static function url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'edit.php?post_type=' . Post_Types::PRODUCT . '&page=' . self::PAGE ) );
	}

	/**
	 * Mailing list page.
	 */
	public function page() {
		if ( ! current_user_can( manage_cap() ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filters and paging only.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$done   = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : '';
		$count  = isset( $_GET['n'] ) ? absint( $_GET['n'] ) : 0;
		// phpcs:enable
		list( $rows, $total ) = Subscribers::query(
			array(
				'status'   => $status,
				'search'   => $search,
				'page'     => $paged,
				'per_page' => 50,
			)
		);
		$counts = Subscribers::counts();
		$labels = Subscribers::status_labels();
		$notes  = array(
			'added'    => __( 'Subscriber saved.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			/* translators: %d: number of addresses. */
			'imported' => sprintf( _n( '%d address imported.', '%d addresses imported.', $count, 'sidcraft-commerce-for-sidcraft-page-builder' ), $count ),
			/* translators: %d: number of subscribers. */
			'bulk'     => sprintf( _n( '%d subscriber updated.', '%d subscribers updated.', $count, 'sidcraft-commerce-for-sidcraft-page-builder' ), $count ),
			'invalid'  => __( 'Enter a valid email address.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'nofile'   => __( 'Choose a CSV file to import.', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
		?>
		<div class="wrap sc-marketing">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Mailing list', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Marketing::CAMPAIGN ) ); ?>"><?php esc_html_e( 'New campaign', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
			<a class="page-title-action" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sidccofo_subscribers_export' ), 'sidccofo_subscribers_export' ) ); ?>"><?php esc_html_e( 'Export CSV', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></a>
			<hr class="wp-header-end">
			<?php if ( isset( $notes[ $done ] ) ) : ?>
				<div class="notice <?php echo in_array( $done, array( 'invalid', 'nofile' ), true ) ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $notes[ $done ] ); ?></p></div>
			<?php endif; ?>

			<?php $this->automation_summary(); ?>

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( self::url() ); ?>" class="<?php echo '' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'All', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( array_sum( $counts ) ) ); ?>)</span></a></li>
				<?php foreach ( $labels as $key => $label ) : ?>
					<li> | <a href="<?php echo esc_url( self::url( array( 'status' => $key ) ) ); ?>" class="<?php echo $key === $status ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $counts[ $key ] ) ); ?>)</span></a></li>
				<?php endforeach; ?>
			</ul>
			<form method="get" class="search-form">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( Post_Types::PRODUCT ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<?php if ( $status ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
				<?php endif; ?>
				<p class="search-box">
					<label class="screen-reader-text" for="sc-sub-search"><?php esc_html_e( 'Search subscribers', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
					<input type="search" id="sc-sub-search" name="s" value="<?php echo esc_attr( $search ); ?>">
					<button class="button"><?php esc_html_e( 'Search', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
				</p>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sidccofo_subscribers">
				<?php wp_nonce_field( 'sidccofo_subscribers' ); ?>
				<div class="tablenav top">
					<div class="alignleft actions bulkactions">
						<label for="sc-bulk" class="screen-reader-text"><?php esc_html_e( 'Bulk action', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label>
						<select name="bulk" id="sc-bulk">
							<option value=""><?php esc_html_e( 'Bulk actions', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></option>
							<option value="unsubscribed"><?php esc_html_e( 'Unsubscribe', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></option>
							<option value="delete"><?php esc_html_e( 'Delete', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></option>
						</select>
						<button class="button" name="do" value="bulk"><?php esc_html_e( 'Apply', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button>
					</div>
					<div class="tablenav-pages"><?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => (int) ceil( $total / 50 ) ) ) ?? '' ); ?></div>
				</div>
				<table class="widefat striped">
					<thead><tr>
						<td class="manage-column column-cb check-column"><input type="checkbox" id="cb-select-all-1" aria-label="<?php esc_attr_e( 'Select all', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>"></td>
						<th><?php esc_html_e( 'Email', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
						<th><?php esc_html_e( 'Name', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
						<th><?php esc_html_e( 'Status', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
						<th><?php esc_html_e( 'Source', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
						<th><?php esc_html_e( 'Tags', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
						<th><?php esc_html_e( 'Orders', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
						<th><?php esc_html_e( 'Spent', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
						<th><?php esc_html_e( 'Since', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></th>
					</tr></thead>
					<tbody>
						<?php if ( ! $rows ) : ?>
							<tr><td colspan="9"><?php esc_html_e( 'No subscribers yet. Add the Newsletter Signup Unit ([sidccofo_newsletter]) to a page, or import a list below.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<th class="check-column"><input type="checkbox" class="sc-sub-cb" name="ids[]" value="<?php echo esc_attr( (string) $row->id ); ?>" aria-label="<?php echo esc_attr( $row->email ); ?>"></th>
								<td><?php echo esc_html( $row->email ); ?></td>
								<td><?php echo esc_html( $row->first_name ); ?></td>
								<td><?php echo esc_html( $labels[ $row->status ] ?? $row->status ); ?></td>
								<td><?php echo esc_html( $row->source ); ?></td>
								<td><?php echo esc_html( str_replace( ',', ', ', $row->tags ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row->orders ) ); ?></td>
								<td><?php echo esc_html( \SidcraftCommerce\format_base( (float) $row->spent ) ); ?></td>
								<td><?php echo esc_html( $row->created ? wp_date( get_option( 'date_format' ), (int) $row->created ) : '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</form>

			<div class="sc-marketing__forms">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="card">
					<h2><?php esc_html_e( 'Add a subscriber', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h2>
					<input type="hidden" name="action" value="sidccofo_subscribers">
					<?php wp_nonce_field( 'sidccofo_subscribers' ); ?>
					<p><label><?php esc_html_e( 'Email', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?><br><input type="email" name="email" class="regular-text" required></label></p>
					<p><label><?php esc_html_e( 'First name', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?><br><input type="text" name="first_name" class="regular-text"></label></p>
					<p><label><?php esc_html_e( 'Tags (comma-separated)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?><br><input type="text" name="tags" class="regular-text"></label></p>
					<p><label><input type="checkbox" name="confirmed" value="1"> <?php esc_html_e( 'They agreed to receive marketing email (otherwise they get a confirmation email first)', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label></p>
					<p><button class="button button-primary" name="do" value="add"><?php esc_html_e( 'Add', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button></p>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="card">
					<h2><?php esc_html_e( 'Import a CSV', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></h2>
					<input type="hidden" name="action" value="sidccofo_subscribers">
					<?php wp_nonce_field( 'sidccofo_subscribers' ); ?>
					<p class="description"><?php esc_html_e( 'Columns: email, first_name, tags (a header row is optional). Addresses that unsubscribed stay unsubscribed.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
					<p><input type="file" name="csv" accept=".csv,text/csv"></p>
					<p><label><input type="checkbox" name="confirmed" value="1"> <?php esc_html_e( 'Everyone in this file agreed to receive marketing email', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></label><br>
						<span class="description"><?php esc_html_e( 'Unticked, they are added as waiting for confirmation and nothing is sent to them.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></span></p>
					<p><button class="button" name="do" value="import"><?php esc_html_e( 'Import', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></button></p>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Automation figures (sent, opened, clicked) above the list.
	 */
	protected function automation_summary() {
		$rows = array(
			'welcome'    => array( __( 'Welcome', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'marketing_welcome' ),
			'cart:%'     => array( __( 'Abandoned cart', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'marketing_abandoned' ),
			'followup:%' => array( __( 'Order follow-up', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'marketing_followup' ),
			'winback:%'  => array( __( 'Win-back', 'sidcraft-commerce-for-sidcraft-page-builder' ), 'marketing_winback' ),
		);
		echo '<h2>' . esc_html__( 'Automations', 'sidcraft-commerce-for-sidcraft-page-builder' ) . ' <a class="button button-small" href="' . esc_url( admin_url( 'edit.php?post_type=' . Post_Types::PRODUCT . '&page=sidccofo-settings&tab=marketing' ) ) . '">' . esc_html__( 'Set up', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</a></h2>';
		echo '<table class="widefat striped sc-marketing__auto"><thead><tr><th>' . esc_html__( 'Email', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'On', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Sent', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Opened', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th><th>' . esc_html__( 'Clicked', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $ref => $row ) {
			$stats = Mailer::stats( $ref );
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_html( $row[0] ),
				\SidcraftCommerce\setting( $row[1], 0 ) ? '✓' : '—',
				esc_html( number_format_i18n( $stats['sent'] ) ),
				esc_html( self::rate( $stats['opened'], $stats['sent'] ) ),
				esc_html( self::rate( $stats['clicked'], $stats['sent'] ) )
			);
		}
		echo '</tbody></table>';
		/* translators: %d: number of carts. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Carts waiting for a reminder or an order: %d.', 'sidcraft-commerce-for-sidcraft-page-builder' ), Abandoned::count() ) ) . '</p>';
	}

	/**
	 * "12 (40%)".
	 *
	 * @param int $n   Count.
	 * @param int $of  Total.
	 * @return string
	 */
	protected static function rate( $n, $of ) {
		return number_format_i18n( $n ) . ( $of ? ' (' . number_format_i18n( 100 * $n / $of ) . '%)' : '' );
	}

	/**
	 * Add, import and bulk actions.
	 */
	public function handle() {
		if ( ! current_user_can( manage_cap() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'sidcraft-commerce-for-sidcraft-page-builder' ), 403 );
		}
		check_admin_referer( 'sidccofo_subscribers' );
		$do        = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$confirmed = ! empty( $_POST['confirmed'] );
		$marketing = new Marketing();

		if ( 'add' === $do ) {
			$email = Subscribers::normalize( isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '' );
			if ( '' === $email ) {
				$this->back( 'invalid' );
			}
			$marketing->subscribe(
				$email,
				array(
					'first_name' => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '',
					'tags'       => isset( $_POST['tags'] ) ? sanitize_text_field( wp_unslash( $_POST['tags'] ) ) : '',
					'source'     => 'admin',
					'confirmed'  => $confirmed,
				)
			);
			$this->back( 'added' );
		}

		if ( 'import' === $do ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is checked with is_uploaded_file().
			$file = isset( $_FILES['csv']['tmp_name'] ) ? (string) $_FILES['csv']['tmp_name'] : '';
			if ( '' === $file || ! is_uploaded_file( $file ) ) {
				$this->back( 'nofile' );
			}
			$n      = 0;
			$handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading an upload.
			while ( $handle && false !== ( $cols = fgetcsv( $handle, 2000, ',', '"', '\\' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				$email = Subscribers::normalize( (string) ( $cols[0] ?? '' ) );
				if ( '' === $email ) {
					continue; // Header row or junk.
				}
				// Imports never send a confirmation email and never re-subscribe someone who left.
				Subscribers::add(
					$email,
					array(
						'first_name' => (string) ( $cols[1] ?? '' ),
						'tags'       => (string) ( $cols[2] ?? '' ),
						'source'     => 'import',
						'status'     => $confirmed ? 'subscribed' : 'pending',
					)
				);
				++$n;
			}
			if ( $handle ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			$this->back( 'imported', $n );
		}

		if ( 'bulk' === $do ) {
			$ids  = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
			$bulk = isset( $_POST['bulk'] ) ? sanitize_key( wp_unslash( $_POST['bulk'] ) ) : '';
			if ( 'delete' === $bulk ) {
				Subscribers::delete( $ids );
			} elseif ( 'unsubscribed' === $bulk ) {
				foreach ( $ids as $id ) {
					Subscribers::set_status( $id, 'unsubscribed' );
				}
			} else {
				$ids = array();
			}
			$this->back( 'bulk', count( $ids ) );
		}
		$this->back( '' );
	}

	/**
	 * Back to the list with a message.
	 *
	 * @param string $done Message key.
	 * @param int    $n    Count.
	 */
	protected function back( $done, $n = 0 ) {
		wp_safe_redirect(
			self::url(
				array(
					'done' => $done,
					'n'    => $n,
				)
			)
		);
		exit;
	}

	/**
	 * CSV export of the whole list.
	 */
	public function export() {
		global $wpdb;
		if ( ! current_user_can( manage_cap() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'sidcraft-commerce-for-sidcraft-page-builder' ), 403 );
		}
		check_admin_referer( 'sidccofo_subscribers_export' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="subscribers-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'email', 'first_name', 'tags', 'status', 'source', 'created', 'confirmed', 'orders', 'spent' ), ',', '"', '\\' );
		$after = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table, paged.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d ORDER BY id ASC LIMIT 500', Subscribers::table(), $after ) );
			foreach ( $rows as $row ) {
				$after = (int) $row->id;
				// A leading = + - @ would run as a formula in spreadsheet apps.
				$safe = static function ( $v ) {
					return preg_match( '/^[=+\-@]/', (string) $v ) ? "'" . $v : $v;
				};
				fputcsv( $out, array_map( $safe, array( $row->email, $row->first_name, $row->tags, $row->status, $row->source, $row->created ? gmdate( 'c', (int) $row->created ) : '', $row->confirmed ? gmdate( 'c', (int) $row->confirmed ) : '', $row->orders, $row->spent ) ), ',', '"', '\\' );
			}
		} while ( count( $rows ) === 500 );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/*
	------------------------------------------------------------------
	 * Campaign screen
	 * ----------------------------------------------------------------
	 */

	/**
	 * Boxes.
	 */
	public function meta_boxes() {
		add_meta_box( 'sidccofo-campaign', __( 'Email', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_campaign' ), Marketing::CAMPAIGN, 'normal', 'high' );
		add_meta_box( 'sidccofo-campaign-send', __( 'Send', 'sidcraft-commerce-for-sidcraft-page-builder' ), array( $this, 'render_send' ), Marketing::CAMPAIGN, 'side', 'high' );
	}

	/**
	 * Subject and audience.
	 *
	 * @param \WP_Post $post Campaign.
	 */
	public function render_campaign( $post ) {
		wp_nonce_field( 'sidccofo_campaign', '_sidccofo_campaign_nonce' );
		$segment = (string) get_post_meta( $post->ID, '_sidccofo_segment', true );
		$value   = (string) get_post_meta( $post->ID, '_sidccofo_segment_value', true );
		$locked  = in_array( Marketing::status( $post->ID ), array( 'sending', 'sent' ), true );
		?>
		<p><label for="sc-campaign-subject"><strong><?php esc_html_e( 'Subject line', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></strong></label><br>
			<input type="text" id="sc-campaign-subject" name="sidccofo_subject" class="large-text" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, '_sidccofo_subject', true ) ); ?>" placeholder="<?php esc_attr_e( 'Empty: the campaign title', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>" <?php disabled( $locked ); ?>></p>
		<p><label for="sc-campaign-segment"><strong><?php esc_html_e( 'Send to', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></strong></label><br>
			<select id="sc-campaign-segment" name="sidccofo_segment" <?php disabled( $locked ); ?>>
				<?php foreach ( Subscribers::segments() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $segment ? $segment : 'all', $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="text" name="sidccofo_segment_value" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Amount, days or tag', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>" aria-label="<?php esc_attr_e( 'Segment value', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?>" <?php disabled( $locked ); ?>></p>
		<p class="description">
			<?php
			/* translators: %d: number of subscribers. */
			echo esc_html( sprintf( __( 'Right now this reaches %d subscribed people. Save to update.', 'sidcraft-commerce-for-sidcraft-page-builder' ), Subscribers::count_segment( $segment ? $segment : 'all', $value ) ) );
			?>
		</p>
		<p class="description"><?php esc_html_e( 'Merge tags for the subject and text: {first_name}, {email}, {store_name}, {shop_url}, {shop_link} (a button), {unsubscribe_url}. Every email gets an unsubscribe link at the bottom.', 'sidcraft-commerce-for-sidcraft-page-builder' ); ?></p>
		<?php
	}

	/**
	 * Status, results and the send buttons.
	 *
	 * @param \WP_Post $post Campaign.
	 */
	public function render_send( $post ) {
		$status = Marketing::status( $post->ID );
		$labels = self::status_labels();
		echo '<p><strong>' . esc_html__( 'Status:', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</strong> ' . esc_html( $labels[ $status ] ) . '</p>';
		if ( 'scheduled' === $status ) {
			$when = (int) get_post_meta( $post->ID, '_sidccofo_send_at', true );
			/* translators: %s: date and time. */
			echo '<p>' . esc_html( sprintf( __( 'Sends %s.', 'sidcraft-commerce-for-sidcraft-page-builder' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $when ) ) ) . '</p>';
		}
		if ( in_array( $status, array( 'sending', 'sent' ), true ) ) {
			$stats = Mailer::stats( 'c' . $post->ID );
			$of    = (int) get_post_meta( $post->ID, '_sidccofo_recipients', true );
			echo '<ul>';
			/* translators: 1: sent, 2: recipients. */
			echo '<li>' . esc_html( sprintf( __( 'Sent: %1$s of %2$s', 'sidcraft-commerce-for-sidcraft-page-builder' ), number_format_i18n( $stats['sent'] ), number_format_i18n( $of ) ) ) . '</li>';
			echo '<li>' . esc_html__( 'Opened:', 'sidcraft-commerce-for-sidcraft-page-builder' ) . ' ' . esc_html( self::rate( $stats['opened'], $stats['sent'] ) ) . '</li>';
			echo '<li>' . esc_html__( 'Clicked:', 'sidcraft-commerce-for-sidcraft-page-builder' ) . ' ' . esc_html( self::rate( $stats['clicked'], $stats['sent'] ) ) . '</li>';
			if ( $stats['failed'] ) {
				echo '<li>' . esc_html__( 'Not delivered:', 'sidcraft-commerce-for-sidcraft-page-builder' ) . ' ' . esc_html( number_format_i18n( $stats['failed'] ) ) . '</li>';
			}
			echo '</ul>';
		}
		echo '<p><button class="button" name="sidccofo_campaign_action" value="test">' . esc_html__( 'Save and send me a test', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</button></p>';
		if ( 'draft' === $status ) {
			echo '<p><button class="button button-primary" name="sidccofo_campaign_action" value="send" onclick="return confirm(this.dataset.confirm)" data-confirm="' . esc_attr__( 'Send this campaign to everyone in the chosen group now?', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '">' . esc_html__( 'Save and send now', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</button></p>';
			echo '<p><label for="sc-campaign-when">' . esc_html__( 'Or send later:', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</label><br><input type="datetime-local" id="sc-campaign-when" name="sidccofo_send_at"> <button class="button" name="sidccofo_campaign_action" value="schedule">' . esc_html__( 'Schedule', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</button></p>';
		} elseif ( in_array( $status, array( 'scheduled', 'sending' ), true ) ) {
			echo '<p><button class="button" name="sidccofo_campaign_action" value="cancel">' . esc_html__( 'Stop sending', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</button></p>';
		}
		echo '<p class="description">' . esc_html__( 'Emails go out in batches (Settings → Marketing) every minute while people visit the site, or on each run of the server’s cron.', 'sidcraft-commerce-for-sidcraft-page-builder' ) . '</p>';
	}

	/**
	 * Campaign status labels.
	 *
	 * @return array
	 */
	protected static function status_labels() {
		return array(
			'draft'     => __( 'Draft', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'scheduled' => __( 'Scheduled', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sending'   => __( 'Sending', 'sidcraft-commerce-for-sidcraft-page-builder' ),
			'sent'      => __( 'Sent', 'sidcraft-commerce-for-sidcraft-page-builder' ),
		);
	}

	/**
	 * Save the boxes, then run the button pressed.
	 *
	 * @param int      $post_id Campaign.
	 * @param \WP_Post $post    Campaign.
	 */
	public function save_campaign( $post_id, $post ) {
		if ( ! isset( $_POST['_sidccofo_campaign_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sidccofo_campaign_nonce'] ) ), 'sidccofo_campaign' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( manage_cap() ) ) {
			return;
		}
		if ( ! in_array( Marketing::status( $post_id ), array( 'sending', 'sent' ), true ) ) {
			if ( isset( $_POST['sidccofo_subject'] ) ) {
				update_post_meta( $post_id, '_sidccofo_subject', sanitize_text_field( wp_unslash( $_POST['sidccofo_subject'] ) ) );
			}
			$segment = isset( $_POST['sidccofo_segment'] ) ? sanitize_key( wp_unslash( $_POST['sidccofo_segment'] ) ) : 'all';
			update_post_meta( $post_id, '_sidccofo_segment', array_key_exists( $segment, Subscribers::segments() ) ? $segment : 'all' );
			if ( isset( $_POST['sidccofo_segment_value'] ) ) {
				update_post_meta( $post_id, '_sidccofo_segment_value', sanitize_text_field( wp_unslash( $_POST['sidccofo_segment_value'] ) ) );
			}
		}
		$action = isset( $_POST['sidccofo_campaign_action'] ) ? sanitize_key( wp_unslash( $_POST['sidccofo_campaign_action'] ) ) : '';
		$status = Marketing::status( $post_id );
		$note   = '';
		if ( 'test' === $action ) {
			$user = wp_get_current_user();
			$row  = Subscribers::by_email( $user->user_email );
			if ( ! $row ) {
				$row = (object) array(
					'email'      => $user->user_email,
					'first_name' => $user->first_name,
					'token'      => '',
				);
			}
			$subject = (string) get_post_meta( $post_id, '_sidccofo_subject', true );
			/* translators: %s: subject. */
			$ok   = Mailer::deliver( $row, sprintf( __( '[Test] %s', 'sidcraft-commerce-for-sidcraft-page-builder' ), '' !== $subject ? $subject : $post->post_title ), Marketing::body( $post ), array(), 0, true );
			$note = $ok ? 'test_sent' : 'test_failed';
		} elseif ( 'send' === $action && 'draft' === $status ) {
			Marketing::queue( $post_id );
			$note = 'queued';
		} elseif ( 'schedule' === $action && 'draft' === $status ) {
			$raw  = isset( $_POST['sidccofo_send_at'] ) ? sanitize_text_field( wp_unslash( $_POST['sidccofo_send_at'] ) ) : '';
			$date = '' !== $raw ? date_create_immutable( $raw, wp_timezone() ) : false;
			if ( $date ) {
				Marketing::queue( $post_id, $date->getTimestamp() );
				$note = 'scheduled';
			} else {
				$note = 'no_date';
			}
		} elseif ( 'cancel' === $action && in_array( $status, array( 'scheduled', 'sending' ), true ) ) {
			Marketing::cancel( $post_id );
			$note = 'cancelled';
		}
		if ( $note ) {
			set_transient( 'sidccofo_campaign_note_' . get_current_user_id(), $note, MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Message after a button.
	 */
	public function notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || Marketing::CAMPAIGN !== $screen->post_type ) {
			return;
		}
		$note = get_transient( 'sidccofo_campaign_note_' . get_current_user_id() );
		if ( ! $note ) {
			return;
		}
		delete_transient( 'sidccofo_campaign_note_' . get_current_user_id() );
		$texts = array(
			'test_sent'   => array( 'success', __( 'Test email sent to you.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'test_failed' => array( 'error', __( 'The test email could not be sent. Check that this site can send email.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'queued'      => array( 'success', __( 'Sending has started.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'scheduled'   => array( 'success', __( 'Campaign scheduled.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'no_date'     => array( 'error', __( 'Choose a date and time to schedule the campaign.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
			'cancelled'   => array( 'success', __( 'Sending stopped. People who already got it keep it.', 'sidcraft-commerce-for-sidcraft-page-builder' ) ),
		);
		if ( isset( $texts[ $note ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $texts[ $note ][0] ), esc_html( $texts[ $note ][1] ) );
		}
	}

	/**
	 * Saved message wording (no "View post" link: campaigns aren't public).
	 *
	 * @param array $messages Messages.
	 * @return array
	 */
	public function messages( $messages ) {
		$messages[ Marketing::CAMPAIGN ] = array_fill( 1, 10, __( 'Campaign saved.', 'sidcraft-commerce-for-sidcraft-page-builder' ) );
		return $messages;
	}

	/**
	 * List columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function columns( $columns ) {
		$date = $columns['date'] ?? null;
		unset( $columns['date'] );
		$columns['sc_status']  = __( 'Status', 'sidcraft-commerce-for-sidcraft-page-builder' );
		$columns['sc_sent']    = __( 'Sent', 'sidcraft-commerce-for-sidcraft-page-builder' );
		$columns['sc_opened']  = __( 'Opened', 'sidcraft-commerce-for-sidcraft-page-builder' );
		$columns['sc_clicked'] = __( 'Clicked', 'sidcraft-commerce-for-sidcraft-page-builder' );
		if ( $date ) {
			$columns['date'] = $date;
		}
		return $columns;
	}

	/**
	 * Column values.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Campaign.
	 */
	public function column( $column, $post_id ) {
		if ( 'sc_status' === $column ) {
			$labels = self::status_labels();
			echo esc_html( $labels[ Marketing::status( $post_id ) ] );
			return;
		}
		if ( ! in_array( $column, array( 'sc_sent', 'sc_opened', 'sc_clicked' ), true ) ) {
			return;
		}
		static $stats = array();
		if ( ! isset( $stats[ $post_id ] ) ) {
			$stats[ $post_id ] = Mailer::stats( 'c' . $post_id );
		}
		$s = $stats[ $post_id ];
		if ( 'sc_sent' === $column ) {
			echo esc_html( number_format_i18n( $s['sent'] ) );
		} elseif ( 'sc_opened' === $column ) {
			echo esc_html( self::rate( $s['opened'], $s['sent'] ) );
		} else {
			echo esc_html( self::rate( $s['clicked'], $s['sent'] ) );
		}
	}
}
