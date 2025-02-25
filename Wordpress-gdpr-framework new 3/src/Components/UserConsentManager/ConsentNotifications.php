<?php
namespace GDPRFramework\Components\UserConsentManager;

use Exception;
use InvalidArgumentException;

class ConsentNotifications {
    private $settings;
    private $default_templates;
    private $db;

    /**
     * Initialize the notifications manager
     *
     * @param object $settings Settings instance
     * @param object $database Database instance
     * @throws InvalidArgumentException If settings is invalid
     */
    public function __construct($settings, $database) {
        if (!$settings) {
            throw new InvalidArgumentException('Invalid settings instance provided');
        }

        $this->settings = $settings;
        $this->db = $database;
        $this->initializeDefaultTemplates();
        $this->initializeHooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function initializeHooks(): void {
        add_action('gdpr_consent_recorded', [$this, 'handleConsentRecorded'], 10, 4);
        add_action('gdpr_consent_version_created', [$this, 'handleVersionCreated'], 10, 2);
        add_filter('gdpr_notification_recipients', [$this, 'filterNotificationRecipients'], 10, 2);
        add_action('gdpr_daily_cleanup', [$this, 'cleanupOldNotifications']);
    }

    /**
     * Initialize default email templates
     */
    private function initializeDefaultTemplates(): void {
        $this->default_templates = [
            'consent_update' => [
                'subject' => __('[{site_name}] Privacy Settings Updated', 'wp-gdpr-framework'),
                'message' => $this->getDefaultTemplate('consent_update')
            ],
            'admin_notification' => [
                'subject' => __('[{site_name}] User Privacy Settings Changed', 'wp-gdpr-framework'),
                'message' => $this->getDefaultTemplate('admin_notification')
            ],
            'version_update' => [
                'subject' => __('[{site_name}] Privacy Policy Updated - Action Required', 'wp-gdpr-framework'),
                'message' => $this->getDefaultTemplate('version_update')
            ]
        ];
    }

    /**
     * Send notification when consent is updated
     *
     * @param int $user_id
     * @param array $consents
     * @throws Exception If notification fails
     */
    public function sendConsentUpdateNotification(int $user_id, array $consents): void {
        $user = get_userdata($user_id);
        if (!$user) {
            throw new InvalidArgumentException('Invalid user ID');
        }

        try {
            // Send user notification
            $this->sendEmail(
                $user->user_email,
                'consent_update',
                [
                    'user_name' => $user->display_name,
                    'user_email' => $user->user_email,
                    'consent_summary' => $this->formatConsentSummary($consents),
                    'ip_address' => $this->getClientIP()
                ]
            );

            // Send admin notification if enabled
            if ($this->settings->get('notify_admin_on_consent_change', false)) {
                $admin_email = $this->settings->get('admin_notification_email', get_option('admin_email'));
                $this->sendEmail(
                    $admin_email,
                    'admin_notification',
                    [
                        'user_name' => $user->display_name,
                        'user_email' => $user->user_email,
                        'consent_summary' => $this->formatConsentSummary($consents),
                        'ip_address' => $this->getClientIP(),
                        'user_profile_url' => get_edit_user_link($user_id)
                    ]
                );
            }

            $this->logNotification($user_id, 'consent_update');

        } catch (Exception $e) {
            error_log('GDPR Framework - Notification Error: ' . $e->getMessage());
            throw new Exception('Failed to send consent update notification');
        }
    }

    /**
     * Send notification when version is updated
     *
     * @param int $user_id
     * @param string $type
     * @throws Exception If notification fails
     */
    public function sendVersionUpdateNotification(int $user_id, string $type): void {
        $user = get_userdata($user_id);
        if (!$user) {
            throw new InvalidArgumentException('Invalid user ID');
        }

        try {
            $privacy_url = add_query_arg(
                'consent_type', 
                $type, 
                get_permalink(get_option('gdpr_privacy_page'))
            );

            $this->sendEmail(
                $user->user_email,
                'version_update',
                [
                    'user_name' => $user->display_name,
                    'consent_type' => $type,
                    'privacy_settings_url' => $privacy_url
                ]
            );

            $this->logNotification($user_id, 'version_update');

        } catch (Exception $e) {
            error_log('GDPR Framework - Version Notification Error: ' . $e->getMessage());
            throw new Exception('Failed to send version update notification');
        }
    }

    /**
     * Send email using template
     *
     * @param string $to
     * @param string $template_key
     * @param array $replacements
     * @return bool
     */
    private function sendEmail(string $to, string $template_key, array $replacements): bool {
        $template = $this->getEmailTemplate($template_key);
        if (!$template) {
            error_log('GDPR Framework - Email template not found: ' . $template_key);
            return false;
        }

        $replacements = array_merge($replacements, [
            'site_name' => get_bloginfo('name'),
            'site_url' => get_bloginfo('url'),
            'date' => wp_date(get_option('date_format'))
        ]);

        $subject = $this->replaceVariables($template['subject'], $replacements);
        $message = $this->replaceVariables($template['message'], $replacements);

        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            sprintf('From: %s <%s>', get_bloginfo('name'), get_option('admin_email'))
        ];

        $to = apply_filters('gdpr_notification_recipients', $to, $template_key);

        return wp_mail($to, $subject, $message, $headers);
    }

   /**
     * Get default template content
     *
     * @param string $type
     * @return string
     */
    private function getDefaultTemplate(string $type): string {
        switch ($type) {
            case 'consent_update':
                return __(
                    "Hello {user_name},\n\n" .
                    "Your privacy settings have been updated on {site_name} on {date}.\n\n" .
                    "Updated Consents:\n{consent_summary}\n\n" .
                    "If you did not make these changes, please contact us immediately.\n\n" .
                    "IP Address: {ip_address}\n\n" .
                    "Best regards,\n{site_name}",
                    'wp-gdpr-framework'
                );

            case 'admin_notification':
                return __(
                    "Privacy settings have been updated.\n\n" .
                    "User: {user_name} ({user_email})\n" .
                    "Date: {date}\n" .
                    "IP Address: {ip_address}\n\n" .
                    "Changes:\n{consent_summary}\n\n" .
                    "View user profile: {user_profile_url}",
                    'wp-gdpr-framework'
                );

            case 'version_update':
                return __(
                    "Hello {user_name},\n\n" .
                    "The privacy policy for {consent_type} has been updated on {site_name}.\n\n" .
                    "As you have previously given consent, we kindly request you to review the changes " .
                    "and update your consent preferences at your earliest convenience:\n\n" .
                    "{privacy_settings_url}\n\n" .
                    "If you take no action, your current consent settings will remain in effect.\n\n" .
                    "Best regards,\n{site_name}",
                    'wp-gdpr-framework'
                );

            default:
                return '';
        }
    }

    /**
     * Get email template with fallback to default
     *
     * @param string $key
     * @return array|null
     */
    private function getEmailTemplate(string $key): ?array {
        $custom_templates = $this->settings->get('email_templates', []);
        
        if (isset($custom_templates[$key]) && 
            !empty($custom_templates[$key]['subject']) && 
            !empty($custom_templates[$key]['message'])) {
            return $custom_templates[$key];
        }

        return $this->default_templates[$key] ?? null;
    }

    /**
     * Format consent changes summary
     *
     * @param array $consents
     * @return string
     */
    private function formatConsentSummary(array $consents): string {
        $summary = '';
        foreach ($consents as $type => $status) {
            $label = $this->getConsentTypeLabel($type);
            $summary .= sprintf(
                "- %s: %s\n",
                $label,
                $status ? __('Granted', 'wp-gdpr-framework') : __('Withdrawn', 'wp-gdpr-framework')
            );
        }
        return $summary;
    }

    /**
     * Replace template variables
     *
     * @param string $content
     * @param array $replacements
     * @return string
     */
    private function replaceVariables(string $content, array $replacements): string {
        foreach ($replacements as $key => $value) {
            $content = str_replace('{' . $key . '}', $value, $content);
        }
        return $content;
    }

    /**
     * Handle consent recorded event
     *
     * @param int $user_id
     * @param string $type
     * @param bool $status
     * @param string $version
     */
    public function handleConsentRecorded(int $user_id, string $type, bool $status, string $version): void {
        try {
            $user = get_userdata($user_id);
            if (!$user) {
                return;
            }

            $this->logConsentChange($user_id, $type, $status, $version);
            do_action('gdpr_consent_notification_sent', $user_id, $type, $status, $version);
            
        } catch (Exception $e) {
            error_log('GDPR Framework - Consent Recording Error: ' . $e->getMessage());
        }
    }

    /**
     * Handle version created event
     *
     * @param string $type
     * @param string $version
     */
    public function handleVersionCreated(string $type, string $version): void {
        if (!$this->settings->get('notify_version_updates', true)) {
            return;
        }

        try {
            $affected_users = $this->getAffectedUsers($type);
            
            foreach ($affected_users as $user_id) {
                $this->sendVersionUpdateNotification($user_id, $type);
            }

        } catch (Exception $e) {
            error_log('GDPR Framework - Version Update Notification Error: ' . $e->getMessage());
        }
    }

    /**
     * Get users affected by version change
     *
     * @param string $type
     * @return array
     */
    private function getAffectedUsers(string $type): array {
        try {
            $table_name = $this->db->getTableName('user_consents');
            $users_table = $this->db->get_prefix() . 'users';
            
            return $this->db->get_col($this->db->prepare(
                "SELECT DISTINCT uc.user_id 
                 FROM {$table_name} uc 
                 INNER JOIN {$users_table} u ON uc.user_id = u.ID 
                 WHERE uc.consent_type = %s 
                 AND uc.status = 1 
                 AND u.user_status = 0",
                $type
            )) ?: [];
    
        } catch (\Exception $e) {
            error_log('GDPR Framework - Error getting affected users: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Filter notification recipients
     *
     * @param string|array $recipients
     * @param string $template_key
     * @return string|array
     */
    public function filterNotificationRecipients($recipients, string $template_key) {
        // Allow for modification of notification recipients
        return apply_filters('gdpr_notification_recipients_' . $template_key, $recipients);
    }

    /**
     * Log notification in database
     *
     * @param int $user_id
     * @param string $type
     */
    private function logNotification(int $user_id, string $type): void {
        try {
            $table_name = $this->db->get_prefix() . 'gdpr_notifications';
            
            $this->db->insert(
                $table_name,
                [
                    'user_id' => $user_id,
                    'notification_type' => $type,
                    'sent_at' => current_time('mysql', true),
                    'ip_address' => $this->getClientIP()
                ],
                ['%d', '%s', '%s', '%s']
            );
        } catch (Exception $e) {
            error_log('GDPR Framework - Notification Logging Error: ' . $e->getMessage());
        }
    }

    /**
     * Log consent change
     *
     * @param int $user_id
     * @param string $type
     * @param bool $status
     * @param string $version
     */
    private function logConsentChange(int $user_id, string $type, bool $status, string $version): void {
        try {
            $table_name = $this->db->get_prefix() . 'gdpr_consent_log';
            
            $this->db->insert(
                $table_name,
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
        } catch (Exception $e) {
            error_log('GDPR Framework - Consent Log Error: ' . $e->getMessage());
        }
    }

    /**
     * Get client IP address
     *
     * @return string
     */
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
     * Get consent type label
     *
     * @param string $type
     * @return string
     */
    private function getConsentTypeLabel(string $type): string {
        $consent_types = $this->settings->get('consent_types', []);
        return $consent_types[$type]['label'] ?? $type;
    }

    /**
     * Clean up old notifications
     *
     * @param int $days_to_keep
     * @return int
     */
    public function cleanupOldNotifications(int $days_to_keep = 90): int {
        try {
            $table_name = $this->db->get_prefix() . 'gdpr_notifications';
            
            return $this->db->query($this->db->prepare(
                "DELETE FROM {$table_name} 
                 WHERE sent_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                max(30, $days_to_keep)
            ));
        } catch (Exception $e) {
            error_log('GDPR Framework - Notification Cleanup Error: ' . $e->getMessage());
            return 0;
        }
    }
}