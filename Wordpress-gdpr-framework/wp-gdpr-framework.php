<?php
/**
 * Plugin Name: WordPress GDPR Framework
 * Plugin URI: https://example.com/wordpress-gdpr-framework
 * Description: A comprehensive GDPR compliance solution
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: wp-gdpr-framework
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('GDPR_FRAMEWORK_VERSION', '1.0.0');
define('GDPR_FRAMEWORK_PATH', plugin_dir_path(__FILE__));
define('GDPR_FRAMEWORK_URL', plugin_dir_url(__FILE__));
define('GDPR_FRAMEWORK_TEMPLATE_PATH', plugin_dir_path(__FILE__) . 'templates/');
define('GDPR_FRAMEWORK_PUBLIC_TEMPLATE_PATH', GDPR_FRAMEWORK_TEMPLATE_PATH . 'public/');
define('GDPR_FRAMEWORK_ADMIN_TEMPLATE_PATH', GDPR_FRAMEWORK_TEMPLATE_PATH . 'admin/');

// Verify required files exist
function gdpr_framework_verify_files() {
    $required_files = [
        'src/Core/Database.php',
        'src/Core/Settings.php',
        'src/Core/GDPRFramework.php',
        'src/Components/UserConsentManager.php',
        'src/Components/TemplateRenderer.php',
        'src/Components/DataEncryptionManager.php',
        'src/Components/DataPortabilityManager.php',
        'src/Components/LoggingAuditManager.php' 
    ];

    foreach ($required_files as $file) {
        if (!file_exists(GDPR_FRAMEWORK_PATH . $file)) {
            return false;
        }
    }
    return true;
}

if (defined('WP_DEBUG') && WP_DEBUG) {
    add_action('wp_ajax_update_user_consent', function() {
        error_log('GDPR Debug: AJAX consent update triggered');
        error_log('POST data: ' . print_r($_POST, true));
    }, 5);
}

// Autoloader
function gdpr_framework_autoloader($class) {
    $prefix = 'GDPRFramework\\';
    $length = strlen($prefix);
    
    if (strncmp($prefix, $class, $length) !== 0) {
        return;
    }

    $relative_class = substr($class, $length);
    $file = GDPR_FRAMEWORK_PATH . 'src/' . str_replace('\\', '/', $relative_class) . '.php';
    
    if (file_exists($file)) {
        require_once $file;
    }
}

spl_autoload_register('gdpr_framework_autoloader');

// Register activation/deactivation hooks
register_activation_hook(__FILE__, function() {
    try {
        global $wpdb;
        
        // Drop existing tables
        $tables = [
            $wpdb->prefix . 'gdpr_audit_log',
            $wpdb->prefix . 'gdpr_user_consents',
            $wpdb->prefix . 'gdpr_user_data',
            $wpdb->prefix . 'gdpr_data_requests',
            $wpdb->prefix . 'gdpr_login_log'
        ];
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }

        error_log('GDPR Framework - Old tables dropped');

        // Create database tables
        $framework = \GDPRFramework\Core\GDPRFramework::getInstance();
        $db = $framework->getDatabase();
        if (!$db) {
            throw new \Exception('Database initialization failed');
        }

        $db->createTables();
        error_log('GDPR Framework - New tables created');

        // Verify tables exist
        foreach ($tables as $table) {
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SHOW TABLES LIKE %s",
                    $table
                )
            );
            if (!$exists) {
                error_log('GDPR Framework - Table not created: ' . $table);
                throw new \Exception('Failed to create table: ' . $table);
            }
        }

        // Add default consent types
        $consent = $framework->getComponent('consent');
        if ($consent) {
            $consent->addDefaultConsentTypes();
        }

        // Clear any cached data
        wp_cache_flush();
        
        flush_rewrite_rules();
        
        error_log('GDPR Framework - Activation completed successfully');
    } catch (\Exception $e) {
        error_log('GDPR Framework Activation Error: ' . $e->getMessage());
        wp_die('GDPR Framework activation failed: ' . esc_html($e->getMessage()));
    }
});

register_deactivation_hook(__FILE__, ['\GDPRFramework\Core\GDPRFramework', 'deactivate']);

function gdpr_framework_init() {
    try {
        // Verify files exist
        if (!gdpr_framework_verify_files()) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>' . 
                     esc_html__('GDPR Framework: Required files are missing.', 'wp-gdpr-framework') . 
                     '</p></div>';
            });
            return;
        }

        // Initialize framework
        $framework = \GDPRFramework\Core\GDPRFramework::getInstance();

        
        // Verify database tables
        if (!$framework->getDatabase()->verifyTables()) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>' . 
                     esc_html__('GDPR Framework: Database tables are missing. Please deactivate and reactivate the plugin.', 'wp-gdpr-framework') . 
                     '</p></div>';
            });
            return;
        }
        
    } catch (\Exception $e) {
        error_log('GDPR Framework Init Error: ' . $e->getMessage());
        add_action('admin_notices', function() use ($e) {
            echo '<div class="notice notice-error"><p>' . 
                 esc_html__('GDPR Framework initialization failed: ', 'wp-gdpr-framework') . 
                 esc_html($e->getMessage()) . '</p></div>';
        });
    }
}

// Add error handler
function gdpr_framework_error_handler($errno, $errstr, $errfile, $errline) {
    error_log("GDPR Framework Error: [$errno] $errstr in $errfile on line $errline");
    return false;
}
set_error_handler('gdpr_framework_error_handler', E_ERROR | E_WARNING | E_PARSE);

add_action('plugins_loaded', 'gdpr_framework_init');