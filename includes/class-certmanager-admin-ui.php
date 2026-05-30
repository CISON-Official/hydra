<?php

namespace Certificates\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'CISON_CERT_TABLE' ) ) {
	define( 'CISON_CERT_TABLE', 'wprx_cison_certificates' );
}

class CertManager_Admin_UI {

	private string $registry_table;

	public function __construct() {
		global $wpdb;
		$this->registry_table = $wpdb->prefix . 'cert_registry';

		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		add_action( 'wp_ajax_cison_sync_membership_status', [ $this, 'handle_membership_sync' ] );
	}

	/* -------------------------------------------------------
	 * Admin menu
	 * ----------------------------------------------------- */

	public function add_admin_menu(): void {
		add_submenu_page(
			'tools.php',
			__( 'Issue Certificate', 'acmqr' ),
			__( 'Issue Certificate', 'acmqr' ),
			'manage_options',
			'acmqr-issue-cert',
			[ $this, 'render_admin_page' ]
		);
	}

	/* -------------------------------------------------------
	 * Admin page render
	 * ----------------------------------------------------- */

	public function render_admin_page(): void {
		global $wpdb;

		$per_page     = 20;
		$current_page = max( 1, intval( $_GET['paged'] ?? 1 ) );
		$offset       = ( $current_page - 1 ) * $per_page;

		$total_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->registry_table}" );
		$total_pages = (int) ceil( $total_items / $per_page );

		$entries = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, user_id, cert_name, user_email, date_issued
			 FROM {$this->registry_table}
			 ORDER BY date_issued DESC
			 LIMIT %d OFFSET %d",
			$per_page,
			$offset
		) );

		$sync_nonce      = wp_create_nonce( 'cison_sync_nonce' );
		$pagination_args = [
			'base'      => add_query_arg( 'paged', '%#%' ),
			'format'    => '',
			'prev_text' => __( '&laquo; Previous' ),
			'next_text' => __( 'Next &raquo;' ),
			'total'     => $total_pages,
			'current'   => $current_page,
		];

		// Count how many source records are not yet in the registry, for the UI prompt.
		$source_table   = CISON_CERT_TABLE;
		$pending_count  = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$source_table} a
			 WHERE a.user_id IS NOT NULL
			   AND a.user_id > 0
			   AND NOT EXISTS (
			       SELECT 1 FROM {$this->registry_table} b
			       WHERE b.user_id = a.user_id
			   )"
		);

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CISON Certificate Migration', 'acmqr' ); ?></h1>

			<p>
				<?php
				printf(
					/* translators: %d: number of users not yet migrated */
					esc_html( _n(
						'%d user in the certificate table has not been migrated to the registry yet.',
						'%d users in the certificate table have not been migrated to the registry yet.',
						$pending_count,
						'acmqr'
					) ),
					number_format_i18n( $pending_count )
				);
				?>
			</p>

			<button id="sync-membership-btn" class="button button-primary" style="margin:20px 0;"
				<?php echo $pending_count === 0 ? 'disabled' : ''; ?>>
				<?php esc_html_e( 'Migrate Users to Registry', 'acmqr' ); ?>
			</button>
			<div id="sync-status-message" style="margin-top:10px;font-weight:bold;padding:10px;display:none;border-left:4px solid transparent;"></div>

			<h2><?php esc_html_e( 'Certificate Registry Entries', 'acmqr' ); ?></h2>

			<div class="tablenav top">
				<div class="tablenav-pages">
					<span class="displaying-num">
						<?php printf( esc_html( _n( '%s item', '%s items', $total_items, 'acmqr' ) ), number_format_i18n( $total_items ) ); ?>
					</span>
					<?php echo paginate_links( $pagination_args ); ?>
				</div>
				<br class="clear">
			</div>

			<table class="wp-list-table widefat fixed striped posts">
				<thead>
					<tr>
						<th style="width:80px;"><?php esc_html_e( 'ID', 'acmqr' ); ?></th>
						<th><?php esc_html_e( 'User ID', 'acmqr' ); ?></th>
						<th><?php esc_html_e( 'Certificate Name', 'acmqr' ); ?></th>
						<th><?php esc_html_e( 'User Email', 'acmqr' ); ?></th>
						<th><?php esc_html_e( 'Date Issued', 'acmqr' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! empty( $entries ) ) : ?>
						<?php foreach ( $entries as $entry ) : ?>
							<tr>
								<td><strong>#<?php echo esc_html( $entry->id ); ?></strong></td>
								<td><?php echo esc_html( $entry->user_id ?: 'N/A' ); ?></td>
								<td><?php echo esc_html( $entry->cert_name ); ?></td>
								<td><?php echo esc_html( $entry->user_email ); ?></td>
								<td><?php echo esc_html( $entry->date_issued ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr>
							<td colspan="5"><?php esc_html_e( 'No registry records found.', 'acmqr' ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php echo paginate_links( $pagination_args ); ?>
				</div>
				<br class="clear">
			</div>
		</div>

		<script>
		jQuery(function ($) {
			const btn       = $('#sync-membership-btn');
			const statusDiv = $('#sync-status-message');

			const STATES = {
				pending: { color: '#333',  background: '#fff',    borderColor: '#ffb900' },
				success: { color: 'green', background: '#ecf7ed', borderColor: '#46b450' },
				error:   { color: 'red',   background: '#fbeae5', borderColor: '#dc3232' },
			};

			function setState(state, message) {
				statusDiv.show().css(STATES[state]).text(message);
			}

			function displayLogs(response) {
				console.group('=== CISON MIGRATION LOGS ===');
				const logs = response?.data?.logs;
				if (Array.isArray(logs) && logs.length) {
					logs.forEach(line => console.log(line));
				} else {
					console.log('No logs returned from server.', response);
				}
				console.groupEnd();
			}

			btn.on('click', function (e) {
				e.preventDefault();
				btn.prop('disabled', true).text('<?php echo esc_js( __( 'Migrating...', 'acmqr' ) ); ?>');
				setState('pending', '<?php echo esc_js( __( 'Reading source table and migrating records. This may take a moment...', 'acmqr' ) ); ?>');

				$.post(ajaxurl, {
					action: 'cison_sync_membership_status',
					nonce:  '<?php echo esc_js( $sync_nonce ); ?>',
				})
				.done(function (response) {
					displayLogs(response);
					if (response.success) {
						setState('success', response.data.message);
					} else {
						setState('error', response.data.message ?? '<?php echo esc_js( __( 'An unknown error occurred.', 'acmqr' ) ); ?>');
					}
				})
				.fail(function (response) {
					displayLogs(response);
					setState('error', '<?php echo esc_js( __( 'Request failed. Please try again.', 'acmqr' ) ); ?>');
				})
				.always(function () {
					btn.prop('disabled', false).text('<?php echo esc_js( __( 'Migrate Users to Registry', 'acmqr' ) ); ?>');
				});
			});
		});
		</script>
		<?php
	}

	/* -------------------------------------------------------
	 * AJAX: migrate users from source cert table → registry
	 * ----------------------------------------------------- */

	public function handle_membership_sync(): void {
		check_ajax_referer( 'cison_sync_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [
				'message' => __( 'Unauthorized access.', 'acmqr' ),
				'logs'    => [ 'Security block: insufficient permissions.' ],
			] );
		}

		global $wpdb;

		$logs         = [];
		$source_table = CISON_CERT_TABLE;
		$target_table = $this->registry_table;

		$logs[] = 'Starting migration...';
		$logs[] = "Source: {$source_table} → Target: {$target_table}";

		/*
		 * Fetch all source rows where:
		 *   Guard 1 — user_id is valid (non-null, non-zero).
		 *   Guard 2 — user_id does NOT already exist in the registry (idempotency:
		 *             re-running the migration never creates duplicate entries).
		 *
		 * Column mapping from source → target:
		 *   user_id                            → user_id
		 *   firstname + middlename + surname   → user_name
		 *   email                              → user_email
		 *   member_id                          → cert_name  (e.g. "CISON/2024/00123")
		 *   cert_id                            → cert_key   (already unique per source schema)
		 *   secret_token                       → cert_hmac
		 *   date_issued (BIGINT unix ts)       → date_issued (datetime via FROM_UNIXTIME)
		 *   certificate_path                   → file_url
		 *   member_type                        → template_id (e.g. "transiting" / "inducted")
		 *
		 * Table names are trusted internal values (constant + $wpdb->prefix),
		 * never derived from user input, so direct interpolation is safe here.
		 */
		$candidates = $wpdb->get_results(
			"SELECT
				a.user_id,
				a.member_id,
				a.cert_id,
				a.certificate_path,
				a.date_issued,
				a.secret_token,
				a.firstname,
				a.middlename,
				a.surname,
				a.email,
				a.member_type
			 FROM {$source_table} a
			 WHERE
			 	a.user_id IS NOT NULL
			 	AND a.user_id > 0
			 	AND NOT EXISTS (
			 		SELECT 1 FROM {$target_table} b
			 		WHERE b.user_id = a.user_id
			 	)"
		);

		if ( $wpdb->last_error ) {
			$logs[] = 'SQL error during candidate fetch: ' . $wpdb->last_error;
			wp_send_json_error( [
				'message' => __( 'Database query failed. See logs for details.', 'acmqr' ),
				'logs'    => $logs,
			] );
		}

		$total_candidates = count( $candidates );

		if ( $total_candidates === 0 ) {
			$logs[] = 'Scan complete: all source users are already present in the registry. Nothing to migrate.';
			wp_send_json_success( [
				'message' => __( 'Migration complete. All users are already in the registry.', 'acmqr' ),
				'logs'    => $logs,
			] );
		}

		$logs[] = "Found {$total_candidates} user(s) not yet in registry. Starting insertions...";

		$inserted_count = 0;
		$skipped_count  = 0;

		foreach ( $candidates as $row ) {
			$user_id = (int) $row->user_id;

			// Build full name from all three name parts; collapse extra whitespace.
			$full_name = trim( implode( ' ', array_filter( [
				$row->firstname,
				$row->middlename,
				$row->surname,
			] ) ) );

			// Convert BIGINT unix timestamp → MySQL datetime string.
			$date_issued_mysql = date( 'Y-m-d H:i:s', (int) $row->date_issued );
            $year = substr($date_issued_mysql, 0, 4);

			// member_type is 'transiting' or 'inducted'; fall back to 'standard' for
			// any legacy rows that were never backfilled.
			$template_id = ! empty( $row->member_type ) ? $row->member_type : 'standard';

			$result = $wpdb->insert(
				$target_table,
				[
					'cert_name'   => "Membership $year",
					'user_id'     => $user_id,
					'user_name'   => $full_name ?: __( 'CISON Member', 'acmqr' ),
					'user_email'  => $row->email,
					'template_id' => $template_id,
					'cert_key'    => $row->cert_id,
					'cert_hmac'   => $row->secret_token,
					'is_main'     => 1,
					'date_issued' => $date_issued_mysql,
					'date_expiry' => null,
					'file_url'    => $row->certificate_path,
				],
				[ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
			);

			if ( $result ) {
				$inserted_count++;
				$logs[] = "Migrated user_id={$user_id} (member_id={$row->member_id}).";
			} else {
				$skipped_count++;
				$logs[] = "Insert failed for user_id={$user_id} (member_id={$row->member_id}): " . $wpdb->last_error;
			}
		}

		$logs[] = "Done. Migrated: {$inserted_count}, Failed: {$skipped_count}.";

		wp_send_json_success( [
			'message' => sprintf(
				/* translators: 1: migrated count, 2: failed count */
				__( 'Migration complete. Migrated %1$d user(s), %2$d failed. Check logs for details.', 'acmqr' ),
				$inserted_count,
				$skipped_count
			),
			'logs' => $logs,
		] );
	}
}