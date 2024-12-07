jQuery(document).ready(function($) {
    'use strict';

    // Tab switching initialization
    function initializeTabs() {
        console.log('Initializing GDPR Framework tabs...'); // Add debug log
        
        // Ensure first tab is active on page load
        $('.tab-content').hide();
        $('.tab-content:first').addClass('active').show();
    
        $('.nav-tab').on('click', function(e) {
            e.preventDefault();
            
            var targetId = $(this).attr('href');
            console.log('Tab clicked:', targetId); // Add debug log
            
            // Update active states
            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            
            // Show/hide content
            $('.tab-content').removeClass('active').hide();
            $(targetId).addClass('active').fadeIn(200);
            
            // Store active tab
            if (typeof(localStorage) !== 'undefined') {
                localStorage.setItem('gdprActiveTab', targetId);
            }
        });
        
        // Restore last active tab if exists
        if (typeof(localStorage) !== 'undefined') {
            var lastTab = localStorage.getItem('gdprActiveTab');
            if (lastTab && $(lastTab).length) {
                $('.nav-tab[href="' + lastTab + '"]').trigger('click');
            }
        }
    }

    // Consent type management
    function initializeConsentTypes() {
        var consentTypeCount = $('#consent-types .consent-type-item').length;
        
        // Add new consent type
        $('#add-consent-type').on('click', function() {
            var template = $('#consent-type-template').html();
            template = template.replace(/{{id}}/g, consentTypeCount++);
            $('#consent-types').append(template);
        });

        // Remove consent type
        $(document).on('click', '.remove-consent-type', function() {
            if (confirm(gdprFrameworkAdmin.i18n.confirmDelete)) {
                $(this).closest('.consent-type-item').remove();
            }
        });
    }

    // Data request processing
    function initializeRequestProcessing() {
        $('.process-request').on('click', function() {
            const $button = $(this);
            const requestId = $button.data('id');
            const requestType = $button.data('type');
            const nonce = $button.data('nonce');

            if (!confirm(
                requestType === 'export' 
                    ? gdprFrameworkAdmin.i18n.confirmExport
                    : gdprFrameworkAdmin.i18n.confirmErasure
            )) {
                return;
            }

            $button.prop('disabled', true)
                   .text(gdprFrameworkAdmin.i18n.processing);

            $.ajax({
                url: gdprFrameworkAdmin.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'gdpr_process_request',
                    request_id: requestId,
                    request_type: requestType,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.message || gdprFrameworkAdmin.i18n.error);
                        $button.prop('disabled', false)
                               .text(gdprFrameworkAdmin.i18n.processRequest);
                    }
                },
                error: function() {
                    alert(gdprFrameworkAdmin.i18n.error);
                    $button.prop('disabled', false)
                           .text(gdprFrameworkAdmin.i18n.processRequest);
                }
            });
        });
    }

    // Key rotation handling
    function initializeKeyRotation() {
        $('#gdpr-rotate-key').on('click', function() {
            if (!confirm(gdprFrameworkAdmin.i18n.confirmRotation)) {
                return;
            }

            var $button = $(this);
            $button.prop('disabled', true)
                   .text(gdprFrameworkAdmin.i18n.rotating);

            $.ajax({
                url: gdprFrameworkAdmin.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'gdpr_rotate_key',
                    nonce: gdprFrameworkAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert(gdprFrameworkAdmin.i18n.rotateSuccess);
                        location.reload();
                    } else {
                        alert(response.data.message || gdprFrameworkAdmin.i18n.error);
                        $button.prop('disabled', false)
                               .text(gdprFrameworkAdmin.i18n.rotateKey);
                    }
                },
                error: function() {
                    alert(gdprFrameworkAdmin.i18n.error);
                    $button.prop('disabled', false)
                           .text(gdprFrameworkAdmin.i18n.rotateKey);
                }
            });
        });
    }

    // Manual cleanup handling
    function initializeManualCleanup() {
        $('#gdpr-manual-cleanup').on('click', function() {
            var $button = $(this);
            $button.prop('disabled', true)
                   .text(gdprFrameworkAdmin.i18n.cleaning);

            $.ajax({
                url: gdprFrameworkAdmin.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'gdpr_manual_cleanup',
                    nonce: $(this).data('nonce')
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(gdprFrameworkAdmin.i18n.error);
                        $button.prop('disabled', false)
                               .text(gdprFrameworkAdmin.i18n.cleanup);
                    }
                },
                error: function() {
                    alert(gdprFrameworkAdmin.i18n.error);
                    $button.prop('disabled', false)
                           .text(gdprFrameworkAdmin.i18n.cleanup);
                }
            });
        });
    }

    // Initialize all functionality
    function initialize() {
        initializeTabs();
        initializeConsentTypes();
        initializeRequestProcessing();
        initializeKeyRotation();
        initializeManualCleanup();
    }

    // Run initialization
    initialize();
});