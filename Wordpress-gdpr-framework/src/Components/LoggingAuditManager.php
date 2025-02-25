<?php
namespace GDPRFramework\Components;

class LoggingAuditManager {
    private $db;
    private $settings;
    private $table_name;
    private $debug = false;  // Add this line

    public function __construct($database, $settings) {
        global $wpdb;
        $this->db = $database;
        $this->settings = $settings;
        $this->table_name = $wpdb->prefix . 'gdpr_audit_log';

        // Enable debug mode if WP_DEBUG is enabled
    $this->debug = defined('WP_DEBUG') && WP_DEBUG;
    
    // Initialize hooks
    $this->initializeHooks();
    
    // Verify table exists
    $this->verifyTable();

    if ($this->debug) {
        error_log("GDPR Audit: Constructor completed successfully");
    }
    }

    private function initializeHooks() {
    // Existing hooks
    add_action('admin_init', [$this, 'registerSettings']);
    add_action('admin_init', [$this, 'addCustomCapabilities']);
    add_action('init', [$this, 'registerShortcodes']);

    add_action('admin_post_gdpr_export_audit_log', [$this, 'handleExport']);
    
    // Consent hooks
    add_action('gdpr_consent_updated', [$this, 'logConsentUpdate'], 10, 3);
    add_action('gdpr_consent_recorded', [$this, 'logConsentRecord'], 10, 3);
    add_action('gdpr_consent_update_failed', [$this, 'logConsentUpdateFailure'], 10, 3);

    // Data portability hooks
    add_action('gdpr_data_exported', [$this, 'logDataExport'], 10, 2);
    add_action('gdpr_data_erased', [$this, 'logDataErasure'], 10, 2);

    // Security hooks
    add_action('gdpr_key_rotated', [$this, 'logKeyRotation'], 10, 1);
    add_action('gdpr_key_rotation_failed', [$this, 'logKeyRotationFailure'], 10, 2);
    add_action('gdpr_data_reencrypted', [$this, 'logDataReencryption'], 10, 1);
    add_action('gdpr_data_reencryption_failed', [$this, 'logDataReencryptionFailure'], 10, 2);

    // Access control hooks
    add_action('gdpr_successful_login', [$this, 'logSuccessfulLogin'], 10, 1);
    add_action('gdpr_failed_login', [$this, 'logFailedLogin'], 10, 2);
    add_action('gdpr_account_locked', [$this, 'logAccountLockout'], 10, 2);
    add_action('gdpr_login_blocked', [$this, 'logLoginBlocked'], 10, 2);

    // Add the clear audit log handler
    add_action('admin_init', [$this, 'handleClearAuditLog']);


   // Add debug action
    /*
   if ($this->debug) {
    add_action('admin_notices', [$this, 'displayDebugInfo']);
}
    */
}

private function verifyTable() {
    try {
        $table_exists = $this->db->query(
            "SHOW TABLES LIKE '{$this->table_name}'"
        );

        if (empty($table_exists)) {
            throw new \Exception("Audit log table does not exist: {$this->table_name}");
        }

        // Verify table structure
        $columns = $this->db->query("DESCRIBE {$this->table_name}");
        $required_columns = [
            'id', 'user_id', 'action', 'details', 'severity', 
            'ip_address', 'user_agent', 'timestamp'
        ];

        $existing_columns = array_map(function($col) {
            return $col->Field;
        }, $columns);

        $missing_columns = array_diff($required_columns, $existing_columns);

        if (!empty($missing_columns)) {
            throw new \Exception("Missing columns in audit log table: " . implode(', ', $missing_columns));
        }

        if ($this->debug) {
            error_log("GDPR Audit: Table verification successful - {$this->table_name}");
        }

    } catch (\Exception $e) {
        error_log("GDPR Audit Error: " . $e->getMessage());
        if ($this->debug) {
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error"><p>GDPR Audit Error: ' . 
                     esc_html($e->getMessage()) . '</p></div>';
            });
        }
    }
}

 /**
     * Add custom capabilities for GDPR officers
     */
    public function addCustomCapabilities() {
        $roles = ['administrator', 'editor']; // Add roles that should have access
        foreach ($roles as $role_name) {
            $role = get_role($role_name);
            if ($role) {
                $role->add_cap('view_gdpr_audit_log');
            }
        }
    }

    public function registerSettings() {
        register_setting('gdpr_framework_settings', 'gdpr_audit_retention_days', [
            'type' => 'integer',
            'default' => 365,
            'sanitize_callback' => [$this, 'sanitizeRetentionDays']
        ]);
    }

    public function sanitizeRetentionDays($days) {
        $days = absint($days);
        return $days < 30 ? 30 : $days;
    }

     /**
 * Enhanced log method with privacy features
 */
public function log($user_id, $action, $details = '', $severity = 'low', $ip_address = '') {
    try {
        if (empty($ip_address)) {
            $ip_address = $this->getClientIP();
        }

        $data = [
            'user_id' => $user_id,
            'action' => $this->sanitizeAction($action),
            'details' => $this->filterSensitiveData($details),
            'severity' => $this->validateSeverity($severity),
            'ip_address' => $this->anonymizeIP($ip_address),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? 
                substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT']), 0, 255) : '',
            'timestamp' => current_time('mysql')
        ];

        if ($this->debug) {
            error_log("GDPR Audit: Attempting to log entry - " . wp_json_encode($data));
        }

        $result = $this->db->insert(
            'audit_log',
            $data,
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($result === false) {
            throw new \Exception($this->db->get_last_error());
        }

        if ($this->debug) {
            error_log("GDPR Audit: Entry logged successfully - ID: " . $this->db->insert_id);
        }

        return true;

    } catch (\Exception $e) {
        error_log("GDPR Audit Error: Failed to log entry - " . $e->getMessage());
        if ($this->debug) {
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error"><p>GDPR Audit Error: ' . 
                     esc_html($e->getMessage()) . '</p></div>';
            });
        }
        return false;
    }
}

public function clearAuditLog() {
    try {
        $this->db->query("TRUNCATE TABLE {$this->table_name}");
        
        // Log that the audit log was cleared
        $this->log(
            get_current_user_id(),
            'audit_log_cleared',
            'Audit log was cleared manually by administrator',
            'high'
        );
        
        return true;
    } catch (\Exception $e) {
        error_log("GDPR Audit Error: Failed to clear audit log - " . $e->getMessage());
        return false;
    }
}

    /**
     * Register shortcode handler
     */
    public function registerShortcodes() {
        add_shortcode('gdpr_audit_log', [$this, 'renderAuditLogShortcode']);
    }

    public function renderAuditLogShortcode($atts) {
        $atts = shortcode_atts([
            'view' => 'own',
            'limit' => 10,
            'page' => 1
        ], $atts);

        if (!$this->checkPermission(get_current_user_id())) {
            return '<p>' . __('You do not have permission to view audit logs.', 'wp-gdpr-framework') . '</p>';
        }

        $args = [
            'limit' => absint($atts['limit']),
            'offset' => (absint($atts['page']) - 1) * absint($atts['limit'])
        ];

        if ($atts['view'] !== 'all' || !current_user_can('view_gdpr_audit_log')) {
            $args['user_id'] = get_current_user_id();
        }

        $result = $this->getAuditLog($args);
        
        // Load template
        ob_start();
        include(GDPR_FRAMEWORK_TEMPLATE_PATH . 'public/audit-log.php');
        return ob_get_clean();
    }

    /**
 * Handle audit log export
 */
public function handleExport() {
    check_admin_referer('gdpr_export_audit_log');

    if (!current_user_can('view_gdpr_audit_log')) {
        wp_die(__('You do not have permission to export audit logs.', 'wp-gdpr-framework'));
    }

    $args = [
        'from_date' => $_GET['from_date'] ?? null,
        'to_date' => $_GET['to_date'] ?? null,
        'severity' => $_GET['severity'] ?? null,
        'limit' => 5000 // Reasonable limit for export
    ];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=gdpr-audit-log-' . date('Y-m-d') . '.csv');
    
    $this->exportLogs($args);
    exit;
}

/**
 * Export logs to CSV
 */
public function exportLogs($args = []) {
    if (!current_user_can('view_gdpr_audit_log')) {
        return false;
    }
    
    $result = $this->getAuditLog($args);
    $logs = $result['logs'];
    
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for proper Excel handling
    fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    // Write headers
    fputcsv($output, [
        __('Date', 'wp-gdpr-framework'),
        __('User', 'wp-gdpr-framework'),
        __('Action', 'wp-gdpr-framework'),
        __('Details', 'wp-gdpr-framework'),
        __('Severity', 'wp-gdpr-framework'),
        __('IP Address', 'wp-gdpr-framework')
    ]);
    
    // Write data
    foreach ($logs as $log) {
        $user_name = '';
        if ($log->user_id) {
            $user = get_userdata($log->user_id);
            $user_name = $user ? $user->display_name : __('Deleted User', 'wp-gdpr-framework');
        } else {
            $user_name = __('System', 'wp-gdpr-framework');
        }
        
        fputcsv($output, [
            wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log->timestamp)),
            $user_name,
            $log->action,
            $log->details,
            $log->severity,
            $log->ip_address
        ]);
    }
    
    fclose($output);
    return true;
}

    /**
     * Private helper methods
     */
    private function checkPermission($user_id = null) {
        if (!is_user_logged_in()) {
            return false;
        }
        
        if (current_user_can('view_gdpr_audit_log')) {
            return true;
        }
        
        return $user_id && $user_id === get_current_user_id();
    }

    private function anonymizeIP($ip) {
        if (empty($ip)) {
            return '';
        }
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\d+$/', '0', $ip);
        }
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return substr($ip, 0, strrpos($ip, ':')) . ':0000';
        }
        
        return '';
    }

    private function sanitizeUserAgent($user_agent) {
        return substr(sanitize_text_field($user_agent), 0, 255);
    }

    private function filterSensitiveData($details) {
        if (is_array($details)) {
            $details = wp_json_encode($details);
        }
        
        // Remove potential sensitive data patterns
        $patterns = [
            '/\b[\w\.-]+@[\w\.-]+\.\w{2,4}\b/', // Email addresses
            '/\b(?:\d[ -]*?){13,16}\b/', // Credit card numbers
            '/password[s]?\s*[:=]\s*[^\s,;]+/i', // Passwords
        ];
        
        return preg_replace($patterns, '[REDACTED]', $details);
    }

    private function sanitizeAction($action) {
        return sanitize_key($action);
    }

    private function validateSeverity($severity) {
        $allowed = ['low', 'medium', 'high'];
        return in_array($severity, $allowed) ? $severity : 'low';
    }
    
    public function logEvent($action, $user_id = null, $details = [], $severity = 'low') {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }
    
        $formatted_details = is_array($details) ? wp_json_encode($details) : $details;
    
        return $this->log(
            $user_id,
            $action,
            $formatted_details,
            $severity,
            $this->getClientIP()
        );
    }
    
    private function getClientIP() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ?: '';
    }

    /**
     * Get recent activities for dashboard
     */
    public function getRecentActivities($limit = 5) {
        return $this->db->get_results($this->db->prepare(
            "SELECT l.*, u.display_name 
             FROM {$this->table_name} l 
             LEFT JOIN {$this->db->get_prefix()}users u ON l.user_id = u.ID 
             ORDER BY timestamp DESC 
             LIMIT %d",
            $limit
        ));
    }

    /**
 * Get audit log entries with filtering
 */
public function getAuditLog($args = []) { if ($this->debug) {
    error_log("GDPR Audit: Starting getAuditLog with args: " . wp_json_encode($args)); }

    try {
        $defaults = [
            'user_id' => null,
            'action' => null,
            'severity' => null,
            'from_date' => null,
            'to_date' => null,
            'limit' => 50,
            'offset' => 0,
            'orderby' => 'timestamp',
            'order' => 'DESC'
        ];

        $args = wp_parse_args($args, $defaults);

    
        // Build query
        $where = [];
        $params = [];

        if (!empty($args['user_id'])) {
            $where[] = 'user_id = %d';
            $params[] = $args['user_id'];
        }

        if (!empty($args['severity'])) {
            $where[] = 'severity = %s';
            $params[] = $args['severity'];
        }

        if (!empty($args['from_date'])) {
            $where[] = 'timestamp >= %s';
            $params[] = $args['from_date'];
        }

        if (!empty($args['to_date'])) {
            $where[] = 'timestamp <= %s';
            $params[] = $args['to_date'];
        }

        $query = "SELECT SQL_CALC_FOUND_ROWS l.*, u.display_name 
                 FROM {$this->table_name} l 
                 LEFT JOIN {$this->db->get_prefix()}users u ON l.user_id = u.ID";

        if (!empty($where)) {
            $query .= ' WHERE ' . implode(' AND ', $where);
        }

        $query .= " ORDER BY {$args['orderby']} {$args['order']}";
        
        if (!empty($args['limit'])) {
            $query .= ' LIMIT %d OFFSET %d';
            array_push($params, $args['limit'], $args['offset']);
        }

        if ($this->debug) {
            error_log("GDPR Audit: Executing query - " . $this->db->prepare($query, $params));
        }

        $logs = $this->db->get_results(
            !empty($params) ? $this->db->prepare($query, $params) : $query
        );

        $total = $this->db->get_var('SELECT FOUND_ROWS()');

        if ($this->debug) {
            error_log("GDPR Audit: Found {$total} entries");
        }

        return [
            'logs' => $logs,
            'total' => (int)$total,
            'pages' => ceil($total / max(1, $args['limit']))
        ];

    } catch (\Exception $e) {
        error_log("GDPR Audit Error: Failed to retrieve logs - " . $e->getMessage());
        return ['logs' => [], 'total' => 0, 'pages' => 0];
    }
}

public function displayDebugInfo() {
    global $wpdb;
    $table_name = $this->table_name;
    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
    
    echo '<div class="notice notice-info is-dismissible">';
    echo '<p><strong>GDPR Audit Debug Info:</strong></p>';
    echo '<ul>';
    echo '<li>Table Name: ' . esc_html($table_name) . '</li>';
    echo '<li>Total Entries: ' . esc_html($count) . '</li>';
    echo '<li>Last Query: ' . esc_html($wpdb->last_query) . '</li>';
    if ($wpdb->last_error) {
        echo '<li>Last Error: ' . esc_html($wpdb->last_error) . '</li>';
    }
    echo '</ul>';
    echo '</div>';
}

    /**
     * Log consent update
     */
    public function logConsentUpdate($user_id, $consent_type, $status) {
        $status_text = $status ? 'granted' : 'withdrawn';
        $this->log(
            $user_id,
            'consent_update',
            sprintf(
                __('User %d %s consent for %s', 'wp-gdpr-framework'),
                $user_id,
                $status_text,
                $consent_type
            ),
            'medium'
        );
    }

    /**
     * Log data export
     */
    public function logDataExport($user_id, $request_id) {
        $this->log(
            $user_id,
            'data_export',
            sprintf(
                __('Data exported for user %d (Request ID: %d)', 'wp-gdpr-framework'),
                $user_id,
                $request_id
            ),
            'medium'
        );
    }

    /**
     * Log data erasure
     */
    public function logDataErasure($user_id, $request_id) {
        $this->log(
            $user_id,
            'data_erasure',
            sprintf(
                __('Data erased for user %d (Request ID: %d)', 'wp-gdpr-framework'),
                $user_id,
                $request_id
            ),
            'high'
        );
    }

    /**
     * Log key rotation
     */
    public function logKeyRotation($admin_id) {
        $this->log(
            $admin_id,
            'key_rotation',
            __('Encryption key rotated', 'wp-gdpr-framework'),
            'high'
        );
    }

    /**
     * Clean old audit logs
     */
    public function cleanupOldLogs() {
        $retention_days = get_option('gdpr_audit_retention_days', 365);
        
        return $this->db->query($this->db->prepare(
            "DELETE FROM {$this->table_name} 
             WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $retention_days
        ));
    }

    /**
     * Export audit logs for a user
     */
    public function exportUserLogs($user_id) {
        return $this->db->get_results($this->db->prepare(
            "SELECT action, details, severity, ip_address, timestamp 
             FROM {$this->table_name} 
             WHERE user_id = %d 
             ORDER BY timestamp DESC",
            $user_id
        ));
    }

    public function logConsentRecord($user_id, $consent_type, $status) {
        $this->log(
            $user_id,
            'consent_recorded',
            sprintf(
                __('Consent recorded for %s: %s', 'wp-gdpr-framework'),
                $consent_type,
                $status ? __('Granted', 'wp-gdpr-framework') : __('Withdrawn', 'wp-gdpr-framework')
            ),
            'medium'
        );
    }
    
    public function logConsentUpdateFailure($user_id, $consent_type, $error) {
        $this->log(
            $user_id,
            'consent_update_failed',
            sprintf(
                __('Failed to update consent for %s: %s', 'wp-gdpr-framework'),
                $consent_type,
                $error
            ),
            'high'
        );
    }
    
    public function logKeyRotationFailure($admin_id, $error) {
        $this->log(
            $admin_id,
            'key_rotation_failed',
            sprintf(
                __('Key rotation failed: %s', 'wp-gdpr-framework'),
                $error
            ),
            'high'
        );
    }
    
    public function logDataReencryption($admin_id) {
        $this->log(
            $admin_id,
            'data_reencrypted',
            __('All sensitive data re-encrypted with new key', 'wp-gdpr-framework'),
            'high'
        );
    }
    
    public function logDataReencryptionFailure($admin_id, $error) {
        $this->log(
            $admin_id,
            'data_reencryption_failed',
            sprintf(
                __('Data re-encryption failed: %s', 'wp-gdpr-framework'),
                $error
            ),
            'high'
        );
    }
    
    public function logSuccessfulLogin($user_id) {
        $this->log(
            $user_id,
            'successful_login',
            __('Successful login', 'wp-gdpr-framework'),
            'low'
        );
    }
    
    public function logLoginBlocked($user_id, $data) {
        $this->log(
            $user_id,
            'login_blocked',
            sprintf(
                __('Login attempt blocked. Reason: %s', 'wp-gdpr-framework'),
                $data['reason']
            ),
            'medium',
            $data['ip_address']
        );
    }
    
    public function logAccountLockout($user_id, $data) {
        $this->log(
            $user_id,
            'account_lockout',
            sprintf(
                __('Account locked for %d minutes due to too many failed attempts', 'wp-gdpr-framework'),
                ceil($data['duration'] / 60)
            ),
            'high',
            $data['ip_address']
        );
    }

    public function logFailedLogin($user_id, $data) {
        $this->log(
            $user_id,
            'failed_login',
            sprintf(
                __('Failed login attempt from IP: %s, Username: %s', 'wp-gdpr-framework'),
                $data['ip_address'],
                $data['username']
            ),
            'medium',
            $data['ip_address']
        );
    }
    
/**
 * Log an action (Developer API)
 *
 * @param int $user_id User ID
 * @param string $action Action name
 * @param array|string $details Action details
 * @param string $severity Severity level (low, medium, high)
 * @return bool Success status
 */
public function log_action($user_id, $action, $details = [], $severity = 'low') {
    // Convert severity from 'info' to our internal levels
    $severity_map = [
        'info' => 'low',
        'warning' => 'medium',
        'error' => 'high'
    ];
    
    $severity = $severity_map[$severity] ?? $severity;
    
    return $this->log(
        $user_id,
        $action,
        is_array($details) ? wp_json_encode($details) : $details,
        $severity
    );
}

public function handleClearAuditLog() {
    // Debug log
    error_log('GDPR Audit: handleClearAuditLog called');
    
    // Check if the clear action was triggered
    if (!isset($_POST['clear_audit_log'])) {
        error_log('GDPR Audit: clear_audit_log not set in POST');
        return;
    }

    // Debug log
    error_log('GDPR Audit: clear_audit_log found in POST');

    // Verify nonce and capabilities
    if (!isset($_POST['clear_audit_nonce']) || 
        !wp_verify_nonce($_POST['clear_audit_nonce'], 'gdpr_clear_audit_log')) {
        error_log('GDPR Audit: nonce verification failed');
        wp_die(__('Security check failed.', 'wp-gdpr-framework'));
    }

    if (!current_user_can('manage_options')) {
        error_log('GDPR Audit: user lacks manage_options capability');
        wp_die(__('Security check failed.', 'wp-gdpr-framework'));
    }

    error_log('GDPR Audit: attempting to clear audit log');
    
    if ($this->clearAuditLog()) {
        error_log('GDPR Audit: audit log cleared successfully');
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 esc_html__('Audit log cleared successfully.', 'wp-gdpr-framework') . 
                 '</p></div>';
        });
    } else {
        error_log('GDPR Audit: failed to clear audit log');
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error is-dismissible"><p>' . 
                 esc_html__('Failed to clear audit log.', 'wp-gdpr-framework') . 
                 '</p></div>';
        });
    }
}
    /**
     * Get audit log statistics
     */
    public function getStats() {
        $stats = [
            'total_entries' => 0,
            'by_severity' => [
                'low' => 0,
                'medium' => 0,
                'high' => 0
            ],
            'recent_high_severity' => []
        ];

        // Get total entries
        $stats['total_entries'] = $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->table_name}"
        );

        // Get counts by severity
        $severity_counts = $this->db->get_results(
            "SELECT severity, COUNT(*) as count 
             FROM {$this->table_name} 
             GROUP BY severity"
        );

        foreach ($severity_counts as $count) {
            $stats['by_severity'][$count->severity] = (int)$count->count;
        }

        // Get recent high severity events
        $stats['recent_high_severity'] = $this->db->get_results(
            "SELECT * FROM {$this->table_name} 
             WHERE severity = 'high' 
             ORDER BY timestamp DESC 
             LIMIT 5"
        );

        return $stats;
    }
}