<?php
/**
 * Plugin Name: Advanced Certificate Manager with QR Verification
 * Plugin URI:  https://docs.cison.org.ng
 * Description: Issues HMAC-secured certificates with QR verification, using custom tables.
 * Version:     1.0.0
 * Author:      Franklin
 * Text Domain: 
 * Domain Path: /languages
 */

namespace Certificates;



if (!defined('ABSPATH')) {
    exit;
}

define('ACMQR_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('ACMQR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ACMQR_QR_LIBRARY', ACMQR_PLUGIN_PATH . 'vendor/phpqrcode/qrlib.php');

/**
 * Autoloader — handles both root and sub-namespaces under Certificates\
 */
spl_autoload_register(function ($class) {
    $prefix = 'Certificates\\';
    $base_dir = ACMQR_PLUGIN_PATH . 'includes/';
    $len = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    // e.g. Certificates\Includes\Activator → Includes\Activator
    $relative_class = substr($class, $len);

    // Certificates\Includes\Activator → includes/class-activator.php
    // Strip the sub-namespace segment (Includes\) and use only the class name
    $parts = explode('\\', $relative_class);
    $class_name = array_pop($parts);                          // e.g. "Activator"
    $sub_path = count($parts)
        ? strtolower(implode('/', $parts)) . '/'              // e.g. "includes/"  (already base, so optional)
        : '';

    $file = $base_dir . $sub_path . 'class-' . strtolower(
        preg_replace('/(?<!^)[A-Z]/', '-$0', $class_name)    // PascalCase → kebab-case
    ) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Explicitly require Activator before registering hooks.
 * register_activation_hook() fires before plugins_loaded, so the
 * autoloader may not have had a chance to run — require directly.
*/
require_once ACMQR_PLUGIN_PATH . 'includes/class-activator.php';
require_once ACMQR_PLUGIN_PATH . 'includes/class-verification-router.php';
require_once ACMQR_PLUGIN_PATH . 'includes/class-database-schema.php';
require_once ACMQR_PLUGIN_PATH . 'includes/class-certmanager-admin-ui.php';
require_once ACMQR_PLUGIN_PATH . 'includes/class-certificate-profile.php';


register_activation_hook(__FILE__, ['\\Certificates\\Includes\\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['\\Certificates\\Includes\\Activator', 'deactivate']);

use Certificates\Includes\VerificationRouter;
use Certificates\Includes\CertificateProfile;
use Certificates\Includes\CertManager_Admin_UI;

add_action('plugins_loaded', function () {
    new VerificationRouter();
    new CertManager_Admin_UI();
    new CertificateProfile();
});
