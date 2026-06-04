<?php

final class ConferenceCleaner
{

    public function __construct()
    {

        add_action('admin_menu', [$this, 'bulk_db_checker_admin_menu']);
        add_action('wp_ajax_bulk_verify_and_update_purchases', [$this, 'process_bulk_buyer_sync_callback']);
    }
    function bulk_db_checker_admin_menu()
    {
        add_menu_page(
            'Bulk Purchase Sync',
            'Bulk Sync',
            'manage_options',
            'bulk-sync-page',
            [$this, 'bulk_buyer_sync_page_html'],
            'dashicons-groups',
            90
        );
    }

    /**
     * 2. Render the Admin Page UI with the Action Button.
     */
    function bulk_buyer_sync_page_html()
    {
        ?>
        <div class="wrap">
            <h1>Bulk Sync User Product Purchases</h1>
            <p>Clicking the button below loops through every user email inside your custom tracking table, verifies if they
                bought any targeted products, and updates their tracking entry.</p>

            <!-- Action Button -->
            <button id="bulk-sync-btn" class="button button-primary button-large">
                Scan and Update All Users
            </button>

            <!-- Dynamic Response Box -->
            <div id="bulk-response-message" style="margin-top: 20px; padding: 10px; display: none; max-width: 600px;"></div>
        </div>

        <!-- 3. AJAX Script to handle the background execution -->
        <script type="text/javascript">
            jQuery(document).ready(function ($) {
                $('#bulk-sync-btn').on('click', function (e) {
                    e.preventDefault();

                    var $button = $(this);
                    var $responseBox = $('#bulk-response-message');

                    // UI loading state
                    $button.prop('disabled', true).text('Processing database rows... (Do not close page)');
                    $responseBox.hide().removeClass('notice notice-success notice-error');

                    $.post(ajaxurl, {
                        action: 'bulk_verify_and_update_purchases',
                        nonce: '<?php echo wp_create_nonce("bulk_sync_nonce"); ?>'
                    }, function (response) {
                        $button.prop('disabled', false).text('Scan and Update All Users');
                        $responseBox.show().html('<p>' + response.data.message + '</p>');

                        if (response.success) {
                            $responseBox.addClass('notice notice-success');
                        } else {
                            $responseBox.addClass('notice notice-error');
                        }
                    }).fail(function () {
                        $button.prop('disabled', false).text('Scan and Update All Users');
                        $responseBox.show().addClass('notice notice-error').html('<p>A server timeout or connection error occurred during processing.</p>');
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * 4. PHP Backend AJAX Handler (Processes all table entries).
     */
    function process_bulk_buyer_sync_callback()
    {
        global $wpdb;

        // Security Check
        if (!check_ajax_referer('bulk_sync_nonce', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Security check failed. Unauthorized action.'));
        }

        // Configuration Settings
        $table_name = $wpdb->prefix . 'nsa_registrations';
        $email_column = 'email';
        $targeted_products = array(12818, 12817, 12816, 12672, 12670);

        // 1. Fetch all distinct email addresses present in your custom table
        // Using a select query to pull only what is required to keep memory usage safe
        $db_users = $wpdb->get_results("SELECT DISTINCT `$email_column` FROM `$table_name` WHERE `$email_column` IS NOT NULL AND `$email_column` != ''");

        if (empty($db_users)) {
            wp_send_json_error(array('message' => 'No target entries or email addresses found inside the database table.'));
        }

        $updated_count = 0;
        $no_match_count = 0;

        // 2. Loop through every email found in the table
        foreach ($db_users as $row) {
            $email = trim($row->$email_column);

            // Attempt to find a matching WordPress user ID for this email (helps optimize WooCommerce check)
            $user_obj = get_user_by('email', $email);
            $user_id = $user_obj ? $user_obj->ID : 0;

            $has_purchased = false;

            // 3. Verify purchase history (Assuming WooCommerce functions)
            $purchased_field = [];
            if (function_exists('wc_customer_bought_product')) {
                foreach ($targeted_products as $product_id) {
                    if (wc_customer_bought_product($email, 0, $product_id)) {
                        $purchased_field[] = true;
                    } else {
                        $purchased_field[] = false;
                    }
                }
            }

            $has_purchased = array_all($purchased_field, fn($value) => $value === true);
            if ($has_purchased) {
                $updated = $wpdb->update(
                    $table_name,
                    array(
                       "id"=>$row->id,
                       "payment_status"=>$row->payment_status,
                    ),
                    array($email_column => $email), 
                    array('%d', '%s'),
                    array('%s')
                );

                if ($updated !== false && $updated > 0) {
                    $updated_count++;
                }
            } else {
                $no_match_count++;
            }
        }

        // 5. Send final summary feedback to the Admin UI
        wp_send_json_success(array(
            'message' => sprintf(
                'Scan complete! Total records analyzed: %d. Successful database updates: %d. No matching purchases found for: %d accounts.',
                count($db_users),
                $updated_count,
                $no_match_count
            )
        ));
    }
}