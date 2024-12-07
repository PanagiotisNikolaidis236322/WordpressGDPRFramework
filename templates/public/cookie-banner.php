<?php if (!defined('ABSPATH')) exit; ?>

<div class="gdpr-cookie-banner" role="alert">
    <div class="gdpr-cookie-content">
        <div class="gdpr-cookie-message">
            <h3><?php esc_html_e('Cookie Consent', 'wp-gdpr-framework'); ?></h3>
            <p>
                <?php echo wp_kses_post(
                    sprintf(
                        __('We use cookies to enhance your experience. By continuing to visit this site you agree to our use of cookies. For more information, please see our %sprivacy policy%s.', 'wp-gdpr-framework'),
                        '<a href="' . esc_url(get_privacy_policy_url()) . '">',
                        '</a>'
                    )
                ); ?>
            </p>
        </div>
        
        <div class="gdpr-cookie-actions">
            <button type="button" class="button" data-action="preferences">
                <?php esc_html_e('Cookie Settings', 'wp-gdpr-framework'); ?>
            </button>
            <button type="button" class="button" data-action="reject">
                <?php esc_html_e('Reject Non-Essential', 'wp-gdpr-framework'); ?>
            </button>
            <button type="button" class="button button-primary" data-action="accept">
                <?php esc_html_e('Accept All', 'wp-gdpr-framework'); ?>
            </button>
        </div>

        <div class="gdpr-cookie-preferences">
            <h3><?php esc_html_e('Cookie Settings', 'wp-gdpr-framework'); ?></h3>
            <div class="gdpr-cookie-types">
                <?php foreach (gdpr_get_consent_types() as $type => $data): 
                    if (strpos($type, 'cookie_') === 0):
                ?>
                    <div class="gdpr-cookie-type">
                        <label>
                            <input type="checkbox" 
                                   name="cookie_consents[]" 
                                   value="<?php echo esc_attr($type); ?>"
                                   <?php echo !empty($data['required']) ? 'checked required disabled' : ''; ?>>
                            <span><?php echo esc_html($data['label']); ?></span>
                        </label>
                        <p class="description"><?php echo esc_html($data['description']); ?></p>
                    </div>
                <?php 
                    endif;
                endforeach; 
                ?>
            </div>
            <div class="gdpr-cookie-actions">
                <button type="button" class="button button-primary" data-action="save">
                    <?php esc_html_e('Save Preferences', 'wp-gdpr-framework'); ?>
                </button>
            </div>
        </div>
    </div>
</div>