<?php

namespace Certificates\Includes;

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CISON_CERT_TABLE')) {
    define('CISON_CERT_TABLE', 'wprx_cison_certificates');
}

class CertManager_Admin_UI
{

    private string $registry_table;

    public function __construct()
    {
        global $wpdb;
        $this->registry_table = $wpdb->prefix . 'cert_registry';

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('wp_ajax_cison_sync_membership_status', [$this, 'handle_migration']);
        add_action('wp_ajax_cison_reverse_migration', [$this, 'handle_reverse_migration']);
        add_action('wp_ajax_cison_delete_registry', [$this, 'handle_delete_registry']);
    }

    /* -------------------------------------------------------
     * Admin menu
     * ----------------------------------------------------- */

    public function add_admin_menu(): void
    {
        add_submenu_page(
            'tools.php',
            __('Issue Certificate', 'acmqr'),
            __('Issue Certificate', 'acmqr'),
            'manage_options',
            'acmqr-issue-cert',
            [$this, 'render_admin_page']
        );
    }

    /* -------------------------------------------------------
     * Admin page render
     * ----------------------------------------------------- */

    public function render_admin_page(): void
    {
        global $wpdb;

        $per_page = 20;
        $current_page = max(1, intval($_GET['paged'] ?? 1));
        $offset = ($current_page - 1) * $per_page;

        $total_items = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->registry_table}");
        $total_pages = (int) ceil($total_items / $per_page);

        $entries = $wpdb->get_results($wpdb->prepare(
            "SELECT id, user_id, cert_name, user_email, date_issued
			 FROM {$this->registry_table}
			 ORDER BY date_issued DESC
			 LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));

        $source_table = CISON_CERT_TABLE;
        $pending_count = (int) $wpdb->get_var(
            "SELECT COUNT(*)
			 FROM {$source_table} a
			 WHERE a.user_id IS NOT NULL
			   AND a.user_id > 0
			   AND NOT EXISTS (
			       SELECT 1 FROM {$this->registry_table} b
			       WHERE b.user_id = a.user_id
			   )"
        );

        // Rows in registry that were originally copied from the source table
        // (identified by cert_key matching a cert_id in the source).
        $migrated_count = (int) $wpdb->get_var(
            "SELECT COUNT(*)
			 FROM {$this->registry_table} b
			 WHERE EXISTS (
			     SELECT 1 FROM {$source_table} a
			     WHERE a.cert_id = b.cert_key
			 )"
        );

        $migrate_nonce = wp_create_nonce('cison_sync_nonce');
        $reverse_nonce = wp_create_nonce('cison_reverse_nonce');
        $delete_nonce = wp_create_nonce('cison_delete_nonce');

        $pagination_args = [
            'base' => add_query_arg('paged', '%#%'),
            'format' => '',
            'prev_text' => __('&laquo; Previous'),
            'next_text' => __('Next &raquo;'),
            'total' => $total_pages,
            'current' => $current_page,
        ];

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('CISON Certificate Migration', 'acmqr'); ?></h1>

            <p>
                <?php
                printf(
                    esc_html(_n(
                        '%d user in the certificate table has not been copied to the registry yet.',
                        '%d users in the certificate table have not been copied to the registry yet.',
                        $pending_count,
                        'acmqr'
                    )),
                    number_format_i18n($pending_count)
                );
                ?>
            </p>

            <!-- Action buttons -->
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin:20px 0;">

                <button id="btn-migrate" class="button button-primary" <?php disabled($pending_count, 0); ?>>
                    <?php esc_html_e('Copy to Registry', 'acmqr'); ?>
                </button>

                <button id="btn-reverse" class="button" style="border-color:#d63638;color:#d63638;" <?php disabled($migrated_count, 0); ?>>
                    <?php esc_html_e('Reverse Migration', 'acmqr'); ?>
                </button>

                <button id="btn-delete" class="button" style="border-color:#d63638;color:#d63638;background:#fff0f0;" <?php disabled($total_items, 0); ?>>
                    <?php esc_html_e('Delete All Registry Entries', 'acmqr'); ?>
                </button>

            </div>

            <div id="sync-status-message"
                style="margin-bottom:16px;font-weight:bold;padding:10px;display:none;border-left:4px solid transparent;"></div>

            <h2><?php esc_html_e('Certificate Registry Entries', 'acmqr'); ?></h2>

            <div class="tablenav top">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php printf(esc_html(_n('%s item', '%s items', $total_items, 'acmqr')), number_format_i18n($total_items)); ?>
                    </span>
                    <?php echo paginate_links($pagination_args); ?>
                </div>
                <br class="clear">
            </div>

            <table class="wp-list-table widefat fixed striped posts">
                <thead>
                    <tr>
                        <th style="width:80px;"><?php esc_html_e('ID', 'acmqr'); ?></th>
                        <th><?php esc_html_e('User ID', 'acmqr'); ?></th>
                        <th><?php esc_html_e('Certificate Name', 'acmqr'); ?></th>
                        <th><?php esc_html_e('User Email', 'acmqr'); ?></th>
                        <th><?php esc_html_e('Date Issued', 'acmqr'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($entries)): ?>
                        <?php foreach ($entries as $entry): ?>
                            <tr>
                                <td><strong>#<?php echo esc_html($entry->id); ?></strong></td>
                                <td><?php echo esc_html($entry->user_id ?: 'N/A'); ?></td>
                                <td><?php echo esc_html($entry->cert_name); ?></td>
                                <td><?php echo esc_html($entry->user_email); ?></td>
                                <td><?php echo esc_html($entry->date_issued); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5"><?php esc_html_e('No registry records found.', 'acmqr'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php echo paginate_links($pagination_args); ?>
                </div>
                <br class="clear">
            </div>
        </div>

        <script>
            jQuery(function ($) {

                const statusDiv = $('#sync-status-message');

                const STATES = {
                    pending: { color: '#333', background: '#fff', borderColor: '#ffb900' },
                    success: { color: 'green', background: '#ecf7ed', borderColor: '#46b450' },
                    error: { color: 'red', background: '#fbeae5', borderColor: '#dc3232' },
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

                function ajaxAction({ btn, action, nonce, pendingText, originalText, confirmMsg = null }) {
                    btn.on('click', function (e) {
                        e.preventDefault();

                        if (confirmMsg && !window.confirm(confirmMsg)) {
                            return;
                        }

                        btn.prop('disabled', true).text(pendingText);
                        setState('pending', '<?php echo esc_js(__('Processing. This may take a moment...', 'acmqr')); ?>');

                        $.post(ajaxurl, { action, nonce })
                            .done(function (response) {
                                displayLogs(response);
                                if (response.success) {
                                    setState('success', response.data.message);
                                } else {
                                    setState('error', response.data.message ?? '<?php echo esc_js(__('An unknown error occurred.', 'acmqr')); ?>');
                                }
                            })
                            .fail(function (response) {
                                displayLogs(response);
                                setState('error', '<?php echo esc_js(__('Request failed. Please try again.', 'acmqr')); ?>');
                            })
                            .always(function () {
                                btn.prop('disabled', false).text(originalText);
                            });
                    });
                }

                ajaxAction({
                    btn: $('#btn-migrate'),
                    action: 'cison_sync_membership_status',
                    nonce: '<?php echo esc_js($migrate_nonce); ?>',
                    pendingText: '<?php echo esc_js(__('Copying...', 'acmqr')); ?>',
                    originalText: '<?php echo esc_js(__('Copy to Registry', 'acmqr')); ?>',
                });

                ajaxAction({
                    btn: $('#btn-reverse'),
                    action: 'cison_reverse_migration',
                    nonce: '<?php echo esc_js($reverse_nonce); ?>',
                    pendingText: '<?php echo esc_js(__('Reversing...', 'acmqr')); ?>',
                    originalText: '<?php echo esc_js(__('Reverse Migration', 'acmqr')); ?>',
                    confirmMsg: '<?php echo esc_js(__('This will remove all registry entries that were copied from the certificate table. Hand-created entries will not be affected. Continue?', 'acmqr')); ?>',
                });

                ajaxAction({
                    btn: $('#btn-delete'),
                    action: 'cison_delete_registry',
                    nonce: '<?php echo esc_js($delete_nonce); ?>',
                    pendingText: '<?php echo esc_js(__('Deleting...', 'acmqr')); ?>',
                    originalText: '<?php echo esc_js(__('Delete All Registry Entries', 'acmqr')); ?>',
                    confirmMsg: '<?php echo esc_js(__('This will permanently delete EVERY entry in the registry, including hand-created ones. This cannot be undone. Are you sure?', 'acmqr')); ?>',
                });

            });
        </script>
        <?php
    }

    /* -------------------------------------------------------
     * Shared: security gate for all AJAX handlers
     * ----------------------------------------------------- */

    private function verify_ajax(string $nonce_action, string $nonce_key): void
    {
        check_ajax_referer($nonce_action, $nonce_key);

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Unauthorized access.', 'acmqr'),
                'logs' => ['Security block: insufficient permissions.'],
            ]);
        }
    }

    /* -------------------------------------------------------
     * AJAX: copy source cert table → registry
     * ----------------------------------------------------- */

    public function handle_migration(): void
    {
        $this->verify_ajax('cison_sync_nonce', 'nonce');

        global $wpdb;

        $logs = [];
        $source_table = CISON_CERT_TABLE;
        $target_table = $this->registry_table;

        $logs[] = 'Starting migration...';
        $logs[] = "Source: {$source_table} → Target: {$target_table}";

        /*
         * Fetch source rows not yet present in the registry.
         *
         * Guard 1 — user_id is valid (non-null, non-zero).
         * Guard 2 — user_id not already in registry (idempotency: safe to re-run).
         *
         * Column mapping:
         *   user_id                          → user_id
         *   firstname + middlename + surname → user_name
         *   email                            → user_email
         *   member_id                        → cert_name
         *   cert_id                          → cert_key   (unique in source; used to identify migrated rows)
         *   secret_token                     → cert_hmac
         *   date_issued (BIGINT unix)        → date_issued (datetime)
         *   certificate_path                 → file_url
         *   member_type                      → template_id
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

        if ($wpdb->last_error) {
            $logs[] = 'SQL error during candidate fetch: ' . $wpdb->last_error;
            wp_send_json_error([
                'message' => __('Database query failed. See logs for details.', 'acmqr'),
                'logs' => $logs,
            ]);
        }

        $total = count($candidates);

        if ($total === 0) {
            $logs[] = 'All source users are already present in the registry. Nothing to copy.';
            wp_send_json_success([
                'message' => __('All users are already in the registry.', 'acmqr'),
                'logs' => $logs,
            ]);
        }

        $logs[] = "Found {$total} user(s) not yet in registry. Inserting...";

        $inserted = 0;
        $failed = 0;



        foreach ($candidates as $row) {
            $user_id = (int) $row->user_id;
            $full_name = trim(implode(' ', array_filter([
                $row->firstname,
                $row->middlename,
                $row->surname,
            ])));

            $custom_date = date('Y-m-d H:i:s', (int) $row->date_issued);
            $year = date('Y', strtotime($custom_date));
            $date_expired_mysql = date('Y-m-d H:i:s', strtotime('+2 years', (int) $row->date_issued));


            $result = $wpdb->insert(
                $target_table,
                [
                    'cert_name' => "Membership $year",
                    'user_id' => $user_id,
                    'user_name' => $full_name ?: __('CISON Member', 'acmqr'),
                    'user_email' => $row->email,
                    'template_id' => $row->member_type ?: 'standard',
                    'cert_key' => $row->cert_id,
                    'cert_hmac' => $row->secret_token,
                    'is_main' => 1,
                    'date_issued' => date('Y-m-d H:i:s', (int) $row->date_issued),
                    'date_expiry' => $date_expired_mysql,
                    'file_url' => $row->certificate_path,
                ],
                ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
            );

            if ($result) {
                $inserted++;
                $logs[] = "Copied user_id={$user_id} (member_id={$row->member_id}).";
            } else {
                $failed++;
                $logs[] = "Failed user_id={$user_id} (member_id={$row->member_id}): " . $wpdb->last_error;
            }
        }

        $logs[] = "Done. Copied: {$inserted}, Failed: {$failed}.";

        wp_send_json_success([
            'message' => sprintf(
                __('Migration complete. Copied %1$d user(s), %2$d failed. Check logs for details.', 'acmqr'),
                $inserted,
                $failed
            ),
            'logs' => $logs,
        ]);
    }

    /* -------------------------------------------------------
     * AJAX: reverse — remove only rows that were copied from source
     * ----------------------------------------------------- */

    public function handle_reverse_migration(): void
    {
        $this->verify_ajax('cison_reverse_nonce', 'nonce');

        global $wpdb;

        $logs = [];
        $source_table = CISON_CERT_TABLE;
        $target_table = $this->registry_table;

        $logs[] = 'Starting reverse migration...';

        /*
         * Delete only registry rows whose cert_key matches a cert_id in the source
         * table. This is the fingerprint left by handle_migration() and means
         * hand-created registry entries are never touched.
         */
        $deleted = $wpdb->query(
            "DELETE b FROM {$target_table} b
			 INNER JOIN {$source_table} a ON a.cert_id = b.cert_key"
        );

        if ($wpdb->last_error) {
            $logs[] = 'SQL error during reverse: ' . $wpdb->last_error;
            wp_send_json_error([
                'message' => __('Reverse migration failed. See logs for details.', 'acmqr'),
                'logs' => $logs,
            ]);
        }

        $logs[] = "Removed {$deleted} migrated row(s) from registry. Hand-created entries were left untouched.";

        wp_send_json_success([
            'message' => sprintf(
                __('Reverse complete. Removed %d migrated entr%s from the registry.', 'acmqr'),
                $deleted,
                $deleted === 1 ? 'y' : 'ies'
            ),
            'logs' => $logs,
        ]);
    }

    /* -------------------------------------------------------
     * AJAX: delete — truncate the entire registry
     * ----------------------------------------------------- */

    public function handle_delete_registry(): void
    {
        $this->verify_ajax('cison_delete_nonce', 'nonce');

        global $wpdb;

        $logs = [];
        $logs[] = 'Truncating registry table...';

        // Get count before truncating so we can report it.
        $count_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->registry_table}");

        $result = $wpdb->query("TRUNCATE TABLE {$this->registry_table}");

        if ($result === false) {
            $logs[] = 'TRUNCATE failed: ' . $wpdb->last_error;
            wp_send_json_error([
                'message' => __('Delete failed. See logs for details.', 'acmqr'),
                'logs' => $logs,
            ]);
        }

        $logs[] = "Truncated registry. {$count_before} row(s) deleted.";

        wp_send_json_success([
            'message' => sprintf(
                __('Registry cleared. %d entr%s permanently deleted.', 'acmqr'),
                $count_before,
                $count_before === 1 ? 'y' : 'ies'
            ),
            'logs' => $logs,
        ]);
    }
}