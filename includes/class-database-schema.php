<?php
namespace Certificates\Includes;

class DatabaseSchema
{
    public static function get_templates_table_sql($wpdb)
    {
        return "CREATE TABLE {$wpdb->prefix}cert_templates (
            id int(11) NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            cert_type varchar(100) NOT NULL,
            template_path varchar(255) NOT NULL,
            duration_days int(11) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY cert_type (cert_type)
        )";
    }

    public static function get_registry_table_sql($wpdb)
    {
        $table_name = $wpdb->prefix . 'cert_registry';
        $charset_collate = $wpdb->get_charset_collate();
        return "CREATE TABLE $table_name (
                    id bigint(20) NOT NULL AUTO_INCREMENT,
                    cert_name varchar(100) NOT NULL,
                    user_id bigint(20) unsigned NULL,
                    user_name varchar(255) NOT NULL,
                    user_email varchar(255) NOT NULL,
                    template_id varchar(200) NOT NULL,
                    cert_key varchar(255) NOT NULL,
                    cert_hmac varchar(255) NOT NULL,
                    is_main tinyint(1) NOT NULL DEFAULT 0,
                    date_issued datetime NOT NULL,
                    date_expiry datetime DEFAULT NULL,
                    file_url varchar(255) DEFAULT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY cert_key (cert_key),
                    KEY idx_user_template (user_id, template_id),
                    KEY idx_expiry (date_expiry)
                ) $charset_collate;
        )";
    }

    public static function drop_registry_table($wpdb)
    {
        return "DROP TABLE {$wpdb->prefix}cert_registry";
    }
}