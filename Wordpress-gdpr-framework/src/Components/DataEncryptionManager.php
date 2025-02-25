<?php
// src/Components/DataEncryptionManager.php
namespace GDPRFramework\Components;

class DataEncryptionManager {
    private $db;
    private $settings;
    private $cipher = 'aes-256-cbc';
    private $key;

    public function __construct($database, $settings) {
        $this->db = $database;
        $this->settings = $settings;
        $this->initializeKey();
	
	// Add AJAX handler
    add_action('wp_ajax_gdpr_rotate_key', [$this, 'handleKeyRotation']);
}

public function handleKeyRotation() {
    check_ajax_referer('gdpr_security_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'wp-gdpr-framework')]);
        return;
    }

    try {
        $this->rotateKey();
        update_option('gdpr_last_key_rotation', time());
        
        // Log successful key rotation
        do_action('gdpr_key_rotated', get_current_user_id());
        
        wp_send_json_success(['message' => __('Encryption key rotated successfully.', 'wp-gdpr-framework')]);
    } catch (\Exception $e) {
        // Log failed key rotation
        do_action('gdpr_key_rotation_failed', get_current_user_id(), $e->getMessage());
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

    /**
     * Initialize or retrieve encryption key
     */
    private function initializeKey() {
        $key = get_option('gdpr_encryption_key');
        if (!$key) {
            $key = $this->generateKey();
            update_option('gdpr_encryption_key', $key);
        }
        $this->key = base64_decode($key);
    }

    /**
     * Generate new encryption key
     */
    private function generateKey() {
        return base64_encode(openssl_random_pseudo_bytes(32));
    }

    /**
     * Encrypt data
     */
    public function encrypt($data) {
        if (empty($data)) {
            return '';
        }

        $iv_length = openssl_cipher_iv_length($this->cipher);
        $iv = openssl_random_pseudo_bytes($iv_length);

        $encrypted = openssl_encrypt(
            is_array($data) ? serialize($data) : $data,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            throw new \Exception('Encryption failed');
        }

        // Combine IV and encrypted data
        $combined = $iv . $encrypted;
        return base64_encode($combined);
    }

    /**
     * Decrypt data
     */
    public function decrypt($encrypted_data) {
        if (empty($encrypted_data)) {
            return '';
        }

        try {
            $decoded = base64_decode($encrypted_data);
            $iv_length = openssl_cipher_iv_length($this->cipher);
            
            // Extract IV and encrypted data
            $iv = substr($decoded, 0, $iv_length);
            $encrypted = substr($decoded, $iv_length);

            $decrypted = openssl_decrypt(
                $encrypted,
                $this->cipher,
                $this->key,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($decrypted === false) {
                throw new \Exception('Decryption failed');
            }

            // Check if data was serialized
            if ($this->isSerialized($decrypted)) {
                return unserialize($decrypted);
            }

            return $decrypted;
        } catch (\Exception $e) {
            error_log('GDPR Framework - Decryption Error: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Rotate encryption key
     */
    public function rotateKey() {
        global $wpdb;
    
        try {
            $wpdb->query('START TRANSACTION');
    
            // Generate new key
            $new_key = $this->generateKey();
            $old_key = $this->key;
    
            // Get all encrypted data
            $tables = [
                $wpdb->prefix . 'gdpr_user_data'
                // Add other tables containing encrypted data
            ];
    
            foreach ($tables as $table) {
                $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE encrypted_data IS NOT NULL");
                
                foreach ($rows as $row) {
                    // Decrypt with old key
                    $this->key = $old_key;
                    $decrypted = $this->decrypt($row->encrypted_data);
    
                    // Encrypt with new key
                    $this->key = base64_decode($new_key);
                    $encrypted = $this->encrypt($decrypted);
    
                    // Update record
                    $wpdb->update(
                        $table,
                        ['encrypted_data' => $encrypted],
                        ['id' => $row->id]
                    );
                }
            }
    
            // Save new key
            update_option('gdpr_encryption_key', $new_key);
            $this->key = base64_decode($new_key);
    
            $wpdb->query('COMMIT');
    
            // Log successful data re-encryption
            do_action('gdpr_data_reencrypted', get_current_user_id());
            
            return true;
    
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            
            // Log failed data re-encryption
            do_action('gdpr_data_reencryption_failed', get_current_user_id(), $e->getMessage());
            
            error_log('GDPR Framework - Key Rotation Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Check if a string is serialized
     */
    private function isSerialized($data) {
        if (!is_string($data)) {
            return false;
        }
        $data = trim($data);
        if ('N;' === $data) {
            return true;
        }
        if (!preg_match('/^([adObis]):/', $data, $badions)) {
            return false;
        }
        switch ($badions[1]) {
            case 'a':
            case 'O':
            case 's':
                if (preg_match("/^{$badions[1]}:[0-9]+:.*[;}]\$/s", $data)) {
                    return true;
                }
                break;
            case 'b':
            case 'i':
            case 'd':
                if (preg_match("/^{$badions[1]}:[0-9.E-]+;\$/", $data)) {
                    return true;
                }
                break;
        }
        return false;
    }
}