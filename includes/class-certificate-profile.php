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
    }

    public function add_certificate_to_profile_tag()
    {
        bp_core_new_nav_item([
            'name' => __('Certificates', 'textdomain'),
            'slug' => 'certificates-profile',
            'position' => 55,
            'screen_function' => array($this, 'view_certificates_screen'),
            'default_subnav_slug' => 'certificates-profile',
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


                    $name = $cert->name ?? __('Untitled Certificate', 'buddyboss-certificates');
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

                    <li class="bbc-list-row <?php echo $is_expired ? 'bbc-list-row--expired' : ''; ?>">

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

                            <?php if (!empty($path)): ?>
                                <a href="<?php echo esc_url($path); ?>" class="bbc-view-btn" target="_blank" rel="noopener noreferrer">
                                    <?php esc_html_e('View Certificate', 'buddyboss-certificates'); ?>
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
}