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
        add_action('wp_ajax_cison_sync_membership_status', [$this, 'handle_membership_sync']);
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

        $sync_nonce = wp_create_nonce('cison_sync_nonce');
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
            <h1><?php esc_html_e('CISON Membership Status Sync', 'acmqr'); ?></h1>

            <button id="sync-membership-btn" class="button button-primary" style="margin:20px 0;">
                <?php esc_html_e('Sync Membership Status', 'acmqr'); ?>
            </button>
            <div id="sync-status-message"
                style="margin-top:10px;font-weight:bold;padding:10px;display:none;border-left:4px solid transparent;"></div>

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
                const btn = $('#sync-membership-btn');
                const statusDiv = $('#sync-status-message');

                const STATES = {
                    pending: { color: '#333', background: '#fff', borderColor: '#ffb900' },
                    success: { color: 'green', background: '#ecf7ed', borderColor: '#46b450' },
                    error: { color: 'red', background: '#fbeae5', borderColor: '#dc3232' },
                };

                function setState(state, message) {
                    statusDiv.show().css(STATES[state]).text(message);
                }

                btn.on('click', function (e) {
                    e.preventDefault();
                    btn.prop('disabled', true).text('<?php echo esc_js(__('Processing Sync...', 'acmqr')); ?>');
                    setState('pending', '<?php echo esc_js(__('Scanning tables. This can take a moment...', 'acmqr')); ?>');

                    $.post(ajaxurl, {
                        action: 'cison_sync_membership_status',
                        nonce: '<?php echo esc_js($sync_nonce); ?>',
                    })
                        .done(function (response) {
                            if (response.success) {
                                setState('success', response.data);
                                setTimeout(() => window.location.replace(window.location.pathname + window.location.search.replace(/[&?]paged=\d+/, '')), 10000);
                            } else {
                                setState('error', response.data);
                            }
                        })
                        .fail(function () {
                            setState('error', '<?php echo esc_js(__('Request failed. Please try again.', 'acmqr')); ?>');
                        })
                        .always(function () {
                            btn.prop('disabled', false).text('<?php echo esc_js(__('Sync Membership Status', 'acmqr')); ?>');
                        });
                });
            });
        </script>
        <?php
    }

    /* -------------------------------------------------------
     * AJAX: membership sync
     * ----------------------------------------------------- */

    public function handle_membership_sync(): void
    {
        check_ajax_referer('cison_sync_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized.', 'acmqr'));
        }

        global $wpdb;

        $source_table = CISON_CERT_TABLE;
        $target_table = $this->registry_table;

        $certificates = $wpdb->get_results(
            "SELECT user_id, email, date_issued, firstname, surname FROM {$source_table}"
        );

        if (empty($certificates)) {
            wp_send_json_error(__('No records found in the base certificate table.', 'acmqr'));
        }

        $inserted_count = 0;

        foreach ($certificates as $cert) {
            if (empty($cert->user_id)) {
                continue;
            }

            $user_id = (int) $cert->user_id;
            $membership_label = 'Membership ' . date('Y', (int) $cert->date_issued);

            // Skip if user has no entry in the registry at all.
            $user_in_registry = (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$target_table} WHERE user_id = %d",
                $user_id
            ));

            if (!$user_in_registry) {
                continue;
            }

            // Skip if the matching membership-year row already exists.
            $row_exists = (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$target_table} WHERE user_id = %d AND cert_name = %s",
                $user_id,
                $membership_label
            ));

            if (!$row_exists) {
                continue;
            }

            $full_name = trim($cert->firstname . ' ' . $cert->surname);
            $unique_key = md5($user_id . $membership_label . time() . uniqid());

            $inserted = $wpdb->insert(
                $target_table,
                [
                    'cert_name' => $membership_label,
                    'user_id' => $user_id,
                    'user_name' => $full_name ?: __('Synchronized Member', 'acmqr'),
                    'user_email' => $cert->email,
                    'template_id' => 'sync_generated_template',
                    'cert_key' => $unique_key,
                    'cert_hmac' => wp_hash($unique_key),
                    'is_main' => 0,
                    'date_issued' => $cert->date_issued,
                    'date_expiry' => null,
                    'file_url' => '',
                ],
                ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
            );

            if ($inserted) {
                $inserted_count++;
            }
        }

        wp_send_json_success(sprintf(
            /* translators: %d: number of new registry entries created */
            __('Sync complete. Created %d new matching entr%s in the registry.', 'acmqr'),
            $inserted_count,
            $inserted_count === 1 ? 'y' : 'ies'
        ));
    }
}