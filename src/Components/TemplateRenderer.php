<?php
namespace GDPRFramework\Components;

class TemplateRenderer {
    private $settings;
    private $template_dirs;

    public function __construct($settings) {
        $this->settings = $settings;
        $this->initializeTemplateDirs();
    }

    /**
     * Initialize template directories
     */
    private function initializeTemplateDirs() {
        $this->template_dirs = [
            'admin' => GDPR_FRAMEWORK_TEMPLATE_PATH . 'admin/',
            'public' => GDPR_FRAMEWORK_TEMPLATE_PATH . 'public/'
        ];

        // Verify template directories exist
        foreach ($this->template_dirs as $dir) {
            if (!is_dir($dir)) {
                error_log("GDPR Framework - Template directory not found: {$dir}");
            }
        }
    }

   
    /**
     * Render a template file
     * 
     * @param string $template Template path relative to template directory
     * @param array $data Variables to extract into template scope
     * @return string Rendered template content
     */
    public function render($template, array $data = []) {
        try {
            // Determine template location
            $template_file = $this->getTemplatePath($template);
            
            if (!file_exists($template_file)) {
                error_log("GDPR Framework - Template not found: {$template_file}");
                return $this->renderError(
                    sprintf(__('Template %s not found.', 'wp-gdpr-framework'), $template)
                );
            }

            // Validate data
            $safe_data = $this->sanitizeTemplateData($data);

            // Extract data into local scope
            if (!empty($safe_data)) {
                extract($safe_data, EXTR_SKIP);
            }

            // Start output buffering
            ob_start();

            // Include template
            include $template_file;

            // Get and clean the buffer
            $content = ob_get_clean();
            
            if ($content === false) {
                throw new \Exception('Failed to get template output');
            }

            return $content;

        } catch (\Exception $e) {
            error_log("GDPR Framework - Template render error: " . $e->getMessage());
            return $this->renderError($e->getMessage());
        }
    }

    /**
     * Get full template path
     */
    private function getTemplatePath($template) {
        // Security check
        $template = str_replace(['../', '..\\'], '', $template);
        
        // Check if template includes directory prefix
        if (strpos($template, '/') !== false) {
            $full_path = GDPR_FRAMEWORK_TEMPLATE_PATH . $template . '.php';
        } else {
            // Fallback to public templates
            $full_path = GDPR_FRAMEWORK_TEMPLATE_PATH . 'public/' . $template . '.php';
        }

        // Verify path is within allowed directories
        if (!$this->isValidTemplatePath($full_path)) {
            error_log("GDPR Framework - Invalid template path: {$full_path}");
            throw new \Exception('Invalid template path');
        }

        return $full_path;
    }

    /**
     * Verify template path is valid
     */
    private function isValidTemplatePath($path) {
        $real_path = realpath($path);
        if ($real_path === false) {
            return false;
        }

        // Check if path is within allowed directories
        foreach ($this->template_dirs as $dir) {
            if (strpos($real_path, realpath($dir)) === 0) {
                return true;
            }
        }

        return false;
    }

     /**
     * Sanitize template data
     */
    private function sanitizeTemplateData(array $data) {
        $safe_data = [];
        foreach ($data as $key => $value) {
            // Only allow alphanumeric and underscore in keys
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                continue;
            }
            $safe_data[$key] = $value;
        }
        return $safe_data;
    }



    /**
     * Render error message
     */
    private function renderError($message) {
        return '<div class="notice notice-error"><p>' . 
               esc_html($message) . 
               '</p></div>';
    }

    /**
     * Check if template exists
     */
    public function templateExists($template) {
        try {
            $path = $this->getTemplatePath($template);
            return file_exists($path);
        } catch (\Exception $e) {
            return false;
        }
    }
}
