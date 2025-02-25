(function($) {
    'use strict';

    // Only initialize consent forms if no React container
    if (!document.getElementById('gdpr-consent-form-root')) {
        $('.gdpr-consent-form').each(function() {
            new ConsentManager(this);
        });
    }
    
    // Cookie banner initialization can stay separate
    $('.gdpr-cookie-banner').each(function() {
        new ConsentManager(this);
    });

})(jQuery);

    // React Consent Form Component
    const ConsentForm = () => {
        const [consents, setConsents] = useState({});
        const [loading, setLoading] = useState(true);
        const [notification, setNotification] = useState(null);
        const [outdatedConsents, setOutdatedConsents] = useState([]);
        const [showHistory, setShowHistory] = useState(false);
        const [userHistory, setUserHistory] = useState([]);

    
        const formData = new FormData();
        formData.append('action', 'gdpr_reset_preferences');
        // Use the correct nonce
        formData.append('gdpr_reset_nonce', $('input[name="gdpr_reset_nonce"]').val());
    

        $.ajax({
            url: gdprConsentForm.ajaxUrl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            beforeSend: function() {
                $updateBtn.prop('disabled', true).text(gdprConsentForm.i18n.updating);
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
                    
                    // Reload page after short delay
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

    // Handle reset button
    $resetBtn.on('click', function() {
        if (!confirm(gdprConsentForm.i18n.confirmReset)) {
            return;
        }

        $.ajax({
            url: gdprConsentForm.ajaxUrl,
            method: 'POST',
            data: {
                action: 'gdpr_reset_preferences', // Changed from gdpr_reset_consent
                nonce: gdprConsentForm.nonce
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

        useEffect(() => {
            loadConsents();
        }, []);

        const loadConsents = async () => {
            try {
                const response = await fetch(
                    `${gdprConsentForm.ajaxUrl}?action=gdpr_get_user_consents&nonce=${gdprConsentForm.nonce}`
                );
                const data = await response.json();
                
                if (data.success) {
                    setConsents(data.consents);
                    setOutdatedConsents(data.outdatedConsents || []);
                }
            } catch (error) {
                console.error('Failed to load consents:', error);
            } finally {
                setLoading(false);
            }
        };

        const handleSubmit = async (e) => {
            e.preventDefault();
            setLoading(true);

            try {
                const response = await fetch(gdprConsentForm.ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams({
                        action: 'update_user_consent',
                        gdpr_nonce: gdprConsentForm.nonce,
                        consents: JSON.stringify(consents)
                    })
                });

                const data = await response.json();

                if (data.success) {
                    setNotification({
                        type: 'success',
                        message: gdprConsentForm.i18n.success
                    });
                    loadConsents(); // Refresh data
                } else {
                    throw new Error(data.data?.message || gdprConsentForm.i18n.error);
                }
            } catch (error) {
                setNotification({
                    type: 'error',
                    message: error.message
                });
            } finally {
                setLoading(false);
            }
        };

        const handleReset = async () => {
            if (!confirm(gdprConsentForm.i18n.confirmReset)) {
                return;
            }

            setLoading(true);
            try {
                const response = await fetch(gdprConsentForm.ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams({
                        action: 'reset_user_consent',
                        gdpr_nonce: gdprConsentForm.nonce
                    })
                });

                const data = await response.json();

                if (data.success) {
                    setConsents(data.consents);
                    setNotification({
                        type: 'success',
                        message: gdprConsentForm.i18n.resetSuccess
                    });
                }
            } catch (error) {
                setNotification({
                    type: 'error',
                    message: gdprConsentForm.i18n.resetError
                });
            } finally {
                setLoading(false);
            }
        };

        const loadHistory = async () => {
            try {
                const response = await fetch(
                    `${gdprConsentForm.ajaxUrl}?action=gdpr_get_consent_history&nonce=${gdprConsentForm.nonce}`
                );
                const data = await response.json();
                
                if (data.success) {
                    setUserHistory(data.history);
                }
            } catch (error) {
                console.error('Failed to load consent history:', error);
            }
        };

        if (loading) {
            return (
                <div className="gdpr-loading">
                    <div className="loading-spinner"></div>
                    <span>Loading...</span>
                </div>
            );
        }

        return (
            <div className="gdpr-consent-form">
                {notification && (
                    <div className={`notification ${notification.type}`}>
                        {notification.message}
                    </div>
                )}

                {outdatedConsents.length > 0 && (
                    <div className="notification warning">
                        <h4>Privacy Policy Updates</h4>
                        <p>Some privacy policies have been updated and require your review:</p>
                        <ul>
                            {outdatedConsents.map(type => (
                                <li key={type}>{gdprConsentForm.consentTypes[type]?.label}</li>
                            ))}
                        </ul>
                    </div>
                )}

                <form onSubmit={handleSubmit}>
                    {Object.entries(gdprConsentForm.consentTypes).map(([type, data]) => (
                        <div 
                            key={type}
                            className={`consent-option ${outdatedConsents.includes(type) ? 'outdated' : ''}`}
                        >
                            <div className="consent-header">
                                <label>
                                    <input
                                        type="checkbox"
                                        checked={consents[type] || false}
                                        onChange={(e) => {
                                            setConsents(prev => ({
                                                ...prev,
                                                [type]: e.target.checked
                                            }));
                                        }}
                                        disabled={data.required}
                                    />
                                    <span className="consent-label">
                                        {data.label}
                                        {data.required && <span className="required-badge">*</span>}
                                    </span>
                                </label>
                                {outdatedConsents.includes(type) && (
                                    <span className="outdated-badge">Update Required</span>
                                )}
                            </div>
                            <div className="consent-description">{data.description}</div>
                        </div>
                    ))}

                    <div className="consent-actions">
                        <div>
                            <button type="button" onClick={handleReset} disabled={loading}>
                                Reset Preferences
                            </button>
                            <button type="button" onClick={() => {
                                if (!showHistory) {
                                    loadHistory();
                                }
                                setShowHistory(!showHistory);
                            }}>
                                {showHistory ? 'Hide History' : 'View History'}
                            </button>
                        </div>
                        <button type="submit" disabled={loading}>
                            {loading ? 'Saving...' : 'Save Preferences'}
                        </button>
                    </div>
                </form>

                {showHistory && (
                    <div className="consent-history">
                        <h3>Consent History</h3>
                        {userHistory.map((entry, index) => (
                            <div key={index} className="history-entry">
                                <div className="history-timestamp">
                                    {new Date(entry.timestamp).toLocaleString()}
                                </div>
                                <div className="history-details">
                                    <strong>{gdprConsentForm.consentTypes[entry.consent_type]?.label}</strong>
                                    <span className={`status-badge ${entry.status ? 'granted' : 'withdrawn'}`}>
                                        {entry.status ? 'Granted' : 'Withdrawn'}
                                    </span>
                                    {entry.outdated && (
                                        <p className="outdated-notice">
                                            This consent was given under an older version of the privacy policy
                                        </p>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        );
    };

    // Initialize React consent form if container exists
    const consentFormContainer = document.getElementById('gdpr-consent-form-root');
    if (consentFormContainer && window.React && window.ReactDOM) {
        const { useState, useEffect } = React;
        ReactDOM.render(
            <ErrorBoundary>
                <ConsentForm />
            </ErrorBoundary>,
            consentFormContainer
        );
    }


    class ConsentManager {
        constructor(element) {
            this.form = $(element);
            this.notice = this.form.find('.gdpr-consent-notice');
            this.submitButton = this.form.find('button[type="submit"]');
            this.resetButton = this.form.find('.reset-consent');
            this.checkboxes = this.form.find('input[type="checkbox"]');

            this.initializeHistoryExport();
            this.initializeCookieBanner();
            this.bindGlobalEvents();
            this.bindEvents();
            this.initTooltips();
        }

        bindGlobalEvents() {
            $(document).on('gdpr:consentUpdated', () => {
                this.updateUI();
            });
        }

        bindEvents() {
            this.form.on('submit', (e) => this.handleSubmit(e));
            this.resetButton.on('click', () => this.handleReset());
            this.checkboxes.on('change', (e) => this.handleCheckboxChange(e));
        }
        

        initTooltips() {
            // Add custom tooltip functionality if needed
            $('.outdated-badge, .required').each(function() {
                $(this).tooltip({
                    position: { my: "center bottom", at: "center top-10" }
                });
            });
        }

        async handleSubmit(e) {
            e.preventDefault();

            // Validate required consents
            if (!this.validateRequiredConsents()) {
                return;
            }

            const consents = this.collectConsents();
            
            try {
                await this.updateConsents(consents);
            } catch (error) {
                console.error('GDPR Consent Error:', error);
                this.showNotice('error', error.message || gdprConsentForm.i18n.error);
            }
        }

        validateRequiredConsents() {
            let valid = true;
            this.checkboxes.filter('[required]').each((_, checkbox) => {
                if (!checkbox.checked) {
                    valid = false;
                    this.showNotice('error', gdprConsentForm.i18n.requiredConsent);
                }
            });
            return valid;
        }

        collectConsents() {
            const consents = {};
            this.checkboxes.each((_, checkbox) => {
                const $checkbox = $(checkbox);
                const type = $checkbox.data('consent-type');
                if (type) {
                    consents[type] = $checkbox.is(':checked') ? 1 : 0;
                }
            });
            return consents;
        }

        initializeCookieBanner() {
            const $banner = $('.gdpr-cookie-banner');
    
            if (!$banner.length) {
                return;
            }
        
            try {
                // Handle button clicks
                $banner.on('click', 'button', (e) => {
                    const $button = $(e.currentTarget);
                    const action = $button.data('action');
                    
                    if (!action) {
                        return;
                    }
                    
                switch (action) {
                    case 'accept':
                        this.handleAcceptAll();
                        break;
                    case 'preferences':
                        this.togglePreferences();
                        break;
                    case 'save':
                        this.handleSavePreferences();
                        break;
                    case 'reject':
                        this.handleRejectNonEssential();
                        break;
                }
            });
        } catch (error) {
            console.error('GDPR Cookie Banner Error:', error);
        }
    
    
            // Handle checkbox changes in preferences
            $banner.on('change', 'input[type="checkbox"]', (e) => {
                const $checkbox = $(e.currentTarget);
                if ($checkbox.prop('required') && !$checkbox.is(':checked')) {
                    $checkbox.prop('checked', true);
                    return false;
                }
            });
        }

        initializeHistoryExport() {
            $('.export-history').on('click', (e) => {
                const $button = $(e.currentTarget);
                const nonce = $button.data('nonce');
                const format = $('.export-format').val();
                
                $button.prop('disabled', true);
                
                if (format === 'json') {
                    window.location.href = gdprFramework.ajaxUrl + 
                        '?action=gdpr_export_consent_history' + 
                        '&nonce=' + nonce + 
                        '&format=json';
                    $button.prop('disabled', false);
                    return;
                }
                
                // CSV download via AJAX
                $.ajax({
                    url: gdprFramework.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'gdpr_export_consent_history',
                        nonce: nonce,
                        format: format
                    },
                    xhrFields: {
                        responseType: 'blob'
                    },
                    success: (blob) => {
                        const url = window.URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = `consent-history.${format}`;
                        document.body.appendChild(a);
                        a.click();
                        window.URL.revokeObjectURL(url);
                        document.body.removeChild(a);
                    },
                    error: () => {
                        alert(gdprConsentForm.i18n.exportError);
                    },
                    complete: () => {
                        $button.prop('disabled', false);
                    }
                });
            });
        }

        handleAcceptAll() {
            const consents = {};
            $('.gdpr-cookie-type input[type="checkbox"]').each((_, checkbox) => {
                const $checkbox = $(checkbox);
                const type = $checkbox.val();
                consents[type] = true;
            });
            
            this.updateCookieConsents(consents);
        }
    
        handleRejectNonEssential() {
            const consents = {};
            $('.gdpr-cookie-type input[type="checkbox"]').each((_, checkbox) => {
                const $checkbox = $(checkbox);
                const type = $checkbox.val();
                consents[type] = $checkbox.prop('required');
            });
            
            this.updateCookieConsents(consents);
        }
    
        handleSavePreferences() {
            const consents = {};
            $('.gdpr-cookie-type input[type="checkbox"]').each((_, checkbox) => {
                const $checkbox = $(checkbox);
                const type = $checkbox.val();
                consents[type] = $checkbox.is(':checked');
            });
            
            this.updateCookieConsents(consents);
        }
    
        togglePreferences() {
            const $prefs = $('.gdpr-cookie-preferences');
            const $banner = $('.gdpr-cookie-banner');
            
            if ($prefs.hasClass('active')) {
                $prefs.removeClass('active');
                $banner.find('.gdpr-cookie-message').show();
            } else {
                $prefs.addClass('active');
                $banner.find('.gdpr-cookie-message').hide();
            }
        }
    
        async updateCookieConsents(consents) {
            try {
                const response = await $.ajax({
                    url: gdprConsentForm.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'gdpr_update_cookie_consent',
                        consents: consents,
                        gdpr_nonce: gdprConsentForm.nonce
                    }
                });
    
                if (response.success) {
                    this.hideCookieBanner();
                    $(document).trigger('gdpr:consentUpdated', [consents]);
                } else {
                    throw new Error(response.data.message);
                }
            } catch (error) {
                console.error('GDPR Cookie Consent Error:', error);
                this.showNotice('error', error.message || gdprConsentForm.i18n.error);
            }
        }
    
        hideCookieBanner() {
            $('.gdpr-cookie-banner').fadeOut(300, function() {
                $(this).remove();
            });
        }

        async updateConsents(consents) {
            this.setLoading(true);
            this.clearNotice();

            try {
                const response = await $.ajax({
                    url: gdprConsentForm.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'update_user_consent',
                        consents: consents,
                        gdpr_nonce: gdprConsentForm.nonce
                    }
                });

                if (response.success) {
                    this.showNotice('success', gdprConsentForm.i18n.success);
                    this.form.trigger('consent:updated', [response.data.consents]);
                    this.updateUI(response.data.consents);
                } else {
                    throw new Error(response.data.message);
                }
            } finally {
                this.setLoading(false);
            }
        }

        handleCookieBannerInteraction(action) {
            if (action === 'accept') {
                this.updateConsents(GDPR_FRAMEWORK_CONSENT_OPTIONS.default_consent_types);
                this.hideCookieBanner();
            } else if (action === 'preferences') {
                this.showPreferences();
            }
        }
        
        showPreferences() {
            try {
                const preferencesUrl = gdprConsentForm.preferencesUrl || '#gdpr-consent-form';
                if (preferencesUrl.startsWith('#')) {
                    const $form = $(preferencesUrl);
                    if ($form.length) {
                        $('html, body').animate({
                            scrollTop: $form.offset().top - 50
                        }, 500);
                    }
                } else {
                    window.location.href = preferencesUrl;
                }
            } catch (error) {
                console.error('GDPR Error:', error);
            }
        }
        
        hideCookieBanner() {
            $('.gdpr-cookie-banner').fadeOut(300, function() {
                $(this).remove();
            });
        }

        handleReset() {
            if (!confirm(gdprConsentForm.i18n.confirmReset)) {
                return;
            }

            this.checkboxes.each((_, checkbox) => {
                const $checkbox = $(checkbox);
                if (!$checkbox.prop('required')) {
                    $checkbox.prop('checked', false);
                }
            });

            this.form.submit();
        }

        handleCheckboxChange(e) {
            const $checkbox = $(e.target);
            
            if ($checkbox.prop('required') && !$checkbox.is(':checked')) {
                $checkbox.prop('checked', true);
                this.showNotice('error', gdprConsentForm.i18n.requiredConsent);
                return false;
            }

            this.clearNotice();
        }

        setLoading(loading) {
            this.submitButton
                .prop('disabled', loading)
                .find('.text')
                .text(loading ? gdprConsentForm.i18n.updating : gdprConsentForm.i18n.update);
            
            this.checkboxes.not('[required]').prop('disabled', loading);
            this.resetButton.prop('disabled', loading);

            if (loading) {
                this.submitButton.addClass('is-loading');
            } else {
                this.submitButton.removeClass('is-loading');
            }
        }

        showNotice(type, message) {
            this.notice
                .removeClass('gdpr-success gdpr-error gdpr-warning')
                .addClass(`gdpr-${type}`)
                .html(message)
                .fadeIn();

            if (type === 'success') {
                setTimeout(() => this.clearNotice(), 5000);
            }
        }

        clearNotice() {
            this.notice.removeClass('gdpr-success gdpr-error gdpr-warning').fadeOut();
        }

        updateUI(consents) {
            // Update any UI elements that depend on consent status
            Object.entries(consents).forEach(([type, status]) => {
                $(`[data-consent-dependent="${type}"]`).toggle(status);
            });
        }
    }  
    
    // Initialize consent forms
    $('.gdpr-consent-form').each(function() {
        new ConsentManager(this);
    });
    
    // Initialize all cookie banners (both shortcode and footer)
    $('.gdpr-cookie-banner, #gdpr-cookie-banner-root').each(function() {
        console.log('Initializing cookie banner:', this);  // Debug line
        new ConsentManager(this);
    });
    
   // Global consent API
   window.gdprConsent = {
    hasConsent: function(type) {
        return $(`input[data-consent-type="${type}"]`).is(':checked');
    },
    onConsentChange: function(callback) {
        $(document).on('consent:updated', callback);
    }
};


})(jQuery);