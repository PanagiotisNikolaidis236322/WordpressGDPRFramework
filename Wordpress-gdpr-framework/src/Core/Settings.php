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

    public function registerSettings() {
        // General Settings
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
            $sanitized[sanitize_key($key)] = [
                'label' => sanitize_text_field($type['label'] ?? ''),
                'description' => sanitize_textarea_field($type['description'] ?? ''),
                'required' => (bool) ($type['required'] ?? false)
            ];
        }
        return $sanitized;
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