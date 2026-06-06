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
        add_action('wp_ajax_cison_delete_entry', [$this, 'handle_delete_entry']);
        add_action('wp_ajax_cison_update_entry', [$this, 'handle_update_entry']);
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
            "SELECT id, user_id, cert_name, user_email, date_issued, date_expiry, file_url, is_main
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

        $migrated_count = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$this->registry_table} b
             WHERE EXISTS (
                 SELECT 1 FROM {$source_table} a
                 WHERE a.cert_id = b.cert_key
             )"
        );

        $migrate_nonce      = wp_create_nonce('cison_sync_nonce');
        $reverse_nonce      = wp_create_nonce('cison_reverse_nonce');
        $delete_nonce       = wp_create_nonce('cison_delete_nonce');
        $delete_entry_nonce = wp_create_nonce('cison_delete_entry_nonce');
        $update_entry_nonce = wp_create_nonce('cison_update_entry_nonce');

        $pagination_args = [
            'base'      => add_query_arg('paged', '%#%'),
            'format'    => '',
            'prev_text' => __('&laquo; Previous'),
            'next_text' => __('Next &raquo;'),
            'total'     => $total_pages,
            'current'   => $current_page,
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
                        <th style="width:60px;"><?php esc_html_e('ID', 'acmqr'); ?></th>
                        <th><?php esc_html_e('User ID', 'acmqr'); ?></th>
                        <th><?php esc_html_e('Certificate Name', 'acmqr'); ?></th>
                        <th><?php esc_html_e('User Email', 'acmqr'); ?></th>
                        <th><?php esc_html_e('Date Issued', 'acmqr'); ?></th>
                        <th><?php esc_html_e('Cert Path', 'acmqr'); ?></th>
                        <th style="width:120px;"><?php esc_html_e('Actions', 'acmqr'); ?></th>
                    </tr>
                </thead>
                <tbody id="cert-registry-tbody">
                    <?php if (!empty($entries)): ?>
                        <?php foreach ($entries as $entry): ?>
                            <tr id="cert-row-<?php echo esc_attr($entry->id); ?>">
                                <td><strong>#<?php echo esc_html($entry->id); ?></strong></td>
                                <td><?php echo esc_html($entry->user_id ?: 'N/A'); ?></td>
                                <td><?php echo esc_html($entry->cert_name); ?></td>
                                <td><?php echo esc_html($entry->user_email); ?></td>
                                <td><?php echo esc_html($entry->date_issued); ?></td>
                                <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                    title="<?php echo esc_attr($entry->file_url); ?>">
                                    <?php echo esc_html($entry->file_url); ?>
                                </td>
                                <td>
                                    <button
                                        class="button button-small btn-edit-entry"
                                        style="margin-right:4px;"
                                        data-id="<?php echo esc_attr($entry->id); ?>"
                                        data-cert-name="<?php echo esc_attr($entry->cert_name); ?>"
                                        data-user-email="<?php echo esc_attr($entry->user_email); ?>"
                                        data-date-expiry="<?php echo esc_attr($entry->date_expiry ?? ''); ?>"
                                        data-is-main="<?php echo esc_attr($entry->is_main ?? 0); ?>"
                                        data-file-url="<?php echo esc_attr($entry->file_url); ?>"
                                    >
                                        <?php esc_html_e('Edit', 'acmqr'); ?>
                                    </button>
                                    <button
                                        class="button button-small btn-delete-entry"
                                        style="border-color:#d63638;color:#d63638;"
                                        data-id="<?php echo esc_attr($entry->id); ?>"
                                    >
                                        <?php esc_html_e('Delete', 'acmqr'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr id="no-entries-row">
                            <td colspan="7"><?php esc_html_e('No registry records found.', 'acmqr'); ?></td>
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

        <!-- ============================================================
             Edit Entry Modal
             ============================================================ -->
        <div id="cison-edit-modal" style="
            display:none;
            position:fixed;
            inset:0;
            z-index:100000;
            background:rgba(0,0,0,.55);
            align-items:center;
            justify-content:center;
        ">
            <div style="
                background:#fff;
                border-radius:6px;
                box-shadow:0 8px 32px rgba(0,0,0,.25);
                padding:28px 32px;
                width:480px;
                max-width:94vw;
                position:relative;
            ">
                <button id="cison-modal-close" style="
                    position:absolute;top:12px;right:14px;
                    background:none;border:none;font-size:22px;
                    cursor:pointer;color:#666;line-height:1;
                " aria-label="<?php esc_attr_e('Close', 'acmqr'); ?>">&times;</button>

                <h2 style="margin-top:0;margin-bottom:20px;font-size:17px;">
                    <?php esc_html_e('Edit Certificate Entry', 'acmqr'); ?>
                    <span id="modal-entry-id" style="color:#888;font-weight:400;"></span>
                </h2>

                <input type="hidden" id="modal-id">

                <table class="form-table" style="margin:0;">
                    <tr>
                        <th style="padding:8px 10px 8px 0;width:130px;">
                            <label for="modal-cert-name"><?php esc_html_e('Certificate Name', 'acmqr'); ?></label>
                        </th>
                        <td style="padding:6px 0;">
                            <input type="text" id="modal-cert-name" class="regular-text" style="width:100%;">
                        </td>
                    </tr>
                    <tr>
                        <th style="padding:8px 10px 8px 0;">
                            <label for="modal-user-email"><?php esc_html_e('User Email', 'acmqr'); ?></label>
                        </th>
                        <td style="padding:6px 0;">
                            <input type="email" id="modal-user-email" class="regular-text" style="width:100%;">
                        </td>
                    </tr>
                    <tr>
                        <th style="padding:8px 10px 8px 0;">
                            <label for="modal-date-expiry"><?php esc_html_e('Expiry Date', 'acmqr'); ?></label>
                        </th>
                        <td style="padding:6px 0;">
                            <input type="date" id="modal-date-expiry" class="regular-text" style="width:100%;">
                        </td>
                    </tr>
                    <tr>
                        <th style="padding:8px 10px 8px 0;">
                            <label for="modal-is-main"><?php esc_html_e('Main Certificate', 'acmqr'); ?></label>
                        </th>
                        <td style="padding:6px 0;">
                            <select id="modal-is-main" style="width:100%;">
                                <option value="1"><?php esc_html_e('Yes', 'acmqr'); ?></option>
                                <option value="0"><?php esc_html_e('No', 'acmqr'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th style="padding:8px 10px 8px 0;">
                            <label for="modal-file-url"><?php esc_html_e('File URL', 'acmqr'); ?></label>
                        </th>
                        <td style="padding:6px 0;">
                            <input type="url" id="modal-file-url" class="regular-text" style="width:100%;">
                        </td>
                    </tr>
                </table>

                <div id="modal-status" style="
                    display:none;
                    margin-top:14px;
                    padding:8px 12px;
                    border-left:4px solid transparent;
                    font-size:13px;
                "></div>

                <div style="margin-top:20px;display:flex;gap:10px;justify-content:flex-end;">
                    <button id="cison-modal-cancel" class="button">
                        <?php esc_html_e('Cancel', 'acmqr'); ?>
                    </button>
                    <button id="cison-modal-save" class="button button-primary">
                        <?php esc_html_e('Save Changes', 'acmqr'); ?>
                    </button>
                </div>
            </div>
        </div>
        <!-- /Edit Modal -->

        <script>
        jQuery(function ($) {

            /* ------ shared helpers ------ */

            const statusDiv = $('#sync-status-message');

            const STATES = {
                pending: { color: '#333',   background: '#fff',     borderColor: '#ffb900' },
                success: { color: 'green',  background: '#ecf7ed',  borderColor: '#46b450' },
                error:   { color: 'red',    background: '#fbeae5',  borderColor: '#dc3232' },
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
                    if (confirmMsg && !window.confirm(confirmMsg)) return;

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

            /* ------ bulk action buttons ------ */

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

            /* ============================================================
             * Per-row: DELETE
             * ============================================================ */

            $(document).on('click', '.btn-delete-entry', function () {
                const btn = $(this);
                const id  = btn.data('id');

                if (!window.confirm(
                    '<?php echo esc_js(__('Delete certificate entry #', 'acmqr')); ?>' + id +
                    '<?php echo esc_js(__('? This cannot be undone.', 'acmqr')); ?>'
                )) return;

                btn.prop('disabled', true).text('<?php echo esc_js(__('Deleting…', 'acmqr')); ?>');
                setState('pending', '<?php echo esc_js(__('Deleting entry…', 'acmqr')); ?>');

                $.post(ajaxurl, {
                    action: 'cison_delete_entry',
                    nonce:  '<?php echo esc_js($delete_entry_nonce); ?>',
                    id:     id,
                })
                .done(function (response) {
                    if (response.success) {
                        $('#cert-row-' + id).fadeOut(300, function () {
                            $(this).remove();
                            if ($('#cert-registry-tbody tr:visible').length === 0) {
                                $('#cert-registry-tbody').append(
                                    '<tr id="no-entries-row"><td colspan="7"><?php echo esc_js(__('No registry records found.', 'acmqr')); ?></td></tr>'
                                );
                            }
                        });
                        setState('success', response.data.message);
                    } else {
                        setState('error', response.data.message ?? '<?php echo esc_js(__('Delete failed.', 'acmqr')); ?>');
                        btn.prop('disabled', false).text('<?php echo esc_js(__('Delete', 'acmqr')); ?>');
                    }
                })
                .fail(function () {
                    setState('error', '<?php echo esc_js(__('Request failed. Please try again.', 'acmqr')); ?>');
                    btn.prop('disabled', false).text('<?php echo esc_js(__('Delete', 'acmqr')); ?>');
                });
            });

            /* ============================================================
             * Per-row: EDIT — open modal
             * ============================================================ */

            const modal       = $('#cison-edit-modal');
            const modalStatus = $('#modal-status');

            function openModal(data) {
                $('#modal-id').val(data.id);
                $('#modal-entry-id').text(' — #' + data.id);
                $('#modal-cert-name').val(data.certName);
                $('#modal-user-email').val(data.userEmail);
                $('#modal-date-expiry').val(data.dateExpiry);
                $('#modal-is-main').val(data.isMain);
                $('#modal-file-url').val(data.fileUrl);
                modalStatus.hide().text('');
                modal.css('display', 'flex');
            }

            function closeModal() {
                modal.hide();
            }

            $(document).on('click', '.btn-edit-entry', function () {
                const btn = $(this);
                openModal({
                    id:         btn.data('id'),
                    certName:   btn.data('cert-name'),
                    userEmail:  btn.data('user-email'),
                    dateExpiry: btn.data('date-expiry'),
                    isMain:     btn.data('is-main'),
                    fileUrl:    btn.data('file-url'),
                });
            });

            $('#cison-modal-close, #cison-modal-cancel').on('click', closeModal);

            // Close on backdrop click
            modal.on('click', function (e) {
                if ($(e.target).is(modal)) closeModal();
            });

            /* ============================================================
             * Modal: SAVE (update entry)
             * ============================================================ */

            $('#cison-modal-save').on('click', function () {
                const saveBtn = $(this);
                const id      = $('#modal-id').val();

                saveBtn.prop('disabled', true).text('<?php echo esc_js(__('Saving…', 'acmqr')); ?>');
                modalStatus.hide();

                $.post(ajaxurl, {
                    action:      'cison_update_entry',
                    nonce:       '<?php echo esc_js($update_entry_nonce); ?>',
                    id:          id,
                    cert_name:   $('#modal-cert-name').val(),
                    user_email:  $('#modal-user-email').val(),
                    date_expiry: $('#modal-date-expiry').val(),
                    is_main:     $('#modal-is-main').val(),
                    file_url:    $('#modal-file-url').val(),
                })
                .done(function (response) {
                    if (response.success) {
                        // Reflect updated values in the table row
                        const row = $('#cert-row-' + id);
                        row.find('td:nth-child(3)').text($('#modal-cert-name').val());
                        row.find('td:nth-child(4)').text($('#modal-user-email').val());

                        // Sync data-attributes so re-opening the modal shows current values
                        row.find('.btn-edit-entry')
                            .data('cert-name',   $('#modal-cert-name').val())
                            .data('user-email',  $('#modal-user-email').val())
                            .data('date-expiry', $('#modal-date-expiry').val())
                            .data('is-main',     $('#modal-is-main').val())
                            .data('file-url',    $('#modal-file-url').val());

                        setState('success', response.data.message);
                        closeModal();
                    } else {
                        modalStatus
                            .css({ color: 'red', background: '#fbeae5', borderColor: '#dc3232', display: 'block' })
                            .text(response.data.message ?? '<?php echo esc_js(__('Update failed.', 'acmqr')); ?>');
                    }
                })
                .fail(function () {
                    modalStatus
                        .css({ color: 'red', background: '#fbeae5', borderColor: '#dc3232', display: 'block' })
                        .text('<?php echo esc_js(__('Request failed. Please try again.', 'acmqr')); ?>');
                })
                .always(function () {
                    saveBtn.prop('disabled', false).text('<?php echo esc_js(__('Save Changes', 'acmqr')); ?>');
                });
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
                'logs'    => ['Security block: insufficient permissions.'],
            ]);
        }
    }

    /* -------------------------------------------------------
     * AJAX: delete a single entry by ID
     * ----------------------------------------------------- */

    public function handle_delete_entry(): void
    {
        $this->verify_ajax('cison_delete_entry_nonce', 'nonce');

        global $wpdb;

        $id = intval($_POST['id'] ?? 0);

        if ($id <= 0) {
            wp_send_json_error(['message' => __('Invalid entry ID.', 'acmqr')]);
        }

        $deleted = $wpdb->delete(
            $this->registry_table,
            ['id' => $id],
            ['%d']
        );

        if ($deleted === false || $deleted === 0) {
            wp_send_json_error([
                'message' => __('Entry not found or already deleted.', 'acmqr'),
            ]);
        }

        wp_send_json_success([
            'message' => sprintf(__('Entry #%d has been deleted.', 'acmqr'), $id),
        ]);
    }

    /* -------------------------------------------------------
     * AJAX: update a single entry by ID
     * ----------------------------------------------------- */

    public function handle_update_entry(): void
    {
        $this->verify_ajax('cison_update_entry_nonce', 'nonce');

        global $wpdb;

        $id = intval($_POST['id'] ?? 0);

        if ($id <= 0) {
            wp_send_json_error(['message' => __('Invalid entry ID.', 'acmqr')]);
        }

        $exists = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$this->registry_table} WHERE id = %d", $id)
        );

        if (!$exists) {
            wp_send_json_error(['message' => __('Entry not found.', 'acmqr')]);
        }

        $update_data   = [];
        $update_format = [];

        if (isset($_POST['cert_name'])) {
            $update_data['cert_name'] = sanitize_text_field($_POST['cert_name']);
            $update_format[]          = '%s';
        }

        if (isset($_POST['user_email'])) {
            $update_data['user_email'] = sanitize_email($_POST['user_email']);
            $update_format[]           = '%s';
        }

        if (isset($_POST['date_expiry'])) {
            $raw = sanitize_text_field($_POST['date_expiry']);
            $update_data['date_expiry'] = $raw !== '' ? $raw : null;
            $update_format[]            = '%s';
        }

        if (isset($_POST['is_main'])) {
            $update_data['is_main'] = intval($_POST['is_main']);
            $update_format[]        = '%d';
        }

        if (isset($_POST['file_url'])) {
            $update_data['file_url'] = esc_url_raw($_POST['file_url']);
            $update_format[]         = '%s';
        }

        if (empty($update_data)) {
            wp_send_json_error(['message' => __('No data provided to update.', 'acmqr')]);
        }

        $result = $wpdb->update(
            $this->registry_table,
            $update_data,
            ['id' => $id],
            $update_format,
            ['%d']
        );

        if ($result === false) {
            wp_send_json_error([
                'message' => __('Update failed: ', 'acmqr') . $wpdb->last_error,
            ]);
        }

        wp_send_json_success([
            'message' => sprintf(__('Entry #%d updated successfully.', 'acmqr'), $id),
        ]);
    }

    /* -------------------------------------------------------
     * AJAX: copy source cert table → registry
     * ----------------------------------------------------- */

    public function handle_migration(): void
    {
        $this->verify_ajax('cison_sync_nonce', 'nonce');

        global $wpdb;

        $logs         = [];
        $source_table = CISON_CERT_TABLE;
        $target_table = $this->registry_table;

        $logs[] = 'Starting migration...';
        $logs[] = "Source: {$source_table} → Target: {$target_table}";

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
                'logs'    => $logs,
            ]);
        }

        $total = count($candidates);

        if ($total === 0) {
            $logs[] = 'All source users are already present in the registry. Nothing to copy.';
            wp_send_json_success([
                'message' => __('All users are already in the registry.', 'acmqr'),
                'logs'    => $logs,
            ]);
        }

        $logs[] = "Found {$total} user(s) not yet in registry. Inserting...";

        $inserted = 0;
        $failed   = 0;

        foreach ($candidates as $row) {
            $user_id   = (int) $row->user_id;
            $full_name = trim(implode(' ', array_filter([
                $row->firstname,
                $row->middlename,
                $row->surname,
            ])));

            $custom_date        = date('Y-m-d H:i:s', (int) $row->date_issued);
            $year               = date('Y', strtotime($custom_date));
            $date_expired_mysql = date('Y-m-d H:i:s', strtotime('+2 years', (int) $row->date_issued));

            $result = $wpdb->insert(
                $target_table,
                [
                    'cert_name'   => "Membership $year",
                    'user_id'     => $user_id,
                    'user_name'   => $full_name ?: __('CISON Member', 'acmqr'),
                    'user_email'  => $row->email,
                    'template_id' => $row->member_type ?: 'standard',
                    'cert_key'    => $row->cert_id,
                    'cert_hmac'   => $row->secret_token,
                    'is_main'     => 1,
                    'date_issued' => date('Y-m-d H:i:s', (int) $row->date_issued),
                    'date_expiry' => $date_expired_mysql,
                    'file_url'    => $row->certificate_path,
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

        $logs         = [];
        $source_table = CISON_CERT_TABLE;
        $target_table = $this->registry_table;

        $logs[] = 'Starting reverse migration...';

        $deleted = $wpdb->query(
            "DELETE b FROM {$target_table} b
             INNER JOIN {$source_table} a ON a.cert_id = b.cert_key"
        );

        if ($wpdb->last_error) {
            $logs[] = 'SQL error during reverse: ' . $wpdb->last_error;
            wp_send_json_error([
                'message' => __('Reverse migration failed. See logs for details.', 'acmqr'),
                'logs'    => $logs,
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

        $logs         = [];
        $logs[]       = 'Truncating registry table...';
        $count_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->registry_table}");

        $result = $wpdb->query("TRUNCATE TABLE {$this->registry_table}");

        if ($result === false) {
            $logs[] = 'TRUNCATE failed: ' . $wpdb->last_error;
            wp_send_json_error([
                'message' => __('Delete failed. See logs for details.', 'acmqr'),
                'logs'    => $logs,
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