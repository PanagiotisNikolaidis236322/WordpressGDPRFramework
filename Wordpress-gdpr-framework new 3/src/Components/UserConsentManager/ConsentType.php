<?php
namespace GDPRFramework\Components\UserConsentManager;

class ConsentType {
    private $db;
    private $settings;
    private $cache = [];
    private $default_types = [
        'necessary' => [
            'label' => 'Necessary Cookies',
            'description' => 'Required for the website to function properly',
            'required' => true
        ],
        'analytics' => [
            'label' => 'Analytics',
            'description' => 'Help us understand how visitors use our website',
            'required' => false
        ],
        'marketing' => [
            'label' => 'Marketing',
            'description' => 'Used for marketing purposes',
            'required' => false
        ]
    ];

    public function __construct($database, $settings) {
        $this->db = $database;
        $this->settings = $settings;
    }

    /**
     * Initialize default consent types
     */
    public function initializeDefaultTypes(): bool {
        try {
            error_log('GDPR Framework - Checking consent types initialization');
            $existing_types = get_option('gdpr_consent_types', []);
            
            if (empty($existing_types)) {
                error_log('GDPR Framework - No consent types found, adding defaults');
                update_option('gdpr_consent_types', $this->default_types);
                $this->cache = $this->default_types;
                do_action('gdpr_consent_types_initialized', $this->default_types);
                return true;
            } else {
                $this->cache = $existing_types;
                return false;
            }
        } catch (\Exception $e) {
            error_log('GDPR Framework - Failed to initialize consent types: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Ensure consent types exist
     */
    public function ensureConsentTypes(): void {
        $types = $this->getAll();
        if (empty($types)) {
            $this->initializeDefaultTypes();
        }
    }

    /**
     * Get all consent types
     */
    public function getAll(): array {
        if (empty($this->cache)) {
            $types = get_option('gdpr_consent_types', []);
            if (empty($types)) {
                error_log('GDPR Framework - No consent types found in getAll()');
                $types = $this->default_types;
                update_option('gdpr_consent_types', $types);
            }
            $this->cache = $types;
        }
        return $this->cache;
    }

    /**
     * Check if a consent type is valid
     */
    public function isValid(string $type): bool {
        $consent_types = $this->getAll();
        return isset($consent_types[$type]) && is_array($consent_types[$type]);
    }

    /**
     * Add a new consent type
     */
    public function add(string $key, array $data): bool {
        $consent_types = $this->getAll();
        
        if (isset($consent_types[$key])) {
            return false;
        }

        $consent_types[$key] = $this->sanitizeConsentData($data);
        $updated = update_option('gdpr_consent_types', $consent_types);
        
        if ($updated) {
            $this->cache = $consent_types;
            do_action('gdpr_consent_type_added', $key, $consent_types[$key]);
        }

        return $updated;
    }

    /**
     * Update an existing consent type
     */
    public function update(string $key, array $data): bool {
        $consent_types = $this->getAll();
        
        if (!isset($consent_types[$key])) {
            return false;
        }

        $old_data = $consent_types[$key];
        $consent_types[$key] = array_merge(
            $old_data,
            $this->sanitizeConsentData($data)
        );

        $updated = update_option('gdpr_consent_types', $consent_types);
        
        if ($updated) {
            $this->cache = $consent_types;
            do_action('gdpr_consent_type_updated', $key, $consent_types[$key], $old_data);
        }

        return $updated;
    }

    /**
     * Remove a consent type
     */
    public function delete(string $key): bool {
        $consent_types = $this->getAll();
        
        if (!isset($consent_types[$key])) {
            return false;
        }

        unset($consent_types[$key]);
        $updated = update_option('gdpr_consent_types', $consent_types);
        
        if ($updated) {
            $this->cache = $consent_types;
            do_action('gdpr_consent_type_deleted', $key);
        }

        return $updated;
    }

    /**
     * Sanitize consent type data
     */
    public function sanitizeConsentData(array $data): array {
        return [
            'label' => sanitize_text_field($data['label'] ?? ''),
            'description' => sanitize_textarea_field($data['description'] ?? ''),
            'required' => !empty($data['required'])
        ];
    }

    /**
     * Get required consent types
     */
    public function getRequired(): array {
        return array_filter($this->getAll(), function($type) {
            return !empty($type['required']);
        });
    }

    /**
     * Clear the cache
     */
    public function clearCache(): void {
        $this->cache = [];
    }
}