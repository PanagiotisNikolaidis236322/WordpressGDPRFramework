<?php
namespace GDPRFramework\Components\UserConsentManager;

use Exception;
use InvalidArgumentException;

class ConsentVersion {
    private $db;
    private $table_name;
    private $version_cache = [];

    /**
     * Initialize the consent version manager
     *
     * @param object $database Database instance
     * @throws InvalidArgumentException If database is invalid
     */
    public function __construct($database) {
        if (!$database || !method_exists($database, 'get_prefix')) {
            throw new InvalidArgumentException('Invalid database instance provided');
        }

        $this->db = $database;
        $this->table_name = $this->db->get_prefix() . 'gdpr_consent_versions';
        
        if (!$this->verifyTable()) {
            $this->createVersionTable();
        }
    }

    /**
     * Get current version for a consent type
     *
     * @param string $type
     * @return string
     * @throws Exception If version creation fails
     */
    public function getCurrentVersion(string $type): string {
        if (empty($type)) {
            throw new InvalidArgumentException('Consent type cannot be empty');
        }
    
        // Validate consent type exists
        $framework = \GDPRFramework\Core\GDPRFramework::getInstance();
        $consent = $framework->getComponent('consent');
        if (!$consent || !$consent->getConsentType()->isValid($type)) {
            throw new \Exception("Invalid consent type: {$type}");
        }
    
        if (isset($this->version_cache[$type])) {
            return $this->version_cache[$type];
        }
    
        try {
            $version = $this->db->get_var(
                $this->db->prepare(
                    "SELECT version FROM {$this->table_name} 
                     WHERE consent_type = %s 
                     ORDER BY created_at DESC 
                     LIMIT 1",
                    $type
                )
            );
    
            if (!$version) {
                $version = $this->createVersion($type);
            }
    
            $this->version_cache[$type] = $version;
            return $version;
    
        } catch (Exception $e) {
            error_log('GDPR Framework - Version Error: ' . $e->getMessage());
            throw new Exception('Failed to retrieve consent version');
        }
    }

    /**
     * Create a new version for a consent type
     *
     * @param string $type
     * @return string
     * @throws Exception If version creation fails
     */
    public function createVersion(string $type): string {
        if (empty($type)) {
            throw new InvalidArgumentException('Consent type cannot be empty');
        }
    
        try {
            $version = $this->generateVersionHash($type);
            
            $table_name = $this->db->getTableName('consent_versions'); // Use proper table name method
            
            $result = $this->db->insert(
                'consent_versions', // Use table suffix only
                [
                    'consent_type' => $type,
                    'version' => $version,
                    'created_at' => current_time('mysql', true),
                    'created_by' => get_current_user_id()
                ],
                ['%s', '%s', '%s', '%d']
            );
    
            if ($result === false) {
                // Use proper error handling
                $error = $this->db->get_last_error();
                throw new \Exception("Failed to create version record: " . $error);
            }
    
            $this->version_cache[$type] = $version;
            
            do_action('gdpr_consent_version_created', $type, $version);
            
            return $version;
    
        } catch (\Exception $e) {
            error_log('GDPR Framework - Version Creation Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get version history for a consent type
     *
     * @param string $type
     * @return array
     */
    public function getVersionHistory(string $type): array {
        if (empty($type)) {
            return [];
        }

        try {
            $results = $this->db->get_results(
                $this->db->prepare(
                    "SELECT version, created_at, created_by, 
                            (SELECT display_name FROM {$this->db->users} WHERE ID = created_by) as creator_name
                     FROM {$this->table_name} 
                     WHERE consent_type = %s 
                     ORDER BY created_at DESC",
                    $type
                )
            );

            return $results ?: [];

        } catch (Exception $e) {
            error_log('GDPR Framework - Version History Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if a user needs to re-consent
     *
     * @param string $type
     * @param string $user_version
     * @return bool
     */
    public function needsReConsent(string $type, string $user_version): bool {
        if (empty($type) || empty($user_version)) {
            return true;
        }

        try {
            $current_version = $this->getCurrentVersion($type);
            return $current_version !== $user_version;
        } catch (Exception $e) {
            error_log('GDPR Framework - Re-consent Check Error: ' . $e->getMessage());
            return true;
        }
    }

    /**
     * Get details for a specific version
     *
     * @param string $type
     * @param string $version
     * @return object|null
     */
    public function getVersionDetails(string $type, string $version): ?object {
        if (empty($type) || empty($version)) {
            return null;
        }

        try {
            return $this->db->get_row(
                $this->db->prepare(
                    "SELECT v.*, u.display_name as creator_name 
                     FROM {$this->table_name} v 
                     LEFT JOIN {$this->db->users} u ON v.created_by = u.ID 
                     WHERE v.consent_type = %s AND v.version = %s",
                    $type,
                    $version
                )
            );
        } catch (Exception $e) {
            error_log('GDPR Framework - Version Details Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate a unique version hash
     *
     * @param string $type
     * @return string
     */
    private function generateVersionHash(string $type): string {
        $data = [
            'type' => $type,
            'timestamp' => current_time('mysql', true),
            'random' => wp_generate_password(16, false),
            'site_id' => get_current_blog_id()
        ];

        return wp_hash(serialize($data));
    }

    /**
     * Clean up old versions
     *
     * @param int $keep_days
     * @return int Number of versions deleted
     */
    public function purgeOldVersions(int $keep_days = 365): int {
        if ($keep_days < 30) {
            $keep_days = 30; // Minimum retention period
        }

        try {
            $result = $this->db->query(
                $this->db->prepare(
                    "DELETE FROM {$this->table_name} 
                     WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                    $keep_days
                )
            );

            return is_numeric($result) ? (int)$result : 0;

        } catch (Exception $e) {
            error_log('GDPR Framework - Version Purge Error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Clear the version cache
     *
     * @param string|null $type
     */
    public function invalidateCache(?string $type = null): void {
        if ($type === null) {
            $this->version_cache = [];
        } else {
            unset($this->version_cache[$type]);
        }
    }

    /**
     * Verify version table exists
     *
     * @return bool
     */
    private function verifyTable(): bool {
        return $this->db->get_var(
            $this->db->prepare(
                "SHOW TABLES LIKE %s",
                $this->table_name
            )
        ) === $this->table_name;
    }

    /**
     * Create version table if it doesn't exist
     */
    private function createVersionTable(): void {
        $charset_collate = $this->db->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            consent_type varchar(50) NOT NULL,
            version char(64) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by bigint(20) unsigned NOT NULL,
            PRIMARY KEY (id),
            KEY consent_type (consent_type),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}