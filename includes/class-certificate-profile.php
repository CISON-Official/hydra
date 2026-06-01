<?php
/**
 * Certificates Profile Tab (BuddyPress / BuddyBoss)
 */
namespace Certificates\Includes;

class CertificateProfile
{
    public function __construct()
    {
        add_action('bp_setup_nav', array($this, 'add_certificate_to_profile_tag'), 20);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_certificate_styles']);
        add_action('wp_ajax_bbc_download_certificate', [$this, 'handle_certificate_download']);

    }

    public function add_certificate_to_profile_tag()
    {
        bp_core_new_nav_item([
            'name' => __('Certificates', 'textdomain'),
            'slug' => 'credential-list',
            'position' => 55,
            'screen_function' => array($this, 'view_certificates_screen'),
            'default_subnav_slug' => 'credential-list',
            'item_css_id' => 'certificates_section_style'
        ]);
    }

    public function view_certificates_screen()
    {
        add_action('bp_template_content', array($this, 'certificates_links_content'));
        bp_core_load_template('members/single/profile');
    }

    /**
     * Fetch user certificates
     */
    public function bbc_get_user_certificates(int $user_id): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cert_registry';

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return [];
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d ORDER BY date_issued DESC",
                $user_id
            )
        ) ?: [];
    }

    /**
     * Render content
     */
    public function certificates_links_content()
    {
        echo $this->list_certificates_content_template();
    }

    /**
     * Template output
     */
    public function list_certificates_content_template(): string
    {
        $displayed_user_id = bp_displayed_user_id();

        if (!$displayed_user_id) {
            return '';
        }

        $certificates = $this->bbc_get_user_certificates($displayed_user_id);
        $count = count($certificates);

        ob_start();
        ?>

        <?php if ($count === 0): ?>
            <div class="bbc-empty-state">
                <span class="bbc-empty-icon">🎓</span>
                <p class="bbc-empty-text">
                    <?php esc_html_e('No certificates found.', 'buddyboss-certificates'); ?>
                </p>
            </div>

        <?php else: ?>
            <ul class="bbc-list">
                <?php foreach ($certificates as $cert):

                    $path = $cert->file_url ?? '';


                    $name = $cert->cert_name;
                    $created_at = $cert->date_issued ?? '';
                    $expire_at = $cert->date_expiry ?? '';

                    $is_expired = !empty($expire_at) && strtotime($expire_at) < time();

                    $issued_label = !empty($created_at)
                        ? date_i18n(get_option('date_format'), strtotime($created_at))
                        : __('Unknown', 'buddyboss-certificates');

                    $expire_label = !empty($expire_at)
                        ? date_i18n(get_option('date_format'), strtotime($expire_at))
                        : __('No expiry', 'buddyboss-certificates');

                    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    $is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true);
                    ?>

                    <li class="bbc-list-row <?php echo $is_expired ? 'bbc-list-row--expired' : ''; ?>"
                        style="list-style: none;padding-left: 0;margin: 0;">

                        <div class="bbc-list-preview">
                            <?php if ($is_image && !empty($path)): ?>
                                <img src="<?php echo esc_url($path); ?>" alt="<?php echo esc_attr($name); ?>" class="bbc-list-thumb"
                                    loading="lazy" />
                            <?php else: ?>
                                <div class="bbc-list-thumb bbc-list-thumb--placeholder" aria-hidden="true">
                                    <span class="bbc-list-thumb-icon">📄</span>
                                </div>
                            <?php endif; ?>

                            <span class="bbc-badge <?php echo $is_expired ? 'bbc-badge--expired' : 'bbc-badge--active'; ?>">
                                <?php echo $is_expired
                                    ? esc_html__('Expired', 'buddyboss-certificates')
                                    : esc_html__('Active', 'buddyboss-certificates'); ?>
                            </span>
                        </div>

                        <div class="bbc-list-details">
                            <h3 class="bbc-list-name">
                                <?php echo esc_html($name); ?>
                            </h3>

                            <div class="bbc-list-meta">
                                <div class="bbc-list-meta-item">
                                    <span class="bbc-meta-label">
                                        <?php esc_html_e('Issued', 'buddyboss-certificates'); ?>
                                    </span>
                                    <span class="bbc-meta-value">
                                        <?php echo esc_html($issued_label); ?>
                                    </span>
                                </div>

                                <div class="bbc-list-meta-item">
                                    <span class="bbc-meta-label">
                                        <?php esc_html_e('Expires', 'buddyboss-certificates'); ?>
                                    </span>
                                    <span class="bbc-meta-value <?php echo $is_expired ? 'bbc-text--expired' : ''; ?>">
                                        <?php echo esc_html($expire_label); ?>
                                    </span>
                                </div>
                            </div>

                            <?php if (!empty($path)):
                                // Generate a secure download URL using WordPress AJAX
                                $download_url = admin_url(
                                    'admin-ajax.php?action=bbc_download_certificate' .
                                    '&cert_key=' . urlencode($cert->cert_key) .
                                    '&cert_hmac=' . urlencode($cert->cert_hmac)
                                );
                                ?>
                                <a href="<?php echo esc_url($download_url); ?>" class="bbc-view-btn">
                                    <?php esc_html_e('Download Certificate', 'buddyboss-certificates'); ?>
                                </a>
                            <?php endif; ?>

                        </div>

                    </li>

                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php
        return ob_get_clean();
    }

    /**
     * Processes and serves the private certificate download securely.
     */
    public function handle_certificate_download(): void
    {
        // 1. Verify user identity
        if (!is_user_logged_in()) {
            wp_die(__('You must be logged in to download certificates.', 'buddyboss-certificates'), 403);
        }

        $cert_key = isset($_GET['cert_key']) ? sanitize_text_field($_GET['cert_key']) : '';
        $cert_hmac = isset($_GET['cert_hmac']) ? sanitize_text_field($_GET['cert_hmac']) : '';

        // Verify security nonce using the cert_key
        if (!check_admin_referer('bbc_download_cert_' . $cert_key)) {
            wp_die(__('Security check failed.', 'buddyboss-certificates'), 403);
        }

        // Fetch single record using your updated method
        $cert = $this->get_single_certificate($cert_key, $cert_hmac);
        if (!$cert) {
            wp_die(__('Certificate not found.', 'buddyboss-certificates'), 404);
        }

        // 4. Double check ownership: Only allow the owner (or administrators) to download
        $current_user_id = get_current_user_id();
        if ((int) $cert->user_id !== $current_user_id && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to download this certificate.', 'buddyboss-certificates'), 403);
        }

        // 5. Convert database file path/URL to an absolute server path
        $file_path = $this->get_absolute_private_path($cert->file_url);

        if (empty($file_path) || !file_exists($file_path)) {
            wp_die(__('The certificate file could not be found on the server.', 'buddyboss-certificates'), 404);
        }

        // 6. Clean buffers and push the file download headers
        $filename = basename($file_path);
        $mime_type = wp_check_filetype($file_path)['type'] ?: 'application/octet-stream';

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mime_type);
        header('Content-Disposition: attachment; filename="' . esc_attr($filename) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));

        readfile($file_path);
        exit;
    }

    /**
     * Helper to convert stored path/URL into an absolute system directory path.
     */
    private function get_absolute_private_path(string $stored_path): string
    {

        $upload_dir = wp_upload_dir();
        $base_private_dir = $upload_dir['basedir'] . '/private-certificates/';

        return $base_private_dir . basename($stored_path);
    }

    public function enqueue_certificate_styles(): void
    {
        if (function_exists('bp_is_user') && bp_is_user()) {

            wp_enqueue_style(
                'bbc-certificates-style',
                plugins_url('assets/css/certificates.css', __FILE__),
                [],
                '1.0.0'
            );
        }
    }

    public function get_single_certificate(string $cert_key, string $cert_hmac)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cert_registry';

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return null;
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE cert_key = %s AND cert_hmac = %s ORDER BY date_issued DESC LIMIT 1",
                $cert_key,
                $cert_hmac
            )
        ) ?: null;
    }

}