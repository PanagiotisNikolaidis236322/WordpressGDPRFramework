<?php
namespace GDPRFramework\Core;

/**
 * Main GDPR Framework Class
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

    /** @var bool */
    private $is_initialized = false;

    /** @var array Component class mapping with dependencies */
    private const COMPONENT_CLASS_MAP = [
        'template' => [
            'class' => '\GDPRFramework\Components\TemplateRenderer',
            'requires' => ['settings']
        ],
        'audit' => [
            'class' => '\GDPRFramework\Components\LoggingAuditManager\LoggingAuditManager',
            'requires' => ['database', 'settings']
        ],
        'consent' => [
            'class' => '\GDPRFramework\Components\UserConsentManager\UserConsentManager',
            'requires' => ['database', 'settings', 'template']
        ],
        'encryption' => [
            'class' => '\GDPRFramework\Components\DataEncryptionManager\DataEncryptionManager',
            'requires' => ['database', 'settings']
        ],
        'access' => [
            'class' => '\GDPRFramework\Components\AccessControlManager\AccessControlManager',
            'requires' => ['database', 'settings']
        ],
        'portability' => [
            'class' => '\GDPRFramework\Components\DataPortabilityManager\DataPortabilityManager',
            'requires' => ['database', 'settings', 'encryption']
        ]
    ];

    /** @var array Initialization state tracking */
    private $initialization_state = [
        'core_loaded' => false,
        'components_loaded' => false,
        'hooks_registered' => false
    ];

    /** @var array Plugin-specific options */
    private const PLUGIN_OPTIONS = [
        'gdpr_last_cleanup',
        'gdpr_cleanup_time',
        'gdpr_retention_days',
        'gdpr_consent_types',
        'gdpr_export_formats',
        'gdpr_export_expiry'
    ];

    /**
     * Get singleton instance
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct() {
        try {
            $this->version = GDPR_FRAMEWORK_VERSION;
            $this->validateEnvironment();
            $this->loadDependencies();
            $this->setupCore();
            $this->initializeComponents();
            
            // Initialize all hooks in the correct order
            $this->registerConsentHooks();     // Register consent-related hooks first
            $this->initializeConsentHooks();   // Initialize consent-specific hooks
            $this->initializeHooks();          // Initialize general hooks
            
            $this->setupCleanupSchedule();
            $this->is_initialized = true;
    
            // Add debug notice for component status
            if (defined('GDPR_FRAMEWORK_DEBUG') && GDPR_FRAMEWORK_DEBUG) {
                add_action('admin_notices', [$this, 'displayComponentStatus']);
            }
        } catch (\Exception $e) {
            $this->logError('Initialization failed: ' . $e->getMessage());
            add_action('admin_notices', [$this, 'displayInitializationError']);
        }
    }

    // Add a method for the cleanup section rendering that was missing:
    public function renderCleanupSection() {
        echo '<p>' . esc_html__('Configure automatic cleanup settings for GDPR data.', 'wp-gdpr-framework') . '</p>';
    }

    /**
     * Display component status in admin
     */
    public function displayComponentStatus() {
        $components = [
            'consent' => $this->getComponent('consent'),
            'template' => $this->getComponent('template'),
            'audit' => $this->getComponent('audit')
        ];
        
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p><strong>GDPR Framework Component Status:</strong></p>';
        echo '<ul>';
        foreach ($components as $name => $component) {
            echo '<li>' . esc_html($name) . ': ' . 
                 ($component ? '✓ Initialized' : '✗ Not Initialized') . '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }


/**
 * Check component dependencies
 */
private function checkDependencies(string $component): bool {
    if (!isset(self::COMPONENT_CLASS_MAP[$component])) {
        $this->logError("Unknown component: {$component}");
        return false;
    }

    foreach (self::COMPONENT_CLASS_MAP[$component]['requires'] as $dependency) {
        if ($dependency === 'database' || $dependency === 'settings') {
            continue;
        }
        if (!isset($this->components[$dependency])) {
            $this->logError("Missing dependency {$dependency} for {$component}");
            return false;
        }
    }
    return true;
}

/**
 * Update initialization state
 */
private function updateState(string $key): void {
    if (isset($this->initialization_state[$key])) {
        $this->initialization_state[$key] = true;
    }
}

/**
 * Handle initialization error with recovery
 */
private function handleInitializationError(\Exception $e): void {
    $this->logError('Initialization error', $e);
    
    if (isset($this->components['audit'])) {
        $this->components['audit']->log(
            0,
            'framework_error',
            'Initialization failed: ' . $e->getMessage(),
            'high'
        );
    }
    
    $this->recoverEssentialComponents();
}

private function initializeConsentForm(): void {
    // Register scripts and styles
    add_action('wp_enqueue_scripts', function() {
        // Only load if user is logged in and consent form is needed
        if (!is_user_logged_in() || !$this->shouldLoadConsentAssets()) {
            return;
        }

        wp_enqueue_script('wp-element');
        wp_enqueue_style(
            'gdpr-framework-public',
            GDPR_FRAMEWORK_URL . 'assets/css/public.css',
            [],
            GDPR_FRAMEWORK_VERSION
        );
        wp_enqueue_script(
            'gdpr-framework-public',
            GDPR_FRAMEWORK_URL . 'assets/js/public.js',
            ['jquery', 'wp-element'],
            GDPR_FRAMEWORK_VERSION,
            true
        );
    });

    // Register shortcode
    add_shortcode('gdpr_consent_form', function($atts) {
        if (!isset($this->components['consent'])) {
            return '';
        }
        return $this->components['consent']->renderConsentForm($atts);
    });
}

private function shouldLoadConsentAssets(): bool {
    global $post;

    // Check if we're on a page/post with the consent form shortcode
    if (is_singular() && is_a($post, 'WP_Post')) {
        if (has_shortcode($post->post_content, 'gdpr_consent_form')) {
            return true;
        }
    }

    // Check if we're on the privacy dashboard page
    $privacy_page_id = get_option('gdpr_privacy_page');
    if ($privacy_page_id && is_page($privacy_page_id)) {
        return true;
    }

    return false;
}

public function addConsentFormData(): void {
    if (!$this->shouldLoadConsentAssets()) {
        return;
    }

    $consent_manager = $this->getComponent('consent');
    if (!$consent_manager) {
        return;
    }

    $user_id = get_current_user_id();
    
    wp_localize_script('gdpr-framework-public', 'gdprConsentForm', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('gdpr_nonce'),
        'consentTypes' => $consent_manager->getConsentType()->getAll(),
        'currentConsents' => $consent_manager->getCurrentUserConsents($user_id),
        'outdatedConsents' => $consent_manager->getOutdatedConsents($user_id),
        'i18n' => $this->getConsentFormTranslations()
    ]);
}

private function getConsentFormTranslations(): array {
    return [
        'success' => __('Your privacy preferences have been updated successfully.', 'wp-gdpr-framework'),
        'error' => __('Failed to update privacy preferences.', 'wp-gdpr-framework'),
        'updating' => __('Updating...', 'wp-gdpr-framework'),
        'resetSuccess' => __('Privacy preferences have been reset to defaults.', 'wp-gdpr-framework'),
        'resetError' => __('Failed to reset privacy preferences.', 'wp-gdpr-framework'),
        'confirmReset' => __('Are you sure you want to reset your privacy preferences?', 'wp-gdpr-framework'),
        'requiredConsent' => __('This consent is required and cannot be disabled.', 'wp-gdpr-framework'),
        'loadingHistory' => __('Loading consent history...', 'wp-gdpr-framework'),
        'noHistory' => __('No consent history available.', 'wp-gdpr-framework'),
        'viewHistory' => __('View History', 'wp-gdpr-framework'),
        'hideHistory' => __('Hide History', 'wp-gdpr-framework'),
        'resetPreferences' => __('Reset Preferences', 'wp-gdpr-framework'),
        'savePreferences' => __('Save Preferences', 'wp-gdpr-framework')
    ];
}

/**
 * Try to recover essential components
 */
private function recoverEssentialComponents(): void {
    try {
        // Ensure template component is available
        if (!isset($this->components['template'])) {
            $this->components['template'] = new \GDPRFramework\Components\TemplateRenderer(
                $this->settings
            );
        }

        // Ensure audit component is available for logging
        if (!isset($this->components['audit']) && $this->database && $this->settings) {
            $this->components['audit'] = new \GDPRFramework\Components\LoggingAuditManager\LoggingAuditManager(
                $this->database,
                $this->settings
            );
        }
    } catch (\Exception $e) {
        $this->logError('Component recovery failed', $e);
    }
}


    /**
     * Validate environment and requirements
     */
    private function validateEnvironment(): void {
        // Check WordPress environment
        if (!function_exists('add_action')) {
            throw new \Exception('WordPress environment not detected');
        }
    
        // Verify required paths
        if (!defined('GDPR_FRAMEWORK_PATH')) {
            throw new \Exception('GDPR_FRAMEWORK_PATH not defined');
        }
    
        // Check required files for each component
        foreach (self::COMPONENT_CLASS_MAP as $component => $config) {
            // Get component file path from class name
            $class_path = str_replace(
                ['\\', 'GDPRFramework'],
                ['/', ''],
                $config['class']
            );
            
            $full_path = GDPR_FRAMEWORK_PATH . 'src' . $class_path . '.php';
            
            if (!file_exists($full_path)) {
                throw new \Exception("Required component file missing: {$class_path}.php");
            }
        }
    }
    

    /**
     * Load required dependencies
     */
    private function loadDependencies(): void {
        $base_path = GDPR_FRAMEWORK_PATH . 'src';
        
        // Core dependencies
        require_once($base_path . '/Core/Database.php');
        require_once($base_path . '/Core/Settings.php');
    
        // Component dependencies
        $required_files = [
            '/Components/TemplateRenderer.php',
            '/Components/LoggingAuditManager/LoggingAuditManager.php',
            '/Components/UserConsentManager/UserConsentManager.php',
            '/Components/UserConsentManager/ConsentType.php',
            '/Components/UserConsentManager/ConsentVersion.php',
            '/Components/UserConsentManager/ConsentNotifications.php',
            '/Components/DataEncryptionManager/DataEncryptionManager.php',
            '/Components/AccessControlManager/AccessControlManager.php',
            '/Components/DataPortabilityManager/DataPortabilityManager.php'
        ];
    
        foreach ($required_files as $file) {
            $file_path = $base_path . $file;
            if (!file_exists($file_path)) {
                error_log('GDPR Framework - Missing required file: ' . $file_path);
                throw new \Exception('Required file not found: ' . $file);
            }
            require_once($file_path);
        }
    }

    private function registerConsentHooks(): void {
        // Register hooks for cookie consent
        add_filter('gdpr_consent_types', [$this, 'filterConsentTypes']);
        add_action('gdpr_consent_updated', [$this, 'handleConsentUpdate'], 10, 3);
        add_action('gdpr_cookie_consent_updated', [$this, 'handleCookieConsentUpdate'], 10, 2);
    }

    public function handleCookieConsentUpdate($user_id, $consents): void {
        try {
            foreach ($consents as $type => $status) {
                if (strpos($type, 'cookie_') === 0) {
                    $this->handleConsentUpdate($user_id, $type, $status);
                }
            }
        } catch (\Exception $e) {
            error_log('GDPR Framework - Cookie Consent Update Error: ' . $e->getMessage());
        }
    }
    
    public function filterConsentTypes($types) {
        $default_types = GDPR_FRAMEWORK_CONSENT_OPTIONS['default_consent_types'] ?? [];
        return array_merge($types, array_map(function($type) {
            return [
                'label' => ucfirst(str_replace('_', ' ', $type)),
                'description' => sprintf(
                    __('Allow %s related functionality', 'wp-gdpr-framework'),
                    str_replace('_', ' ', $type)
                ),
                'required' => $type === 'necessary'
            ];
        }, $default_types));
    }
    

     /**
     * Setup core components
     */
    private function setupCore(): void {
        try {
            // Initialize Database
            $this->database = new Database();
            if (!$this->database->verifyTables()) {
                throw new \Exception('Database tables verification failed');
            }

            // Initialize Settings
            $this->settings = new Settings();
            
        } catch (\Exception $e) {
            throw new \Exception('Core setup failed: ' . $e->getMessage());
        }
    }

   /**
 * Initialize components with proper error handling
 */
private function initializeComponents(): void {
    try {
        error_log('GDPR Framework - Starting component initialization');

        if (!$this->database || !$this->settings) {
            throw new \Exception('Core components not initialized');
        }
        
        // Initialize Template Renderer first
        if (!isset($this->components['template'])) {
            $this->components['template'] = new \GDPRFramework\Components\TemplateRenderer($this->settings);
            error_log('GDPR Framework - Template renderer initialized');
        }

        // Initialize Consent Manager and its dependencies
        if (!isset($this->components['consent'])) {
            error_log('GDPR Framework - Initializing consent manager');
            
            // Create ConsentType instance
            $consentType = new \GDPRFramework\Components\UserConsentManager\ConsentType(
                $this->database,
                $this->settings
            );

            // Create ConsentVersion instance
            $consentVersion = new \GDPRFramework\Components\UserConsentManager\ConsentVersion(
                $this->database
            );

            // Create ConsentNotifications instance
            $notifications = new \GDPRFramework\Components\UserConsentManager\ConsentNotifications(
                $this->settings,
                $this->database
            );

            // Initialize UserConsentManager with all dependencies
            $this->components['consent'] = new \GDPRFramework\Components\UserConsentManager\UserConsentManager(
                $this->database,
                $this->settings,
                $this->components['template'],
                $consentType,
                $consentVersion,
                $notifications
            );
            
            error_log('GDPR Framework - Consent manager initialized');
        }

        // Initialize other components
        $component_classes = [
            'audit' => '\GDPRFramework\Components\LoggingAuditManager\LoggingAuditManager',
            'encryption' => '\GDPRFramework\Components\DataEncryptionManager\DataEncryptionManager',
            'access' => '\GDPRFramework\Components\AccessControlManager\AccessControlManager',
            'portability' => '\GDPRFramework\Components\DataPortabilityManager\DataPortabilityManager'
        ];

        foreach ($component_classes as $key => $class) {
            if (!isset($this->components[$key])) {
                $this->components[$key] = new $class($this->database, $this->settings);
                error_log("GDPR Framework - {$key} component initialized");
            }
        }

        if (!$this->verifyComponentInitialization()) {
            throw new \Exception('Failed to initialize all required components');
        }

        $this->updateState('components_loaded');
        error_log('GDPR Framework - All components initialized successfully');

    } catch (\Exception $e) {
        error_log('GDPR Framework - Component initialization failed: ' . $e->getMessage());
        throw $e;
    }
}

private function verifyDependencies(): void {
    foreach (self::COMPONENT_CLASS_MAP as $component => $config) {
        foreach ($config['requires'] as $dependency) {
            if ($dependency === 'database' && !$this->database) {
                throw new \Exception("Missing database dependency for {$component}");
            }
            if ($dependency === 'settings' && !$this->settings) {
                throw new \Exception("Missing settings dependency for {$component}");
            }
            if (!isset($this->components[$dependency])) {
                throw new \Exception("Missing {$dependency} dependency for {$component}");
            }
        }
    }
}
    /**
     * Initialize security components
     */
    private function initializeSecurityComponents(): void {
        try {
            if (!isset($this->components['encryption'])) {
                $this->components['encryption'] = new \GDPRFramework\Components\DataEncryptionManager\DataEncryptionManager(
                    $this->database,
                    $this->settings
                );
            }

            if (!isset($this->components['access'])) {
                $this->components['access'] = new \GDPRFramework\Components\AccessControlManager\AccessControlManager(
                    $this->database,
                    $this->settings
                );
            }
        } catch (\Exception $e) {
            $this->logError('Security component initialization failed: ' . $e->getMessage());
        }
    }

    /**
     * Initialize data handling components
     */
    private function initializeDataComponents(): void {
        try {
            if (!isset($this->components['portability'])) {
                $this->components['portability'] = new \GDPRFramework\Components\DataPortabilityManager\DataPortabilityManager(
                    $this->database,
                    $this->settings
                );
            }
        } catch (\Exception $e) {
            $this->logError('Data component initialization failed: ' . $e->getMessage());
        }
    }

    /**
     * Log error with context
     */
    private function logError(string $message, ?\Exception $e = null): void {
        $error_message = '[GDPR Framework] ' . $message;
        if ($e) {
            $error_message .= ' - ' . $e->getMessage();
            $error_message .= ' in ' . $e->getFile() . ':' . $e->getLine();
        }

        error_log($error_message);

        if (isset($this->components['audit'])) {
            $this->components['audit']->log(
                get_current_user_id(),
                'framework_error',
                $message,
                'high'
            );
        }
    }

    /**
 * Initialize consent-specific hooks
 */
private function initializeConsentHooks(): void {
    if (!isset($this->components['consent'])) {
        return;
    }

    // Add cookie consent initialization
    add_action('init', function() {
        if (!is_admin() && !isset($_COOKIE['gdpr_consent_set'])) {
            add_action('wp_footer', 'gdpr_framework_render_cookie_banner', 100);
        }
    });

    // Add consent form shortcode
    add_shortcode('gdpr_consent_form', [$this->components['consent'], 'renderConsentForm']);
    add_shortcode('gdpr_consent_status', [$this->components['consent'], 'renderConsentStatus']);

    // Add consent status to body classes
    add_filter('body_class', [$this, 'addConsentBodyClasses']);

    // Add consent management to user profile
    add_action('show_user_profile', [$this->components['consent'], 'addConsentFields']);
    add_action('edit_user_profile', [$this->components['consent'], 'addConsentFields']);

    // Add consent status widget to admin dashboard
    if (is_admin()) {
        add_action('wp_dashboard_setup', [$this, 'addConsentDashboardWidget']);
    }
}

/**
 * Add consent dashboard widget
 */
public function addConsentDashboardWidget(): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    wp_add_dashboard_widget(
        'gdpr_consent_overview',
        __('GDPR Consent Overview', 'wp-gdpr-framework'),
        [$this, 'renderConsentDashboardWidget']
    );
}

/**
 * Render consent dashboard widget
 */
public function renderConsentDashboardWidget(): void {
    if (!isset($this->components['consent'])) {
        return;
    }

    $stats = $this->components['consent']->getConsentStats();
    include GDPR_FRAMEWORK_TEMPLATE_PATH . 'admin/dashboard-widgets/consent-overview.php';
}

/**
 * Add consent-based body classes
 */
public function addConsentBodyClasses(array $classes): array {
    if (!isset($this->components['consent']) || !is_user_logged_in()) {
        return $classes;
    }

    try {
        $user_id = get_current_user_id();
        $consent_manager = $this->components['consent'];
        
        // Get consent types directly from settings
        $consent_types = get_option('gdpr_consent_types', []);

        foreach ($consent_types as $type => $data) {
            if ($consent_manager->hasConsent($type, $user_id)) {
                $classes[] = 'gdpr-consent-' . sanitize_html_class($type);
            }
        }
    } catch (\Exception $e) {
        error_log('GDPR Framework - Body Class Error: ' . $e->getMessage());
    }

    return $classes;
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

    public function handleConsentUpdate($user_id, $type, $status): void {
        try {
            if (isset($this->components['audit'])) {
                $this->components['audit']->log(
                    $user_id,
                    'consent_update',
                    sprintf(
                        __('User updated %s consent to: %s', 'wp-gdpr-framework'),
                        $type,
                        $status ? 'granted' : 'withdrawn'
                    ),
                    'medium'
                );
            }
    
            do_action('gdpr_consent_updated', $user_id, $type, $status);
        } catch (\Exception $e) {
            error_log('GDPR Framework - Consent Update Error: ' . $e->getMessage());
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

    private function verifyComponentInitialization(): bool {
        $required_components = ['template', 'consent', 'audit'];
        foreach ($required_components as $component) {
            if (!isset($this->components[$component])) {
                error_log("GDPR Framework - Required component not initialized: {$component}");
                return false;
            }
        }
        return true;
    }


    /**
     * Get specific component
     */
    public function getComponent(string $name) {
        if (!isset($this->components[$name])) {
            error_log("GDPR Framework - Attempted to access undefined component: {$name}");
            if (defined('GDPR_FRAMEWORK_DEBUG') && GDPR_FRAMEWORK_DEBUG) {
                error_log("GDPR Framework - Available components: " . print_r(array_keys($this->components), true));
            }
            return null;
        }
        return $this->components[$name];
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
 * Enhanced deactivation with cleanup
 */
public function deactivate(): void {
    if (!current_user_can('activate_plugins')) {
        return;
    }

    try {
        // Clear scheduled tasks
        wp_clear_scheduled_hook('gdpr_daily_cleanup');
        
        // Clean up components
        foreach ($this->components as $component) {
            if (method_exists($component, 'cleanup')) {
                $component->cleanup();
            }
        }
        
        // Clear plugin options
        $this->cleanupOptions();
        
        // Clear instance
        self::$instance = null;
        
        flush_rewrite_rules();
        
    } catch (\Exception $e) {
        $this->logError('Deactivation failed', $e);
    }
}

/**
 * Cleanup plugin options
 */
private function cleanupOptions(): void {
    foreach (self::PLUGIN_OPTIONS as $option) {
        delete_option($option);
    }
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
    

    