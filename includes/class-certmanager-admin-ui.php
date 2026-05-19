<?php
namespace Certificates\Includes;

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
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_acmqr_fetch_profile', [$this, 'ajax_fetch_profile']);
        add_action('wp_ajax_acmqr_generate_certificate', [$this, 'ajax_generate_certificate']);
        add_action('wp_ajax_acmqr_get_templates', [$this, 'ajax_get_templates']);
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

    public function enqueue_assets($hook)
    {
        if ($hook !== 'tools_page_acmqr-issue-cert') {
            return;
        }
        wp_enqueue_script('acmqr-admin', ACMQR_PLUGIN_URL . 'assets/js/admin-wizard.js', ['jquery'], '1.0.0', true);
        wp_localize_script('acmqr-admin', 'acmqr_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('acmqr_admin_nonce'),
        ]);
        wp_enqueue_style('acmqr-admin', ACMQR_PLUGIN_URL . 'assets/css/admin-wizard.css', [], '1.0.0');
    }

    private function get_data()
    {
        global $wpdb;
        $table_name = $this->registry_table;



        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY id "
        ));

        return [
            'results' => $results,

        ];
    }

    public function render_admin_page()
    {
        // 1. Fetch data from your custom database schema
        // $data = $this->get_data(); // Expecting an array containing 'results', 'current_page', and 'total_pages'

        // 2. Safely capture the HTML block into memory
        ob_start();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Issue Certificate', 'acmqr'); ?></h1>
            <div id="acmqr-wizard">
                <div class="wizard-steps">
                    <div class="step active" data-step="1"><?php _e('Template & Recipient', 'acmqr'); ?></div>
                    <div class="step" data-step="2"><?php _e('Details & Generate', 'acmqr'); ?></div>
                    <div class="step" data-step="3"><?php _e('Success', 'acmqr'); ?></div>
                </div>
                <div class="wizard-content">
                    <div id="step1" class="step-content active">
                        <form id="step1-form">
                            <table class="form-table">
                                <tr>
                                    <th><label for="template_id"><?php _e('Certificate Template', 'acmqr'); ?></label></th>
                                    <td>
                                        <select name="template_id" id="template_id" required>
                                            <option value=""><?php _e('Loading...', 'acmqr'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="is_institutional"><?php _e('Institutional Member?', 'acmqr'); ?></label>
                                    </th>
                                    <td>
                                        <input type="radio" name="is_institutional" value="yes" id="inst_yes">
                                        <?php _e('Yes', 'acmqr'); ?>
                                        <input type="radio" name="is_institutional" value="no" id="inst_no" checked>
                                        <?php _e('No (Guest)', 'acmqr'); ?>
                                    </td>
                                </tr>
                                <tr id="membership_row" style="display:none;">
                                    <th><label for="membership_number"><?php _e('Membership Number', 'acmqr'); ?></label></th>
                                    <td>
                                        <input type="text" name="membership_number" id="membership_number">
                                        <button type="button" id="fetch_profile_btn"
                                            class="button"><?php _e('Fetch Profile', 'acmqr'); ?></button>
                                        <span id="profile_feedback"></span>
                                    </td>
                                </tr>
                                <tr id="guest_row">
                                    <th><label for="guest_email"><?php _e('Guest Email', 'acmqr'); ?></label></th>
                                    <td><input type="email" name="guest_email" id="guest_email"></td>
                                </tr>
                            </table>
                            <button type="button" id="step1_next"
                                class="button button-primary"><?php _e('Next', 'acmqr'); ?></button>
                        </form>
                    </div>
                    <div id="step2" class="step-content">
                        <form id="step2-form">
                            <div id="user_details_fields"></div>
                            <label><input type="checkbox" name="is_main" value="1">
                                <?php _e('Mark as Main Certificate', 'acmqr'); ?></label>
                            <br><br>
                            <button type="button" id="step2_back" class="button"><?php _e('Back', 'acmqr'); ?></button>
                            <button type="button" id="generate_cert_btn"
                                class="button button-primary"><?php _e('Generate Certificate', 'acmqr'); ?></button>
                        </form>
                    </div>
                    <div id="step3" class="step-content">
                        <div id="success_message"></div>
                        <div id="cert_actions"></div>
                        <button type="button" id="step3_new" class="button"><?php _e('Issue Another', 'acmqr'); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
        // 3. Collect the buffered content string and close out cleanly
        return ob_get_clean();
    }


    // AJAX: fetch user profile by membership number
    public function ajax_fetch_profile()
    {
        check_ajax_referer('acmqr_admin_nonce', 'nonce');
        // if (!current_user_can('manage_options')) {
        //     wp_die('Forbidden');
        // }
        $membership = sanitize_text_field($_POST['membership_number']);
        if (empty($membership)) {
            wp_send_json_error('Membership number required.');
        }
        $user_query = new \WP_User_Query([
            'meta_key' => 'membership_number',
            'meta_value' => $membership,
            'number' => 1
        ]);
        $users = $user_query->get_results();
        if (empty($users)) {
            wp_send_json_error('No user found with that membership number.');
        }
        $user = $users[0];
        wp_send_json_success([
            'user_id' => $user->ID,
            'full_name' => $user->display_name,
            'email' => $user->user_email,
            'membership' => $membership
        ]);
    }

    // AJAX: get templates list for dropdown
    public function ajax_get_templates()
    {
        check_ajax_referer('acmqr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        global $wpdb;
        $templates = $wpdb->get_results("SELECT id, title FROM {$this->templates_table}");
        wp_send_json_success($templates);
    }

    // AJAX: generate certificate, create guest if needed, update user data, call registry engine
    public function ajax_generate_certificate()
    {
        check_ajax_referer('acmqr_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $template_id = intval($_POST['template_id']);
        $is_main = isset($_POST['is_main']) ? (bool) $_POST['is_main'] : false;
        $full_name = sanitize_text_field($_POST['full_name']);
        $email = sanitize_email($_POST['email']);
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $is_institutional = $_POST['is_institutional'] === 'yes';

        // If institutional but no user_id provided (should have been fetched), error
        if ($is_institutional && !$user_id) {
            wp_send_json_error('Institutional member requires a valid user account.');
        }

        // Guest: create or fetch user by email
        if (!$is_institutional) {
            $existing = get_user_by('email', $email);
            if ($existing) {
                $user_id = $existing->ID;
                // Update display name if changed
                if ($existing->display_name !== $full_name) {
                    wp_update_user(['ID' => $existing->ID, 'display_name' => $full_name]);
                }
            } else {
                // Create new user (subscriber role)
                $username = sanitize_user($email);
                $user_id = wp_insert_user([
                    'user_login' => $username,
                    'user_email' => $email,
                    'display_name' => $full_name,
                    'role' => 'subscriber',
                    'user_pass' => wp_generate_password()
                ]);
                if (is_wp_error($user_id)) {
                    wp_send_json_error('Could not create user account: ' . $user_id->get_error_message());
                }
            }
        } else {
            // Update institutional user's display name if changed
            $user = get_user_by('ID', $user_id);
            if ($user && $user->display_name !== $full_name) {
                wp_update_user(['ID' => $user_id, 'display_name' => $full_name]);
            }
            // Also update email if changed? careful – maybe allow but require confirmation?
            if ($user && $user->user_email !== $email) {
                wp_update_user(['ID' => $user_id, 'user_email' => $email]);
            }
        }

        // Issue certificate using the existing engine
        $result = CertRegistry_Engine::issue_certificate($user_id, $template_id, $is_main);
        if (!$result) {
            wp_send_json_error('Certificate issuance failed. Check database or template validity.');
        }

        // Build print preview URL (front-end page with shortcode)
        $preview_url = home_url('/?certificate_instance_id=' . $result['id']);

        wp_send_json_success([
            'cert_id' => $result['id'],
            'cert_key' => $result['cert_key'],
            'preview_url' => $preview_url,
            'message' => sprintf(__('Certificate generated successfully! ID: %d', 'acmqr'), $result['id'])
        ]);
    }
}