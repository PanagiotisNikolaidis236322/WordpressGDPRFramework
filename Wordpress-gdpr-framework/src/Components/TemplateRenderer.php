<?php
namespace GDPRFramework\Components;

class TemplateRenderer {
    private $settings;

    public function __construct($settings) {
        $this->settings = $settings;
    }

    public function render($template, $data = []) {
        $template_file = GDPR_FRAMEWORK_PATH . 'templates/' . $template . '.php';
        
        if (!file_exists($template_file)) {
            return '<div class="notice notice-error"><p>' . 
                   sprintf(__('Template %s not found.', 'wp-gdpr-framework'), $template) . 
                   '</p></div>';
        }

        // Make data available to template
        extract($data);

        // Start output buffering
        ob_start();

        // Include template
        include $template_file;

        // Return the buffered content
        return ob_get_clean();
    }

    public function renderPrivacyDashboard($atts = []) {
        if (!is_user_logged_in()) {
            return sprintf(
                '<p>%s</p>',
                __('Please log in to access your privacy dashboard.', 'wp-gdpr-framework')
            );
        }
    
        $user_id = get_current_user_id();
        $recent_exports = $this->getRecentExports($user_id);
        $consent_types = get_option('gdpr_consent_types', []);
    
        return $this->template->renderPublic('privacy-dashboard', [
            'user_id' => $user_id,
            'recent_exports' => $recent_exports,
            'consent_types' => $consent_types
        ]);
    }
}