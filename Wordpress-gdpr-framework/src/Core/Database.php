<?php
namespace GDPRFramework\Core;

class Database {
    protected $wpdb;
    protected $tables;
    public $insert_id;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->defineTables();
    }

    private function defineTables() {
        $charset_collate = $this->wpdb->get_charset_collate();
        $prefix = $this->wpdb->prefix . 'gdpr_';
        
        $this->tables = [
            'user_consents' => "CREATE TABLE IF NOT EXISTS {$prefix}user_consents (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    user_id bigint(20) unsigned NOT NULL,
                    consent_type varchar(50) NOT NULL,
                    status tinyint(1) NOT NULL DEFAULT 0,
                    ip_address varchar(45),
                    user_agent text,
                    timestamp datetime DEFAULT CURRENT_TIMESTAMP,
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
                    KEY user_id (user_id)
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
                    KEY user_id (user_id)
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
                    KEY ip_address (ip_address)
                ) $charset_collate"
        ];
    }

    public function createTables() {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        foreach ($this->tables as $sql) {
            dbDelta($sql);
        }
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

    public function verifyTables() {
        $errors = [];
        $prefix = $this->wpdb->prefix . 'gdpr_';
        
        foreach (array_keys($this->tables) as $table) {
            $table_name = $prefix . $table;
            $table_exists = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SHOW TABLES LIKE %s",
                    $table_name
                )
            );
            
            if (!$table_exists) {
                $errors[] = "Table {$table_name} missing";
                error_log("GDPR Framework: Missing table {$table_name}");
            }
        }
        
        return empty($errors);
    }
}