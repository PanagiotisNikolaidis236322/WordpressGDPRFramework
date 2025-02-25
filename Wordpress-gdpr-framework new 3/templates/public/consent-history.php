<?php if (!defined('ABSPATH')) exit; ?>

<div class="gdpr-consent-history" role="region" aria-label="<?php esc_attr_e('Consent History', 'wp-gdpr-framework'); ?>">
    <div class="gdpr-section-header">
        <h3><?php _e('Consent History', 'wp-gdpr-framework'); ?></h3>
        <?php if (!empty($consent_history)): ?>
            <div class="gdpr-export-actions">
    <select class="export-format">
        <option value="csv">CSV</option>
        <option value="json">JSON</option>
    </select>
    <button type="button" class="button export-history" data-nonce="<?php echo wp_create_nonce('gdpr_export_history'); ?>">
        <span class="dashicons dashicons-download"></span>
        <?php _e('Export History', 'wp-gdpr-framework'); ?>
    </button>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($consent_history)): ?>
        <div class="gdpr-history-timeline">
            <?php foreach ($consent_history as $entry): ?>
                <div class="history-entry">
                    <div class="entry-timestamp">
                        <time datetime="<?php echo esc_attr($entry->timestamp); ?>">
                            <?php echo esc_html(
                                date_i18n(
                                    get_option('date_format') . ' ' . get_option('time_format'),
                                    strtotime($entry->timestamp)
                                )
                            ); ?>
                        </time>
                    </div>
                    
                    <div class="entry-details">
                        <div class="consent-type">
                            <strong><?php echo esc_html($entry->consent_type_label); ?></strong>
                            <span class="consent-status status-<?php 
                                echo $entry->status ? 'granted' : 'withdrawn';
                                echo $entry->outdated ? ' status-outdated' : '';
                            ?>">
                                <?php 
                                echo $entry->status 
                                    ? esc_html__('Granted', 'wp-gdpr-framework')
                                    : esc_html__('Withdrawn', 'wp-gdpr-framework');
                                if ($entry->outdated) {
                                    echo ' (' . esc_html__('Outdated', 'wp-gdpr-framework') . ')';
                                }
                                ?>
                            </span>
                        </div>
                        
                        <div class="consent-meta">
                            <span class="version-info">
                                <?php printf(
                                    esc_html__('Version: %s', 'wp-gdpr-framework'),
                                    esc_html($entry->version)
                                ); ?>
                            </span>
                            <?php if (!empty($entry->ip_address)): ?>
                                <span class="ip-info">
                                    <?php printf(
                                        esc_html__('IP: %s', 'wp-gdpr-framework'),
                                        esc_html($entry->ip_address)
                                    ); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($entry->policy_version)): ?>
                            <div class="policy-version">
                                <small>
                                    <?php printf(
                                        esc_html__('Policy Version: %s', 'wp-gdpr-framework'),
                                        esc_html($entry->policy_version)
                                    ); ?>
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($total_pages > 1): ?>
            <div class="gdpr-pagination" role="navigation" aria-label="<?php esc_attr_e('Consent history pagination', 'wp-gdpr-framework'); ?>">
                <?php
                echo paginate_links([
                    'base' => add_query_arg('history_page', '%#%'),
                    'format' => '',
                    'prev_text' => __('&laquo; Previous', 'wp-gdpr-framework'),
                    'next_text' => __('Next &raquo;', 'wp-gdpr-framework'),
                    'total' => $total_pages,
                    'current' => $current_page,
                    'aria_current' => 'page'
                ]);
                ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <p class="gdpr-no-history">
            <?php _e('No consent history available.', 'wp-gdpr-framework'); ?>
        </p>
    <?php endif; ?>
</div>
