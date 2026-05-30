<?php
namespace Certificates\Includes;

define('CISON_CERT_TABLE', 'wprx_cison_certificates');
class CertManager_Admin_UI
{
    private $templates_table;
    private $registry_table;

    public function __construct()
    {
        global $wpdb;
        $this->templates_table = $wpdb->prefix . 'cert_templates';
        $this->registry_table = $wpdb->prefix . 'cert_registry';

        add_action('admin_menu', [$this, 'add_admin_menu']);
        // add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        // add_action('wp_ajax_acmqr_fetch_profile', [$this, 'ajax_fetch_profile']);
        // add_action('wp_ajax_acmqr_generate_certificate', [$this, 'ajax_generate_certificate']);
        // add_action('wp_ajax_acmqr_get_templates', [$this, 'ajax_get_templates']);
        add_action('wp_ajax_cison_sync_membership_status', [$this, 'handle_membership_sync']);

    }

    public function add_admin_menu()
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

    public function render_admin_page()
    {
        global $wpdb;

        $per_page = 20;
        $current_page = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
        $offset = ($current_page - 1) * $per_page;

        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $this->registry_table");
        $total_pages = ceil($total_items / $per_page);

        $entries = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $this->registry_table ORDER BY date_issued DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );

        ?>
        <div class="wrap">
            <h1>CISON Membership Status Sync</h1>

            <button id="sync-membership-btn" class="button button-primary" style="margin:20px 0;padding: 5px 15px;">Sync
                Membership Status</button>
            <div id="sync-status-message"
                style="margin-top: 10px; font-weight: bold; padding: 10px; display: none; border-left: 4px solid #fff;"></div>

            <!-- Registry Display Table Section -->
            <h2>Certificate Registry Entries</h2>

            <!-- WordPress Top Pagination Interface Link Styling Element -->
            <div class="tablenav top">
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo number_format_i18n($total_items); ?> items</span>
                    <?php
                    echo paginate_links(array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('&laquo; Previous'),
                        'next_text' => __('Next &raquo;'),
                        'total' => $total_pages,
                        'current' => $current_page,
                    ));
                    ?>
                </div>
                <br class="clear" />
            </div>

            <table class="wp-list-table widefat fixed striped posts">
                <thead>
                    <tr>
                        <th style="width: 80px;">ID</th>
                        <th>User ID</th>
                        <th>Certificate Name</th>
                        <th>User Email</th>
                        <th>Date Issued</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($entries)): ?>
                        <?php foreach ($entries as $entry): ?>
                            <tr>
                                <td><strong>#<?php echo esc_html($entry->id); ?></strong></td>
                                <td><?php echo esc_html($entry->user_id ? $entry->user_id : 'N/A'); ?></td>
                                <td><?php echo esc_html($entry->cert_name); ?></td>
                                <td><?php echo esc_html($entry->user_email); ?></td>
                                <td><?php echo esc_html($entry->date_issued); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5">No registry records found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- WordPress Bottom Pagination Interface Link Styling Element -->
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links(array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('&laquo; Previous'),
                        'next_text' => __('Next &raquo;'),
                        'total' => $total_pages,
                        'current' => $current_page,
                    ));
                    ?>
                </div>
                <br class="clear" />
            </div>
        </div>

        <!-- AJAX JavaScript Handler -->
        <script type="text/javascript">
            jQuery(document).ready(function ($) {
                $('#sync-membership-btn').on('click', function (e) {
                    e.preventDefault();
                    var button = $(this);
                    var statusDiv = $('#sync-status-message');

                    button.prop('disabled', true).text('Processing Sync...');
                    statusDiv.show().css({ 'color': '#333', 'background': '#fff', 'border-color': '#ffb900' }).text('Scanning tables. This can take a moment...');

                    $.post(ajaxurl, {
                        action: 'cison_sync_membership_status',
                        nonce: '<?php echo wp_create_nonce("cison_sync_nonce"); ?>'
                    }, function (response) {
                        button.prop('disabled', false).text('Sync Membership Status');
                        if (response.success) {
                            statusDiv.css({ 'color': 'green', 'background': '#ecf7ed', 'border-color': '#46b450' }).text(response.data);
                            // Reload after success to show updated table content on page 1
                            setTimeout(function () {
                                window.location.href = window.location.href.split('?')[0] + '?page=membership-sync';
                            }, 2000);
                        } else {
                            statusDiv.css({ 'color': 'red', 'background': '#fbeae5', 'border-color': '#dc3232' }).text(response.data);
                        }
                    });
                });
            });
        </script>
        <?php
    }

    public function handle_membership_sync()
    {
        // Security & capabilities authorization check
        check_ajax_referer('cison_sync_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized process execution attempt.');
        }

        global $wpdb;

        // Explicit assignments from schemas
        $table_a = CISON_CERT_TABLE;
        $table_b = $wpdb->prefix . 'cert_registry';

        // Retrieve requested data inputs from Table A
        $certificates = $wpdb->get_results("SELECT user_id, email, date_issued, firstname, surname FROM $table_a");

        if (empty($certificates)) {
            wp_send_json_error('No records found in the base certificate table.');
        }

        $inserted_count = 0;

        foreach ($certificates as $cert) {
            $user_id = $cert->user_id;

            // Skip entry if user_id is empty or evaluated as zero
            if (empty($user_id)) {
                continue;
            }

            // Table A explicitly types date_issued as BIGINT unix timestamp
            $year = date('Y', intval($cert->date_issued));
            $membership_string = "Membership " . $year;

            // Condition 1: Check whether a user with the user ID is in the second table (Table B)
            $user_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_b WHERE user_id = %d",
                $user_id
            ));

            if (!$user_exists) {
                continue; // Condition failed: close process loop step
            }

            // Condition 2: Check whether for that user entry, there is a name (cert_name) matching "Membership [year]"
            $string_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_b WHERE user_id = %d AND cert_name = %s",
                $user_id,
                $membership_string
            ));

            if (!$string_exists) {
                continue; // Condition failed: close process loop step
            }

            // Both conditions passed! Proceeding to generate unique items and create the new record in Table B
            $full_name = trim($cert->firstname . ' ' . $cert->surname);
            $unique_key = md5($user_id . $membership_string . time() . uniqid());
            $hmac_key = wp_hash($unique_key);

            $inserted = $wpdb->insert(
                $table_b,
                array(
                    'cert_name' => $membership_string,
                    'user_id' => $user_id,
                    'user_name' => !empty($full_name) ? $full_name : 'Synchronized Member',
                    'user_email' => $cert->email,
                    'template_id' => 'sync_generated_template',
                    'cert_key' => $unique_key,
                    'cert_hmac' => $hmac_key,
                    'is_main' => 0,
                    'date_issued' => current_time('mysql'),
                    'date_expiry' => null,
                    'file_url' => ''
                ),
                array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
            );

            if ($inserted) {
                $inserted_count++;
            }
        }

        wp_send_json_success("Sync processed completely! Created " . $inserted_count . " new matching entries in the registry.");
    }

}