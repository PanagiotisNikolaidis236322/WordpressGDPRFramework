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

 // Enable debug mode if WP_DEBUG is enabled
if (!defined('GDPR_FRAMEWORK_DEBUG')) {
    define('GDPR_FRAMEWORK_DEBUG', defined('WP_DEBUG') && WP_DEBUG);
}

// Add debug logging function
function gdpr_framework_debug_log($message) {
    if (GDPR_FRAMEWORK_DEBUG) {
        error_log('GDPR Framework - ' . $message);
    }
}

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
define('GDPR_FRAMEWORK_COOKIE_BANNER_TEMPLATE', GDPR_FRAMEWORK_TEMPLATE_PATH . 'public/cookie-banner.php');
define('GDPR_FRAMEWORK_CONSENT_OPTIONS', [
    'cookie_expiry' => 365,
    'require_explicit_consent' => true,
    'show_cookie_banner' => true,
    'cookie_banner_position' => 'bottom',
    'default_consent_types' => [
        'necessary' => true,
        'functional' => false,
        'analytics' => false,
        'marketing' => false
    ]
]);

// Define required files and their purposes
$GLOBALS['GDPR_REQUIRED_FILES'] = [
    // Core files
    'src/Core/GDPRFramework.php' => 'Main framework class',
    'src/Core/Database.php' => 'Database operations',
    'src/Core/Settings.php' => 'Settings management',
    
    // Components
    'src/Components/TemplateRenderer.php' => 'Template rendering',
    
    // User Consent Manager
    'src/Components/UserConsentManager/UserConsentManager.php' => 'Consent management',
    'src/Components/UserConsentManager/ConsentType.php' => 'Consent type handling',
    'src/Components/UserConsentManager/ConsentVersion.php' => 'Consent versioning',
    'src/Components/UserConsentManager/ConsentNotifications.php' => 'Consent notifications',
    
    // Data Encryption Manager
    'src/Components/DataEncryptionManager/DataEncryptionManager.php' => 'Data encryption',
    
    // Access Control Manager
    'src/Components/AccessControlManager/AccessControlManager.php' => 'Access control',
    
    // Data Portability Manager
    'src/Components/DataPortabilityManager/DataPortabilityManager.php' => 'Data portability',
    
    // Logging Audit Manager
    'src/Components/LoggingAuditManager/LoggingAuditManager.php' => 'Audit logging',
    
    // Templates
    'templates/admin/settings.php' => 'Admin settings template',
    'templates/admin/dashboard.php' => 'Admin dashboard template',
    'templates/admin/audit-log.php' => 'Admin audit log template',
    'templates/public/consent-form.php' => 'Public consent form',
    'templates/public/privacy-dashboard.php' => 'Public privacy dashboard',
    'templates/public/cookie-banner.php' => 'Cookie consent banner',
    'templates/public/consent-history.php' => 'Public Consent History',
    
    // Assets
    'assets/css/admin.css' => 'Admin styles',
    'assets/css/public.css' => 'Public styles',
    'assets/js/admin.js' => 'Admin scripts',
    'assets/js/public.js' => 'Public scripts'
];

/**
 * Verify required files exist
 */
function gdpr_framework_verify_files() {
    $missing_files = [];
    $plugin_dir = plugin_dir_path(__FILE__);
    
    error_log('GDPR Framework - Starting file verification');
    
    foreach ($GLOBALS['GDPR_REQUIRED_FILES'] as $file => $purpose) {
        $full_path = $plugin_dir . $file;
        if (!file_exists($full_path)) {
            $missing_files[$file] = $purpose;
            error_log("GDPR Framework - Missing required file: {$file} ({$purpose})");
        } else {
            error_log("GDPR Framework - Found required file: {$file}");
        }
    }

    if (empty($missing_files)) {
        error_log('GDPR Framework - All required files verified successfully');
    } else {
        error_log('GDPR Framework - Missing ' . count($missing_files) . ' required files');
    }

    return $missing_files;
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
    $base_dir = plugin_dir_path(__FILE__) . 'src/';

    // Check if the class uses the namespace prefix
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    // Get the relative class name
    $relative_class = substr($class, $len);

    // Replace namespace separator with directory separator
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // If the file exists, require it
    if (file_exists($file)) {
        require_once $file;
        error_log('GDPR Framework - Loaded class file: ' . $file);
    } else {
        error_log('GDPR Framework - Could not find class file: ' . $file);
    }
}

spl_autoload_register('gdpr_framework_autoloader');

/**
 * Verify directory structure
 */
function gdpr_framework_verify_structure() {
    $base_dir = plugin_dir_path(__FILE__);
    $required_dirs = [
        'src/Core',
        'src/Components',
        'src/Components/LoggingAuditManager',
        'src/Components/UserConsentManager',
        'src/Components/DataEncryptionManager',
        'src/Components/AccessControlManager',
        'src/Components/DataPortabilityManager',
        'templates/admin',
        'templates/public',
        'assets/css',
        'assets/js'
    ];

    error_log('GDPR Framework - Starting directory structure verification');
    
    $missing_dirs = [];
    foreach ($required_dirs as $dir) {
        $full_path = $base_dir . $dir;
        if (!is_dir($full_path)) {
            $missing_dirs[] = $dir;
            error_log('GDPR Framework - Missing directory: ' . $full_path);
        } else {
            error_log('GDPR Framework - Found directory: ' . $full_path);
        }
    }

    if (!empty($missing_dirs)) {
        error_log('GDPR Framework - Missing directories: ' . implode(', ', $missing_dirs));
        throw new \Exception('Missing required directories: ' . implode(', ', $missing_dirs));
    }

    error_log('GDPR Framework - Directory structure verified successfully');
    return true;
}

register_activation_hook(__FILE__, function() {
    try {
        // Initialize framework
        $framework = \GDPRFramework\Core\GDPRFramework::getInstance();
        
        // Initialize database
        $db = $framework->getDatabase();
        if (!$db->createTables()) {
            throw new \Exception('Failed to create database tables');
        }

        // Initialize consent types
        $consent = $framework->getComponent('consent');
        if ($consent) {
            $consent_type = $consent->getConsentType();
            $consent_type->initializeDefaultTypes();
        }

        // Verify tables were created
        if (!$db->verifyTables()) {
            throw new \Exception('Failed to verify database tables');
        }

        error_log('GDPR Framework - Plugin activated successfully');
        flush_rewrite_rules();
        
    } catch (\Exception $e) {
        error_log('GDPR Framework Activation Error: ' . $e->getMessage());
        wp_die('GDPR Framework activation failed: ' . esc_html($e->getMessage()));
    }
});

register_deactivation_hook(__FILE__, function() {
    // Optionally clean up database tables
    // Comment out if you want to preserve data
    /*
    global $wpdb;
    $tables = [
        'user_consents',
        'consent_types',
        'consent_versions'
    ];
    
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}gdpr_{$table}");
    }
    */
    
    // Clear any plugin options
    delete_option('gdpr_consent_types');
    delete_option('gdpr_settings');
    
    // Clear any transients
    delete_transient('gdpr_framework_activated');
    
    // Flush rewrite rules
    flush_rewrite_rules();
});


// Add admin notice for missing files
add_action('admin_notices', function() {
    $missing_files = gdpr_framework_verify_files();
    
    if (!empty($missing_files)) {
        echo '<div class="notice notice-error">';
        echo '<p><strong>' . esc_html__('GDPR Framework: Required files are missing:', 'wp-gdpr-framework') . '</strong></p>';
        echo '<ul style="list-style-type: disc; margin-left: 20px;">';
        foreach ($missing_files as $file => $purpose) {
            echo '<li>' . esc_html(sprintf(
                '%s (%s)',
                $file,
                $purpose
            )) . '</li>';
        }
        echo '</ul>';
        echo '<p>' . esc_html__('Please reinstall the plugin or contact support.', 'wp-gdpr-framework') . '</p>';
        echo '</div>';
    }
});



function gdpr_framework_init() {
    try {
        // Verify files exist
        $missing_files = gdpr_framework_verify_files();
        if (!empty($missing_files)) {
            error_log('GDPR Framework - Missing required files: ' . print_r($missing_files, true));
            return;
        }

        // Initialize framework
        $framework = \GDPRFramework\Core\GDPRFramework::getInstance();
        
        // Verify components are properly initialized
        $consent = $framework->getComponent('consent');
        if (!$consent) {
            error_log('GDPR Framework - Consent component not initialized properly');
            return;
        }

        // Add consent management hooks
        add_action('wp_enqueue_scripts', function() {
            gdpr_framework_enqueue_consent_assets();
        });
        
        if (GDPR_FRAMEWORK_CONSENT_OPTIONS['show_cookie_banner']) {
            add_action('wp_footer', 'gdpr_framework_render_cookie_banner');
        }
        
        // Add body class filter
        add_filter('body_class', [$framework, 'addConsentBodyClasses']);
        
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

function gdpr_framework_cookie_banner_shortcode($atts = []) {
    // Don't render if we shouldn't show the banner
    if (!gdpr_framework_should_show_cookie_banner()) {
        return '';
    }

    // Parse attributes
    $atts = shortcode_atts([
        'position' => GDPR_FRAMEWORK_CONSENT_OPTIONS['cookie_banner_position'],
        'theme' => 'light'
    ], $atts);

    // Add debugging if enabled
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('GDPR Cookie Banner Shortcode - Rendering banner with attributes: ' . print_r($atts, true));
    }

    // Enqueue required assets
    gdpr_framework_enqueue_consent_assets();

    // Start output buffering
    ob_start();

    // Include the template
    if (file_exists(GDPR_FRAMEWORK_COOKIE_BANNER_TEMPLATE)) {
        include GDPR_FRAMEWORK_COOKIE_BANNER_TEMPLATE;
    } else {
        error_log('GDPR Cookie Banner Error - Template file not found: ' . GDPR_FRAMEWORK_COOKIE_BANNER_TEMPLATE);
    }

    // Return the buffered content
    return ob_get_clean();
}

// Register the shortcode
add_shortcode('gdpr_cookie_banner', 'gdpr_framework_cookie_banner_shortcode');

function gdpr_framework_enqueue_consent_assets() {
    global $post;
    
    // Check if we need to load assets
    if (!is_user_logged_in() && 
        !is_singular() && // Check if we're on a singular post/page
        !gdpr_framework_should_show_cookie_banner()) {
        return;
    }

    // Check for shortcode if we have post content
    $has_shortcode = $post && (
        has_shortcode($post->post_content, 'gdpr_consent_form') || 
        has_shortcode($post->post_content, 'gdpr_cookie_banner')
    );

    // Always load if shortcode present or cookie banner should show
    if (!$has_shortcode && !gdpr_framework_should_show_cookie_banner()) {
        return;
    }

    wp_enqueue_style(
        'gdpr-framework-consent',
        GDPR_FRAMEWORK_URL . 'assets/css/public.css',
        [],
        GDPR_FRAMEWORK_VERSION
    );

    wp_enqueue_script(
        'gdpr-framework-consent',
        GDPR_FRAMEWORK_URL . 'assets/js/public.js',
        ['jquery', 'jquery-ui-tooltip'],
        GDPR_FRAMEWORK_VERSION,
        true
    );

    wp_localize_script('gdpr-framework-consent', 'gdprConsentForm', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('gdpr_nonce'),
        'i18n' => [
            'success' => __('Your privacy preferences have been updated successfully.', 'wp-gdpr-framework'),
            'error' => __('Failed to update privacy preferences.', 'wp-gdpr-framework'),
            'updating' => __('Updating...', 'wp-gdpr-framework'),
            'update' => __('Update Privacy Settings', 'wp-gdpr-framework'),
            'confirmReset' => __('Are you sure you want to reset your privacy preferences?', 'wp-gdpr-framework'),
            'requiredConsent' => __('This consent is required and cannot be disabled.', 'wp-gdpr-framework')
        ]
    ]);
}

if (!function_exists('gdpr_framework_should_show_cookie_banner')) {
    function gdpr_framework_should_show_cookie_banner() {
        // Don't show if consent already set
        if (isset($_COOKIE['gdpr_consent_set'])) {
            return false;
        }

        // Don't show in admin
        if (is_admin()) {
            return false;
        }

        // Check if banner is enabled in settings
        if (!GDPR_FRAMEWORK_CONSENT_OPTIONS['show_cookie_banner']) {
            return false;
        }

        return true;
    }
}  
function gdpr_framework_enqueue_cookie_banner_assets() {
    // Only enqueue if cookie consent not already set
    if (isset($_COOKIE['gdpr_consent_set'])) {
        return;
    }

    // Enqueue React and dependencies
    wp_enqueue_script('wp-element');
    wp_enqueue_style(
        'gdpr-cookie-banner',
        GDPR_FRAMEWORK_URL . 'assets/css/cookie-banner.css',
        [],
        GDPR_FRAMEWORK_VERSION
    );
    
    wp_enqueue_script(
        'gdpr-cookie-banner',
        GDPR_FRAMEWORK_URL . 'assets/js/cookie-banner.js',
        ['wp-element', 'wp-components'],
        GDPR_FRAMEWORK_VERSION,
        true
    );

    // Pass settings to JavaScript
    wp_localize_script('gdpr-cookie-banner', 'gdprCookieBannerSettings', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('gdpr_cookie_consent'),
        'privacyPolicyUrl' => get_privacy_policy_url(),
        'consentTypes' => gdpr_get_consent_types(),
        'position' => GDPR_FRAMEWORK_CONSENT_OPTIONS['cookie_banner_position'],
        'defaultConsents' => GDPR_FRAMEWORK_CONSENT_OPTIONS['default_consent_types'],
        'i18n' => [
            'title' => __('Cookie Consent', 'wp-gdpr-framework'),
            'description' => __('We use cookies to enhance your experience. By continuing to visit this site you agree to our use of cookies.', 'wp-gdpr-framework'),
            'acceptAll' => __('Accept All', 'wp-gdpr-framework'),
            'reject' => __('Reject Non-Essential', 'wp-gdpr-framework'),
            'settings' => __('Cookie Settings', 'wp-gdpr-framework'),
            'save' => __('Save Preferences', 'wp-gdpr-framework'),
            'close' => __('Close', 'wp-gdpr-framework')
        ]
    ]);
}

if (!function_exists('gdpr_has_cookie_consent')) {
    function gdpr_has_cookie_consent($type = null) {
        if ($type === null) {
            return isset($_COOKIE['gdpr_consent_set']);
        }
        
        return isset($_COOKIE["gdpr_consent_{$type}"]) && 
               $_COOKIE["gdpr_consent_{$type}"] === 'true';
    }
}

// Update the existing render function
function gdpr_framework_render_cookie_banner() {
    if (isset($_COOKIE['gdpr_consent_set'])) {
        return;
    }

    gdpr_framework_enqueue_cookie_banner_assets();
    echo '<div id="gdpr-cookie-banner-root"></div>';
}

// Add the AJAX handler for cookie consent
add_action('wp_ajax_gdpr_update_cookie_consent', 'gdpr_handle_cookie_consent_update');
add_action('wp_ajax_nopriv_gdpr_update_cookie_consent', 'gdpr_handle_cookie_consent_update');

add_action('init', function() {
    add_action('wp_ajax_gdpr_reset_preferences', function() {
        $framework = \GDPRFramework\Core\GDPRFramework::getInstance();
        $consent = $framework->getComponent('consent');
        if (!$consent) {
            wp_send_json_error(['message' => 'Consent component not initialized']);
            return;
        }
        $consent->handleResetPreferences();
    });

    add_action('wp_ajax_gdpr_update_privacy_settings', function() {
        $framework = \GDPRFramework\Core\GDPRFramework::getInstance();
        $consent = $framework->getComponent('consent');
        if (!$consent) {
            wp_send_json_error(['message' => 'Consent component not initialized']);
            return;
        }
        $consent->handlePrivacySettingsUpdate();
    });
});

function gdpr_handle_cookie_consent_update() {
    check_ajax_referer('gdpr_cookie_consent', 'nonce');

    $consents = isset($_POST['consents']) ? json_decode(stripslashes($_POST['consents']), true) : [];
    $user_id = get_current_user_id();
    
    try {
        $framework = GDPRFramework\Core\GDPRFramework::getInstance();
        $consent_manager = $framework->getComponent('consent');

        if (!$consent_manager) {
            throw new Exception('Consent manager not initialized');
        }

        // Update consents for logged-in users
        if ($user_id) {
            $consent_manager->updateUserConsents($user_id, $consents);
        }

        // Set consent cookies
        $expiry = time() + (DAY_IN_SECONDS * GDPR_FRAMEWORK_CONSENT_OPTIONS['cookie_expiry']);
        setcookie(
            'gdpr_consent_set',
            'true',
            $expiry,
            COOKIEPATH,
            COOKIE_DOMAIN,
            is_ssl(),
            true
        );

        foreach ($consents as $type => $status) {
            setcookie(
                "gdpr_consent_{$type}",
                $status ? 'true' : 'false',
                $expiry,
                COOKIEPATH,
                COOKIE_DOMAIN,
                is_ssl(),
                true
            );
        }

        wp_send_json_success([
            'message' => __('Cookie preferences updated successfully.', 'wp-gdpr-framework'),
            'consents' => $consents
        ]);

    } catch (Exception $e) {
        wp_send_json_error([
            'message' => $e->getMessage()
        ]);
    }
}

function gdpr_get_consent_types() {
    $framework = \GDPRFramework\Core\GDPRFramework::getInstance();
    $consent = $framework->getComponent('consent');
    return $consent ? $consent->getConsentType()->getAll() : [];
}


function gdpr_framework_enqueue_admin_consent_assets($hook) {
    if (strpos($hook, 'gdpr-framework') === false) {
        return;
    }

    wp_enqueue_style(
        'gdpr-framework-admin-consent',
        GDPR_FRAMEWORK_URL . 'assets/css/admin-consent.css',
        [],
        GDPR_FRAMEWORK_VERSION
    );

    wp_enqueue_script(
        'gdpr-framework-admin-consent',
        GDPR_FRAMEWORK_URL . 'assets/js/admin-consent.js',
        ['jquery', 'jquery-ui-sortable'],
        GDPR_FRAMEWORK_VERSION,
        true
    );

    wp_localize_script('gdpr-framework-admin-consent', 'gdprAdminConsent', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('gdpr_admin_nonce'),
        'i18n' => [
            'confirmDelete' => __('Are you sure you want to delete this consent type?', 'wp-gdpr-framework'),
            'confirmReset' => __('Are you sure you want to reset to default consent types?', 'wp-gdpr-framework'),
            'saved' => __('Consent types saved successfully.', 'wp-gdpr-framework'),
            'error' => __('Failed to save consent types.', 'wp-gdpr-framework')
        ]
    ]);
}

if (defined('WP_DEBUG') && WP_DEBUG) {
    add_action('wp_footer', function() {
        echo '<!-- GDPR Cookie Banner Debug:
        Cookie Set: ' . (isset($_COOKIE['gdpr_consent_set']) ? 'Yes' : 'No') . '
        Show Banner: ' . (gdpr_framework_should_show_cookie_banner() ? 'Yes' : 'No') . '
        Position: ' . GDPR_FRAMEWORK_CONSENT_OPTIONS['cookie_banner_position'] . '
        Template Exists: ' . (file_exists(GDPR_FRAMEWORK_COOKIE_BANNER_TEMPLATE) ? 'Yes' : 'No') . '
        -->';
    }, 999);
}

// Add error handler
function gdpr_framework_error_handler($errno, $errstr, $errfile, $errline) {
    error_log("GDPR Framework Error: [$errno] $errstr in $errfile on line $errline");
    return false;
}
set_error_handler('gdpr_framework_error_handler', E_ERROR | E_WARNING | E_PARSE);

add_action('plugins_loaded', 'gdpr_framework_init');