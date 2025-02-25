<?php
namespace GDPRFramework\Core;

class Settings {
    private $options = [];
    private $option_prefix = 'gdpr_';

    public function __construct() {
        $this->loadSettings();
        add_action('admin_init', [$this, 'registerSettings']);
    }

    private function loadSettings() {
        $default_settings = $this->getDefaultSettings();
        
        foreach ($default_settings as $key => $default) {
            $this->options[$key] = get_option(
                $this->option_prefix . $key, 
                $default
            );
        }
    }

    private function getDefaultSettings() {
        return [
            'consent_types' => [
                'marketing' => [
                    'label' => __('Marketing Communications', 'wp-gdpr-framework'),
                    'description' => __('Allow us to send marketing communications', 'wp-gdpr-framework'),
                    'required' => false
                ],
                'analytics' => [
                    'label' => __('Analytics Tracking', 'wp-gdpr-framework'),
                    'description' => __('Allow analytics tracking for website improvement', 'wp-gdpr-framework'),
                    'required' => false
                ],
                'necessary' => [
                    'label' => __('Necessary Cookies', 'wp-gdpr-framework'),
                    'description' => __('Required for the website to function properly', 'wp-gdpr-framework'),
                    'required' => true
                ]
            ],
            'retention_periods' => [
                'audit_logs' => 365,
                'user_data' => 730,
                'consent_records' => 1825
            ],
            'privacy_policy_page' => 0,
            'dpo_email' => '',
            'encryption_algorithm' => 'aes-256-cbc',
            'export_formats' => ['json', 'xml', 'csv'],
            'cookie_settings' => [
                'consent_expiry' => 365,
                'cookie_expiry' => 30
            ]
        ];
    }

    private function getDefaultConsentTypes() {
        return [
            'necessary' => [
                'label' => __('Necessary Cookies', 'wp-gdpr-framework'),
                'description' => __('Required for the website to function properly', 'wp-gdpr-framework'),
                'required' => true
            ],
            'analytics' => [
                'label' => __('Analytics', 'wp-gdpr-framework'),
                'description' => __('Help us understand how visitors use our website', 'wp-gdpr-framework'),
                'required' => false
            ],
            'marketing' => [
                'label' => __('Marketing', 'wp-gdpr-framework'),
                'description' => __('Used to track visitors across websites for marketing purposes', 'wp-gdpr-framework'),
                'required' => false
            ]
        ];
    }

    public function renderConsentTypes() {
        $consent_types = $this->get('consent_types', []);
        include GDPR_FRAMEWORK_PATH . 'templates/admin/consent-types.php';
    }

    private function validateConsentType(array $type): bool {
        $required_fields = ['label', 'description'];
        foreach ($required_fields as $field) {
            if (empty($type[$field])) {
                return false;
            }
        }
        return true;
    }

    public function registerSettings() {
        // General Settings
         // Consent Settings
         register_setting(
            'gdpr_framework_settings',
            $this->option_prefix . 'consent_types',
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitizeConsentTypes'],
                'default' => $this->getDefaultConsentTypes()
            ]
        );

        register_setting(
            'gdpr_framework_settings',
            $this->option_prefix . 'consent_expiry',
            [
                'type' => 'integer',
                'sanitize_callback' => 'absint',
                'default' => 365
            ]
        );

        // Add settings section for consent management
        add_settings_section(
            'gdpr_consent_section',
            __('Consent Management', 'wp-gdpr-framework'),
            [$this, 'renderConsentSection'],
            'gdpr_framework_settings'
        );

        add_settings_field(
            'gdpr_consent_types',
            __('Consent Types', 'wp-gdpr-framework'),
            [$this, 'renderConsentTypes'],
            'gdpr_framework_settings',
            'gdpr_consent_section'
        );


        register_setting(
            'gdpr_framework_settings',
            $this->option_prefix . 'privacy_policy_page',
            ['sanitize_callback' => 'absint']
        );

        register_setting(
            'gdpr_framework_settings',
            $this->option_prefix . 'dpo_email',
            ['sanitize_callback' => 'sanitize_email']
        );

        register_setting(
            'gdpr_framework_settings',
            $this->option_prefix . 'consent_types',
            ['sanitize_callback' => [$this, 'sanitizeConsentTypes']]
        );

        register_setting(
            'gdpr_framework_settings',
            $this->option_prefix . 'retention_periods',
            ['sanitize_callback' => [$this, 'sanitizeRetentionPeriods']]
        );

        // Add settings sections
        add_settings_section(
            'gdpr_general_section',
            __('General Settings', 'wp-gdpr-framework'),
            [$this, 'renderGeneralSection'],
            'gdpr_framework_settings'
        );

        add_settings_section(
            'gdpr_consent_section',
            __('Consent Settings', 'wp-gdpr-framework'),
            [$this, 'renderConsentSection'],
            'gdpr_framework_settings'
        );

        add_settings_section(
            'gdpr_retention_section',
            __('Data Retention', 'wp-gdpr-framework'),
            [$this, 'renderRetentionSection'],
            'gdpr_framework_settings'
        );
    }

    public function sanitizeConsentTypes($consent_types) {
        if (!is_array($consent_types)) {
            return $this->getDefaultSettings()['consent_types'];
        }
    
        $sanitized = [];
        foreach ($consent_types as $key => $type) {
            // Skip if not array or missing required fields
            if (!is_array($type)) {
                continue;
            }
    
            $sanitized_key = sanitize_key($key);
            $sanitized[$sanitized_key] = [
                'label' => sanitize_text_field($type['label'] ?? ''),
                'description' => sanitize_textarea_field($type['description'] ?? ''),
                'required' => !empty($type['required']),
                'version' => $type['version'] ?? wp_generate_uuid4(),
                'created_at' => $type['created_at'] ?? current_time('mysql'),
                'updated_at' => current_time('mysql')
            ];
        }
    
        // Always ensure necessary consent type exists
        if (!isset($sanitized['necessary'])) {
            $sanitized['necessary'] = [
                'label' => __('Necessary Cookies', 'wp-gdpr-framework'),
                'description' => __('Required for the website to function properly', 'wp-gdpr-framework'),
                'required' => true,
                'version' => wp_generate_uuid4(),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ];
        }
    
        return $sanitized;
    }

    // Add method to check if consent type exists
public function hasConsentType(string $type): bool {
    $consent_types = $this->get('consent_types', []);
    return isset($consent_types[$type]);
}

// Add method to get single consent type
public function getConsentType(string $type): ?array {
    $consent_types = $this->get('consent_types', []);
    return $consent_types[$type] ?? null;
}

private function validateConsentTypes($types) {
    if (!is_array($types)) {
        return false;
    }

    foreach ($types as $type) {
        if (!is_array($type) || 
            empty($type['label']) || 
            empty($type['description'])) {
            return false;
        }
    }
    return true;
}

// Add method to update single consent type
public function updateConsentType(string $type, array $data): bool {
    $consent_types = $this->get('consent_types', []);
    
    if (!isset($consent_types[$type])) {
        return false;
    }

    $consent_types[$type] = array_merge(
        $consent_types[$type],
        $this->sanitizeConsentTypes([$type => $data])[$type]
    );

    return $this->set('consent_types', $consent_types);
}
    

    public function sanitizeRetentionPeriods($periods) {
        if (!is_array($periods)) {
            return $this->getDefaultSettings()['retention_periods'];
        }

        $sanitized = [];
        foreach ($periods as $key => $days) {
            $sanitized[sanitize_key($key)] = absint($days);
        }
        return $sanitized;
    }

    public function setDefaults() {
        $defaults = $this->getDefaultSettings();
        foreach ($defaults as $key => $value) {
            if (!get_option($this->option_prefix . $key)) {
                update_option($this->option_prefix . $key, $value);
            }
        }
    
        // Ensure default consent types exist
        $consent_types = get_option($this->option_prefix . 'consent_types', []);
        if (empty($consent_types)) {
            update_option($this->option_prefix . 'consent_types', [
                'necessary' => [
                    'label' => __('Necessary Cookies', 'wp-gdpr-framework'),
                    'description' => __('Required for the website to function properly', 'wp-gdpr-framework'),
                    'required' => true
                ],
                'analytics' => [
                    'label' => __('Analytics', 'wp-gdpr-framework'),
                    'description' => __('Help us understand how visitors use our website', 'wp-gdpr-framework'),
                    'required' => false
                ]
            ]);
        }
    }

    public function get($key, $default = null) {
        return $this->options[$key] ?? $default;
    }

    public function set($key, $value) {
        $this->options[$key] = $value;
        return update_option($this->option_prefix . $key, $value);
    }

    public function delete($key) {
        unset($this->options[$key]);
        return delete_option($this->option_prefix . $key);
    }

    public function renderGeneralSection() {
        echo '<p>' . __('Configure general GDPR framework settings.', 'wp-gdpr-framework') . '</p>';
    }

    public function renderConsentSection() {
        echo '<p>' . __('Configure consent types and their descriptions.', 'wp-gdpr-framework') . '</p>';
    }

    public function renderRetentionSection() {
        echo '<p>' . __('Configure data retention periods.', 'wp-gdpr-framework') . '</p>';
    }
}