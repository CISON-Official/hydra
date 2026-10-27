<?php
namespace Certificates\Includes;


class Config
{
    public static function get($key, $default = null)
    {
        if (defined($key)) {
            return constant($key);
        }

        $env = getenv($key);
        if ($env !== false) {
            return $env;
        }

        return $default;
    }
}


class VerificationRouter
{
    public function __construct()
    {
        // 1. Handle secure routing/redirects BEFORE headers are sent
        add_action('template_redirect', array($this, 'handle_early_routing'));

        // 2. Register shortcode strictly for rendering layout
        add_shortcode('db_qr_verifier', array($this, 'intercept_qr_scan'));
    }

    /**
     * Intercepts request early to perform safe URL redirects if parameters are bad.
     */
    public function handle_early_routing()
    {
        global $post;

        // Only run this logic on pages containing your specific shortcode
        if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'db_qr_verifier')) {
            return;
        }

        // Validate required parameters
        $key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        $hmac = isset($_GET['hmac']) ? sanitize_text_field($_GET['hmac']) : '';


        if (empty($key) || empty($hmac)) {
            wp_safe_redirect(home_url());
            exit;
        }

        $result = $this->validate_certificate($key, $hmac);

        if ($result === false) {
            wp_safe_redirect(home_url());
            exit;
        }

        // Optional: Expiry validation can be un-commented safely here
        /*
        $current_time = current_time('mysql');
        if ($result->date_expiry !== null && strtotime($result->date_expiry) < strtotime($current_time)) {
            wp_safe_redirect(home_url());
            exit;
        }
        */
    }

    /**
     * Shortcode execution callback. Only fires if early routing checks passed.
     */
    public function intercept_qr_scan()
    {
        $key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        $hmac = isset($_GET['hmac']) ? sanitize_text_field($_GET['hmac']) : '';

        // Fetch verified record
        $result = $this->validate_certificate($key, $hmac);
        if (!$result) {
            return '';
        }

        // Fetch user object
        $user = get_userdata($result->user_id);

        // Return the HTML content layout directly into the page stream
        return $this->display_template($result, $user);
    }

    /**
     * Validate the signature and retrieve the record.
     *
     * The certificate file (and its QR code) is produced by the REST API with
     * the signature embedded in it. The registry row stores that exact value,
     * so verification must compare against the stored signature rather than
     * recomputing a new one.
     *
     * @return object|false Registry row or false.
     */
    private function validate_certificate($key, $hmac)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'cert_registry';

        $record = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE cert_key = %s", $key));
        if (!$record) {
            return false;
        }

        $stored_hmac = isset($record->cert_hmac) ? (string) $record->cert_hmac : '';

        if ($stored_hmac === '' || $hmac === '' || !hash_equals($stored_hmac, $hmac)) {
            return false;
        }

        return $record;
    }

    /**
     * Buffers layout and returns it as a string to the shortcode handler.
     */
    private function display_template($certificate, $user)
    {
        ob_start();
        ?>
        <div class="certificate-verification-badge"
            style="border: 1px solid #ccc; padding: 20px; text-align: center; border-radius: 5px;">
            <h1><?php esc_html_e($certificate->cert_name, 'text-domain'); ?> </h1>
            <h2><?php esc_html_e('Certificate Verified', 'text-domain'); ?></h2>
            <p>
                <strong><?php esc_html_e('Holder Name:', 'text-domain'); ?></strong>
                <strong><?php esc_html_e($certificate->user_name, 'text-domain'); ?></strong>
            </p>
            <p>
                <strong><?php esc_html_e('Email:', 'text-domain'); ?></strong>
                <strong><?php esc_html_e($certificate->user_email, 'text-domain'); ?></strong>
            <p>
                <strong><?php esc_html_e('Certificate Key:', 'text-domain'); ?></strong>
                <code><?php echo esc_html($certificate->cert_key); ?></code>
            </p>

            <p>
                <strong><?php esc_html_e('Issued:', 'text-domain'); ?></strong>
                <strong><?php esc_html_e($certificate->date_issued, 'text-domain'); ?></strong>
            </p>

            </p>
        </div>
        <?php
        return ob_get_clean();
    }
}
