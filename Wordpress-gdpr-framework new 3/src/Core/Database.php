<?php
namespace GDPRFramework\Core;

class Database {
    protected $wpdb;
    protected $tables;
    public $insert_id;
    private $last_error;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->defineTables();
    }

    protected function defineTables() {
        $charset_collate = $this->wpdb->get_charset_collate();
        $prefix = $this->wpdb->prefix . 'gdpr_';
        
        $this->tables = [
            'consent_types' => "CREATE TABLE IF NOT EXISTS {$prefix}consent_types (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                type_key varchar(50) NOT NULL,
                label varchar(255) NOT NULL,
                description text NOT NULL,
                required tinyint(1) NOT NULL DEFAULT 0,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY type_key (type_key)
            ) $charset_collate",
            
            'user_consents' => "CREATE TABLE IF NOT EXISTS {$prefix}user_consents (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NOT NULL,
                consent_type varchar(50) NOT NULL,
                status tinyint(1) NOT NULL DEFAULT 0,
                version char(64) NOT NULL,
                ip_address varchar(45),
                user_agent text,
                timestamp datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY user_id (user_id),
                KEY consent_type (consent_type),
                KEY version (version)
            ) $charset_collate",
                
            'consent_versions' => "CREATE TABLE IF NOT EXISTS {$prefix}consent_versions (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                consent_type varchar(50) NOT NULL,
                version char(64) NOT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                created_by bigint(20) unsigned NOT NULL,
                PRIMARY KEY (id),
                KEY consent_type (consent_type),
                KEY version (version)
            ) $charset_collate",
    
            'consent_log' => "CREATE TABLE IF NOT EXISTS {$prefix}consent_log (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NOT NULL,
                consent_type varchar(50) NOT NULL,
                status tinyint(1) NOT NULL,
                version char(64) NOT NULL,
                ip_address varchar(45),
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY user_id (user_id),
                KEY consent_type (consent_type)
            ) $charset_collate",
                    
            'user_data' => "CREATE TABLE IF NOT EXISTS {$prefix}user_data (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NOT NULL,
                data_type varchar(50) NOT NULL,
                encrypted_data text NOT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY user_id (user_id)
            ) $charset_collate",
                    
            'audit_log' => "CREATE TABLE IF NOT EXISTS {$prefix}audit_log (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NULL,
                action varchar(100) NOT NULL,
                details text,
                severity enum('low', 'medium', 'high') DEFAULT 'low',
                ip_address varchar(45),
                user_agent text,
                timestamp datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY user_id (user_id),
                KEY severity (severity),
                KEY timestamp (timestamp)
            ) $charset_collate",
                    
            'data_requests' => "CREATE TABLE IF NOT EXISTS {$prefix}data_requests (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NOT NULL,
                request_type enum('export', 'erasure') NOT NULL,
                status enum('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                completed_at datetime NULL,
                details text,
                PRIMARY KEY (id),
                KEY user_id (user_id),
                KEY status (status)
            ) $charset_collate",
    
            'login_log' => "CREATE TABLE IF NOT EXISTS {$prefix}login_log (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NULL,
                success tinyint(1) NOT NULL DEFAULT 0,
                ip_address varchar(45) NOT NULL,
                user_agent text,
                timestamp datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY user_id (user_id),
                KEY ip_address (ip_address),
                KEY timestamp (timestamp)
            ) $charset_collate"
        ];
    }

    /**
 * Get column from query results
 *
 * @param string|array $query Query or array containing query and parameters
 * @param int $column_offset Optional. Column to return. Indexed from 0.
 * @return array Array of column values
 */
public function get_col($query, $column_offset = 0) {
    try {
        if (is_array($query)) {
            $result = $this->wpdb->get_col(
                $this->prepare($query[0], array_slice($query, 1)),
                $column_offset
            );
        } else {
            $result = $this->wpdb->get_col($query, $column_offset);
        }

        return $result ?: [];

    } catch (\Exception $e) {
        error_log('GDPR Framework - Database Error: ' . $e->getMessage());
        return [];
    }
}

    public function createTables() {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $errors = [];
        try {
            foreach ($this->tables as $table_name => $sql) {
                // Log the SQL for debugging
                error_log("GDPR Framework - Creating table: {$table_name}");
                error_log("SQL: {$sql}");
                
                $result = dbDelta($sql);
                
                // Verify table was created
                $table = $this->wpdb->prefix . 'gdpr_' . $table_name;
                if (!$this->tableExists($table)) {
                    $errors[] = "Failed to create table: {$table}";
                }
            }
            
            if (!empty($errors)) {
                throw new \Exception(implode("\n", $errors));
            }
            
            error_log('GDPR Framework - All tables created successfully');
            return true;
            
        } catch (\Exception $e) {
            error_log('GDPR Framework - Database Creation Error: ' . $e->getMessage());
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    public function verifyAndCreateTables() {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $results = [];
        $success = true;
        
        error_log('GDPR Framework - Starting table verification and creation');
        
        foreach ($this->tables as $table => $sql) {
            $table_name = $this->wpdb->prefix . 'gdpr_' . $table;
            error_log("GDPR Framework - Checking table: {$table_name}");
            
            if (!$this->tableExists($table_name)) {
                error_log("GDPR Framework - Table {$table_name} doesn't exist, creating...");
                error_log("GDPR Framework - SQL: {$sql}");
                
                // Use dbDelta instead of direct query
                $dbdelta_result = dbDelta($sql);
                error_log("GDPR Framework - dbDelta result: " . print_r($dbdelta_result, true));
                
                // Verify table was created
                if (!$this->tableExists($table_name)) {
                    error_log("GDPR Framework - Failed to create table: {$table_name}");
                    error_log("GDPR Framework - Last SQL error: " . $this->wpdb->last_error);
                    $success = false;
                    $results[$table] = false;
                } else {
                    error_log("GDPR Framework - Successfully created table: {$table_name}");
                    $results[$table] = true;
                }
            } else {
                error_log("GDPR Framework - Table {$table_name} already exists");
                $results[$table] = true;
            }
        }
        
        error_log('GDPR Framework - Table creation results: ' . print_r($results, true));
        return $success;
    }

    private function verifyTableStructure($table_name) {
        $result = $this->wpdb->get_results("SHOW CREATE TABLE {$table_name}", ARRAY_A);
        if (empty($result)) {
            error_log("GDPR Framework - Failed to get table structure for {$table_name}");
            return false;
        }
        error_log("GDPR Framework - Table structure for {$table_name}: " . print_r($result[0], true));
        return true;
    }

    private function tableExists($table) {
        return $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $table
            )
        ) === $table;
    }

    public function getTableName($table) {
        return $this->wpdb->prefix . 'gdpr_' . $table;
    }


    public function insert($table_suffix, $data, $format = null) {
        $table_name = $this->wpdb->prefix . 'gdpr_' . $table_suffix;
        error_log('GDPR Framework - Attempting to insert into table: ' . $table_name);
        
        $result = $this->wpdb->insert($table_name, $data, $format);
        
        if ($result === false) {
            error_log('GDPR Framework - Database Insert Error: ' . $this->wpdb->last_error);
            return false;
        }
        
        $this->insert_id = $this->wpdb->insert_id;
        return true;
    }
    
    public function update($table_suffix, $data, $where, $format = null, $where_format = null) {
        $table_name = $this->wpdb->prefix . 'gdpr_' . $table_suffix;
        return $this->wpdb->update($table_name, $data, $where, $format, $where_format);
    }

    public function delete($table_suffix, $where, $where_format = null) {
        $table_name = $this->wpdb->prefix . 'gdpr_' . $table_suffix;
        return $this->wpdb->delete($table_name, $where, $where_format);
    }

    public function get_var($query, $args = []) {
        if (!empty($args)) {
            return $this->wpdb->get_var($this->prepare($query, ...$args));
        }
        return $this->wpdb->get_var($query);
    }

    public function get_row($query, $args = [], $output = OBJECT) {
        if (is_array($query)) {
            return $this->wpdb->get_row($this->prepare($query[0], array_slice($query, 1)), $output);
        }
        return $this->wpdb->get_row($query, $output);
    }

    public function get_results($query, $output = OBJECT) {
        if (is_array($query)) {
            return $this->wpdb->get_results($this->prepare($query[0], array_slice($query, 1)), $output);
        }
        return $this->wpdb->get_results($query, $output);
    }

    public function prepare($query, ...$args) {
        return $this->wpdb->prepare($query, ...$args);
    }

    public function query($query, $args = []) {
        if (!empty($args)) {
            return $this->wpdb->get_results($this->prepare($query, $args));
        }
        return $this->wpdb->get_results($query);
    }

    public function get_prefix() {
        return $this->wpdb->prefix;
    }

    public function get_last_error() {
        return $this->wpdb->last_error;
    }

    public function beginTransaction() {
        $this->wpdb->query('START TRANSACTION');
    }

    public function commit() {
        $this->wpdb->query('COMMIT');
    }

    public function rollback() {
        $this->wpdb->query('ROLLBACK');
    }

    // Add method to get user consents with versions
public function getUserConsentsWithVersions(int $user_id): array {
    $user_consents_table = $this->getTableName('user_consents');
    $consent_versions_table = $this->getTableName('consent_versions');
    
    return $this->wpdb->get_results(
        $this->wpdb->prepare(
            "SELECT uc.*, cv.created_at as version_created_at, 
                    u.display_name as version_creator_name
             FROM {$user_consents_table} uc
             LEFT JOIN {$consent_versions_table} cv 
                ON uc.consent_type = cv.consent_type 
                AND uc.version = cv.version
             LEFT JOIN {$this->wpdb->users} u ON cv.created_by = u.ID
             WHERE uc.user_id = %d
             ORDER BY uc.timestamp DESC",
            $user_id
        )
    );
}

// Add method to cleanup orphaned records
public function cleanupOrphanedRecords(): array {
    $results = [
        'orphaned_consents' => 0,
        'invalid_versions' => 0
    ];

    try {
        $user_consents_table = $this->getTableName('user_consents');
        
        // Delete orphaned consents
        $results['orphaned_consents'] = $this->wpdb->query("
            DELETE uc FROM {$user_consents_table} uc
            LEFT JOIN {$this->wpdb->users} u ON uc.user_id = u.ID
            WHERE u.ID IS NULL
        ");

        // Delete consents with invalid versions
        $consent_versions_table = $this->getTableName('consent_versions');
        $results['invalid_versions'] = $this->wpdb->query("
            DELETE uc FROM {$user_consents_table} uc
            LEFT JOIN {$consent_versions_table} cv 
                ON uc.consent_type = cv.consent_type 
                AND uc.version = cv.version
            WHERE cv.id IS NULL
        ");

        return $results;
    } catch (\Exception $e) {
        error_log('GDPR Framework - Cleanup Error: ' . $e->getMessage());
        return $results;
    }
}

    // Add version specific methods
    public function getConsentVersions($type = null) {
        $table = $this->getTableName('consent_versions');
        if ($type) {
            return $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT cv.*, u.display_name as creator_name 
                     FROM {$table} cv
                     LEFT JOIN {$this->wpdb->users} u ON cv.created_by = u.ID
                     WHERE cv.consent_type = %s 
                     ORDER BY cv.created_at DESC",
                    $type
                )
            );
        }
        return $this->wpdb->get_results(
            "SELECT cv.*, u.display_name as creator_name 
             FROM {$table} cv
             LEFT JOIN {$this->wpdb->users} u ON cv.created_by = u.ID
             ORDER BY cv.created_at DESC"
        );
    }

    public function verifyConsentIntegrity(): bool {
        try {
            // Check for orphaned consents (no corresponding user)
            $user_consents_table = $this->getTableName('user_consents');
            $orphaned_consents = $this->wpdb->get_var("
                SELECT COUNT(*) FROM {$user_consents_table} uc
                LEFT JOIN {$this->wpdb->users} u ON uc.user_id = u.ID
                WHERE u.ID IS NULL
            ");
    
            if ($orphaned_consents > 0) {
                error_log("GDPR Framework: Found {$orphaned_consents} orphaned consent records");
                return false;
            }
    
            // Check for invalid versions
            $consent_versions_table = $this->getTableName('consent_versions');
            $invalid_versions = $this->wpdb->get_var("
                SELECT COUNT(*) FROM {$user_consents_table} uc
                LEFT JOIN {$consent_versions_table} cv 
                    ON uc.consent_type = cv.consent_type 
                    AND uc.version = cv.version
                WHERE cv.id IS NULL
            ");
    
            if ($invalid_versions > 0) {
                error_log("GDPR Framework: Found {$invalid_versions} invalid version references");
                return false;
            }
    
            return true;
        } catch (\Exception $e) {
            error_log('GDPR Framework - Consent Integrity Check Error: ' . $e->getMessage());
            return false;
        }
    }

    public function verifyTables() {
        $prefix = $this->wpdb->prefix . 'gdpr_';
        $all_exist = true;
        
        foreach (array_keys($this->tables) as $table) {
            $table_name = $prefix . $table;
            if (!$this->tableExists($table_name)) {
                error_log("GDPR Framework: Missing table {$table_name}");
                $all_exist = false;
            }
        }
        
        return $all_exist;
    }
}