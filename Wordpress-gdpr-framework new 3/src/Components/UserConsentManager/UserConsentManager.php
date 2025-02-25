<?php
namespace GDPRFramework\Components\UserConsentManager;

use Exception;
use InvalidArgumentException;

class UserConsentManager {
    private $db;
    private $settings;
    private $template;
    private $consentType;
    private $consentVersion;
    private $notifications;
    private $table_name; 

    public function __construct(
        $database, 
        $settings, 
        $template = null,
        ConsentType $consentType = null,
        ConsentVersion $consentVersion = null,
        ConsentNotifications $notifications = null
    ) {
        $this->db = $database;
        $this->settings = $settings;
        $this->template = $template;

        // Initialize table name
        $this->table_name = $this->db->getTableName('user_consents');
        
        // Store dependencies
        $this->consentType = $consentType ?? new ConsentType($database, $settings);
        $this->consentVersion = $consentVersion ?? new ConsentVersion($database);
        $this->notifications = $notifications ?? new ConsentNotifications($settings, $database);
        
        // Ensure consent types are initialized
        $this->consentType->ensureConsentTypes();
        
        // Initialize hooks
        $this->initializeHooks();
        
        error_log('GDPR Framework - UserConsentManager initialized with all dependencies');
    }

    private function verifyInitialization() {
        if (!$this->consentType || !$this->consentVersion || !$this->notifications) {
            throw new \Exception('UserConsentManager not properly initialized');
        }
    }

     // Add button handlers
     public function handleResetPreferences(): void {
        try {
            error_log('GDPR Framework - Handling preferences reset');
            error_log('POST data: ' . print_r($_POST, true));
    
            if (!isset($_POST['gdpr_reset_nonce']) || 
                !wp_verify_nonce($_POST['gdpr_reset_nonce'], 'gdpr_reset_preferences')) {
                throw new \Exception(__('Security check failed.', 'wp-gdpr-framework'));
            }
    
            $user_id = $this->validateUser();
            
            // Add debug logging
            error_log('GDPR Framework - Attempting to reset preferences for user ' . $user_id);
    
            try {
                if ($this->resetUserConsents($user_id)) {
                    wp_send_json_success([
                        'message' => __('Privacy preferences have been reset.', 'wp-gdpr-framework'),
                        'consents' => $this->getCurrentUserConsents($user_id)
                    ]);
                } else {
                    throw new \Exception(__('Failed to reset privacy preferences.', 'wp-gdpr-framework'));
                }
            } catch (\Exception $e) {
                error_log('GDPR Framework - Reset operation failed: ' . $e->getMessage());
                throw $e;
            }
    
        } catch (\Exception $e) {
            error_log('GDPR Framework - Reset preferences failed: ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Initialize all required components
     *
     * @throws Exception If component initialization fails
     */
    private function initializeComponents(): void {
        try {
            // Initialize ConsentType if not already initialized
            if (!$this->consentType) {
                $this->consentType = new ConsentType($this->db, $this->settings);
            }
            
            // Initialize ConsentVersion
            $this->consentVersion = new ConsentVersion($this->db);
            
            // Initialize ConsentNotifications
            $this->notifications = new ConsentNotifications($this->settings, $this->db);
            
        } catch (Exception $e) {
            error_log('GDPR Framework - Component Initialization Error: ' . $e->getMessage());
            throw new Exception('Failed to initialize consent management components');
        }
    }

    private function enqueueConsentFormAssets(): void {
        // Enqueue React and ReactDOM from WordPress
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
    
        // Add data for React component
        wp_localize_script('gdpr-framework-public', 'gdprConsentForm', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gdpr_nonce'),
            'consentTypes' => $this->consentType->getAll(),
            'currentConsents' => $this->getCurrentUserConsents(get_current_user_id()),
            'outdatedConsents' => $this->getOutdatedConsents(get_current_user_id()),
            'i18n' => $this->getConsentFormTranslations()
        ]);
    }

    public function getConsentFormTranslations(): array {
        return [
            'success' => __('Your privacy preferences have been updated successfully.', 'wp-gdpr-framework'),
            'error' => __('Failed to update privacy preferences.', 'wp-gdpr-framework'),
            'updating' => __('Updating...', 'wp-gdpr-framework'),
            'update' => __('Update Privacy Settings', 'wp-gdpr-framework'),
            'confirmReset' => __('Are you sure you want to reset your privacy preferences?', 'wp-gdpr-framework'),
            'resetSuccess' => __('Privacy preferences have been reset.', 'wp-gdpr-framework'),
            'resetError' => __('Failed to reset privacy preferences.', 'wp-gdpr-framework'),
            'requiredConsent' => __('This consent is required and cannot be disabled.', 'wp-gdpr-framework')
        ];
    }

    public function getOutdatedConsents(int $user_id): array {
        $outdated = [];
        $consent_types = $this->consentType->getAll();
        
        foreach ($consent_types as $type => $data) {
            if ($this->needsReConsent($type, $user_id)) {
                $outdated[] = $type;
            }
        }
        
        return $outdated;
    }

    /**
 * Get the ConsentType component
 *
 * @return ConsentType
 * @throws Exception if component not initialized
 */
    public function getConsentType(): ConsentType {
    if (!$this->consentType) {
        throw new Exception('ConsentType component not initialized');
    }
    return $this->consentType;
    }

    /**
     * Initialize WordPress hooks
     */
    private function initializeHooks(): void {
        // Admin hooks
        add_action('admin_init', [$this, 'registerSettings']);

        // Add AJAX handlers for buttons
        add_action('wp_ajax_gdpr_reset_preferences', [$this, 'handleResetPreferences']);
        add_action('wp_ajax_gdpr_update_privacy_settings', [$this, 'handlePrivacySettingsUpdate']);
        
         // Add AJAX handlers
        add_action('wp_ajax_update_user_consent', [$this, 'handleConsentUpdate']);
        add_action('wp_ajax_gdpr_reset_consent', [$this, 'handleConsentReset']);
    
         // Add script localization
        add_action('wp_enqueue_scripts', [$this, 'localizeConsentScript']);

        add_action('wp_ajax_gdpr_get_consent_history', [$this, 'handleConsentHistoryRequest']); 
        add_action('wp_ajax_gdpr_export_consent_history', [$this, 'handleConsentHistoryExport']);
        
        // Shortcodes
        add_shortcode('gdpr_consent_form', [$this, 'renderConsentForm']);
        add_shortcode('gdpr_consent_status', [$this, 'renderConsentStatus']);
        add_shortcode('gdpr_consent_history', [$this, 'renderConsentHistory']);
        
        // Cleanup hooks
        add_action('gdpr_daily_cleanup', [$this, 'cleanupOldConsents']);
    }

    private function logDebug(string $message): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('GDPR Framework - ' . $message);
        }
    }

    public function handlePrivacySettingsUpdate(): void {
        try {
            // Verify nonce
            $this->validateNonce();
            
            // Get and validate user ID
            $user_id = $this->validateUser();
            if (!$user_id) {
                throw new \Exception(__('You must be logged in to update privacy settings.', 'wp-gdpr-framework'));
            }
    
            // Validate consents data
            $consents = $this->validateConsents();
            
            $this->logDebug('Processing privacy settings update for user ' . $user_id);
            
            // Update the consents
            $result = $this->updateUserConsents($user_id, $consents);
            
            if ($result) {
                wp_send_json_success([
                    'message' => __('Privacy settings updated successfully.', 'wp-gdpr-framework'),
                    'consents' => $this->getCurrentUserConsents($user_id)
                ]);
            } else {
                throw new \Exception(__('Failed to update privacy settings.', 'wp-gdpr-framework'));
            }
            
        } catch (\Exception $e) {
            $this->logDebug('Privacy settings update failed: ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function localizeConsentScript(): void {
        wp_localize_script('gdpr-framework-public', 'gdprConsentForm', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gdpr_nonce'),
            'i18n' => [
                'success' => __('Your privacy preferences have been updated successfully.', 'wp-gdpr-framework'),
                'error' => __('Failed to update privacy preferences.', 'wp-gdpr-framework'),
                'updating' => __('Updating...', 'wp-gdpr-framework'),
                'update' => __('Update Privacy Settings', 'wp-gdpr-framework'),
                'confirmReset' => __('Are you sure you want to reset your privacy preferences?', 'wp-gdpr-framework'),
                'resetSuccess' => __('Privacy preferences have been reset.', 'wp-gdpr-framework'),
                'resetError' => __('Failed to reset privacy preferences.', 'wp-gdpr-framework')
            ]
        ]);
    }



    /**
     * Register settings
     */
    public function registerSettings(): void {
        register_setting(
            'gdpr_framework_settings',
            'gdpr_consent_types',
            [
                'type' => 'array',
                'sanitize_callback' => [$this->consentType, 'sanitizeConsentData'],
                'default' => []
            ]
        );
    }

    /**
     * Handle consent update AJAX request
     */
    public function handleConsentUpdate(): void {
        try {
            error_log('GDPR Framework - Handling consent update');
            
            // Verify nonce
            if (!isset($_POST['gdpr_nonce']) || 
                !wp_verify_nonce($_POST['gdpr_nonce'], 'gdpr_nonce')) {
                throw new \Exception(__('Security check failed.', 'wp-gdpr-framework'));
            }
    
            // Validate user
            $user_id = get_current_user_id();
            if (!$user_id) {
                throw new \Exception(__('You must be logged in to update privacy settings.', 'wp-gdpr-framework'));
            }
    
            // Get available consent types
            $available_types = $this->consentType->getAll();
            if (empty($available_types)) {
                throw new \Exception(__('No consent types are configured.', 'wp-gdpr-framework'));
            }
    
            error_log('GDPR Framework - Available consent types: ' . print_r($available_types, true));
    
            // Process consent updates
            $consents = [];
            foreach ($available_types as $type => $config) {
                // Required consents are always true
                if (!empty($config['required'])) {
                    $consents[$type] = true;
                    continue;
                }
                
                // Process optional consents
                $consents[$type] = isset($_POST['consents'][$type]) ? 
                    filter_var($_POST['consents'][$type], FILTER_VALIDATE_BOOLEAN) : 
                    false;
            }
    
            error_log('GDPR Framework - Processing consents: ' . print_r($consents, true));
    
            if ($this->updateUserConsents($user_id, $consents)) {
                wp_send_json_success([
                    'message' => __('Privacy settings updated successfully.', 'wp-gdpr-framework'),
                    'consents' => $this->getCurrentUserConsents($user_id)
                ]);
            } else {
                throw new \Exception(__('Failed to update privacy settings.', 'wp-gdpr-framework'));
            }
    
        } catch (\Exception $e) {
            error_log('GDPR Framework - Consent Update Error: ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    

    public function handleConsentReset(): void {
        check_ajax_referer('gdpr_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in to perform this action.', 'wp-gdpr-framework')]);
            return;
        }
    
        try {
            $user_id = get_current_user_id();
            $result = $this->resetUserConsents($user_id);
            
            if ($result) {
                wp_send_json_success([
                    'message' => __('Privacy preferences have been reset successfully.', 'wp-gdpr-framework')
                ]);
            } else {
                throw new \Exception(__('Failed to reset privacy preferences.', 'wp-gdpr-framework'));
            }
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

     /**
     * New method to check if re-consent is needed
     */
    public function needsReConsent(string $type, int $user_id): bool {
        $latest_consent = $this->db->get_row(
            $this->db->prepare(
                "SELECT version FROM {$this->table_name} 
                 WHERE user_id = %d AND consent_type = %s 
                 ORDER BY timestamp DESC LIMIT 1",
                $user_id,
                $type
            )
        );

        if (!$latest_consent) {
            return true;
        }

        return $this->consentVersion->needsReConsent($type, $latest_consent->version);
    }

    /**
     * Save a consent record
     */
    private function saveConsent(int $user_id, string $type, bool $status): bool {
        try {
            if (!$this->consentType->isValid($type)) {
                throw new \Exception("Invalid consent type: {$type}");
            }
    
            $version = $this->consentVersion->getCurrentVersion($type);
            
            $result = $this->db->insert(
                'user_consents',
                [
                    'user_id' => $user_id,
                    'consent_type' => $type,
                    'status' => $status,
                    'version' => $version,
                    'ip_address' => $this->getClientIP(),
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'timestamp' => current_time('mysql', true)
                ],
                ['%d', '%s', '%d', '%s', '%s', '%s', '%s']
            );
    
            if (!$result) {
                throw new \Exception('Failed to save consent');
            }
    
            // Log the consent change
            $this->db->insert(
                'consent_log',
                [
                    'user_id' => $user_id,
                    'consent_type' => $type,
                    'status' => $status,
                    'version' => $version,
                    'ip_address' => $this->getClientIP(),
                    'created_at' => current_time('mysql', true)
                ],
                ['%d', '%s', '%d', '%s', '%s', '%s']
            );
    
            do_action('gdpr_consent_recorded', $user_id, $type, $status, $version);
            return true;
    
        } catch (\Exception $e) {
            error_log('GDPR Framework - Save Consent Error: ' . $e->getMessage());
            throw $e;
        }
    }

    
    /**
     * Get consent status for a user
     */
    public function getConsentStatus(string $type, ?int $user_id = null): bool {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        if (!$user_id || !$this->consentType->isValid($type)) {
            return false;
        }

        try {
            $query = $this->db->prepare(
                "SELECT status FROM {$this->table_name} 
                 WHERE user_id = %d AND consent_type = %s 
                 ORDER BY timestamp DESC LIMIT 1",
                $user_id,
                $type
            );

            error_log('GDPR Framework - Executing query: ' . $query);
            
            return (bool) $this->db->get_var($query);

        } catch (\Exception $e) {
            error_log('GDPR Framework - Get Consent Status Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get consent statistics
     */
    public function getConsentStats(): array {
        $stats = [
            'total_users' => count_users()['total_users'],
            'consent_types' => []
        ];
    
        $consent_types = $this->consentType->getAll();
        if (!empty($consent_types) && is_array($consent_types)) {
            foreach ($consent_types as $type_key => $type) {
                if (!is_array($type)) {
                    continue;
                }
    
                $count = (int) $this->db->get_var($this->db->prepare(
                    "SELECT COUNT(DISTINCT user_id) FROM {$this->table_name}
                     WHERE consent_type = %s AND status = 1",
                    $type_key
                ));
    
                $stats['consent_types'][$type_key] = [
                    'label' => isset($type['label']) ? $type['label'] : __('Unknown', 'wp-gdpr-framework'),
                    'count' => $count,
                    'percentage' => $stats['total_users'] > 0 
                        ? round(($count / $stats['total_users']) * 100, 1) 
                        : 0
                ];
            }
        }
    
        return $stats;
    }

    /**
     * Get total number of users with any consent
     */
    public function getTotalConsents(): int {
        return (int) $this->db->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$this->table_name}"
        );
    }

    /**
     * Get number of active consents
     */
    public function getActiveConsents(): int {
        return (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE status = 1"
        );
    }

    /**
     * Get consent history for a user
     */
    public function getConsentHistory(int $user_id): array {
        return $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->table_name} 
                 WHERE user_id = %d 
                 ORDER BY timestamp DESC",
                $user_id
            )
        ) ?: [];
    }

    /**
     * Render the consent form
     */
    public function renderConsentForm(array $atts = []): string {
        $this->enqueueAssets();
        
        if (!is_user_logged_in()) {
            return sprintf(
                '<div class="gdpr-notice gdpr-error">%s</div>',
                esc_html__('Please log in to manage your privacy settings.', 'wp-gdpr-framework')
            );
        }
    
        try {
            $user_id = get_current_user_id();
            
            // Get consent types with proper error handling
            $consent_types = $this->consentType->getAll();
            if (!is_array($consent_types)) {
                error_log('GDPR Framework - Invalid consent types returned: ' . print_r($consent_types, true));
                $consent_types = [];
            }
    
            // Get current consents
            $current_consents = $this->getCurrentUserConsents($user_id);
            if (!is_array($current_consents)) {
                error_log('GDPR Framework - Invalid current consents returned: ' . print_r($current_consents, true));
                $current_consents = [];
            }
    
            // Get outdated consents
            $outdated_consents = $this->getOutdatedConsents($user_id);
            if (!is_array($outdated_consents)) {
                error_log('GDPR Framework - Invalid outdated consents returned: ' . print_r($outdated_consents, true));
                $outdated_consents = [];
            }
    
            error_log('GDPR Framework - Rendering consent form with data: ' . print_r([
                'consent_types' => array_keys($consent_types),
                'current_consents' => $current_consents,
                'outdated_consents' => $outdated_consents
            ], true));
    
            ob_start();
            include(GDPR_FRAMEWORK_PATH . 'templates/public/consent-form.php');
            return ob_get_clean();
    
        } catch (\Exception $e) {
            error_log('GDPR Framework - Consent Form Error: ' . $e->getMessage());
            return sprintf(
                '<div class="gdpr-notice gdpr-error">%s</div>',
                esc_html__('Error loading consent form.', 'wp-gdpr-framework')
            );
        }
    }

    private function validateUser(): int {
        $user_id = get_current_user_id();
        if (!$user_id) {
            throw new \Exception(__('You must be logged in to update privacy settings.', 'wp-gdpr-framework'));
        }
        return $user_id;
    }
    
    private function validateNonce(): void {
        if (!isset($_POST['gdpr_nonce']) || !wp_verify_nonce($_POST['gdpr_nonce'], 'gdpr_nonce')) {
            throw new \Exception(__('Security check failed.', 'wp-gdpr-framework'));
        }
    }
    
    private function validateConsents(): array {
        if (!isset($_POST['consents']) || !is_array($_POST['consents'])) {
            throw new \Exception(__('Invalid consent data received.', 'wp-gdpr-framework'));
        }
        return array_map(function($value) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }, $_POST['consents']);
    }


    public function getCurrentUserConsents(int $user_id): array {
        $consents = [];
        // Use the ConsentType component to get all types
        $consent_types = $this->consentType->getAll();
        
        if (!empty($consent_types)) {
            foreach ($consent_types as $type => $data) {
                $consents[$type] = $this->getConsentStatus($type, $user_id);
            }
        }
        
        return $consents;
    }

    private function getClientIP(): string {
        $ip_headers = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = filter_var($_SERVER[$header], FILTER_VALIDATE_IP);
                if ($ip !== false) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    /**
     * Enqueue required assets for the consent form
     */
    private function enqueueAssets(): void {
        try {
            wp_enqueue_style('dashicons');
            wp_enqueue_style(
                'gdpr-framework-public',
                GDPR_FRAMEWORK_URL . 'assets/css/public.css',
                [],
                GDPR_FRAMEWORK_VERSION
            );
            wp_enqueue_script(
                'gdpr-framework-public',
                GDPR_FRAMEWORK_URL . 'assets/js/public.js',
                ['jquery'],
                GDPR_FRAMEWORK_VERSION,
                true
            );

        wp_localize_script('gdpr-framework-public', 'gdprConsentHistory', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gdpr_consent_history'),
            'i18n' => [
                'exportSuccess' => __('Consent history exported successfully.', 'wp-gdpr-framework'),
                'exportError' => __('Failed to export consent history.', 'wp-gdpr-framework'),
                'loadingError' => __('Failed to load consent history.', 'wp-gdpr-framework')
            ]
        ]);

        wp_localize_script('gdpr-framework-public', 'gdprConsentForm', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gdpr_nonce'),
            'i18n' => [
                'success' => __('Your privacy preferences have been updated successfully.', 'wp-gdpr-framework'),
                'error' => __('Failed to update privacy preferences.', 'wp-gdpr-framework'),
                'updating' => __('Updating...', 'wp-gdpr-framework'),
                'update' => __('Update Privacy Settings', 'wp-gdpr-framework'),
                'confirmReset' => __('Are you sure you want to reset your privacy preferences?', 'wp-gdpr-framework')
            ]
        ]);
    } catch (\Exception $e) {
        $this->logDebug('Failed to enqueue assets: ' . $e->getMessage());
    }
    }

    /**
     * Initialize default consent types
     */
    public function initializeDefaultTypes(): void {
        $this->consentType->addDefaultTypes();
    }

    /**
     * Check if user has given consent
     *
     * @param string $type Consent type to check
     * @param int|null $user_id Optional user ID, defaults to current user
     * @return bool
     */
    public function hasConsent(string $type, ?int $user_id = null): bool {
        if (!$this->consentType->isValid($type)) {
            return false;
        }

        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        if (!$user_id) {
            return false;
        }

        // Required consents are always considered granted
        if ($this->consentType->isRequired($type)) {
            return true;
        }

        return $this->getConsentStatus($type, $user_id);
    }

    /**
     * Get all user consents with detailed information
     *
     * @param int $user_id
     * @return array
     */
    public function getUserConsents(int $user_id): array {
        $consents = [];
        foreach ($this->consentType->getAll() as $type => $data) {
            $latest_consent = $this->db->get_row(
                $this->db->prepare(
                    "SELECT * FROM {$this->table_name} 
                     WHERE user_id = %d AND consent_type = %s 
                     ORDER BY timestamp DESC LIMIT 1",
                    $user_id,
                    $type
                )
            );

            $consents[$type] = [
                'type' => $type,
                'label' => $data['label'],
                'description' => $data['description'],
                'required' => !empty($data['required']),
                'status' => $latest_consent ? (bool)$latest_consent->status : false,
                'timestamp' => $latest_consent ? $latest_consent->timestamp : null,
                'ip_address' => $latest_consent ? $latest_consent->ip_address : null
            ];
        }
        return $consents;
    }

    public function handleConsentHistoryRequest() {
        try {
            // Verify nonce
            check_ajax_referer('gdpr_consent_history', 'nonce');
            
            // Verify user is logged in
            if (!is_user_logged_in()) {
                throw new \Exception(__('You must be logged in to view consent history.', 'wp-gdpr-framework'));
            }
            
            $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
            $per_page = 10;
            
            // Get consent history with pagination
            $history = $this->db->get_results(
                $this->db->prepare(
                    "SELECT ch.*, ct.label as consent_type_label 
                     FROM {$this->table_name} ch
                     LEFT JOIN {$this->db->prefix}gdpr_consent_types ct 
                        ON ch.consent_type = ct.id
                     WHERE ch.user_id = %d 
                     ORDER BY ch.timestamp DESC
                     LIMIT %d OFFSET %d",
                    get_current_user_id(),
                    $per_page,
                    ($page - 1) * $per_page
                )
            );
    
            // Get total count for pagination
            $total_items = $this->db->get_var(
                $this->db->prepare(
                    "SELECT COUNT(*) FROM {$this->table_name} 
                     WHERE user_id = %d",
                    get_current_user_id()
                )
            );
    
            $total_pages = ceil($total_items / $per_page);
    
            // Format the data
            $formatted_history = array_map(function($entry) {
                return [
                    'timestamp' => $entry->timestamp,
                    'consent_type_label' => $entry->consent_type_label,
                    'status' => $entry->status ? 'granted' : 'withdrawn',
                    'version' => $entry->version,
                    'ip_address' => $entry->ip_address,
                    'outdated' => $this->consentVersion->needsReConsent(
                        $entry->consent_type,
                        $entry->version
                    )
                ];
            }, $history);
    
            wp_send_json_success([
                'history' => $formatted_history,
                'total_pages' => $total_pages,
                'current_page' => $page
            ]);
    
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }
    
    // Add this method to handle history export
    public function handleConsentHistoryExport() {
        try {
            check_ajax_referer('gdpr_consent_history', 'nonce');
            
            if (!is_user_logged_in()) {
                throw new \Exception(__('You must be logged in to export consent history.', 'wp-gdpr-framework'));
            }
    
            $format = isset($_GET['format']) ? sanitize_text_field($_GET['format']) : 'csv';
    
            $history = $this->db->get_results(
                $this->db->prepare(
                    "SELECT ch.*, ct.label as consent_type_label 
                     FROM {$this->table_name} ch
                     LEFT JOIN {$this->db->prefix}gdpr_consent_types ct 
                        ON ch.consent_type = ct.id
                     WHERE ch.user_id = %d 
                     ORDER BY ch.timestamp DESC",
                    get_current_user_id()
                )
            );
    
            switch($format) {
                case 'json':
                    header('Content-Type: application/json; charset=utf-8');
                    header('Content-Disposition: attachment; filename=consent-history.json');
                    echo wp_json_encode($this->formatHistoryForExport($history));
                    break;
                    
                default:
                    // CSV Export
                    header('Content-Type: text/csv; charset=utf-8');
                    header('Content-Disposition: attachment; filename=consent-history.csv');
                    
                    $output = fopen('php://output', 'w');
                    
                    // Add UTF-8 BOM
                    fputs($output, "\xEF\xBB\xBF");
                    
                    // Add headers
                    fputcsv($output, [
                        'Date',
                        'Consent Type',
                        'Status',
                        'Version',
                        'IP Address'
                    ]);
                    
                    // Add data
                    foreach ($history as $entry) {
                        fputcsv($output, [
                            wp_date(
                                get_option('date_format') . ' ' . get_option('time_format'),
                                strtotime($entry->timestamp)
                            ),
                            $entry->consent_type_label,
                            $entry->status ? 'Granted' : 'Withdrawn',
                            $entry->version,
                            $entry->ip_address
                        ]);
                    }
                    
                    fclose($output);
                    break;
            }
            exit;
    
        } catch (\Exception $e) {
            wp_die($e->getMessage());
        }
    }

    private function formatHistoryForExport($history) {
        return array_map(function($entry) {
            return [
                'date' => wp_date(
                    get_option('date_format') . ' ' . get_option('time_format'),
                    strtotime($entry->timestamp)
                ),
                'type' => $entry->consent_type_label,
                'status' => $entry->status ? 'Granted' : 'Withdrawn',
                'version' => $entry->version,
                'ip_address' => $entry->ip_address,
                'outdated' => $entry->outdated
            ];
        }, $history);
    }

    public function renderConsentHistory($atts = []): string {
        if (!is_user_logged_in()) {
            return sprintf(
                '<div class="gdpr-notice gdpr-error">%s</div>',
                esc_html__('Please log in to view your consent history.', 'wp-gdpr-framework')
            );
        }
    
        try {
            $user_id = get_current_user_id();
            $page = isset($_GET['history_page']) ? max(1, absint($_GET['history_page'])) : 1;
            $per_page = 10;
            
            $consent_history = $this->getPaginatedConsentHistory($user_id, $page, $per_page);
            $total_count = $this->getConsentHistoryCount($user_id);
            $total_pages = ceil($total_count / $per_page);
    
            $data = [
                'consent_history' => $consent_history,
                'current_page' => $page,
                'total_pages' => $total_pages
            ];
    
            ob_start();
            extract($data);
            include(GDPR_FRAMEWORK_PATH . '/templates/public/consent-history.php');
            return ob_get_clean();
    
        } catch (\Exception $e) {
            error_log('GDPR Framework - Consent History Error: ' . $e->getMessage());
            return '<div class="gdpr-notice gdpr-error">' . 
                   esc_html__('Failed to load consent history.', 'wp-gdpr-framework') . 
                   '</div>';
        }
    }
    
    private function getPaginatedConsentHistory(int $user_id, int $page, int $per_page): array {
        $offset = ($page - 1) * $per_page;
        
        // Get proper table names with prefixes
        $consents_table = $this->db->get_prefix() . 'gdpr_user_consents';
        $consent_types_table = $this->db->get_prefix() . 'gdpr_consent_types';
        $versions_table = $this->db->get_prefix() . 'gdpr_consent_versions';
        
        try {
            $results = $this->db->get_results(
                $this->db->prepare(
                    "SELECT ch.*, ct.label as consent_type_label, 
                            cv.created_at as version_created_at,
                            cv.version as current_version
                     FROM {$consents_table} ch
                     LEFT JOIN {$consent_types_table} ct 
                        ON ch.consent_type = ct.id
                     LEFT JOIN (
                         SELECT consent_type, version, created_at
                         FROM {$versions_table}
                         WHERE id IN (
                             SELECT MAX(id) 
                             FROM {$versions_table} 
                             GROUP BY consent_type
                         )
                     ) cv ON ch.consent_type = cv.consent_type
                     WHERE ch.user_id = %d 
                     ORDER BY ch.timestamp DESC, ch.id DESC
                     LIMIT %d OFFSET %d",
                    $user_id,
                    $per_page,
                    $offset
                )
            ) ?: [];
    
            // Add outdated status
            foreach ($results as &$result) {
                $result->outdated = isset($result->version, $result->current_version) && 
                                  $result->version !== $result->current_version;
            }
    
            return $results;
    
        } catch (\Exception $e) {
            error_log('GDPR Framework - Consent History Error: ' . $e->getMessage());
            return [];
        }
    }
    
    private function getConsentHistoryCount(int $user_id): int {
        return (int) $this->db->get_var(
            $this->db->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE user_id = %d",
                $user_id
            )
        );
    }

    /**
     * Bulk update consents for a user
     *
     * @param int $user_id
     * @param array $consents
     * @return bool
     * @throws \Exception
     */
    public function updateUserConsents(int $user_id, array $consents): bool {
    if (!$user_id) {
        throw new \Exception(__('Invalid user ID', 'wp-gdpr-framework'));
    }

    $this->db->query('START TRANSACTION');

    try {
        foreach ($consents as $type => $status) {
            if ($this->consentType->isValid($type)) {
                if ($this->consentType->isRequired($type) && !$status) {
                    continue;
                }
                $this->saveConsent($user_id, $type, (bool)$status);
            }
        }

        $this->db->query('COMMIT');
        do_action('gdpr_user_consents_updated', $user_id, $consents);
        
        return true;

    } catch (\Exception $e) {
        $this->db->query('ROLLBACK');
        $this->logDebug('Failed to update user consents: ' . $e->getMessage());
        throw $e;
    }
}

    /**
     * Clean up old consent records
     * 
     * @param int $days_to_keep Number of days to keep consent records
     * @return int Number of records deleted
     */
    public function cleanupOldConsents(int $days_to_keep = 365): int {
        try {
            $result = $this->db->query($this->db->prepare(
                "DELETE FROM {$this->table_name} 
                 WHERE status = 0 
                 AND timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
                max(30, $days_to_keep)
            ));

            // Trigger cleanup event for audit logging
            do_action('gdpr_consents_cleanup_completed', is_numeric($result) ? (int)$result : 0);

            return is_numeric($result) ? (int)$result : 0;

        } catch (Exception $e) {
            error_log('GDPR Framework - Consent Cleanup Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Render consent status shortcode
     * 
     * @param array $atts Shortcode attributes
     * @return string Rendered output
     */
    public function renderConsentStatus(array $atts = []): string {
        $atts = shortcode_atts([
            'type' => '',
            'user_id' => get_current_user_id()
        ], $atts);

        if (!$atts['type'] || !$this->consentType->isValid($atts['type'])) {
            return '';
        }

        $consent_info = $this->getUserConsents($atts['user_id'])[$atts['type']] ?? null;
        if (!$consent_info) {
            return '';
        }

        $classes = [
            'gdpr-consent-status',
            'status-' . ($consent_info['status'] ? 'granted' : 'withdrawn')
        ];

        if ($this->needsReConsent($atts['type'], $atts['user_id'])) {
            $classes[] = 'status-outdated';
        }

        return sprintf(
            '<span class="%s">%s</span>',
            esc_attr(implode(' ', $classes)),
            $consent_info['status'] 
                ? esc_html__('Consent Granted', 'wp-gdpr-framework')
                : esc_html__('Consent Not Granted', 'wp-gdpr-framework')
        );
    }

    // Add method to handle consent version changes
    public function handleConsentVersionChange(string $type): void {
        try {
            $affected_users = $this->db->get_col($this->db->prepare(
                "SELECT DISTINCT user_id FROM {$this->table_name} 
                 WHERE consent_type = %s AND status = 1",
                $type
            ));

            foreach ($affected_users as $user_id) {
                $this->notifications->sendVersionUpdateNotification($user_id, $type);
            }

            do_action('gdpr_consent_version_change_processed', $type, count($affected_users));

        } catch (Exception $e) {
            error_log('GDPR Framework - Version Change Error: ' . $e->getMessage());
            throw $e;
        }
    }

    // Add method to verify database integrity
    public function verifyDatabaseIntegrity(): bool {
        try {
            // Verify consent records have required fields
            $missing_fields = $this->db->get_results("
                SELECT * FROM {$this->table_name} 
                WHERE user_id IS NULL 
                   OR consent_type IS NULL 
                   OR status IS NULL 
                   OR version IS NULL 
                LIMIT 1
            ");

            if (!empty($missing_fields)) {
                error_log('GDPR Framework - Data Integrity Issue: Found consent records with missing required fields');
                return false;
            }

            // Verify all consent types are valid
            $invalid_types = $this->db->get_col("
                SELECT DISTINCT consent_type 
                FROM {$this->table_name}
            ");

            foreach ($invalid_types as $type) {
                if (!$this->consentType->isValid($type)) {
                    error_log('GDPR Framework - Data Integrity Issue: Found invalid consent type: ' . $type);
                    return false;
                }
            }

            return true;

        } catch (Exception $e) {
            error_log('GDPR Framework - Database Verification Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Reset all non-required consents for a user
     *
     * @param int $user_id
     * @return bool
     * @throws \Exception
     */
    public function resetUserConsents(int $user_id): bool {
        try {
            $available_types = $this->consentType->getAll();
            if (empty($available_types)) {
                throw new \Exception(__('No consent types are configured.', 'wp-gdpr-framework'));
            }
    
            error_log('GDPR Framework - Resetting consents for types: ' . print_r(array_keys($available_types), true));
    
            $consents = [];
            foreach ($available_types as $type => $config) {
                // Required consents stay true, others reset to false
                $consents[$type] = !empty($config['required']);
            }
    
            return $this->updateUserConsents($user_id, $consents);
    
        } catch (\Exception $e) {
            error_log('GDPR Framework - Reset Consents Error: ' . $e->getMessage());
            throw $e;
        }
    }
}
