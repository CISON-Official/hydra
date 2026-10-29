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
                "SELECT * FROM {$table} WHERE user_id = %d ORDER BY is_main DESC, date_issued DESC",
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
                    $local_path = $this->resolve_certificate_path($path);
                    $preview_url = $this->path_to_url($path);

                    $name = $cert->cert_name;

                    $ext = strtolower(pathinfo((string) wp_parse_url($path, PHP_URL_PATH), PATHINFO_EXTENSION));
                    $is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true);

                    $certificate_url = '';
                    if ($local_path !== null || $this->is_url($path)) {
                        if ($this->is_url($path)) {
                            $certificate_url = $path;
                        } else {
                            $ajax_url = admin_url(
                                'admin-ajax.php?action=bbc_download_certificate' .
                                '&cert_key=' . urlencode($cert->cert_key) .
                                '&cert_hmac=' . urlencode($cert->cert_hmac)
                            );
                            $certificate_url = wp_nonce_url($ajax_url, 'bbc_download_cert_' . $cert->cert_key);
                        }
                    }
                    ?>

                    <li class="bbc-list-row" style="list-style: none;padding-left: 0;margin: 0;">
                        <?php if ($certificate_url !== ''): ?>
                            <a href="<?php echo esc_url($certificate_url); ?>" class="bbc-list-row-link" target="_blank" rel="noopener noreferrer" style="display:flex;align-items:center;gap:16px;padding:12px 16px;text-decoration:none;color:inherit;">
                        <?php else: ?>
                            <div class="bbc-list-row-link" style="display:flex;align-items:center;gap:16px;padding:12px 16px;">
                        <?php endif; ?>

                            <div class="bbc-list-preview" style="flex:0 0 auto;">
                                <?php if ($is_image && $preview_url !== ''): ?>
                                    <img src="<?php echo esc_url($preview_url); ?>" alt="<?php echo esc_attr($name); ?>" class="bbc-list-thumb"
                                        loading="lazy" style="width:60px;height:60px;object-fit:cover;border-radius:4px;" />
                                <?php else: ?>
                                    <div class="bbc-list-thumb bbc-list-thumb--placeholder" aria-hidden="true" style="width:60px;height:60px;display:flex;align-items:center;justify-content:center;background:#f0f0f0;border-radius:4px;">
                                        <span class="bbc-list-thumb-icon">📄</span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="bbc-list-details" style="flex:1;">
                                <h3 class="bbc-list-name" style="margin:0;font-size:16px;font-weight:600;">
                                    <?php echo esc_html($name); ?>
                                </h3>
                            </div>

                        <?php if ($certificate_url !== ''): ?>
                            </a>
                        <?php else: ?>
                            </div>
                        <?php endif; ?>

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
        if (!is_user_logged_in()) {
            wp_die(__('You must be logged in to download certificates.', 'buddyboss-certificates'), 403);
        }

        $cert_key = isset($_GET['cert_key']) ? sanitize_text_field($_GET['cert_key']) : '';
        $cert_hmac = isset($_GET['cert_hmac']) ? sanitize_text_field($_GET['cert_hmac']) : '';

        if (empty($cert_key) || !check_admin_referer('bbc_download_cert_' . $cert_key)) {
            wp_die(__('Your link has expired or security check failed.', 'buddyboss-certificates'), 403);
        }

        $cert = $this->get_single_certificate($cert_key, $cert_hmac);
        if (!$cert) {
            wp_die(__('Certificate not found in registry.', 'buddyboss-certificates'), 404);
        }

        $current_user_id = get_current_user_id();
        if ((int) $cert->user_id !== (int) $current_user_id && !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to download this certificate.', 'buddyboss-certificates'), 403);
        }

        $file_path = $this->resolve_certificate_path((string) $cert->file_url);

        if ($file_path === null) {
            wp_die(__('The certificate file could not be found on the server.', 'buddyboss-certificates'), 404);
        }

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
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));

        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
            @apache_setenv('dont-vary', '1');
        }
        header('X-Accel-Buffering: no');
        header('Content-Encoding: none');

        $chunk_size = 1048576;

        $handle = fopen($file_path, 'rb');
        if ($handle !== false) {
            while (!feof($handle)) {
                echo fread($handle, $chunk_size);
                if (connection_status() !== 0) {
                    fclose($handle);
                    exit;
                }
                flush();

                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
            }
            fclose($handle);
        }

        exit;

    }

    public function enqueue_certificate_styles(): void
    {
        if (function_exists('bp_is_user') && bp_is_user()) {

            wp_enqueue_style(
                'bbc-certificates-style',
                plugin_dir_url(dirname(__FILE__)) . 'assets/css/certificates.css',
                [],
                '1.0.2'
            );
        }
    }

    private function is_url(string $value): bool
    {
        return (bool) preg_match('#^https?://#i', $value);
    }

    /**
     * Convert a stored path or URL into a readable local file path.
     */
    private function resolve_certificate_path(string $file_url): ?string
    {
        $file_url = trim($file_url);

        if ($file_url === '') {
            return null;
        }

        if (!$this->is_url($file_url)) {
            return file_exists($file_url) ? $file_url : null;
        }

        $url_path = (string) wp_parse_url($file_url, PHP_URL_PATH);
        if ($url_path === '') {
            return null;
        }

        $content_path = rtrim((string) wp_parse_url(content_url(), PHP_URL_PATH), '/');
        if ($content_path !== '' && strpos($url_path, $content_path . '/') === 0) {
            $candidate = WP_CONTENT_DIR . substr($url_path, strlen($content_path));
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        $site_path = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        $relative = ($site_path !== '' && strpos($url_path, $site_path . '/') === 0)
            ? substr($url_path, strlen($site_path))
            : $url_path;

        $candidate = ABSPATH . ltrim($relative, '/');
        return file_exists($candidate) ? $candidate : null;
    }

    /**
     * Convert a local path inside wp-content into a public URL for previews.
     */
    private function path_to_url(string $path): string
    {
        if ($path === '') {
            return '';
        }

        if ($this->is_url($path)) {
            return $path;
        }

        $normalized_path = wp_normalize_path($path);
        $content_dir = wp_normalize_path(WP_CONTENT_DIR);

        if (strpos($normalized_path, $content_dir) === 0) {
            return content_url(substr($normalized_path, strlen($content_dir)));
        }

        return '';
    }

    public function get_single_certificate(string $cert_key, string $cert_hmac)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'cert_registry';

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return null;
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE cert_key = %s AND cert_hmac = %s ORDER BY date_issued DESC LIMIT 1",
                $cert_key,
                $cert_hmac
            )
        ) ?: null;
    }

}