<?php
namespace Certificates\Includes;

use Certificates\Includes\DatabaseSchema;

class Activator
{
    public static function activate(): void
    {
        self::create_tables();
        self::seed_default_templates();
        self::add_rewrite_rules();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
        self::clean_tables();
    }
    static public function clean_tables()
    {
        global $wpdb;
        $registry_clean = DatabaseSchema::drop_registry_table($wpdb);
        $wpdb->query($registry_clean);
    }
    private static function create_tables(): void
    {
        global $wpdb;

        $templates_sql = DatabaseSchema::get_templates_table_sql($wpdb);
        $registry_sql = DatabaseSchema::get_registry_table_sql($wpdb);

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($templates_sql);
        dbDelta($registry_sql);
    }

    private static function seed_default_templates(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cert_templates';

        $classic = $wpdb->get_row(
            $wpdb->prepare("SELECT id FROM {$table_name} WHERE cert_type = %s LIMIT 1", 'default-classic')
        );
        if (!$classic) {
            $wpdb->insert($table_name, [
                'title' => 'Classic Institutional Certificate',
                'cert_type' => 'default-classic',
                'template_path' => 'templates/classic.php',
                'duration_days' => 365,
                'created_at' => current_time('mysql'),
            ]);
        }

        $modern = $wpdb->get_row(
            $wpdb->prepare("SELECT id FROM {$table_name} WHERE cert_type = %s LIMIT 1", 'modern-minimal')
        );
        if (!$modern) {
            $wpdb->insert($table_name, [
                'title' => 'Modern Minimalist Award',
                'cert_type' => 'modern-minimal',
                'template_path' => 'templates/modern.php',
                'duration_days' => null,
                'created_at' => current_time('mysql'),
            ]);
        }
    }

    private static function add_rewrite_rules(): void
    {
        add_rewrite_rule('^qr/scan/?$', 'index.php?qr_scan=1', 'top');
        add_rewrite_tag('%qr_scan%', '([^&]+)');
    }
}