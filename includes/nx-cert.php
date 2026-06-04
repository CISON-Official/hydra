<?php
/*
 * Plugin Name: CISON Member-ID & Cert Manager (Drop-in v2.3.0)
 * Description: Displays member ID, payment status and manages certificate issuance including cohort cutoff support. Drop-in replacement (production-safe).
 * Version: 2.3.0
 * Author: Nz (updated)
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ---------------------------
 * Constants
 * --------------------------- */
define('CISON_CURRENT_YEAR', (int) date('Y'));
define('CISON_PRIVATE_DIR', WP_CONTENT_DIR . '/private/');
define('CISON_CERTIFICATE_DIR', CISON_PRIVATE_DIR . 'certificates/');
define('CISON_CERTIFICATE_URL', content_url('/private/certificates/'));
define('CISON_CERT_TABLE', 'wprx_cison_certificates');

/* ---------------------------
 * Enqueue assets
 * --------------------------- */
add_action('wp_enqueue_scripts', 'cison_member_display_enqueue_assets');
function cison_member_display_enqueue_assets() {
    if (function_exists('bp_is_user') && bp_is_user()) {
        wp_enqueue_style(
            'cison-member-display-styles',
            plugin_dir_url(__FILE__) . 'assets/cison-member-display.css',
            [],
            '2.3.0'
        );
    }
}

/* ---------------------------
 * Activation: ensure table exists & add member_type / cutoff_date
 * --------------------------- */
register_activation_hook(__FILE__, 'cison_activation_setup');
function cison_activation_setup() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table_name      = CISON_CERT_TABLE;

    // 1) Base table
    $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        user_id BIGINT(20) UNSIGNED NOT NULL,
        member_id VARCHAR(50) NOT NULL,
        cert_id VARCHAR(20) NOT NULL,
        certificate_path VARCHAR(255) NOT NULL,
        date_issued BIGINT(20) NOT NULL,
        secret_token VARCHAR(16) NOT NULL,
        last_updated BIGINT(20) NOT NULL,
        firstname VARCHAR(100) DEFAULT '',
        middlename VARCHAR(100) DEFAULT '',
        surname VARCHAR(100) DEFAULT '',
        email VARCHAR(100) DEFAULT '',
        PRIMARY KEY (user_id),
        UNIQUE KEY cert_id (cert_id),
        KEY member_id (member_id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    if ($wpdb->last_error) {
        error_log("CISON activation: dbDelta error: " . $wpdb->last_error);
    }

    // 2) Add member_type + cutoff_date if missing
    $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");
    if ($columns === null) {
        error_log("CISON activation: failed to get columns for {$table_name}: " . $wpdb->last_error);
        return;
    }

    $need_member_type = !in_array('member_type', $columns, true);
    $need_cutoff_date = !in_array('cutoff_date', $columns, true);

    if ($need_member_type) {
        $res = $wpdb->query(
            "ALTER TABLE {$table_name}
             ADD COLUMN member_type ENUM('transiting','inducted') NULL DEFAULT NULL AFTER email"
        );
        if ($res === false) {
            error_log("CISON activation: failed to add member_type: " . $wpdb->last_error);
        }
    }

    if ($need_cutoff_date) {
        $res = $wpdb->query(
            "ALTER TABLE {$table_name}
             ADD COLUMN cutoff_date DATE NULL DEFAULT NULL AFTER member_type"
        );
        if ($res === false) {
            error_log("CISON activation: failed to add cutoff_date: " . $wpdb->last_error);
        }
    }

    // 3) Backfill legacy rows as transiting
    if ($need_member_type || $need_cutoff_date) {
        $update_sql = $wpdb->prepare(
            "UPDATE {$table_name}
             SET member_type = %s,
                 cutoff_date = FROM_UNIXTIME(date_issued, '%%Y-%%m-%%d')
             WHERE member_type IS NULL",
            'transiting'
        );
        $upd = $wpdb->query($update_sql);
        if ($upd === false) {
            error_log("CISON activation: failed backfill: " . $wpdb->last_error);
        } else {
            set_transient('cison_activation_backfilled_count', $upd, 60);
        }
    }
}

/* Show simple admin notice after activation backfill */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) {
        return;
    }
    $count = get_transient('cison_activation_backfilled_count');
    if ($count !== false) {
        echo '<div class="notice notice-success is-dismissible"><p>CISON: Backfilled existing certificate rows as <strong>transiting</strong> (' . intval($count) . ' rows).</p></div>';
        delete_transient('cison_activation_backfilled_count');
    }
});

/* ---------------------------
 * Ensure private directories exist
 * --------------------------- */
add_action('init', 'cison_init_directories');
function cison_init_directories() {
    if (!file_exists(CISON_PRIVATE_DIR)) {
        wp_mkdir_p(CISON_PRIVATE_DIR);
        @file_put_contents(
            CISON_PRIVATE_DIR . '.htaccess',
            "Order deny,allow\nDeny from all"
        );
    }
    if (!file_exists(CISON_CERTIFICATE_DIR)) {
        wp_mkdir_p(CISON_CERTIFICATE_DIR);
    }
}

/* ======================================================
 * FEE / PRODUCT MAPPING HELPERS
 * ====================================================== */

/**
 * Return all product IDs (Regular / Retired / Student) that count as
 * "Annual dues" for a given year.
 */
function cison_get_annual_dues_product_ids($year) {
    $year = (int) $year;

    static $map = [
        // year  => [regular, retired, student]
        2024 => [317, 624, 623],
        2025 => [5035, 5983, 5980],
        2026 => [12110, 12112, 12114], // add when ready
    ];

    return $map[$year] ?? [];
}

function cison_get_dev_levy_product_ids($year) {
    $year = (int) $year;

    static $map = [
        2024 => [368, 624, 13101],
        2025 => [5063, 5983, 13108],
        2026 => [12116, 12112, 13110], // add when ready
    ];

    return $map[$year] ?? [];
}

/**
 * Build the list of required fees for a member.
 *
 * Each fee entry is:
 *   key => [
 *      'name'        => '2024 Annual Dues/Subscription',
 *      'product_ids' => [id1, id2, id3]  // ANY of these counts
 *   ]
 */
function cison_get_required_fees($is_transiting, $reg_year, $is_student) {
    $current_year = CISON_CURRENT_YEAR;
    $required     = [];

     if ($is_transiting) {
        // Base transiting fees
        $required = [
            'nsa_dues' => [
                'name'        => '2023 NSA Membership Dues',
                'product_ids' => [885],
            ],
            'transition_fee' => [
                'name'        => 'NSA to CISON Transition Fee',
                'product_ids' => [366],
            ],
        ];

        // Track ALL years from 2024 up to current year
        for ($year = 2024; $year <= $current_year; $year++) {

            $annual_ids = cison_get_annual_dues_product_ids($year);
            if (!empty($annual_ids)) {
                $required["annual_dues_{$year}"] = [
                    'name'        => "{$year} Annual Dues/Subscription",
                    'product_ids' => $annual_ids,
                ];
            }

            $dev_ids = cison_get_dev_levy_product_ids($year);
            if (!empty($dev_ids)) {
                $required["dev_levy_{$year}"] = [
                    'name'        => "{$year} Development Levy",
                    'product_ids' => $dev_ids,
                ];
            }
        }

        return $required;
	 }

    // Non-transiting (new/regular members)
    if ($is_student === true) {
		$required['new_member_fee'] = [
			'name'        => 'New Member Registration Fee',
			'product_ids' => [13114],
		];
	} else {
		$required['new_member_fee'] = [
			'name'        => 'New Member Registration Fee',
			'product_ids' => [320],
		];
	}
    $reg_year = (int) $reg_year;
    if ($reg_year < 2024) {
        $reg_year = 2024;
    }
    if ($reg_year > $current_year) {
        $reg_year = $current_year;
    }

    for ($year = $reg_year; $year <= $current_year; $year++) {
        $required["annual_dues_{$year}"] = [
            'name'        => "{$year} Annual Dues/Subscription",
            'product_ids' => cison_get_annual_dues_product_ids($year),
        ];

        $required["dev_levy_{$year}"] = [
            'name'        => "{$year} Development Levy",
            'product_ids' => cison_get_dev_levy_product_ids($year),
//'product_ids' => ($year === 2024) ? [368] : [5063],
        ];
    }

    return $required;
}

/**
 * Extract a flat array of product IDs from required fee array.
 */
function cison_extract_product_ids($required_fees) {
    $ids = [];

    foreach ($required_fees as $fee) {
        if (isset($fee['product_ids']) && is_array($fee['product_ids'])) {
            foreach ($fee['product_ids'] as $pid) {
                $pid = (int) $pid;
                if ($pid > 0) {
                    $ids[] = $pid;
                }
            }
        } elseif (isset($fee['product_id'])) {
            $pid = (int) $fee['product_id'];
            if ($pid > 0) {
                $ids[] = $pid;
            }
        }
    }

    $ids = array_unique($ids);

    return $ids;
}

/**
 * Helper: compute unpaid fees from required + paid_fees array.
 *
 * $paid_fees is an array keyed like $required_fees with boolean values.
 */
function cison_get_unpaid_fees($required_fees, $paid_fees) {
    $unpaid = [];

    foreach ($required_fees as $key => $fee) {
        if (empty($paid_fees[$key])) {
            $unpaid[$key] = $fee;
        }
    }

    return $unpaid;
}

/**
 * Determine which required fees have been paid by the user.
 *
 * Returns an array keyed as required_fees with boolean values:
 *   [
 *     'annual_dues_2024' => true/false,
 *     'dev_levy_2024'    => true/false,
 *     ...
 *   ]
 *
 * Uses both WooCommerce Subscriptions (if present) and normal orders.
 */
function cison_get_paid_fees($user_id) {
    $user_id = (int) $user_id;
    if ($user_id <= 0) {
        return [];
    }

    // Cache to transient to avoid hammering WC queries
    $cache_key = 'cison_paid_fees_' . $user_id;
    $cached    = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }

    // Determine member context
    $member_id = function_exists('bp_get_profile_field_data')
        ? bp_get_profile_field_data(['field' => 894, 'user_id' => $user_id])
        : '';

    $is_transiting = function_exists('bp_get_profile_field_data')
        ? (bp_get_profile_field_data(['field' => 1595, 'user_id' => $user_id]) === 'Yes')
        : false;
	
	$is_student = bp_get_member_type( $user_id ) === "student-member";

    $reg_year = $is_transiting
        ? 2023
        : ($member_id ? max(2024, min((int) substr($member_id, 0, 4), CISON_CURRENT_YEAR)) : CISON_CURRENT_YEAR);

    $required_fees = cison_get_required_fees($is_transiting, $reg_year, $is_student);

    // Initialise all as unpaid
    $paid_fees = [];
    foreach ($required_fees as $key => $_fee) {
        $paid_fees[$key] = false;
    }

    // Build product lookup: product_id => [fee_key1, fee_key2, ...]
    $fee_lookup = [];
    foreach ($required_fees as $key => $fee) {
        $ids = [];

        if (isset($fee['product_ids'])) {
            $ids = (array) $fee['product_ids'];
        } elseif (isset($fee['product_id'])) {
            $ids = [(int) $fee['product_id']];
        }

        foreach ($ids as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0) {
                continue;
            }
            if (!isset($fee_lookup[$pid])) {
                $fee_lookup[$pid] = [];
            }
            $fee_lookup[$pid][] = $key;
        }
    }

    if (empty($fee_lookup)) {
        set_transient($cache_key, $paid_fees, 15 * MINUTE_IN_SECONDS);
        return $paid_fees;
    }

    // Helper closure for matching product/variation/parent
    $mark_paid = function ($product_id) use (&$paid_fees, $fee_lookup) {
        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            return;
        }
        if (isset($fee_lookup[$product_id])) {
            foreach ($fee_lookup[$product_id] as $fee_key) {
                $paid_fees[$fee_key] = true;
            }
        }
    };

    // 1) Subscriptions
    if (function_exists('wcs_get_users_subscriptions')) {
        $subscriptions = wcs_get_users_subscriptions($user_id);
        foreach ($subscriptions as $subscription) {
            if (!in_array($subscription->get_status(), ['active', 'pending-cancel'], true)) {
                continue;
            }

            foreach ($subscription->get_items() as $item) {
                $pid = (int) $item->get_product_id();
                $vid = (int) $item->get_variation_id();

                $mark_paid($pid);
                $mark_paid($vid);

                if (function_exists('wc_get_product')) {
                    $prod = wc_get_product($pid);
                    if ($prod) {
                        $parent_id = (int) $prod->get_parent_id();
                        $mark_paid($parent_id);
                    }
                }
            }
        }
    }

    // 2) Standard orders
    if (function_exists('wc_get_orders')) {
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'status'      => ['completed', 'processing'],
            'limit'       => -1,
            'return'      => 'objects',
        ]);

        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $pid = (int) $item->get_product_id();
                $vid = (int) $item->get_variation_id();

                $mark_paid($pid);
                $mark_paid($vid);

                if (function_exists('wc_get_product')) {
                    $prod = wc_get_product($pid);
                    if ($prod) {
                        $parent_id = (int) $prod->get_parent_id();
                        $mark_paid($parent_id);
                    }
                }
            }
        }
    }

    set_transient($cache_key, $paid_fees, 15 * MINUTE_IN_SECONDS);

    return $paid_fees;
}

/**
 * Get last completed payment date (Y-m-d H:i:s) that paid
 * ANY of the required fee products for this user.
 */
function cison_get_last_payment_date($user_id) {
    $user_id = (int) $user_id;
    if ($user_id <= 0 || !function_exists('wc_get_orders')) {
        return null;
    }

    try {
        $member_id = function_exists('bp_get_profile_field_data')
            ? bp_get_profile_field_data(['field' => 894, 'user_id' => $user_id])
            : '';

        $is_transiting = function_exists('bp_get_profile_field_data')
            ? (bp_get_profile_field_data(['field' => 1595, 'user_id' => $user_id]) === 'Yes')
            : false;

        $reg_year = $is_transiting
            ? 2023
            : ($member_id ? max(2024, min((int) substr($member_id, 0, 4), CISON_CURRENT_YEAR)) : CISON_CURRENT_YEAR);
		
		$is_student = bp_get_member_type( $user_id ) === "student-member";

        $required = cison_get_required_fees($is_transiting, $reg_year, $is_student);
        $tracked_ids = cison_extract_product_ids($required);
        if (empty($tracked_ids)) {
            return null;
        }

        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'status'      => 'completed',
            'limit'       => 200,
            'orderby'     => 'date_completed',
            'order'       => 'DESC',
            'return'      => 'objects',
        ]);

        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $pid = (int) $item->get_product_id();
                $vid = (int) $item->get_variation_id();

                $matches = in_array($pid, $tracked_ids, true) || ($vid && in_array($vid, $tracked_ids, true));

                if (!$matches && function_exists('wc_get_product')) {
                    $prod = wc_get_product($pid);
                    if ($prod) {
                        $parent_id = (int) $prod->get_parent_id();
                        if ($parent_id && in_array($parent_id, $tracked_ids, true)) {
                            $matches = true;
                        }
                    }
                }

                if ($matches) {
                    $dt = $order->get_date_completed() ?: $order->get_date_created();
                    return $dt ? $dt->date_i18n('Y-m-d H:i:s') : null;
                }
            }
        }
    } catch (Throwable $e) {
        error_log("CISON: cison_get_last_payment_date error: " . $e->getMessage());
    }

    return null;
}

/* ======================================================
 * CUTOFF CONFIG
 * ====================================================== */

/**
 * Option structure: ['active' => 'YYYY-mm-dd', 'map' => ['2025' => '2025-09-26', ...]]
 */
function cison_get_cutoffs_option() {
    $opt = get_option('cison_cutoffs', []);

    if (!is_array($opt)) {
        $opt = [];
    }

    $opt['map']    = isset($opt['map']) && is_array($opt['map']) ? $opt['map'] : [];
    $opt['active'] = !empty($opt['active']) ? date('Y-m-d', strtotime($opt['active'])) : null;

    foreach ($opt['map'] as $k => $v) {
        $opt['map'][$k] = date('Y-m-d', strtotime($v));
    }

    return $opt;
}
function cison_update_cutoffs_option($data) {
    update_option('cison_cutoffs', $data);
}

/* ======================================================
 * ELIGIBILITY PREVIEW
 * ====================================================== */

/**
 * Returns:
 *   [
 *     'eligible'       => bool,
 *     'reason'         => string,
 *     'applied_cutoff' => 'YYYY-mm-dd'|null
 *   ]
 */
function cison_preview_user_eligibility($user_id) {
    $user_id = (int) $user_id;

    $member_id = function_exists('bp_get_profile_field_data')
        ? bp_get_profile_field_data(['field' => 894, 'user_id' => $user_id])
        : '';

    if (empty($member_id)) {
        return [
            'eligible'       => false,
            'reason'         => 'Member ID not set',
            'applied_cutoff' => null,
        ];
    }

    $is_transiting = function_exists('bp_get_profile_field_data')
        ? (bp_get_profile_field_data(['field' => 1595, 'user_id' => $user_id]) === 'Yes')
        : false;

    $reg_year = $is_transiting
        ? 2023
        : max(2024, min((int) substr($member_id, 0, 4), CISON_CURRENT_YEAR));
	
	$is_student = bp_get_member_type( $user_id ) === "student-member";

    $required_fees = cison_get_required_fees($is_transiting, $reg_year, $is_student);
    $paid_fees     = cison_get_paid_fees($user_id);
    $unpaid_fees   = cison_get_unpaid_fees($required_fees, $paid_fees);

    if ($is_transiting) {
        $eligible = empty($unpaid_fees) ||
            (count($unpaid_fees) === 1 && isset($unpaid_fees["dev_levy_" . CISON_CURRENT_YEAR]));

        return [
            'eligible'       => (bool) $eligible,
            'reason'         => $eligible ? 'Transiting & all fees paid (or only current dev levy unpaid)' : 'Transiting but fees unpaid',
            'applied_cutoff' => $eligible ? date('Y-m-d') : null,
        ];
    }

    // Non-transiting
    if (!empty($unpaid_fees)) {
        return [
            'eligible'       => false,
            'reason'         => 'Non-transiting: fees unpaid',
            'applied_cutoff' => null,
        ];
    }

    $last_payment = cison_get_last_payment_date($user_id);
    $last_payment_norm = $last_payment ? date('Y-m-d', strtotime($last_payment)) : null;

    $cutoffs      = cison_get_cutoffs_option();
    $active_cutoff = $cutoffs['active'] ?? null;

    if (!$active_cutoff) {
        return [
            'eligible'       => false,
            'reason'         => 'No active cutoff configured for inducted members',
            'applied_cutoff' => null,
        ];
    }

    if (!$last_payment_norm) {
        return [
            'eligible'       => false,
            'reason'         => 'Non-transiting: no payment date found',
            'applied_cutoff' => $active_cutoff,
        ];
    }

    if ($last_payment_norm <= $active_cutoff) {
        return [
            'eligible'       => true,
            'reason'         => "Non-transiting: last payment {$last_payment_norm} <= cutoff {$active_cutoff}",
            'applied_cutoff' => $active_cutoff,
        ];
    }

    return [
        'eligible'       => false,
        'reason'         => "Non-transiting: last payment {$last_payment_norm} > cutoff {$active_cutoff}",
        'applied_cutoff' => $active_cutoff,
    ];
}

/* ======================================================
 * CERTIFICATE ROW CREATION
 * ====================================================== */

function cison_get_next_cert_number() {
    global $wpdb;
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . CISON_CERT_TABLE);
    if ($wpdb->last_error) {
        error_log("CISON: Error counting certificates: " . $wpdb->last_error);
        return 1;
    }
    return $count + 1;
}

/**
 * Create/refresh certificate DB row if user is eligible.
 */
function cison_check_eligibility_and_create_row_if_missing($user_id) {
    global $wpdb;
    $table = CISON_CERT_TABLE;

    $user_id = (int) $user_id;

    $member_id = function_exists('bp_get_profile_field_data')
        ? bp_get_profile_field_data(['field' => 894, 'user_id' => $user_id])
        : '';

    if (empty($member_id)) {
        return false;
    }

    // If row exists & file exists, nothing to do
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE user_id = %d LIMIT 1",
        $user_id
    ));

    if ($existing && !empty($existing->certificate_path) && file_exists($existing->certificate_path)) {
        return true;
    }

    // Check eligibility
    $preview = cison_preview_user_eligibility($user_id);
    if (empty($preview['eligible'])) {
        return false;
    }

    $is_transiting = function_exists('bp_get_profile_field_data')
        ? (bp_get_profile_field_data(['field' => 1595, 'user_id' => $user_id]) === 'Yes')
        : false;

    $member_type   = $is_transiting ? 'transiting' : 'inducted';
    $applied_cutoff = $preview['applied_cutoff'] ?? null;

    $firstname = function_exists('bp_get_profile_field_data')
        ? bp_get_profile_field_data(['field' => 1, 'user_id' => $user_id])
        : '';
    $middlename = function_exists('bp_get_profile_field_data')
        ? bp_get_profile_field_data(['field' => 864, 'user_id' => $user_id])
        : '';
    $surname = function_exists('bp_get_profile_field_data')
        ? bp_get_profile_field_data(['field' => 2, 'user_id' => $user_id])
        : '';
    $email = get_userdata($user_id) ? get_userdata($user_id)->user_email : '';

    $date_now        = date('Y-m-d H:i:s');
    $date_issued_unix = strtotime($date_now);

    $cert_id   = $existing ? $existing->cert_id : (CISON_CURRENT_YEAR . '-' . sprintf('%05d', cison_get_next_cert_number()));
    $cert_path = CISON_CERTIFICATE_DIR . "certificate_{$cert_id}.pdf";
    $secret_token = wp_generate_password(12, false);

    $cutoff_date_to_store = ($member_type === 'transiting')
        ? date('Y-m-d', $date_issued_unix)
        : $applied_cutoff;

    if ($existing) {
        $upd = $wpdb->update(
            $table,
            [
                'certificate_path' => $cert_path,
                'date_issued'      => $date_issued_unix,
                'secret_token'     => $secret_token,
                'last_updated'     => time(),
                'firstname'        => $firstname,
                'middlename'       => $middlename,
                'surname'          => $surname,
                'email'            => $email,
                'member_type'      => $member_type,
                'cutoff_date'      => $cutoff_date_to_store,
            ],
            ['user_id' => $user_id],
            ['%s','%d','%s','%d','%s','%s','%s','%s','%s','%s'],
            ['%d']
        );
        if ($upd === false) {
            error_log("CISON: failed to update certificate row for user {$user_id}: " . $wpdb->last_error);
            return false;
        }
    } else {
        $ins = $wpdb->insert(
            $table,
            [
                'user_id'         => $user_id,
                'member_id'       => $member_id,
                'cert_id'         => $cert_id,
                'certificate_path'=> $cert_path,
                'date_issued'     => $date_issued_unix,
                'secret_token'    => $secret_token,
                'last_updated'    => time(),
                'firstname'       => $firstname,
                'middlename'      => $middlename,
                'surname'         => $surname,
                'email'           => $email,
                'member_type'     => $member_type,
                'cutoff_date'     => $cutoff_date_to_store,
            ],
            ['%d','%s','%s','%s','%d','%s','%d','%s','%s','%s','%s','%s','%s']
        );
        if ($ins === false) {
            error_log("CISON: failed to insert certificate row for user {$user_id}: " . $wpdb->last_error);
            return false;
        }
    }

    // NOTE: This only updates DB. PDF creation is handled by your existing process.

    return true;
}

/* ---------------------------
 * Hooks: trigger checks
 * --------------------------- */
add_action('wp_login', 'cison_check_on_login', 10, 2);
function cison_check_on_login($user_login, $user) {
    cison_check_eligibility_and_create_row_if_missing($user->ID);
}

add_action('xprofile_updated_profile', 'cison_on_profile_update', 10, 1);
function cison_on_profile_update($user_id) {
    cison_check_eligibility_and_create_row_if_missing($user_id);
}

add_action('woocommerce_order_status_changed', 'cison_on_order_status_changed', 10, 3);
function cison_on_order_status_changed($order_id, $from_status, $to_status) {
    if ('completed' === $to_status) {
        $order   = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        $user_id = $order ? $order->get_customer_id() : 0;
        if ($user_id) {
            delete_transient('cison_paid_fees_' . $user_id);
            cison_check_eligibility_and_create_row_if_missing($user_id);
        }
    }
}

/* ---------------------------
 * Certificate existence helper
 * --------------------------- */
function cison_check_certificate_exists($member_id) {
    global $wpdb;
    $table = CISON_CERT_TABLE;

    $certificate_path = $wpdb->get_var($wpdb->prepare(
        "SELECT certificate_path FROM {$table} WHERE member_id = %s LIMIT 1",
        $member_id
    ));

    if ($wpdb->last_error) {
        error_log("CISON: error checking certificate existence: " . $wpdb->last_error);
    }

    return ($certificate_path && file_exists($certificate_path)) ? $certificate_path : false;
}

/**
 * Checks if a specific Product ID exists within the required fees structure.
 *
 * @param int $target_pid The Product ID you are looking for.
 * @param array $required_fees The array returned by cison_get_required_fees().
 * @return bool True if the ID is found, false otherwise.
 */
function is_product_in_required_fees($target_pid, $required_fees) {
    // Ensure we are comparing integers
    $target_pid = (int) $target_pid;

    foreach ($required_fees as $fee) {
        // Check the 'product_ids' array (plural)
        if (isset($fee['product_ids']) && is_array($fee['product_ids'])) {
            if (in_array($target_pid, array_map('intval', $fee['product_ids']))) {
                return true;
            }
        }
        
        // Check the 'product_id' single value (singular)
        if (isset($fee['product_id']) && (int)$fee['product_id'] === $target_pid) {
            return true;
        }
    }

    return false;
}

/* ======================================================
 * CERTIFICATE ENDPOINT
 * ====================================================== */

add_action('init', 'cison_register_certificate_endpoint');
function cison_register_certificate_endpoint() {
    // Keep rewrite rule (optional) so /certificate works nicely
    add_rewrite_rule('^certificate/?$', 'index.php?cison_certificate=1', 'top');
}
add_filter('query_vars', 'cison_certificate_query_vars');
function cison_certificate_query_vars($vars) {
    $vars[] = 'cison_certificate';
    return $vars;
}

add_action('template_redirect', 'cison_handle_certificate_request');
function cison_handle_certificate_request() {
    // Fire either when our query var is set OR when URL path contains "/certificate"
    $has_query_var   = (bool) get_query_var('cison_certificate');
    $request_uri     = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $looks_like_cert = (strpos($request_uri, '/certificate') !== false);

    if (!$has_query_var && !$looks_like_cert) {
        return;
    }

    $user_id = absint(isset($_GET['user_id']) ? $_GET['user_id'] : 0);
    $token   = sanitize_text_field(isset($_GET['token']) ? $_GET['token'] : '');

    if (!$user_id || !$token) {
        status_header(400);
        wp_die('Invalid request.', 'Error', ['response' => 400]);
    }

    $stored_token = get_transient("cison_certificate_token_{$user_id}");
    if (
        !$stored_token ||
        !preg_match('/^(.+)-(.+)-(.+)$/', $stored_token, $matches)
    ) {
        status_header(403);
        wp_die('Invalid or expired token - please refresh your profile page and try again.', 'Error', ['response' => 403]);
    }

    $nonce_part   = $matches[1];
    $random_part  = $matches[2];
    $ip_part      = $matches[3];
    $current_ip   = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';

    if (
        ("{$nonce_part}-{$random_part}" !== $token) ||
        ($ip_part !== $current_ip) ||
        !wp_verify_nonce($nonce_part, "cison_certificate_{$user_id}")
    ) {
        status_header(403);
        wp_die('Invalid or expired token - please refresh your profile page and try again.', 'Error', ['response' => 403]);
    }

    $current_user_id = get_current_user_id();
    if ($current_user_id !== $user_id && !current_user_can('read_private_posts')) {
        status_header(403);
        wp_die('Permission denied.', 'Error', ['response' => 403]);
    }

    $member_id = function_exists('bp_get_profile_field_data')
        ? bp_get_profile_field_data(['field' => 894, 'user_id' => $user_id])
        : '';

    if (!$member_id) {
        status_header(404);
        wp_die('Member ID is not set for this user.', 'Error', ['response' => 404]);
    }

    $certificate_file = cison_check_certificate_exists($member_id);
    if ($certificate_file) {
        status_header(200);
        nocache_headers();

        header('Content-Type: application/pdf');
        $cert_id = basename($certificate_file, '.pdf');
        header('Content-Disposition: inline; filename="' . $cert_id . '.pdf"');

        readfile($certificate_file);
        delete_transient("cison_certificate_token_{$user_id}");
        exit;
    }

    status_header(404);
    wp_die('Certificate not found.', 'Error', ['response' => 404]);
}

/* ======================================================
 * SMALL HELPERS
 * ====================================================== */

// Human-friendly date like "26th September, 2025"
if (!function_exists('cison_format_human_date')) {
    function cison_format_human_date($ymd) {
        if (empty($ymd)) {
            return '';
        }
        $ts = strtotime($ymd);
        if (!$ts) {
            return $ymd;
        }
        $d = (int) date('j', $ts);
        $suffix = 'th';
        if (!in_array($d % 100, [11, 12, 13], true)) {
            $suffix = [1 => 'st', 2 => 'nd', 3 => 'rd'][$d % 10] ?? 'th';
        }
        return $d . $suffix . ' ' . date('F, Y', $ts);
    }
}

/* ======================================================
 * FRONTEND: MEMBER PROFILE DISPLAY
 * ====================================================== */

add_action('bp_before_member_header_meta', 'cison_display_member_id_and_fees');
function cison_display_member_id_and_fees() {
    if (!is_user_logged_in()) {
        return;
    }

    $user_id = function_exists('bp_displayed_user_id') ? bp_displayed_user_id() : 0;
    if (!$user_id) {
        return;
    }
	$raw_types = bp_get_member_type( $user_id );

    $current_user_id = get_current_user_id();
    if ($current_user_id !== $user_id && !current_user_can('read_private_posts')) {
        echo '<div class="profile-member-info-container"><strong>Member ID:</strong> <span style="color: gray;">Permission denied</span></div>';
        return;
    }

    $member_id = function_exists('bp_get_profile_field_data')
        ? bp_get_profile_field_data(['field' => 894, 'user_id' => $user_id])
        : '';

    if (empty($member_id)) {
        error_log("CISON: User {$user_id}: No member ID found in field 894 - no fees assigned");
        ?>
        <div class="profile-member-info-container">
            <div class="member-id-section">
                <strong>Member ID:</strong> <span style="color: red;">Not set</span>
                <span class="memberid-separator"></span>
                <span class="fees-unpaid">
                    <i class="fa fa-exclamation-circle" aria-hidden="true"></i>
                    No fees assigned until Member ID is fixed.
                </span>
            </div>
        </div>
        <?php
        return;
    }

    $is_transiting = function_exists('bp_get_profile_field_data')
        ? (bp_get_profile_field_data(['field' => 1595, 'user_id' => $user_id]) === 'Yes')
        : false;

    $reg_year = $is_transiting
        ? 2023
        : max(2024, min((int) substr($member_id, 0, 4), CISON_CURRENT_YEAR));

    // Clearing cache when viewing own profile or for admins
    if (current_user_can('manage_options') || get_current_user_id() === $user_id) {
        delete_transient('cison_paid_fees_' . $user_id);
    }
	
	$is_student = bp_get_member_type( $user_id ) === "student-member";

    $required_fees = cison_get_required_fees($is_transiting, $reg_year, $is_student);
    $paid_fees     = cison_get_paid_fees($user_id);
    $unpaid_fees   = cison_get_unpaid_fees($required_fees, $paid_fees);

    $preview = cison_preview_user_eligibility($user_id);

    // Build payment link (for unpaid ones) - uses ORIGINAL "product_id" if present
    $unpaid_product_ids = [];
    foreach ($unpaid_fees as $fee) {
        if (isset($fee['product_ids']) && is_array($fee['product_ids'])) {
			if (count($fee['product_ids']) !== 3){
            foreach ($fee['product_ids'] as $pid) {
                $pid = (int) $pid;
                if ($pid > 0) {
                    $unpaid_product_ids[] = $pid;
                }
            }
			}else {
             if ($raw_types == "student-member") {
				 $unpaid_product_ids[] = $fee['product_ids'][2];
			 } else {
				 $unpaid_product_ids[] = $fee['product_ids'][0];
			 }
			}
        } elseif (isset($fee['product_id'])) {
            $pid = (int) $fee['product_id'];
            if ($pid > 0) {
                $unpaid_product_ids[] = $pid;
            }
        }
    }
    $unpaid_product_ids = array_unique($unpaid_product_ids);

    $token = wp_create_nonce("cison_certificate_{$user_id}") . '-' . bin2hex(random_bytes(8));
    $payment_link = '#';
    if (!empty($unpaid_product_ids) && function_exists('wc_get_checkout_url')) {
        $payment_link = wc_get_checkout_url() . '?add-to-cart=' . implode(',', $unpaid_product_ids);
    }

    // Determine eligibility for display:
    $is_eligible_for_certificate = false;
    $certificate_path            = false;

    if ($is_transiting) {

    // 1) If certificate already exists, always allow viewing it (grandfather rule)
    $certificate_path = cison_check_certificate_exists($member_id);
    if ($certificate_path) {
        $is_eligible_for_certificate = true; // allow access even if 2026 unpaid
    } else {
        // 2) Otherwise fall back to normal eligibility rules (to issue new certs)
        $is_eligible_for_certificate = empty($unpaid_fees) ||
            (count($unpaid_fees) === 1 && isset($unpaid_fees["dev_levy_" . CISON_CURRENT_YEAR]));

        if ($is_eligible_for_certificate) {
            $certificate_path = cison_check_certificate_exists($member_id);
        }
    }
}
 else {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT certificate_path FROM " . CISON_CERT_TABLE . " WHERE user_id = %d LIMIT 1",
            $user_id
        ));
        if ($row && !empty($row->certificate_path) && file_exists($row->certificate_path)) {
            $certificate_path            = $row->certificate_path;
            $is_eligible_for_certificate = true;
        }
    }

    $certificate_link = '';
    if ($is_eligible_for_certificate && $certificate_path) {
        // We can safely use /certificate; handler also catches /members/certificate
        $certificate_link = site_url("/certificate?user_id={$user_id}&token={$token}");
        set_transient("cison_certificate_token_{$user_id}", $token . '-' . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''), 5 * MINUTE_IN_SECONDS);
    }
	
// 	$account_type = bp_get_profile_field_data( array(
//         'field'   => 1614,
//         'user_id' => $user_id,
//     ) );
// 	error_log("oiiojioiojodijwiojiowjioedwjioedjiowjewdiojeiodw                 ".$account_type);
    ?>
    <div class="profile-member-info-container">
        <div class="member-id-section">
            <strong>Member ID:</strong> <?php echo esc_html($member_id); ?>
            <span class="memberid-separator"></span>

            <?php if (empty($unpaid_fees)) : ?>
                <?php
                $induction_suffix = '';
                if (!$is_transiting) {
                    if (!empty($preview['eligible'])) {
                        if (!empty($certificate_path)) {
                            $induction_suffix = '. Inducted ' . cison_format_human_date($preview['applied_cutoff']) . '.';
                        } else {
                            $induction_suffix = '. Eligible for induction (Cohort: ' . cison_format_human_date($preview['applied_cutoff']) . ').';
                        }
                    } else {
                        if (empty($preview['applied_cutoff'])) {
                            $induction_suffix = 'Awaiting screening and induction';
                        } else {
                            $induction_suffix = '. Not in current cohort (cutoff: ' . cison_format_human_date($preview['applied_cutoff']) . ').';
                        }
                    }
                }
                ?>
                <span class="fees-paid">
                    <i class="fa fa-trophy" aria-hidden="true"></i>
                    <?php echo $is_transiting ? '' : $induction_suffix; ?>
                </span>
            <?php elseif (!empty($paid_fees) && !empty($unpaid_fees)) : ?>
                <span class="fees-partial">
                    <i class="fa fa-hourglass-half" aria-hidden="true"></i>
                    Limited Membership - Some fees paid.
                </span>
            <?php else : ?>
                <span class="fees-unpaid">
                    <i class="fa fa-lock" aria-hidden="true"></i>
                    Pending Membership – No payments yet.
                </span>
            <?php endif; ?>

            <?php if (empty($unpaid_fees)) : ?>
                <div class="fees-paid-details">
                    <details class="fee-details">
                        <summary class="toggle-details-paid">
                            <span class="toggle-text">
                                <i class="fa fa-check-circle" aria-hidden="true"></i>
                                All <?php echo count($paid_fees); ?> fees paid!
                            </span>
                            <i class="fa fa-chevron-down chevron-icon" aria-hidden="true"></i>
                        </summary>
                        <div class="fee-details-content">
                            <p>Here’s what you’ve paid to unlock full benefits:</p>
                            <div class="fee-table">
                                <div class="fee-row header">
                                    <span>Fee Name</span>
                                    <span>Status</span>
                                </div>
                                <?php foreach ($required_fees as $key => $fee) : ?>
                                    <div class="fee-row fee-<?php echo !empty($paid_fees[$key]) ? 'paid' : 'unpaid'; ?>">
                                        <span><?php echo esc_html($fee['name']); ?></span>
                                        <span>
                                            <?php if (!empty($paid_fees[$key])) : ?>
                                                <i class="fa fa-check" aria-hidden="true"></i> Paid
                                            <?php else : ?>
                                                <i class="fa fa-times" aria-hidden="true"></i> Unpaid
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </details>
                </div>
            <?php endif; ?>

            <?php if ($certificate_link) : ?>
                <div class="certificate-wrapper">
                    <a href="<?php echo esc_url($certificate_link); ?>"
                       class="cison-cert-btn"
                       target="_blank"
                       rel="noopener"
                       aria-label="View Membership Certificate (opens in new tab)">
                        <svg class="cison-cert-icon" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M12 2l2.09 4.23L18.8 7l-3.4 3.32.8 4.68L12 13.77 7.8 15l.8-4.68L5.2 7l4.71-.77L12 2zM7 19h10a1 1 0 0 1 0 2H7a1 1 0 1 1 0-2z"/>
                        </svg>
                        <span>View Certificate</span>
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($unpaid_fees)) : ?>
            <div class="fees-action-required">
                <details class="fee-details">
                    <summary class="toggle-details">
                        <span class="toggle-text">
                            <i class="fa fa-exclamation-circle" aria-hidden="true"></i>
                            <?php echo count($unpaid_fees); ?> Unpaid Fees - Pay all fees to unlock full benefits & certificate
                        </span>
                        <i class="fa fa-chevron-down chevron-icon" aria-hidden="true"></i>
                    </summary>
                    <div class="fee-details-content">
                        <p>Settle these fees for full benefits & certificate:</p>
                        <div class="fee-table">
                            <div class="fee-row header">
                                <span>Fee Name</span>
                                <span>Status</span>
                            </div>
                            <?php foreach ($required_fees as $key => $fee) : ?>
                                <div class="fee-row <?php echo !empty($paid_fees[$key]) ? 'fee-paid' : 'fee-unpaid'; ?>">
                                    <span><?php echo esc_html($fee['name']); ?></span>
                                    <span>
                                        <?php if (!empty($paid_fees[$key])) : ?>
                                            <i class="fa fa-check" aria-hidden="true"></i> Paid
                                        <?php else : ?>
                                            <i class="fa fa-times" aria-hidden="true"></i> Unpaid
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($payment_link !== '#') : ?>
                            <div class="pay-now-wrapper">
                                <a href="<?php echo esc_url($payment_link); ?>" class="pay-now-button">Pay Now</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </details>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/* ======================================================
 * SHORTCODE: CERTIFICATE BUTTON
 * ====================================================== */

add_shortcode('cison_certificate_button', function () {
    if (!is_user_logged_in()) {
        return '<style>.cison-cert-btn{display:inline-flex;align-items:center;gap:10px;background:#0f4236;color:#fff;padding:12px 18px;border-radius:8px;font-weight:600;text-decoration:none}
        .cison-cert-btn.is-locked{background:#ccc;color:#666;cursor:not-allowed}</style>
        <a class="cison-cert-btn is-locked" aria-disabled="true">Log in to view certificate</a>';
    }

    $fallback_css = '<style>.cison-cert-btn{display:inline-flex;align-items:center;gap:10px;background:#0f4236;color:#fff;padding:12px 18px;border-radius:8px;font-weight:600;text-decoration:none}
    .cison-cert-btn:hover{background:#146c54;color:#fff;text-decoration:none}
    .cison-cert-btn.is-locked{background:#ccc;color:#666;cursor:not-allowed}
    .cison-cert-icon{fill:currentColor}</style>';

    $user_id     = get_current_user_id();
    $profile_url = function_exists('bp_core_get_user_domain')
        ? bp_core_get_user_domain($user_id)
        : site_url('/profile');

    $member_id = function_exists('bp_get_profile_field_data')
        ? bp_get_profile_field_data(['field' => 894, 'user_id' => $user_id])
        : '';

    $is_transiting = function_exists('bp_get_profile_field_data')
        ? (bp_get_profile_field_data(['field' => 1595, 'user_id' => $user_id]) === 'Yes')
        : false;

    $is_eligible       = false;
    $certificate_path  = false;

    if ($is_transiting && $member_id) {
    $reg_year    = 2023;
	$is_student = bp_get_member_type( $user_id ) === "student-member";
    $required    = cison_get_required_fees(true, $reg_year, $is_student);
    $paid_fees   = cison_get_paid_fees($user_id);
    $unpaid_fees = cison_get_unpaid_fees($required, $paid_fees);

    // 1) Always check existing certificate first
    $certificate_path = cison_check_certificate_exists($member_id);

    if ($certificate_path) {
        // grandfather: keep access to already-issued cert
        $is_eligible = true;
    } else {
        // normal eligibility for first-time issuance
        $is_eligible = empty($unpaid_fees) ||
            (count($unpaid_fees) === 1 && isset($unpaid_fees["dev_levy_" . CISON_CURRENT_YEAR]));
    }
}
 else {
        global $wpdb;
        if ($member_id) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT certificate_path FROM " . CISON_CERT_TABLE . " WHERE member_id = %s LIMIT 1",
                $member_id
            ));
            if ($row && !empty($row->certificate_path) && file_exists($row->certificate_path)) {
                $certificate_path = $row->certificate_path;
                $is_eligible      = true;
            }
        }
    }

    if ($is_eligible && $certificate_path) {
        $token = wp_create_nonce("cison_certificate_{$user_id}") . '-' . bin2hex(random_bytes(8));
        $link  = site_url("/certificate?user_id={$user_id}&token={$token}");
        set_transient("cison_certificate_token_{$user_id}", $token . '-' . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''), 5 * MINUTE_IN_SECONDS);

        return $fallback_css . '
        <a href="' . esc_url($link) . '" class="cison-cert-btn" target="_blank" rel="noopener" data-state="active">
            <svg class="cison-cert-icon" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M12 2l2.09 4.23L18.8 7l-3.4 3.32.8 4.68L12 13.77 7.8 15l.8-4.68L5.2 7l4.71-.77L12 2zM7 19h10a1 1 0 0 1 0 2H7a1 1 0 1 1 0-2z"/>
            </svg>
            <span>View Certificate</span>
        </a>';
    }

    $why_locked = !$member_id
        ? 'Member ID not set'
        : (!$is_transiting ? 'Certificates currently for inducted/transiting members only' : 'Complete required fees to unlock');

    return $fallback_css . '
    <a href="' . esc_url($profile_url) . '" class="cison-cert-btn is-locked" aria-disabled="true"
       title="' . esc_attr($why_locked) . '" data-state="locked"
       data-member-id="' . esc_attr($member_id) . '" data-transiting="' . ($is_transiting ? '1' : '0') . '">
        <svg class="cison-cert-icon" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 2l2.09 4.23L18.8 7l-3.4 3.32.8 4.68L12 13.77 7.8 15l.8-4.68L5.2 7l4.71-.77L12 2zM7 19h10a1 1 0 0 1 0 2H7a1 1 0 1 1 0-2z"/>
        </svg>
        <span>No Certificate yet!</span>
    </a>';
});

/* ======================================================
 * SHORTCODE: VERIFICATION
 * ====================================================== */

add_shortcode('cison_verify', 'cison_verification_shortcode');
function cison_verification_shortcode() {
    global $wpdb;
    $table_name = CISON_CERT_TABLE;

    ob_start();

    $input_from_url = sanitize_text_field(isset($_GET['verify_id']) ? $_GET['verify_id'] : '');
    ?>
    <div class="cison-verify-container">
        <h2 class="cison-verify-title">CISON Membership and Certificate Verification</h2>
        <form method="post" class="cison-verify-form">
            <?php wp_nonce_field('cison_verify_action', 'cison_verify_nonce'); ?>
            <div class="input-group">
                <label for="verify_id">Member ID or Certificate ID</label>
                <input type="text" id="verify_id" name="verify_id" placeholder="e.g., XXXXABC123" value="<?php echo esc_attr($input_from_url); ?>" required>
                <p class="input-note">Scan QR code or enter ID manually</p>
            </div>
            <input type="submit" value="Verify" class="cison-verify-button">
        </form>
        <?php
        $input = $input_from_url;
        $should_verify = ($input_from_url && empty($_POST)) ||
            ($_SERVER['REQUEST_METHOD'] === 'POST' &&
             isset($_POST['cison_verify_nonce']) &&
             check_admin_referer('cison_verify_action', 'cison_verify_nonce'));

        if ($should_verify) {
            $input = $input ?: sanitize_text_field($_POST['verify_id']);
            $is_qr = (substr_count($input, '|') === 2);
            $is_certificate_id = (strpos($input, '-') !== false && !$is_qr);

            if ($is_qr) {
                $parts = explode('|', $input, 3);
                $stmt = $wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE member_id = %s AND cert_id = %s AND secret_token = %s",
                    $parts[0],
                    $parts[1],
                    $parts[2]
                );
                $row = $wpdb->get_row($stmt);
            } else {
                $stmt = $wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE " . ($is_certificate_id ? 'cert_id' : 'member_id') . " = %s",
                    $input
                );
                $row = $wpdb->get_row($stmt);
            }

            if ($wpdb->last_error) {
                error_log("CISON verification error for {$input}: " . $wpdb->last_error);
            }

            if ($row && file_exists($row->certificate_path)) {
                $full_name = trim("{$row->firstname} {$row->middlename} {$row->surname}");
                ?>
                <div class="verify-result">
                    <h3>Verification Successful</h3>
                    <div class="result-row"><span class="label">Full Name:</span><span class="value"><?php echo esc_html($full_name ?: 'Not provided'); ?></span></div>
                    <div class="result-row"><span class="label">Member ID:</span><span class="value"><?php echo esc_html($row->member_id); ?></span></div>
                    <div class="result-row"><span class="label">Certificate ID:</span><span class="value"><?php echo esc_html($row->cert_id); ?></span></div>
                    <div class="result-row"><span class="label">Membership Status:</span><span class="value">Registered Statistician</span></div>
                    <div class="result-row"><span class="label">Certificate Status:</span><span class="value">Verified!</span></div>
                    <div class="result-row"><span class="value">Transitioned from National Statistical Association (NSA)</span></div>
                    <div class="result-row">
                        <span class="label">Date Issued:</span>
                        <span class="value"><?php echo esc_html(date('F j, Y', $row->date_issued)); ?></span>
                    </div>
                    <div class="result-row">
                        <span class="label">Expiry Date:</span>
                        <span class="value"><?php echo esc_html(date('F j, Y', strtotime('+2 years', $row->date_issued))); ?></span>
                    </div>
                </div>
                <?php
            } else {
                ?>
                <div class="verify-error">
                    <h3>Verification Failed</h3>
                    <p>No valid record found for "<?php echo esc_html($input); ?>".</p>
                    <p>The certificate may have been deleted or is invalid. Please try again or contact support.</p>
                </div>
                <?php
            }
        }
        ?>
    </div>
    <style>
        .cison-verify-container { max-width: 600px; margin: 40px auto; padding: 25px; background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1); font-family: 'Arial', sans-serif; }
        .cison-verify-title { font-size: 24px; color: #333; text-align: center; margin-bottom: 20px; }
        .cison-verify-form { display: flex; flex-direction: column; gap: 15px; }
        .input-group label { font-size: 14px; color: #555; margin-bottom: 5px; font-weight: bold; }
        .input-group input[type="text"] { padding: 10px; font-size: 16px; border: 1px solid #ddd; border-radius: 4px; width: 100%; box-sizing: border-box; }
        .input-group input[type="text"]:focus { border-color: #007bff; outline: none; }
        .input-note { font-size: 12px; color: #777; margin-top: 5px; }
        .cison-verify-button { padding: 10px; font-size: 16px; background: #007bff; color: #fff; border: none; border-radius: 4px; cursor: pointer; transition: background 0.3s; }
        .cison-verify-button:hover { background: #0056b3; }
        .verify-result, .verify-error { margin-top: 25px; padding: 15px; border-radius: 6px; }
        .verify-result { background: #e6ffe6; border: 1px solid #00cc00; }
        .verify-error { background: #ffe6e6; border: 1px solid #ff0000; }
        .verify-result h3, .verify-error h3 { font-size: 18px; margin-bottom: 15px; color: #333; }
        .result-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
        .result-row:last-child { border-bottom: none; }
        .result-row .label { font-weight: bold; color: #555; }
        .result-row .value { color: #333; }
        @media (max-width: 500px) {
            .cison-verify-container { margin: 20px; padding: 15px; }
            .result-row { flex-direction: column; gap: 5px; }
        }
    </style>
    <?php
    return ob_get_clean();
}

/* ======================================================
 * ADMIN: STATUS MONITOR
 * ====================================================== */

add_action('admin_menu', function () {
    add_submenu_page(
        'tools.php',
        'CISON Member Certificate Status',
        'Cert & Fees Monitor',
        'manage_options',
        'cison-status-monitor',
        'cison_render_status_monitor_page'
    );
});

function cison_render_status_monitor_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Permission denied');
    }

    global $wpdb;
    $xprofile = $wpdb->prefix . 'bp_xprofile_data';
    $users_tbl = $wpdb->users;
    $cert_tbl  = CISON_CERT_TABLE;

    $per_page  = max(1, intval(isset($_GET['per_page']) ? $_GET['per_page'] : 25));
    $page      = max(1, intval(isset($_GET['paged']) ? $_GET['paged'] : 1));
    $search    = trim(sanitize_text_field(isset($_GET['s']) ? $_GET['s'] : ''));
    $scope     = isset($_GET['scope']) && in_array($_GET['scope'], ['transiting','inducted','all'], true) ? $_GET['scope'] : 'transiting';
    $certf     = isset($_GET['cert']) && in_array($_GET['cert'], ['all','issued','missing'], true) ? $_GET['cert'] : 'all';
    $unpaidf   = isset($_GET['unpaid']) && in_array($_GET['unpaid'], ['all','none','some'], true) ? $_GET['unpaid'] : 'all';
    $orderby   = isset($_GET['orderby']) ? $_GET['orderby'] : 'user_id';
    $order     = (isset($_GET['order']) && strtoupper($_GET['order']) === 'DESC') ? 'DESC' : 'ASC';

    $sortable_whitelist = ['user_id','member_id','fullname','cert_status','unpaid_count'];
    if (!in_array($orderby, $sortable_whitelist, true)) {
        $orderby = 'user_id';
    }

    $with_member_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT user_id FROM {$xprofile} WHERE field_id = %d AND value <> ''",
            894
        )
    );

    $transiting_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT user_id FROM {$xprofile} WHERE field_id = %d AND value = %s",
            1595, 'Yes'
        )
    );
    $transiting_set = array_flip($transiting_ids ?: []);

    $candidates = $with_member_ids ?: [];
    if ($scope === 'transiting') {
        $candidates = array_values(array_filter($candidates, function ($uid) use ($transiting_set) {
            return isset($transiting_set[$uid]);
        }));
    } elseif ($scope === 'inducted') {
        $candidates = array_values(array_filter($candidates, function ($uid) use ($transiting_set) {
            return !isset($transiting_set[$uid]);
        }));
    }

    // Search
    if ($search !== '' && !empty($candidates)) {
        $like = '%' . $wpdb->esc_like($search) . '%';

        $ids_from_profile = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT user_id
             FROM {$xprofile}
             WHERE (field_id IN (1,2,894) AND value LIKE %s)",
            $like
        ));

        $ids_from_users = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$users_tbl}
             WHERE user_email LIKE %s OR user_login LIKE %s OR user_nicename LIKE %s",
            $like, $like, $like
        ));

        $ids_from_cert = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$cert_tbl} WHERE cert_id LIKE %s",
            $like
        ));

        $search_union = array_unique(array_merge($ids_from_profile ?: [], $ids_from_users ?: [], $ids_from_cert ?: []));
        if (!empty($search_union)) {
            $search_set = array_flip($search_union);
            $candidates = array_values(array_filter($candidates, function ($uid) use ($search_set) {
                return isset($search_set[$uid]);
            }));
        } else {
            $candidates = [];
        }
    }

    $rows = [];
    if (!empty($candidates)) {
        foreach ($candidates as $user_id) {
            $member_id = bp_get_profile_field_data(['field' => 894, 'user_id' => $user_id]);
            $firstname = bp_get_profile_field_data(['field' => 1,   'user_id' => $user_id]);
            $surname   = bp_get_profile_field_data(['field' => 2,   'user_id' => $user_id]);
            $fullname  = trim($firstname . ' ' . $surname);

            $cert_row = $wpdb->get_row($wpdb->prepare(
                "SELECT certificate_path FROM {$cert_tbl} WHERE user_id = %d LIMIT 1",
                $user_id
            ));
            $has_cert = ($cert_row && !empty($cert_row->certificate_path) && file_exists($cert_row->certificate_path));

            $is_transiting = (bp_get_profile_field_data(['field' => 1595, 'user_id' => $user_id]) === 'Yes');
            $reg_year      = $is_transiting ? 2023 : max(2024, min((int) substr((string) $member_id, 0, 4), CISON_CURRENT_YEAR));
			$is_student = bp_get_member_type( $user_id ) === "student-member";
            $required_fees = cison_get_required_fees($is_transiting, $reg_year, $is_student);
            $paid_fees     = cison_get_paid_fees($user_id);
            $unpaid_fees   = cison_get_unpaid_fees($required_fees, $paid_fees);

            $rows[] = [
                'user_id'          => (int) $user_id,
                'member_id'        => (string) $member_id,
                'fullname'         => $fullname,
                'cert_status'      => $has_cert ? 1 : 0,
                'cert_status_text' => $has_cert ? 'Yes' : 'No',
                'unpaid_count'     => count($unpaid_fees),
                'unpaid_list'      => implode(', ', array_column($unpaid_fees, 'name')),
            ];
        }
    }

    // Filter by cert/unpaid
    if (!empty($rows)) {
        if ($certf === 'issued') {
            $rows = array_values(array_filter($rows, function ($r) {
                return $r['cert_status'] === 1;
            }));
        } elseif ($certf === 'missing') {
            $rows = array_values(array_filter($rows, function ($r) {
                return $r['cert_status'] === 0;
            }));
        }

        if ($unpaidf === 'none') {
            $rows = array_values(array_filter($rows, function ($r) {
                return $r['unpaid_count'] === 0;
            }));
        } elseif ($unpaidf === 'some') {
            $rows = array_values(array_filter($rows, function ($r) {
                return $r['unpaid_count'] > 0;
            }));
        }
    }

    // Sort
    if (!empty($rows)) {
        usort($rows, function ($a, $b) use ($orderby, $order) {
            $av = isset($a[$orderby]) ? $a[$orderby] : null;
            $bv = isset($b[$orderby]) ? $b[$orderby] : null;

            if (in_array($orderby, ['fullname','member_id'], true)) {
                $cmp = strcasecmp((string) $av, (string) $bv);
            } else {
                $cmp = $av <=> $bv;
            }

            return ($order === 'DESC') ? -$cmp : $cmp;
        });
    }

    $total       = count($rows);
    $total_pages = max(1, (int) ceil($total / $per_page));
    if ($page > $total_pages) {
        $page = $total_pages;
    }
    $offset     = ($page - 1) * $per_page;
    $paged_rows = array_slice($rows, $offset, $per_page);

    // Per-user cache refresh
    if (isset($_GET['refresh_user'])) {
        $refresh_id = intval($_GET['refresh_user']);
        delete_transient('cison_paid_fees_' . $refresh_id);
        echo '<div class="notice notice-success is-dismissible"><p>Cache for user ID ' . esc_html($refresh_id) . ' refreshed.</p></div>';
    }

    $base_url = admin_url('tools.php?page=cison-status-monitor');
    $preserve = function (array $extra = []) use ($base_url, $search, $scope, $certf, $unpaidf, $per_page, $orderby, $order) {
        $args = array_merge([
            's'        => $search,
            'scope'    => $scope,
            'cert'     => $certf,
            'unpaid'   => $unpaidf,
            'per_page' => $per_page,
            'orderby'  => $orderby,
            'order'    => $order,
        ], $extra);
        return esc_url(add_query_arg($args, $base_url));
    };

    echo '<div class="wrap">';
    echo '<h1>Certificate & Fee Status Monitor</h1>';

    echo '<form method="get" style="margin:12px 0 16px;">';
    echo '<input type="hidden" name="page" value="cison-status-monitor" />';

    echo '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="Search member ID, name, email, cert ID" style="min-width:300px;margin-right:6px;">';

    echo '<select name="scope" style="margin-right:6px;">';
    foreach (['transiting' => 'Transiting', 'inducted' => 'Inducted', 'all' => 'All with Member ID'] as $k => $label) {
        echo '<option value="' . esc_attr($k) . '" ' . selected($scope, $k, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';

    echo '<select name="cert" style="margin-right:6px;">';
    foreach (['all' => 'Cert: All', 'issued' => 'Cert: Issued', 'missing' => 'Cert: Missing'] as $k => $label) {
        echo '<option value="' . esc_attr($k) . '" ' . selected($certf, $k, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';

    echo '<select name="unpaid" style="margin-right:6px;">';
    foreach (['all' => 'Unpaid: All', 'none' => 'Unpaid: None', 'some' => 'Unpaid: Some'] as $k => $label) {
        echo '<option value="' . esc_attr($k) . '" ' . selected($unpaidf, $k, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';

    echo '<label style="margin-right:6px;">Per page ';
    echo '<input type="number" min="5" max="200" name="per_page" value="' . esc_attr($per_page) . '" style="width:80px;">';
    echo '</label>';

    echo '<button class="button button-primary">Apply</button> ';
    echo '<a class="button" href="' . esc_url($base_url) . '">Reset</a>';
    echo '</form>';

    echo '<p><em>Found <strong>' . $total . '</strong> member(s). Showing ' . count($paged_rows) . ' on page ' . $page . ' of ' . $total_pages . '.</em></p>';

    $sortable_header = function ($key, $label) use ($preserve, $orderby, $order) {
        $next_order = ($orderby === $key && $order === 'ASC') ? 'DESC' : 'ASC';
        $url        = $preserve(['orderby' => $key, 'order' => $next_order]);
        $arrow      = '';
        if ($orderby === $key) {
            $arrow = $order === 'ASC' ? ' ↑' : ' ↓';
        }
        return '<a href="' . $url . '">' . esc_html($label . $arrow) . '</a>';
    };

    echo '<table class="widefat fixed striped">';
    echo '<thead><tr>';
    echo '<th>' . $sortable_header('user_id', 'User ID') . '</th>';
    echo '<th>' . $sortable_header('member_id', 'Member ID') . '</th>';
    echo '<th>' . $sortable_header('fullname', 'Full Name') . '</th>';
    echo '<th>' . $sortable_header('cert_status', 'Cert Issued?') . '</th>';
    echo '<th>' . $sortable_header('unpaid_count', 'Unpaid Fees') . '</th>';
    echo '<th>Refresh</th>';
    echo '</tr></thead><tbody>';

    if (empty($paged_rows)) {
        echo '<tr><td colspan="6"><em>No results.</em></td></tr>';
    } else {
        foreach ($paged_rows as $r) {
            $refresh_url = $preserve([
                'refresh_user' => $r['user_id'],
                'paged'        => $page,
            ]);
            echo '<tr>';
            echo '<td>' . esc_html($r['user_id']) . '</td>';
            echo '<td>' . esc_html($r['member_id']) . '</td>';
            echo '<td>' . esc_html($r['fullname']) . '</td>';
            echo '<td>' . ($r['cert_status'] ? '✅ Yes' : '❌ No') . '</td>';
            echo '<td>' . ($r['unpaid_count'] ? esc_html($r['unpaid_count'] . ' (' . $r['unpaid_list'] . ')') : 'None') . '</td>';
            echo '<td><a href="' . $refresh_url . '" class="button">Refresh</a></td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';

    if ($total_pages > 1) {
        echo '<div style="margin-top:16px;">';
        for ($p = 1; $p <= $total_pages; $p++) {
            $class = $p == $page ? 'button-primary' : 'button-secondary';
            $url   = $preserve(['paged' => $p]);
            echo "<a href='{$url}' class='button {$class}' style='margin-right:4px;'>{$p}</a>";
        }
        echo '</div>';
    }

    echo '</div>';
}

/* ======================================================
 * BULK TRANSITING CERT UPDATE (TOOLS)
 * ====================================================== */

add_action('admin_menu', function () {
    add_submenu_page(
        'tools.php',
        'CISON Bulk Cert Update (Transiting)',
        'Bulk Cert Update',
        'manage_options',
        'cison-bulk-cert-update',
        'cison_bulk_cert_update_page'
    );
});

function cison_bulk_cert_update_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Permission denied');
    }

    global $wpdb;
    $xprofile = $wpdb->prefix . 'bp_xprofile_data';

    $batch_size = 25;
    $offset     = isset($_GET['offset']) ? intval($_GET['offset']) : 0;

    $all_user_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT user_id FROM {$xprofile} WHERE field_id = %d AND value = %s",
        1595, 'Yes'
    ));

    $total     = count($all_user_ids);
    $batch_ids = array_slice($all_user_ids, $offset, $batch_size);
    $processed = $offset;

    foreach ($batch_ids as $user_id) {
        delete_transient('cison_paid_fees_' . $user_id);
        cison_check_eligibility_and_create_row_if_missing($user_id);
        $processed++;
    }

    echo '<div class="wrap"><h1>Bulk Certificate Update (Transiting)</h1>';
    echo "<p>Processed <strong>{$processed}</strong> of <strong>{$total}</strong> transiting members.</p>";

    if ($processed < $total) {
        $next_url = add_query_arg([
            'page'   => 'cison-bulk-cert-update',
            'offset' => $processed,
        ], admin_url('tools.php'));

        echo "<p>Processing next batch in 1 second…</p>";
        echo "<script>
            setTimeout(function(){
                window.location.href = " . wp_json_encode($next_url) . ";
            }, 1000);
        </script>";
    } else {
        echo '<p><strong>All done!</strong> Certificates processed for all transiting members.</p>';
    }

    echo '</div>';
}

/* ======================================================
 * CERTIFICATE SYNC PAGE (OPTIONAL)
 * ====================================================== */

add_action('admin_menu', function () {
    add_submenu_page(
        'tools.php',
        'CISON Certificate Management',
        'Certificate Management',
        'manage_options',
        'cison-cert-management',
        'cison_certificate_sync_page'
    );
});

function cison_certificate_sync_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Permission denied');
    }

    global $wpdb;
    $table_name = CISON_CERT_TABLE;

    if (isset($_POST['sync_certificates']) && check_admin_referer('cison_sync_certificates')) {
        $files = glob(CISON_CERTIFICATE_DIR . 'certificate_*.pdf');
        foreach ($files as $file) {
            if (!file_exists($file)) {
                continue;
            }
            $cert_id = str_replace(['certificate_', '.pdf'], '', basename($file));
            $user_id = bp_get_userid_by_cert_id($cert_id);
            if ($user_id) {
                $member_id = function_exists('bp_get_profile_field_data')
                    ? bp_get_profile_field_data(['field' => 894, 'user_id' => $user_id])
                    : '';
                $result = $wpdb->update(
                    $table_name,
                    [
                        'certificate_path' => $file,
                        'last_updated'     => time(),
                        'member_id'        => $member_id,
                    ],
                    ['cert_id' => $cert_id],
                    ['%s','%d','%s'],
                    ['%s']
                );
                if ($result === false) {
                    error_log("Sync failed for cert_id {$cert_id}: " . $wpdb->last_error);
                }
            }
        }
        echo '<div class="updated"><p>Certificates synced.</p></div>';
    }

    if (isset($_POST['clear_certificates']) && check_admin_referer('cison_clear_certificates')) {
        $result = $wpdb->query("TRUNCATE TABLE {$table_name}");
        if ($result === false) {
            error_log("Failed to truncate {$table_name}: " . $wpdb->last_error);
        }
        echo '<div class="updated"><p>Certificate records cleared.</p></div>';
    }

    ?>
    <div class="wrap">
        <h1>Certificate Management</h1>
        <form method="post" style="margin-bottom: 20px;">
            <?php wp_nonce_field('cison_sync_certificates'); ?>
            <input type="submit" name="sync_certificates" value="Sync Certificates from Files" class="button-primary">
            <p class="description">Scans certificate PDFs in the private directory and updates their paths in the database.</p>
        </form>
        <form method="post">
            <?php wp_nonce_field('cison_clear_certificates'); ?>
            <input type="submit" name="clear_certificates" value="Clear Certificate Records" class="button-secondary" onclick="return confirm('Are you sure you want to clear all certificate records? This cannot be undone.');">
            <p class="description">Clears all records from the certificate database. Use this if you’ve deleted certificate files and want to start fresh.</p>
        </form>
    </div>
    <?php
}

/* ======================================================
 * LOOKUP HELPERS
 * ====================================================== */

function bp_get_userid_by_cert_id($cert_id) {
    global $wpdb;
    $result = $wpdb->get_var($wpdb->prepare(
        "SELECT user_id FROM " . CISON_CERT_TABLE . " WHERE cert_id = %s LIMIT 1",
        $cert_id
    ));
    if ($wpdb->last_error) {
        error_log("Error in bp_get_userid_by_cert_id for {$cert_id}: " . $wpdb->last_error);
    }
    return $result;
}

function bp_get_userid_by_profile_field($field_id, $value) {
    global $wpdb;
    $result = $wpdb->get_var($wpdb->prepare(
        "SELECT user_id FROM {$wpdb->prefix}bp_xprofile_data WHERE field_id = %d AND value = %s LIMIT 1",
        $field_id,
        $value
    ));
    if ($wpdb->last_error) {
        error_log("Error in bp_get_userid_by_profile_field: " . $wpdb->last_error);
    }
    return $result;
}

/* ======================================================
 * ADMIN: CISON DEBUG USER
 * ====================================================== */

add_action('admin_menu', function () {
    add_submenu_page(
        'tools.php',
        'CISON Debug User',
        'CISON Debug',
        'manage_options',
        'cison-debug-user',
        'cison_render_debug_user_page'
    );
});

function cison_render_debug_user_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Permission denied');
    }

    $uid  = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    $data = null;
    if ($uid > 0) {
        $data = cison__collect_debug($uid);
    }

    echo '<div class="wrap"><h1>CISON Debug User</h1>';
    echo '<form method="get" style="margin:12px 0 16px;">';
    echo '<input type="hidden" name="page" value="cison-debug-user" />';
    echo '<label>User ID: <input type="number" name="user_id" value="' . esc_attr($uid ?: '') . '" min="1" style="width:120px"></label> ';
    echo '<button class="button button-primary">Inspect</button>';
    echo '</form>';

    if ($data) {
        echo '<h2>Result</h2>';
        echo '<pre style="max-width:100%;white-space:pre-wrap;background:#fff;border:1px solid #ccd0d4;padding:12px;">'
            . esc_html(json_encode($data, JSON_PRETTY_PRINT)) . '</pre>';
    } elseif ($uid) {
        echo '<div class="notice notice-error"><p>No data found for that user.</p></div>';
    }

    echo '</div>';
}

function cison__collect_debug($user_id) {
    global $wpdb;
    $table = CISON_CERT_TABLE;

    $user_id = (int) $user_id;

    $member_id_raw = function_exists('bp_get_profile_field_data')
        ? bp_get_profile_field_data(['field' => 894, 'user_id' => $user_id])
        : '';
    $transiting_raw = function_exists('bp_get_profile_field_data')
        ? bp_get_profile_field_data(['field' => 1595, 'user_id' => $user_id])
        : '';
    $is_transiting  = ($transiting_raw === 'Yes');

    $reg_year = $is_transiting
        ? 2023
        : ($member_id_raw ? max(2024, min((int) substr($member_id_raw, 0, 4), CISON_CURRENT_YEAR)) : null);
	
	$is_student = bp_get_member_type( $user_id ) === "student-member";
	
    $required_fees = ($reg_year !== null) ? cison_get_required_fees($is_transiting, $reg_year, $is_student) : [];
    $paid_fees     = cison_get_paid_fees($user_id);
    $unpaid_fees   = cison_get_unpaid_fees($required_fees, $paid_fees);

    $last_payment  = cison_get_last_payment_date($user_id);
    $cutoffs       = cison_get_cutoffs_option();
    $active_cutoff = $cutoffs['active'] ?? null;

    $preview = cison_preview_user_eligibility($user_id);

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE user_id = %d LIMIT 1",
        $user_id
    ));
    $row_exists  = (bool) $row;
    $file_exists = false;
    if ($row && !empty($row->certificate_path)) {
        $file_exists = file_exists($row->certificate_path);
    }

    return [
        'user_id'           => $user_id,
        'member_id_raw'     => (string) $member_id_raw,
        'transiting_field'  => (string) $transiting_raw,
        'is_transiting'     => (bool) $is_transiting,
        'reg_year'          => $reg_year,
        'required_fee_keys' => array_keys($required_fees),
        'product_ids_used'  => cison_extract_product_ids($required_fees),
        'paid_fee_keys'     => array_keys(array_filter($paid_fees)),
        'unpaid_fee_keys'   => array_keys($unpaid_fees),
        'last_payment'      => $last_payment,
        'active_cutoff'     => $active_cutoff,
        'preview'           => $preview,
        'cert_row_exists'   => $row_exists,
        'cert_file_exists'  => $file_exists,
        'cert_row'          => $row ?: null,
    ];
}

/* ======================================================
 * ADMIN: CUTOFF MANAGER + NOTICE
 * ====================================================== */

add_action('admin_menu', function () {
    add_submenu_page(
        'tools.php',
        'CISON Cutoff Manager',
        'Cutoff Manager',
        'manage_options',
        'cison-cutoff-manager',
        'cison_render_cutoff_manager_page'
    );
});

function cison_render_cutoff_manager_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Permission denied');
    }

    $cutoffs = cison_get_cutoffs_option();
    $active  = $cutoffs['active'] ?? '';
    $map     = $cutoffs['map'] ?? [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('cison_cutoff_update')) {
        $new_active = sanitize_text_field(isset($_POST['active_cutoff']) ? $_POST['active_cutoff'] : '');
        $map_year   = sanitize_text_field(isset($_POST['map_year']) ? $_POST['map_year'] : '');
        $map_date   = sanitize_text_field(isset($_POST['map_date']) ? $_POST['map_date'] : '');

        if ($map_year && $map_date) {
            $map[$map_year] = $map_date;
        }

        if ($new_active) {
            $cutoffs['active'] = $new_active;
        }

        $cutoffs['map'] = $map;
        cison_update_cutoffs_option($cutoffs);

        echo '<div class="notice notice-success is-dismissible"><p>Cutoff settings saved successfully.</p></div>';
        $active = $cutoffs['active'];
    }

    ?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-calendar-alt"></span> CISON Cutoff Manager</h1>
        <p>Manage active and historical cutoff dates for inducted (non-transiting) members.</p>

        <form method="post" style="margin-top:20px; max-width:500px;">
            <?php wp_nonce_field('cison_cutoff_update'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="active_cutoff">Active Cutoff Date</label></th>
                    <td><input type="date" id="active_cutoff" name="active_cutoff"
                               value="<?php echo esc_attr($active); ?>"
                               class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="map_year">Year Map (optional)</label></th>
                    <td>
                        <input type="text" id="map_year" name="map_year" placeholder="e.g., 2025"
                               pattern="\d{4}" style="width:80px;text-align:center;">
                        → <input type="date" id="map_date" name="map_date">
                        <p class="description">Use this to map specific years to cutoff dates for archive/audit tracking.</p>
                    </td>
                </tr>
            </table>
            <p class="submit"><button type="submit" class="button button-primary">Save Cutoff Settings</button></p>
        </form>

        <h2>📅 Current Configuration</h2>
        <table class="widefat striped" style="max-width:600px;">
            <thead><tr><th>Type</th><th>Value</th></tr></thead>
            <tbody>
            <tr><td><strong>Active Cutoff</strong></td><td><?php echo $active ? esc_html($active) : '<em>Not Set</em>'; ?></td></tr>
            <?php if (!empty($map)) : ?>
                <?php foreach ($map as $year => $date) : ?>
                    <tr><td><?php echo esc_html($year); ?></td><td><?php echo esc_html($date); ?></td></tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr><td colspan="2"><em>No historical cutoff mappings yet.</em></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) {
        return;
    }

    $cutoffs = get_option('cison_cutoffs', []);
    $active  = isset($cutoffs['active']) ? trim($cutoffs['active']) : '';
    $link    = admin_url('tools.php?page=cison-cutoff-manager');

    if (empty($active)) {
        echo '<div class="notice notice-error" style="border-left-color:#d63638!important;">
                <p><strong>CISON Notice:</strong> No active cutoff date is configured for <em>inducted</em> members.<br>
                <a href="' . esc_url($link) . '" class="button button-small" style="margin-top:4px;">Set Cutoff Date</a></p>
              </div>';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $active)) {
        echo '<div class="notice notice-warning" style="border-left-color:#dba617!important;">
                <p><strong>CISON Warning:</strong> Active cutoff date is invalid (<code>' . esc_html($active) . '</code>).<br>
                <a href="' . esc_url($link) . '" class="button button-small" style="margin-top:4px;">Review Cutoff Settings</a></p>
              </div>';
    }
}
);


add_action('admin_menu', 'add_export_users_submenu');

function add_export_users_submenu() {
    add_submenu_page(
        'users.php',                
        'Export & Verify Members',
        'Export Members',
        'manage_options',
        'export-cison-members',
        'cison_export_members_page_callback'
    );
}

function cison_export_members_page_callback() {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied.');
    }

    global $wpdb;

    $selected_reg_year = isset($_GET['reg_year']) ? intval($_GET['reg_year']) : 0;
    $selected_dues_year = isset($_GET['dues_year']) ? intval($_GET['dues_year']) : 0;
    $paid_dues_filter   = isset($_GET['paid_dues']) ? sanitize_text_field($_GET['paid_dues']) : 'all'; // all / yes / no
    $has_cert_filter    = isset($_GET['has_cert']) ? sanitize_text_field($_GET['has_cert']) : 'all';   // all / yes / no
	
	$user_args = ['number' => -1, 'fields' => 'all'];

    // Get available registration years
    $years = $wpdb->get_col(
        "SELECT DISTINCT YEAR(user_registered) FROM {$wpdb->users} ORDER BY user_registered DESC"
    );

    // Possible dues years (from plugin's mapping + current)
    $dues_years = array_merge(
        [CISON_CURRENT_YEAR],
        array_keys(cison_get_annual_dues_product_ids(0)) // hack to get keys if function allows
    );
    $dues_years = array_unique(array_filter($dues_years));

    // Handle export
    if (isset($_POST['export_members']) && check_admin_referer('cison_export')) {
        $format = sanitize_text_field($_POST['format']);

        if ($selected_reg_year > 0) {
            $user_args['date_query'] = [
                ['column' => 'user_registered', 'year' => $selected_reg_year]
            ];
        }
        $users = get_users($user_args);

        $export_data = [];

        foreach ($users as $user) {
            $uid = $user->ID;

            $member_id = function_exists('bp_get_profile_field_data')
                ? bp_get_profile_field_data(['field' => 894, 'user_id' => $uid])
                : '-';

            $is_transiting = function_exists('bp_get_profile_field_data')
                ? (bp_get_profile_field_data(['field' => 1595, 'user_id' => $uid]) === 'Yes')
                : false;

            $reg_year = $is_transiting ? 2023 : ($member_id ? max(2024, (int) substr($member_id, 0, 4)) : date('Y'));
			
			$is_student = bp_get_member_type( $uid ) === "student-member";

            $required_fees = cison_get_required_fees($is_transiting, $reg_year, $is_student);
            $paid_fees     = cison_get_paid_fees($uid);
            $unpaid_fees   = cison_get_unpaid_fees($required_fees, $paid_fees);

            // Full dues paid for selected year?
            $full_dues_paid_selected = '-';
            if ($selected_dues_year > 0 && isset($required_fees["annual_dues_{$selected_dues_year}"])) {
                $full_dues_paid_selected = (!empty($paid_fees["annual_dues_{$selected_dues_year}"]) &&
                                            !empty($paid_fees["dev_levy_{$selected_dues_year}"])) ? 'Yes' : 'No';
            }

            // Certificate info
            $cert_row = $wpdb->get_row($wpdb->prepare(
                "SELECT cert_id, date_issued FROM " . CISON_CERT_TABLE . " WHERE user_id = %d LIMIT 1",
                $uid
            ));
            $cert_id   = $cert_row ? $cert_row->cert_id : '-';
            $issue_date = $cert_row ? date('Y-m-d H:i', $cert_row->date_issued) : '-';

            // Last payment date (any tracked fee)
            $last_payment = cison_get_last_payment_date($uid) ?: '-';

            $export_data[] = [
                'User ID'                  => $uid,
                'Member ID'                => $member_id,
                'Username'                 => $user->user_login,
                'Email'                    => $user->user_email,
                'Reg. Year'                => $reg_year,
                'Transiting'               => $is_transiting ? 'Yes' : 'No',
                'Purchased Certificate'    => !empty($paid_fees['new_member_fee']) ? 'Yes' : 'No',
                'Paid Any Dues'            => empty($unpaid_fees) ? 'Full' : (count($unpaid_fees) < count($required_fees) ? 'Partial' : 'No'),
                'Full Dues ' . ($selected_dues_year ?: 'Current') => $full_dues_paid_selected,
                'Certificate ID'           => $cert_id,
                'Certificate Issued Date'  => $issue_date,
                'Last Payment Date'        => $last_payment,
            ];
        }

        
        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="cison_members_export_' . date('Y-m-d') . '.csv"');
            $output = fopen('php://output', 'w');
            fputcsv($output, array_keys($export_data[0] ?? []));
            foreach ($export_data as $row) {
                fputcsv($output, $row);
            }
            fclose($output);
            exit;
        }
        // ... add json / xlsx similarly
    }

    // Display page
    $users = get_users($user_args); // filtered below

    ?>
    <div class="wrap">
        <h1>CISON Members Export & Verification</h1>

        <form method="get">
            <input type="hidden" name="page" value="export-cison-members">

            <label>Registration Year:
                <select name="reg_year">
                    <option value="0">All</option>
                    <?php foreach ($years as $y): ?>
                        <option value="<?php echo $y; ?>" <?php selected($selected_reg_year, $y); ?>><?php echo $y; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>Dues Year Check:
                <select name="dues_year">
                    <option value="0">No specific year</option>
                    <?php foreach ($dues_years as $y): ?>
                        <option value="<?php echo $y; ?>" <?php selected($selected_dues_year, $y); ?>><?php echo $y; ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>Paid Dues:
                <select name="paid_dues">
                    <option value="all">All</option>
                    <option value="yes">Has paid dues</option>
                    <option value="no">No dues paid</option>
                </select>
            </label>

            <label>Has Certificate:
                <select name="has_cert">
                    <option value="all">All</option>
                    <option value="yes">Issued</option>
                    <option value="no">Not issued</option>
                </select>
            </label>

            <button type="submit" class="button">Filter</button>
        </form>

        <br>

        <form method="post">
            <?php wp_nonce_field('cison_export'); ?>
            <select name="format">
                <option value="csv">CSV</option>
                <!-- <option value="xlsx">XLSX</option> -->
                <!-- <option value="json">JSON</option> -->
            </select>
            <button type="submit" name="export_members" class="button button-primary">Export Filtered List</button>
        </form>

        <br>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>User ID</th>
                    <th>Member ID</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Reg Year</th>
					<th>Phone Number</th>
                    <th>Transiting</th>
                    <th>Cert Purchased</th>
                    <th>Paid Dues</th>
                    <th>Cert ID</th>
                    <th>Cert Issued</th>
                    <th>Last Payment</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
					<?php
                    	$uid = $user->ID;
                    	$member_id = bp_get_profile_field_data(['field' => 894, 'user_id' => $uid]) ?: '-';
						$reg_year = $is_transiting ? 2023 : ($member_id ? max(2024, (int) substr($member_id, 0, 4)) : date('Y'));
						
						$phone_number = xprofile_get_field_data('Mobile no', $uid);
						$is_transiting = function_exists('bp_get_profile_field_data') ? (bp_get_profile_field_data(['field' => 1595, 'user_id' => $uid]) === 'Yes') : false;
						$paid_dues = cison_get_paid_fees($uid);

					?>
					<tr>
                        <td><?php echo esc_html($uid); ?></td>
						<td><?php echo esc_html($member_id); ?></td>
						<td><?php echo esc_html($user->display_name); ?></td>
						<td><?php echo esc_html($user->user_email); ?></td>
						<td><?php echo esc_html($reg_year); ?></td>
						<td><?php echo esc_html($phone_number); ?></td>
						<td><?php echo esc_html($is_transiting); ?></td>
						<td></td>
						<td><? echo esc_html(implode(', ', $paid_dues[$i]));; ?></td>
                    </tr>
               <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
