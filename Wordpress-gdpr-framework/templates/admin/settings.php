<?php
/**
 * GDPR Framework Settings Template
 * 
 * Displays the main settings interface for the GDPR Framework plugin.
 * Includes tabs for General Settings, Consent Management, Access Control,
 * Data Portability, and Security settings.
 *
 * @package WordPress GDPR Framework
 */

if (!defined('ABSPATH')) exit;
?>
<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php settings_errors(); ?>

    <!-- Tab Navigation -->
    <div class="nav-tab-wrapper">
        <a href="#general" class="nav-tab nav-tab-active" data-tab="general">
            <?php _e('General', 'wp-gdpr-framework'); ?>
        </a>
        <a href="#consent" class="nav-tab" data-tab="consent">
            <?php _e('Consent Management', 'wp-gdpr-framework'); ?>
        </a>
        <a href="#access-control" class="nav-tab" data-tab="access-control">
            <?php _e('Access Control', 'wp-gdpr-framework'); ?>
        </a>
        <a href="#data-portability" class="nav-tab" data-tab="data-portability">
            <?php _e('Data Portability', 'wp-gdpr-framework'); ?>
        </a>
        <a href="#security" class="nav-tab" data-tab="security">
            <?php _e('Security', 'wp-gdpr-framework'); ?>
        </a>
        <a href="#cleanup" class="nav-tab" data-tab="cleanup">
            <?php _e('Cleanup', 'wp-gdpr-framework'); ?>
        </a>
    </div>

    <form method="post" action="options.php" class="gdpr-settings-form">
        <?php 
        settings_fields('gdpr_framework_settings');
        $consent_types = get_option('gdpr_consent_types', []); 
        ?>
        
        <!-- General Settings Tab -->
        <div id="general" class="tab-content">
            <h2><?php _e('General Settings', 'wp-gdpr-framework'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="gdpr_retention_days">
                            <?php _e('Data Retention Period (days)', 'wp-gdpr-framework'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="number" 
                               id="gdpr_retention_days"
                               name="gdpr_retention_days" 
                               value="<?php echo esc_attr(get_option('gdpr_retention_days', 365)); ?>"
                               min="30"
                               class="regular-text">
                        <p class="description">
                            <?php _e('Number of days to retain user data before automatic cleanup.', 'wp-gdpr-framework'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="gdpr_privacy_policy_page">
                            <?php _e('Privacy Policy Page', 'wp-gdpr-framework'); ?>
                        </label>
                    </th>
                    <td>
                        <?php
                        wp_dropdown_pages([
                            'name' => 'gdpr_privacy_policy_page',
                            'id' => 'gdpr_privacy_policy_page',
                            'show_option_none' => __('Select a page', 'wp-gdpr-framework'),
                            'option_none_value' => '0',
                            'selected' => get_option('gdpr_privacy_policy_page', 0)
                        ]);
                        ?>
                        <p class="description">
                            <?php _e('Select the page containing your privacy policy.', 'wp-gdpr-framework'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Consent Management Tab -->
        <div id="consent" class="tab-content" style="display: none;">
            <h2><?php _e('Consent Types', 'wp-gdpr-framework'); ?></h2>
            <p class="description">
                <?php _e('Define different types of consent that users can give or withdraw.', 'wp-gdpr-framework'); ?>
            </p>
            
            <div id="consent-types">
                <?php 
                if (!empty($consent_types)):
                    foreach ($consent_types as $key => $type):
                ?>
                    <div class="consent-type-item">
                        <div class="consent-type-header">
                            <input type="text"
                                   name="gdpr_consent_types[<?php echo esc_attr($key); ?>][label]"
                                   value="<?php echo esc_attr($type['label']); ?>"
                                   class="regular-text"
                                   placeholder="<?php _e('Consent Type Label', 'wp-gdpr-framework'); ?>"
                                   required>
                            
                            <label class="required-checkbox">
                                <input type="checkbox"
                                       name="gdpr_consent_types[<?php echo esc_attr($key); ?>][required]"
                                       value="1"
                                       <?php checked(!empty($type['required'])); ?>>
                                <?php _e('Required', 'wp-gdpr-framework'); ?>
                            </label>
                            
                            <button type="button" class="button remove-consent-type">
                                <?php _e('Remove', 'wp-gdpr-framework'); ?>
                            </button>
                        </div>
                        
                        <textarea name="gdpr_consent_types[<?php echo esc_attr($key); ?>][description]"
                                  class="large-text"
                                  placeholder="<?php _e('Description', 'wp-gdpr-framework'); ?>"
                                  required><?php echo esc_textarea($type['description']); ?></textarea>
                    </div>
                <?php
                    endforeach;
                endif;
                ?>
            </div>

            <button type="button" class="button button-secondary" id="add-consent-type">
                <?php _e('Add Consent Type', 'wp-gdpr-framework'); ?>
            </button>
        </div>

        <!-- Access Control Tab -->
        <div id="access-control" class="tab-content" style="display: none;">
            <h2><?php _e('Access Control Settings', 'wp-gdpr-framework'); ?></h2>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="gdpr_max_login_attempts">
                            <?php _e('Maximum Login Attempts', 'wp-gdpr-framework'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="number" 
                               id="gdpr_max_login_attempts"
                               name="gdpr_max_login_attempts" 
                               value="<?php echo esc_attr(get_option('gdpr_max_login_attempts', 5)); ?>"
                               min="1" 
                               max="10"
                               class="small-text">
                        <p class="description">
                            <?php _e('Number of failed attempts before temporary lockout.', 'wp-gdpr-framework'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="gdpr_lockout_duration">
                            <?php _e('Lockout Duration (seconds)', 'wp-gdpr-framework'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="number" 
                               id="gdpr_lockout_duration"
                               name="gdpr_lockout_duration" 
                               value="<?php echo esc_attr(get_option('gdpr_lockout_duration', 900)); ?>"
                               min="300" 
                               step="60"
                               class="regular-text">
                        <p class="description">
                            <?php _e('How long users are locked out after exceeding maximum attempts.', 'wp-gdpr-framework'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php if (current_user_can('manage_options') && isset($access_manager)): ?>
                <h3><?php _e('Recent Login Attempts', 'wp-gdpr-framework'); ?></h3>
                <?php 
                $attempts = $access_manager->getLoginAttempts(null, 5);
                if (!empty($attempts)): 
                ?>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th><?php _e('User', 'wp-gdpr-framework'); ?></th>
                                <th><?php _e('Status', 'wp-gdpr-framework'); ?></th>
                                <th><?php _e('IP Address', 'wp-gdpr-framework'); ?></th>
                                <th><?php _e('Date', 'wp-gdpr-framework'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attempts as $attempt): ?>
                                <tr>
                                    <td>
                                        <?php
                                        if ($attempt->user_id) {
                                            $user = get_userdata($attempt->user_id);
                                            echo $user ? esc_html($user->display_name) : __('Unknown User', 'wp-gdpr-framework');
                                        } else {
                                            echo __('Failed Attempt', 'wp-gdpr-framework');
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <span class="status-<?php echo $attempt->success ? 'success' : 'failure'; ?>">
                                            <?php echo $attempt->success ? 
                                                __('Success', 'wp-gdpr-framework') : 
                                                __('Failed', 'wp-gdpr-framework'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html($attempt->ip_address); ?></td>
                                    <td><?php echo esc_html(
                                        date_i18n(
                                            get_option('date_format') . ' ' . get_option('time_format'),
                                            strtotime($attempt->timestamp)
                                        )
                                    ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p><?php _e('No login attempts recorded.', 'wp-gdpr-framework'); ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Data Portability Tab -->
        <div id="data-portability" class="tab-content" style="display: none;">
            <h2><?php _e('Data Portability Settings', 'wp-gdpr-framework'); ?></h2>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Export Format', 'wp-gdpr-framework'); ?></th>
                    <td>
                        <?php 
                        $allowed_formats = get_option('gdpr_export_formats', ['json']);
                        $formats = [
                            'json' => 'JSON',
                            'xml' => 'XML',
                            'csv' => 'CSV'
                        ];
                        ?>
                        <fieldset>
                            <legend class="screen-reader-text">
                                <?php _e('Export Format', 'wp-gdpr-framework'); ?>
                            </legend>
                            <?php foreach ($formats as $value => $label): ?>
                                <label>
                                    <input type="checkbox" 
                                           name="gdpr_export_formats[]" 
                                           value="<?php echo esc_attr($value); ?>"
                                           <?php checked(in_array($value, $allowed_formats)); ?>>
                                    <?php echo esc_html($label); ?>
                                </label><br>
                            <?php endforeach; ?>
                            <p class="description">
                                <?php _e('Select available formats for data export.', 'wp-gdpr-framework'); ?>
                            </p>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="gdpr_export_expiry">
                            <?php _e('Export Expiry', 'wp-gdpr-framework'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="number" 
                               id="gdpr_export_expiry"
                               name="gdpr_export_expiry" 
                               value="<?php echo esc_attr(get_option('gdpr_export_expiry', 48)); ?>"
                               min="1" 
                               max="168"
                               class="small-text">
                        <?php _e('hours', 'wp-gdpr-framework'); ?>
                        <p class="description">
                            <?php _e('How long should exported data files be kept before automatic deletion (1-168 hours).', 'wp-gdpr-framework'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php if (!empty($portability) && $portability instanceof \GDPRFramework\Components\DataPortabilityManager): ?>
                <h3><?php _e('Pending Data Requests', 'wp-gdpr-framework'); ?></h3>
                <?php 
                $pending_requests = $portability->getRequestsWithUsers();
                if (!empty($pending_requests)): 
                ?>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th><?php _e('User', 'wp-gdpr-framework'); ?></th>
                                <th><?php _e('Email', 'wp-gdpr-framework'); ?></th>
                                <th><?php _e('Request Type', 'wp-gdpr-framework'); ?></th>
                                <th><?php _e('Status', 'wp-gdpr-framework'); ?></th>
                                <th><?php _e('Requested', 'wp-gdpr-framework'); ?></th>
                                <th><?php _e('Actions', 'wp-gdpr-framework'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_requests as $request): ?>
                                <tr>
                                    <td><?php echo esc_html($request->display_name); ?></td>
                                    <td><?php echo esc_html($request->user_email); ?></td>
                                    <td>
                                        <?php if ($request->request_type === 'export'): ?>
                                            <span class="dashicons dashicons-download"></span>
                                        <?php else: ?>
                                            <span class="dashicons dashicons-trash"></span>
                                        <?php endif; ?>
                                        <?php echo esc_html(ucfirst($request->request_type)); ?>
                                    </td>
                                    <td>
                                        <span class="status-<?php echo esc_attr($request->status); ?>">
                                            <?php echo esc_html(ucfirst($request->status)); ?>
                                            </span>
                                    </td>
                                    <td>
                                        <?php echo esc_html(
                                            date_i18n(
                                                get_option('date_format') . ' ' . get_option('time_format'),
                                                strtotime($request->created_at)
                                            )
                                        ); ?>
                                    </td>
                                    <td>
                                        <button type="button" 
                                                class="button process-request"
                                                data-id="<?php echo esc_attr($request->id); ?>"
                                                data-type="<?php echo esc_attr($request->request_type); ?>"
                                                data-nonce="<?php echo wp_create_nonce('gdpr_process_request'); ?>">
                                            <?php _e('Process Request', 'wp-gdpr-framework'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p><?php _e('No pending requests.', 'wp-gdpr-framework'); ?></p>
                <?php endif; ?>

                <!-- Recent Exports Section -->
                <?php
                $recent_exports = $portability->getRecentExportsWithUsers();
                if (!empty($recent_exports)): 
                ?>
                    <h3><?php _e('Recent Exports', 'wp-gdpr-framework'); ?></h3>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th><?php _e('User', 'wp-gdpr-framework'); ?></th>
                                <th><?php _e('Email', 'wp-gdpr-framework'); ?></th>
                                <th><?php _e('Status', 'wp-gdpr-framework'); ?></th>
                                <th><?php _e('Created', 'wp-gdpr-framework'); ?></th>
                                <th><?php _e('Completed', 'wp-gdpr-framework'); ?></th>
                                <th><?php _e('Actions', 'wp-gdpr-framework'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_exports as $export): ?>
                                <tr>
                                    <td><?php echo esc_html($export->display_name); ?></td>
                                    <td><?php echo esc_html($export->user_email); ?></td>
                                    <td>
                                        <span class="gdpr-status gdpr-status-<?php echo esc_attr($export->status); ?>">
                                            <?php echo esc_html(ucfirst($export->status)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo esc_html(
                                            date_i18n(
                                                get_option('date_format') . ' ' . get_option('time_format'),
                                                strtotime($export->created_at)
                                            )
                                        ); ?>
                                    </td>
                                    <td>
                                        <?php echo $export->completed_at ? esc_html(
                                            date_i18n(
                                                get_option('date_format') . ' ' . get_option('time_format'),
                                                strtotime($export->completed_at)
                                            )
                                        ) : '-'; ?>
                                    </td>
                                    <td>
                                        <?php if ($export->status === 'completed'): ?>
                                            <a href="<?php echo esc_url(add_query_arg([
                                                'action' => 'gdpr_download_export',
                                                'request_id' => $export->id,
                                                'nonce' => wp_create_nonce('gdpr_download_' . $export->id)
                                            ], admin_url('admin-ajax.php'))); ?>" 
                                               class="button button-secondary">
                                                <?php _e('Download', 'wp-gdpr-framework'); ?>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Security Tab -->
        <div id="security" class="tab-content" style="display: none;">
            <h2><?php _e('Security Settings', 'wp-gdpr-framework'); ?></h2>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Encryption Status', 'wp-gdpr-framework'); ?></th>
                    <td>
                        <?php
                        $key_exists = get_option('gdpr_encryption_key') ? true : false;
                        if ($key_exists):
                        ?>
                            <div class="notice notice-success inline">
                                <p><?php _e('Encryption is properly configured.', 'wp-gdpr-framework'); ?></p>
                            </div>
                        <?php else: ?>
                            <div class="notice notice-error inline">
                                <p><?php _e('Encryption key not configured.', 'wp-gdpr-framework'); ?></p>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Key Rotation', 'wp-gdpr-framework'); ?></th>
                    <td>
                        <button type="button" 
                                id="gdpr-rotate-key" 
                                class="button button-secondary"
                                <?php echo !$key_exists ? 'disabled' : ''; ?>>
                            <?php _e('Rotate Encryption Key', 'wp-gdpr-framework'); ?>
                        </button>
                        <p class="description">
                            <?php _e('Rotating the encryption key will re-encrypt all sensitive data with a new key. This operation may take some time.', 'wp-gdpr-framework'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Last Key Rotation', 'wp-gdpr-framework'); ?></th>
                    <td>
                        <?php
                        $last_rotation = get_option('gdpr_last_key_rotation');
                        echo $last_rotation 
                            ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_rotation))
                            : __('Never', 'wp-gdpr-framework');
                        ?>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Cleanup Tab -->
        <div id="cleanup" class="tab-content" style="display: none;">
            <h2><?php _e('Cleanup Settings', 'wp-gdpr-framework'); ?></h2>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Cleanup Schedule', 'wp-gdpr-framework'); ?></th>
                    <td>
                        <?php
                        $cleanup_status = isset($gdpr) ? $gdpr->getCleanupStatus() : null;
                        if ($cleanup_status):
                        ?>
                            <p>
                                <strong><?php _e('Next Scheduled Cleanup:', 'wp-gdpr-framework'); ?></strong>
                                <?php echo esc_html($cleanup_status['next_run']); ?>
                            </p>
                            <p>
                                <strong><?php _e('Last Cleanup:', 'wp-gdpr-framework'); ?></strong>
                                <?php echo esc_html($cleanup_status['last_run']); ?>
                            </p>
                        <?php endif; ?>
                        <button type="button" 
                                class="button" 
                                id="gdpr-manual-cleanup"
                                data-nonce="<?php echo wp_create_nonce('gdpr_manual_cleanup'); ?>">
                            <?php _e('Run Cleanup Now', 'wp-gdpr-framework'); ?>
                        </button>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="gdpr_audit_retention_days">
                            <?php _e('Audit Log Retention', 'wp-gdpr-framework'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="number" 
                               id="gdpr_audit_retention_days"
                               name="gdpr_audit_retention_days" 
                               value="<?php echo esc_attr(get_option('gdpr_audit_retention_days', 365)); ?>"
                               min="30" 
                               class="small-text">
                        <?php _e('days', 'wp-gdpr-framework'); ?>
                        <p class="description">
                            <?php _e('Number of days to keep audit log entries before deletion.', 'wp-gdpr-framework'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <?php submit_button(); ?>
    </form>
</div>

<!-- Template for new consent type -->
<script type="text/template" id="consent-type-template">
    <div class="consent-type-item">
        <div class="consent-type-header">
            <input type="text"
                   name="gdpr_consent_types[{{id}}][label]"
                   class="regular-text"
                   placeholder="<?php _e('Consent Type Label', 'wp-gdpr-framework'); ?>"
                   required>
            
            <label class="required-checkbox">
                <input type="checkbox"
                       name="gdpr_consent_types[{{id}}][required]"
                       value="1">
                <?php _e('Required', 'wp-gdpr-framework'); ?>
            </label>
            
            <button type="button" class="button remove-consent-type">
                <?php _e('Remove', 'wp-gdpr-framework'); ?>
            </button>
        </div>
        
        <textarea name="gdpr_consent_types[{{id}}][description]"
                  class="large-text"
                  placeholder="<?php _e('Description', 'wp-gdpr-framework'); ?>"
                  required></textarea>
    </div>
</script>