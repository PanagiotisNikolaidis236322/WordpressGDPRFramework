<?php if (!defined('ABSPATH')) exit; ?>

<div class="gdpr-consent-form-wrapper">
    <?php if (!is_user_logged_in()): ?>
        <div class="gdpr-notice gdpr-error">
            <?php esc_html_e('Please log in to manage your privacy settings.', 'wp-gdpr-framework'); ?>
        </div>
    <?php else: ?>
        <?php 
        // Ensure variables are properly initialized
        $consent_types = is_array($consent_types) ? $consent_types : [];
        $current_consents = is_array($current_consents ?? []) ? $current_consents : [];
        $outdated_consents = is_array($outdated_consents ?? []) ? $outdated_consents : [];
        ?>

        <form method="post" class="gdpr-consent-form" id="gdprConsentForm">
            <?php 
            // Add both nonces for update and reset actions
            wp_nonce_field('gdpr_nonce', 'gdpr_nonce');
            wp_nonce_field('gdpr_reset_preferences', 'gdpr_reset_nonce');
            ?>
            
            <?php if (!empty($outdated_consents)): ?>
                <div class="gdpr-notice gdpr-warning">
                    <div class="notice-icon">
                        <span class="dashicons dashicons-warning"></span>
                    </div>
                    <div class="notice-content">
                        <p><?php esc_html_e('Some of your privacy settings need to be reviewed due to policy updates:', 'wp-gdpr-framework'); ?></p>
                        <ul>
                        <?php foreach ($outdated_consents as $type): ?>
                            <?php if (isset($consent_types[$type]) && is_array($consent_types[$type])): ?>
                                <li><?php echo esc_html($consent_types[$type]['label'] ?? ''); ?></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <div class="gdpr-consent-options">
                <?php if (empty($consent_types)): ?>
                    <div class="gdpr-notice gdpr-error">
                        <?php esc_html_e('No privacy settings are currently configured.', 'wp-gdpr-framework'); ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($consent_types as $type => $data): 
                        // Ensure $data is an array
                        if (!is_array($data)) {
                            continue;
                        }

                        $is_outdated = in_array($type, $outdated_consents);
                        $classes = ['consent-option'];
                        if (!empty($data['required'])) {
                            $classes[] = 'required-consent';
                        }
                        if ($is_outdated) {
                            $classes[] = 'outdated-consent';
                        }
                    ?>
                        <div class="<?php echo esc_attr(implode(' ', $classes)); ?>">
                            <div class="consent-header">
                                <label class="consent-label">
                                    <input type="checkbox" 
                                        name="consents[<?php echo esc_attr($type); ?>]"
                                        value="1"
                                        <?php checked(!empty($current_consents[$type])); ?>
                                        <?php disabled(!empty($data['required'])); ?>
                                        <?php checked(!empty($data['required'])); ?>>
                                    <span class="consent-text">
                                        <?php echo esc_html($data['label'] ?? ''); ?>
                                        <?php if (!empty($data['required'])): ?>
                                            <span class="required">*</span>
                                        <?php endif; ?>
                                    </span>
                                </label>
                                
                                <?php if ($is_outdated): ?>
                                    <span class="outdated-badge">
                                        <?php esc_html_e('Update Required', 'wp-gdpr-framework'); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($data['description'])): ?>
                                <div class="consent-description">
                                    <?php echo wp_kses_post($data['description']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="gdpr-consent-actions">
                <button type="button" class="reset-consent">
                    <?php esc_html_e('Reset Preferences', 'wp-gdpr-framework'); ?>
                </button>
                <button type="submit" class="update-consent">
                    <?php esc_html_e('Update Privacy Settings', 'wp-gdpr-framework'); ?>
                </button>
            </div>

            <div class="gdpr-notice" style="display:none;"></div>
        </form>
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    const $form = $('#gdprConsentForm');
    const $notice = $('.gdpr-consent-notice');
    const $resetBtn = $('.reset-consent');
    const $updateBtn = $('button[type="submit"]');

    $form.on('submit', function(e) {
        e.preventDefault();
        
        // Create FormData from the form
        const formData = new FormData(this);
        formData.append('action', 'gdpr_update_privacy_settings');
        formData.append('gdpr_nonce', gdprConsentForm.nonce);

        $.ajax({
            url: gdprConsentForm.ajaxUrl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            beforeSend: function() {
                $updateBtn.prop('disabled', true)
                         .text(gdprConsentForm.i18n.updating);
                $notice.removeClass('gdpr-success gdpr-error').hide();
            },
            success: function(response) {
                if (response.success) {
                    $notice.addClass('gdpr-success')
                           .html(response.data.message)
                           .fadeIn();
                    
                    // Update checkboxes if needed
                    if (response.data.consents) {
                        Object.entries(response.data.consents).forEach(([type, status]) => {
                            $(`input[name="consents[${type}]"]`).prop('checked', status);
                        });
                    }
                    
                    // Reload after short delay
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    $notice.addClass('gdpr-error')
                           .html(response.data.message)
                           .fadeIn();
                }
            },
            error: function() {
                $notice.addClass('gdpr-error')
                       .html(gdprConsentForm.i18n.error)
                       .fadeIn();
            },
            complete: function() {
                $updateBtn.prop('disabled', false)
                         .text(gdprConsentForm.i18n.update);
            }
        });
    });

    // Handle reset button
    $resetBtn.on('click', function() {
    if (!confirm(gdprConsentForm.i18n.confirmReset)) {
        return;
    }

    $.ajax({
        url: gdprConsentForm.ajaxUrl,
        method: 'POST',
        data: {
            action: 'gdpr_reset_preferences',
            gdpr_reset_nonce: $('input[name="gdpr_reset_nonce"]').val() // Use correct nonce field
        },
        beforeSend: function() {
            $resetBtn.prop('disabled', true);
            $notice.removeClass('gdpr-success gdpr-error').hide();
        },
        success: function(response) {
            if (response.success) {
                window.location.reload();
            } else {
                $notice.addClass('gdpr-error')
                       .html(response.data.message)
                       .fadeIn();
            }
        },
        error: function() {
            $notice.addClass('gdpr-error')
                   .html(gdprConsentForm.i18n.error)
                   .fadeIn();
        },
        complete: function() {
            $resetBtn.prop('disabled', false);
        }
    });
    });

});
</script>