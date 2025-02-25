<?php
namespace GDPRFramework\Components\AccessControlManager;

class AccessControlManager {
    private $db;
    private $settings;
    private $session_duration = 3600; // 1 hour
    private $max_attempts = 5;
    private $lockout_duration = 900; // 15 minutes
    private $table_name;

    public function __construct($database, $settings) {
        $this->db = $database;
        $this->settings = $settings;
        $this->table_name = $this->db->get_prefix() . 'gdpr_login_log';

        // Get settings values
        $this->max_attempts = get_option('gdpr_max_login_attempts', 5);
        $this->lockout_duration = get_option('gdpr_lockout_duration', 900);

        // Initialize hooks
        add_action('init', [$this, 'initializeSession']);
        add_action('wp_login', [$this, 'logSuccessfulLogin'], 10, 2);
        add_action('wp_login_failed', [$this, 'logFailedLogin']);
        add_filter('authenticate', [$this, 'checkLoginAttempts'], 30, 3);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function registerSettings() {
        // Register settings for max attempts
        register_setting(
            'gdpr_framework_settings',
            'gdpr_max_login_attempts',
            [
                'type' => 'integer',
                'default' => 5,
                'sanitize_callback' => 'absint'
            ]
        );

        // Register settings for lockout duration
        register_setting(
            'gdpr_framework_settings', 
            'gdpr_lockout_duration',
            [
                'type' => 'integer',
                'default' => 900,
                'sanitize_callback' => 'absint'
            ]
        );

        // Add settings section and fields
        add_settings_section(
            'gdpr_access_control_section',
            __('Access Control Settings', 'wp-gdpr-framework'),
            [$this, 'renderAccessControlSection'],
            'gdpr_framework_settings'
        );

        add_settings_field(
            'gdpr_max_login_attempts',
            __('Maximum Login Attempts', 'wp-gdpr-framework'),
            [$this, 'renderMaxAttemptsField'],
            'gdpr_framework_settings',
            'gdpr_access_control_section'
        );

        add_settings_field(
            'gdpr_lockout_duration',
            __('Lockout Duration', 'wp-gdpr-framework'),
            [$this, 'renderLockoutDurationField'],
            'gdpr_framework_settings',
            'gdpr_access_control_section'
        );
    }

    public function initializeSession() {
        if (!session_id() && !headers_sent()) {
            session_set_cookie_params([
                'lifetime' => $this->session_duration,
                'path' => COOKIEPATH,
                'domain' => COOKIE_DOMAIN,
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
            session_start();
        }
    }

    public function checkLoginAttempts($user, $username, $password) {
        if (!empty($username)) {
            $ip = $this->getClientIP();
            $is_locked = $this->isIPLocked($ip);

            if ($is_locked) {
                return new \WP_Error(
                    'locked_out',
                    sprintf(
                        __('Too many failed login attempts. Please try again in %d minutes.', 'wp-gdpr-framework'),
                        ceil($this->lockout_duration / 60)
                    )
                );
            }
        }
        return $user;
    }

    public function logSuccessfulLogin($username, $user) {
        $this->logLoginAttempt($user->ID, true);
        $this->clearFailedAttempts($this->getClientIP());
        $this->setAuthenticationToken($user->ID);
        
        // Log successful login to audit
        do_action('gdpr_successful_login', $user->ID);
    }
    
    public function logFailedLogin($username) {
        $user = get_user_by('login', $username);
        $user_id = $user ? $user->ID : 0;
        $this->logLoginAttempt($user_id, false);
    
        // Log failed login to audit
        do_action('gdpr_failed_login', $user_id, [
            'username' => $username,
            'ip_address' => $this->getClientIP()
        ]);
    
        // Check for lockout
        if ($this->isIPLocked($this->getClientIP())) {
            do_action('gdpr_account_locked', $user_id, [
                'ip_address' => $this->getClientIP(),
                'duration' => $this->lockout_duration
            ]);
        }
    }

    private function logLoginAttempt($user_id, $success) {
        return $this->db->insert(
            'login_log',
            [
                'user_id' => $user_id,
                'success' => $success,
                'ip_address' => $this->getClientIP(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'timestamp' => current_time('mysql')
            ],
            ['%d', '%d', '%s', '%s', '%s']
        );
    }

    private function isIPLocked($ip) {
        $query = $this->db->prepare(
            "SELECT COUNT(*) FROM {$this->table_name}
             WHERE ip_address = %s 
             AND success = 0 
             AND timestamp > DATE_SUB(NOW(), INTERVAL %d SECOND)",
            $ip,
            $this->lockout_duration
        );

        return (int)$this->db->get_var($query) >= $this->max_attempts;
    }

    private function clearFailedAttempts($ip) {
        return $this->db->delete(
            'login_log',
            [
                'ip_address' => $ip,
                'success' => 0
            ],
            ['%s', '%d']
        );
    }

    private function setAuthenticationToken($user_id) {
        $token = wp_generate_password(32, false);
        update_user_meta($user_id, 'gdpr_auth_token', $token);
        $_SESSION['gdpr_auth_token'] = $token;
        $_SESSION['gdpr_user_id'] = $user_id;
    }

    public function validateAccess($user_id, $capability = '') {
        if (!$this->verifySession($user_id)) {
            return false;
        }

        if (!empty($capability) && !user_can($user_id, $capability)) {
            return false;
        }

        return true;
    }

    private function verifySession($user_id) {
        if (!isset($_SESSION['gdpr_user_id']) || 
            !isset($_SESSION['gdpr_auth_token']) || 
            $_SESSION['gdpr_user_id'] != $user_id) {
            return false;
        }

        $stored_token = get_user_meta($user_id, 'gdpr_auth_token', true);
        return hash_equals($stored_token, $_SESSION['gdpr_auth_token']);
    }

    private function getClientIP() {
        $headers = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = filter_var($_SERVER[$header], FILTER_VALIDATE_IP);
                if ($ip !== false) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    public function getLoginAttempts($user_id = null, $limit = 10) {
        $query = "SELECT * FROM {$this->table_name}";
        $params = [];

        if ($user_id) {
            $query .= " WHERE user_id = %d";
            $params[] = $user_id;
        }

        $query .= " ORDER BY timestamp DESC LIMIT %d";
        $params[] = $limit;

        return $this->db->get_results(
            $params ? $this->db->prepare($query, $params) : $query
        );
    }

    public function cleanOldLogs($days = 30) {
        return $this->db->query($this->db->prepare(
            "DELETE FROM {$this->table_name} 
             WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    }

    public function renderAccessControlSection() {
        echo '<p>' . esc_html__('Configure login attempt limits and lockout settings.', 'wp-gdpr-framework') . '</p>';
    }

    public function renderMaxAttemptsField() {
        $value = get_option('gdpr_max_login_attempts', 5);
        echo '<input type="number" name="gdpr_max_login_attempts" value="' . esc_attr($value) . '" min="1" max="10" />';
        echo '<p class="description">' . esc_html__('Number of failed attempts before temporary lockout.', 'wp-gdpr-framework') . '</p>';
    }

    public function renderLockoutDurationField() {
        $value = get_option('gdpr_lockout_duration', 900);
        echo '<input type="number" name="gdpr_lockout_duration" value="' . esc_attr($value) . '" min="300" step="60" />';
        echo '<p class="description">' . esc_html__('Lockout duration in seconds (minimum 300s).', 'wp-gdpr-framework') . '</p>';
    }
}