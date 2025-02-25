<?php
namespace GDPRFramework\Core;

/**
 * Main GDPR Framework Class
 * 
 * Handles the initialization and coordination of all GDPR functionality
 */
class GDPRFramework {
    /** @var GDPRFramework|null */
    private static $instance = null;

    /** @var array */
    private $components = [];

    /** @var Database */
    private $database;

    /** @var Settings */
    private $settings;

    /** @var string */
    private $version;

    /**
     * Get singleton instance
     *
     * @return GDPRFramework
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to enforce singleton pattern
     */
    private function __construct() {
        $this->version = GDPR_FRAMEWORK_VERSION;
        try {
            $this->setupCore();
            $this->initializeComponents();
            $this->initializeHooks();
            $this->setupCleanupSchedule();
        } catch (\Exception $e) {
            error_log('GDPR Framework Error: ' . $e->getMessage());
            add_action('admin_notices', [$this, 'displayInitializationError']);
        }
    }

    /**
     * Setup core components
     */
    private function setupCore() {
        $this->database = new Database();
        $this->settings = new Settings();
    }

    /**
     * Initialize essential components
     */
    private function initializeComponents() {
        try {
            // Initialize essential components first
            $this->components['template'] = new \GDPRFramework\Components\TemplateRenderer(
                $this->settings
            );
    
            // Initialize LoggingAuditManager before other components
            $this->components['audit'] = new \GDPRFramework\Components\LoggingAuditManager(
                $this->database,
                $this->settings
            );
            
            // Add action to initialize other components later
            add_action('init', [$this, 'initLateComponents'], 10);
        } catch (\Exception $e) {
            error_log('GDPR Framework Component Init Error: ' . $e->getMessage());
        }
    }

    /**
     * Initialize remaining components
     */
    public function initLateComponents() {
        try {
            // Initialize other components only when needed
            if (!isset($this->components['encryption'])) {
                $this->components['encryption'] = new \GDPRFramework\Components\DataEncryptionManager(
                    $this->database,
                    $this->settings
                );
            }
    
            if (!isset($this->components['consent'])) {
                $this->components['consent'] = new \GDPRFramework\Components\UserConsentManager(
                    $this->database,
                    $this->settings
                );
            }
    
            if (!isset($this->components['access'])) {
                $this->components['access'] = new \GDPRFramework\Components\AccessControlManager(
                    $this->database,
                    $this->settings
                );
            }
    
            if (!isset($this->components['portability'])) {
                $this->components['portability'] = new \GDPRFramework\Components\DataPortabilityManager(
                    $this->database,
                    $this->settings
                );
            }
    
            // Register cleanup task
            add_action('gdpr_daily_cleanup', [$this->components['audit'], 'cleanupOldLogs']);
        } catch (\Exception $e) {
            error_log('GDPR Framework Late Component Init Error: ' . $e->getMessage());
        }
    }

    /**
     * Initialize WordPress hooks
     */
    private function initializeHooks() {
        // Admin
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_init', [$this, 'initializeAdmin']);
        add_filter('plugin_action_links_' . plugin_basename(GDPR_FRAMEWORK_PATH . 'wp-gdpr-framework.php'), 
            [$this, 'addPluginLinks']
        );

        // Assets
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueuePublicAssets']);

        // AJAX handlers
        $this->initializeAjaxHandlers();

        // Cron jobs
        add_action('init', [$this, 'setupCronJobs']);
    }

   /**
 * Initialize AJAX handlers
 */
private function initializeAjaxHandlers() {
    $ajax_actions = [
        'gdpr_update_consent',
        'gdpr_export_data',
        'gdpr_erase_data',
        'gdpr_get_audit_log',
        'gdpr_process_request'
    ];

    foreach ($ajax_actions as $action) {
        add_action('wp_ajax_' . $action, [$this, 'handleAjax']);
        add_action('wp_ajax_nopriv_' . $action, [$this, 'handleAjaxNoPriv']);
    }

    // Add specific handler for consent updates
    add_action('wp_ajax_update_user_consent', [$this, 'handleConsentUpdate']);
    add_action('wp_ajax_nopriv_update_user_consent', [$this, 'handleConsentUpdate']);

    // Add specific handler for process request
    add_action('wp_ajax_gdpr_process_request', function() {
        if (isset($this->components['portability'])) {
            $this->components['portability']->handleRequestProcessing();
        }
    });
}

    /**
     * Handle authenticated AJAX requests
     */
    public function handleAjax() {
        $action = $_REQUEST['action'] ?? '';
        
        switch ($action) {
            case 'gdpr_update_consent':
                if (isset($this->components['consent'])) {
                    $this->components['consent']->handleConsentUpdate();
                }
                break;
                
            case 'gdpr_export_data':
                if (isset($this->components['portability'])) {
                    $this->components['portability']->handleExportRequest();
                }
                break;
                
            case 'gdpr_erase_data':
                if (isset($this->components['portability'])) {
                    $this->components['portability']->handleErasureRequest();
                }
                break;
                
            case 'gdpr_get_audit_log':
                if (isset($this->components['audit'])) {
                    $this->components['audit']->handleLogRequest();
                }
                break;
        }
        
        wp_die();
    }

    public function handleConsentUpdate() {
        if (isset($this->components['consent'])) {
            $this->components['consent']->handleConsentUpdate();
        } else {
            wp_send_json_error([
                'message' => __('Consent management not initialized.', 'wp-gdpr-framework')
            ]);
        }
    }

    /**
     * Handle non-authenticated AJAX requests
     */
    public function handleAjaxNoPriv() {
        wp_send_json_error('Authentication required');
    }

    /**
     * Add admin menu items
     */
    public function addAdminMenu() {
        add_menu_page(
            __('GDPR Framework', 'wp-gdpr-framework'),
            __('GDPR Framework', 'wp-gdpr-framework'),
            'manage_options',
            'gdpr-framework',
            [$this, 'renderDashboard'],
            'dashicons-shield',
            80
        );

        add_submenu_page(
            'gdpr-framework',
            __('Dashboard', 'wp-gdpr-framework'),
            __('Dashboard', 'wp-gdpr-framework'),
            'manage_options',
            'gdpr-framework',
            [$this, 'renderDashboard']
        );

        add_submenu_page(
            'gdpr-framework',
            __('Settings', 'wp-gdpr-framework'),
            __('Settings', 'wp-gdpr-framework'),
            'manage_options',
            'gdpr-framework-settings',
            [$this, 'renderSettings']
        );

        add_submenu_page(
            'gdpr-framework',
            __('Audit Log', 'wp-gdpr-framework'),
            __('Audit Log', 'wp-gdpr-framework'),
            'manage_options',
            'gdpr-framework-audit',
            [$this, 'renderAuditLog']
        );
    }

    /**
     * Initialize admin settings
     */
    public function initializeAdmin() {
        register_setting('gdpr_framework_settings', 'gdpr_retention_days');
        register_setting('gdpr_framework_settings', 'gdpr_consent_types');
        
        $this->addCleanupSettings();
    }

    /**
     * Add plugin action links
     */
    public function addPluginLinks($links) {
        $plugin_links = [
            '<a href="' . admin_url('admin.php?page=gdpr-framework-settings') . '">' . 
                __('Settings', 'wp-gdpr-framework') . '</a>',
            '<a href="https://example.com/docs/gdpr-framework">' . 
                __('Documentation', 'wp-gdpr-framework') . '</a>'
        ];
        return array_merge($plugin_links, $links);
    }

    /**
     * Enqueue admin assets
     */
    public function enqueueAdminAssets($hook) {
        if (strpos($hook, 'gdpr-framework') === false) {
            return;
        }
        // Add jQuery UI
    wp_enqueue_script('jquery-ui-core');
    wp_enqueue_script('jquery-ui-tabs');

        wp_enqueue_style(
            'gdpr-framework-admin',
            GDPR_FRAMEWORK_URL . 'assets/css/admin.css',
            [],
            $this->version
        );

        wp_enqueue_script(
            'gdpr-framework-admin',
            GDPR_FRAMEWORK_URL . 'assets/js/admin.js',
            ['jquery'],
            $this->version,
            true
        );

        wp_localize_script('gdpr-framework-admin', 'gdprFrameworkAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gdpr_admin_nonce'),
            'i18n' => [
                'confirmDelete' => __('Are you sure you want to delete this item?', 'wp-gdpr-framework'),
                'confirmErase' => __('Are you sure you want to erase this data? This action cannot be undone.', 'wp-gdpr-framework'),
                'confirmExport' => __('Are you sure you want to process this export request?', 'wp-gdpr-framework'),
                'confirmRotation' => __('Are you sure you want to rotate the encryption key? This process cannot be interrupted.', 'wp-gdpr-framework'),
                'saved' => __('Settings saved successfully.', 'wp-gdpr-framework'),
                'error' => __('An error occurred. Please try again.', 'wp-gdpr-framework'),
                'processing' => __('Processing...', 'wp-gdpr-framework'),
                'processRequest' => __('Process Request', 'wp-gdpr-framework'),
                'rotating' => __('Rotating Key...', 'wp-gdpr-framework'),
                'rotateKey' => __('Rotate Encryption Key', 'wp-gdpr-framework'),
                'rotateSuccess' => __('Encryption key rotated successfully.', 'wp-gdpr-framework'),
                'cleaning' => __('Cleaning...', 'wp-gdpr-framework'),
                'cleanup' => __('Run Cleanup', 'wp-gdpr-framework')
            ]
        ]);
    }

    /**
     * Enqueue public assets
     */
    public function enqueuePublicAssets() {
        if (!$this->shouldLoadPublicAssets()) {
            return;
        }

        wp_enqueue_style(
            'gdpr-framework-public',
            GDPR_FRAMEWORK_URL . 'assets/css/public.css',
            [],
            $this->version
        );

        wp_enqueue_script(
            'gdpr-framework-public',
            GDPR_FRAMEWORK_URL . 'assets/js/public.js',
            ['jquery'],
            $this->version,
            true
        );

        wp_localize_script('gdpr-framework-public', 'gdprFramework', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gdpr_nonce')
        ]);
    }

    /**
     * Check if public assets should be loaded
     */
    private function shouldLoadPublicAssets() {
        return is_user_logged_in() || 
               has_shortcode(get_post()->post_content ?? '', 'gdpr_consent_form') ||
               has_shortcode(get_post()->post_content ?? '', 'gdpr_privacy_dashboard');
    }

    /**
     * Render dashboard page
     */
    public function renderDashboard() {
        try {
            if (!isset($this->components['template'])) {
                throw new \Exception(__('Template component not initialized.', 'wp-gdpr-framework'));
            }
    
            echo $this->components['template']->render('admin/dashboard', [
                'consent' => $this->components['consent'] ?? null,
                'portability' => $this->components['portability'] ?? null,
                'encryption' => $this->components['encryption'] ?? null,
                'audit' => $this->components['audit'] ?? null,
                'stats' => $this->getStats(),
                'database_ok' => $this->database->verifyTables(),
                'cleanup_status' => [
                    'next_run' => wp_next_scheduled('gdpr_daily_cleanup') 
                        ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), wp_next_scheduled('gdpr_daily_cleanup'))
                        : __('Not scheduled', 'wp-gdpr-framework')
                ]
            ]);
        } catch (\Exception $e) {
            echo '<div class="notice notice-error"><p>' . 
                 esc_html__('Error loading dashboard: ', 'wp-gdpr-framework') . 
                 esc_html($e->getMessage()) . '</p></div>';
        }
    }

    /**
     * Render settings page
     */
    public function renderSettings() {
        if (!isset($this->components['template'])) {
            echo '<div class="wrap"><h1>' . esc_html__('GDPR Framework Settings', 'wp-gdpr-framework') . '</h1>';
            echo '<p>' . esc_html__('Settings component not initialized.', 'wp-gdpr-framework') . '</p></div>';
            return;
        }

        echo $this->components['template']->render('admin/settings', [
            'settings' => $this->settings,
            'access_manager' => $this->components['access'] ?? null,
            'portability' => $this->components['portability'] ?? null,
            'encryption' => $this->components['encryption'] ?? null,
            'consent_types' => get_option('gdpr_consent_types', [])
        ]);
    }

    /**
     * Render audit log page
     */
    public function renderAuditLog() {
        if (!isset($this->components['template'])) {
            echo '<div class="wrap"><h1>' . esc_html__('GDPR Audit Log', 'wp-gdpr-framework') . '</h1>';
            echo '<p>' . esc_html__('Template component not initialized.', 'wp-gdpr-framework') . '</p></div>';
            return;
        }

        echo $this->components['template']->render('admin/audit-log', [
            'audit' => $this->components['audit'] ?? null,
            'stats' => $this->components['audit'] ? $this->components['audit']->getStats() : null
        ]);
    }

    /**
     * Get component statistics
     */
    private function getStats(): array 
    {
        $stats = [
            'total_consents' => 0,
            'active_consents' => 0,
            'pending_requests' => 0,
            'data_requests' => 0,
            'recent_exports' => 0
        ];
    
        try {
            if (isset($this->components['consent']) && 
                method_exists($this->components['consent'], 'getTotalConsents')) {
                $stats['total_consents'] = $this->components['consent']->getTotalConsents();
                $stats['active_consents'] = $this->components['consent']->getActiveConsents();
            }
    
            if (isset($this->components['portability']) && 
                method_exists($this->components['portability'], 'getPendingRequests')) {
                $requests = $this->components['portability']->getPendingRequests();
                $stats['pending_requests'] = is_array($requests) ? count($requests) : 0;
                $stats['data_requests'] = $stats['pending_requests'];
            }
        } catch (\Exception $e) {
            error_log('GDPR Framework Stats Error: ' . $e->getMessage());
        }
    
        return $stats;
    }

    public function getDatabase() {
        return $this->database;
    }

    public static function activate() {
        $instance = self::getInstance();
        if (!current_user_can('activate_plugins')) {
            return;
        }

        $instance->getDatabase()->createTables();
        $instance->settings->setDefaults();
        
        // Clear any cached data
        wp_cache_flush();
        
        // Schedule cron jobs
        $instance->setupCronJobs();
        
        flush_rewrite_rules();
    }


    /**
     * Setup cleanup schedule
     */
    private function setupCleanupSchedule() {
        if (!wp_next_scheduled('gdpr_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'gdpr_daily_cleanup');
        }

        add_action('gdpr_daily_cleanup', [$this, 'performCleanup']);
    }

    /**
     * Perform cleanup tasks
     */
    public function performCleanup() {
        try {
            // Clean up audit logs
            if (isset($this->components['audit'])) {
                $this->components['audit']->cleanupOldLogs();
            }

            // Clean up expired exports
            if (isset($this->components['portability'])) {
                $this->components['portability']->cleanupExpiredExports();
            }

            // Log cleanup activity
            if (isset($this->components['audit'])) {
                $this->components['audit']->log(
                    0,
                    'maintenance',
                    __('Automated cleanup performed', 'wp-gdpr-framework'),
                    'low'
                );
            }

            update_option('gdpr_last_cleanup', current_time('mysql'));
        } catch (\Exception $e) {
            error_log('GDPR Framework Cleanup Error: ' . $e->getMessage());
        }
    }

    /**
     * Add cleanup settings
     */
    private function addCleanupSettings() {
        add_settings_section(
            'gdpr_cleanup_section',
            __('Cleanup Settings', 'wp-gdpr-framework'),
            [$this, 'renderCleanupSection'],
            'gdpr_framework_settings'
        );

        register_setting('gdpr_framework_settings', 'gdpr_cleanup_time', [
            'type' => 'string',
            'default' => '00:00',
            'sanitize_callback' => 'sanitize_text_field'
        ]);
    }

    /**
     * Get cleanup status
     */
    public function getCleanupStatus() {
        $next_cleanup = wp_next_scheduled('gdpr_daily_cleanup');
        
        return [
            'next_run' => $next_cleanup ? date_i18n(
                get_option('date_format') . ' ' . get_option('time_format'),
                $next_cleanup
            ) : __('Not scheduled', 'wp-gdpr-framework'),
            'last_run' => get_option('gdpr_last_cleanup', __('Never', 'wp-gdpr-framework'))
        ];
    }

    /**
     * Manually trigger cleanup
     */
    public function manualCleanup() {
        if (!current_user_can('manage_options')) {
            return false;
        }

        $this->performCleanup();
        return true;
    }

    /**
     * Get specific component
     */
    public function getComponent($name) {
        return $this->components[$name] ?? null;
    }

    /**
     * Display initialization error
     */
    public function displayInitializationError() {
        echo '<div class="notice notice-error"><p>' . 
             esc_html__('GDPR Framework failed to initialize properly. Please check the error logs.', 'wp-gdpr-framework') . 
             '</p></div>';
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        wp_clear_scheduled_hook('gdpr_daily_cleanup');
        flush_rewrite_rules();
    }

    /**
     * Setup cron jobs for the plugin
     */
    public function setupCronJobs() {
        if (!wp_next_scheduled('gdpr_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'gdpr_daily_cleanup');
        }
    }

    /**
     * Verify database tables exist
     *
     * @return bool
     */
    public function verifyTables() {
        if (!$this->database) {
            return false;
        }
        return $this->database->verifyTables();
    }
     
}
    

    