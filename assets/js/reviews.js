/**
 * TrustScript Reviews Settings Page JavaScript
 */
(function($) {
    'use strict';

    /**
     * Create status badge element
     * @param {string} message - Badge text content
     * @param {string} type - Badge type: 'success' or 'danger'
     * @returns {HTMLElement} - Span element with badge styling
     */
    function createStatusBadge(message, type) {
        const span = document.createElement('span');
        span.className = 'trustscript-badge trustscript-badge-' + type;
        span.textContent = message;
        return span;
    }

    function initReviews() {
        bindTriggerStatusChange();
        bindSaveSettings();
        bindSyncOrders();
        bindDelayToggle();
        bindInternationalToggle();
        updateDelayDescription();
    }

    function updateDelayDescription() {
        const triggerStatus = $('#trustscript_review_trigger_status').val();
        const $desc = $('#delay-description');
        
        if (triggerStatus === 'delivered') {
            $desc.text(TrustscriptAdmin.i18n.delayDelivered);
        } else {
            $desc.text(TrustscriptAdmin.i18n.delayCompleted);
        }
    }

    function bindTriggerStatusChange() {
        $('#trustscript_review_trigger_status').on('change', updateDelayDescription);
    }

    function bindDelayToggle() {
        $('#trustscript_review_delay_hours').on('change', function() {
            $('#trustscript-custom-delay-wrapper').toggle($(this).val() === 'custom');
        });

        $('#trustscript_international_delay_hours').on('change', function() {
            $('#trustscript-custom-intl-delay-wrapper').toggle($(this).val() === 'custom');
        });
    }

    function bindInternationalToggle() {
        $('#trustscript_enable_international_handling').on('change', function() {
            if ($(this).is(':checked')) {
                $('#trustscript-international-delay-section').slideDown(300);
            } else {
                $('#trustscript-international-delay-section').slideUp(300);
            }
        });
    }

    function bindSaveSettings() {
        $('#trustscript-save-review-settings').on('click', function() {
            const $btn = $(this);
            const $status = $('#trustscript-review-save-status');
            
            $btn.prop('disabled', true).text(TrustscriptAdmin.i18n.saving);
            $status.empty();

            const categories = [];
            $('input[name="trustscript_review_categories[]"]:checked').each(function() {
                categories.push($(this).val());
            });

            const memberpress_memberships = [];
            $('input[name="trustscript_memberpress_memberships[]"]:checked').each(function() {
                memberpress_memberships.push($(this).val());
            });

            const keywords = [];
            $('input[name="trustscript_keywords[]"]:checked').each(function() {
                keywords.push($(this).val());
            });

            let delay_hours = $('#trustscript_review_delay_hours').val();
            if (delay_hours === 'custom') {
                const customValue = $('#trustscript_custom_delay_value').val() || '0';
                const customUnit = $('#trustscript_custom_delay_unit').val() || 'days';
                delay_hours = customUnit === 'hours' ? customValue : (parseInt(customValue, 10) * 24);
            }

            let intl_delay_hours = $('#trustscript_international_delay_hours').val();
            if (intl_delay_hours === 'custom') {
                const customIntlValue = $('#trustscript_custom_intl_delay_value').val() || '0';
                const customIntlUnit = $('#trustscript_custom_intl_delay_unit').val() || 'days';
                intl_delay_hours = customIntlUnit === 'hours' ? customIntlValue : (parseInt(customIntlValue, 10) * 24);
            }

            const data = {
                action: 'trustscript_save_review_settings',
                nonce: TrustscriptAdmin.save_review_nonce,
                enabled: $('#trustscript_reviews_enabled').is(':checked'),
                categories: categories,
                memberpress_memberships: memberpress_memberships,
                trustscript_review_keywords: keywords,
                memberpress_delay_days: $('#trustscript_memberpress_delay_days').val(),
                auto_publish: $('#trustscript_auto_publish').is(':checked'),
                enable_voting: $('#trustscript_enable_voting').is(':checked'),
                delay_hours: delay_hours,
                trigger_status: $('#trustscript_review_trigger_status').val(),
                auto_sync_enabled: $('#trustscript_auto_sync_enabled').is(':checked'),
                auto_sync_time: $('#trustscript_auto_sync_time').val(),
                auto_sync_lookback: $('#trustscript_auto_sync_lookback').val(),
                trustscript_woocommerce_min_value: $('#trustscript_woocommerce_min_value').val(),
                trustscript_woocommerce_exclude_free: $('#trustscript_woocommerce_exclude_free').is(':checked') ? '1' : '0',
                enable_international_handling: $('#trustscript_enable_international_handling').is(':checked') ? 'true' : 'false',
                international_delay_hours: intl_delay_hours
            };

            $.post(TrustscriptAdmin.ajax_url, data, function(response) {
                $btn.prop('disabled', false).text(TrustscriptAdmin.i18n.saveButton);
                
                if (response.success) {
                    $status.empty().append(createStatusBadge(TrustscriptAdmin.i18n.saveSuccess, 'success'));
                    setTimeout(function() { $status.empty(); }, 3000);
                } else {
                    var message = (response.data && response.data.message) ? response.data.message : TrustscriptAdmin.i18n.syncFailed;
                    $status.empty().append(createStatusBadge(message, 'danger'));
                }
            }).fail(function() {
                $btn.prop('disabled', false).text(TrustscriptAdmin.i18n.saveButton);
                $status.empty().append(createStatusBadge(TrustscriptAdmin.i18n.networkError, 'danger'));
            });
        });
    }

    function bindSyncOrders() {
        $('#trustscript-sync-orders').on('click', function() {
            const $btn = $(this);
            const $status = $('#trustscript-sync-status');
            const $results = $('#trustscript-sync-results');
            const $count = $('#sync-count');
            
            if (!$btn.data('syncConfirmed')) {
                $status.html(
                    '<div style="padding:12px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;margin-bottom:12px;">' +
                    '<p style="margin:0 0 8px 0;font-weight:600;">' + TrustscriptAdmin.i18n.syncConfirm + '</p>' +
                    '<button type="button" class="sync-confirm-yes button button-primary" style="margin-right:6px;">Yes, Sync</button>' +
                    '<button type="button" class="sync-confirm-no button">Cancel</button>' +
                    '</div>'
                ).show();
                
                $status.find('.sync-confirm-yes').on('click', function() {
                    $btn.data('syncConfirmed', true);
                    $btn.click();
                });
                
                $status.find('.sync-confirm-no').on('click', function() {
                    $btn.data('syncConfirmed', false);
                    $status.empty();
                });
                
                return;
            }
            
            $btn.data('syncConfirmed', false);
            
            $btn.prop('disabled', true).empty();
            const spinIcon = document.createElement('span');
            spinIcon.className = 'dashicons dashicons-update trustscript-spin';
            $btn.append(spinIcon).append(document.createTextNode(' ' + TrustscriptAdmin.i18n.syncing));
            $status.empty();
            $results.hide();

            const data = {
                action: 'trustscript_sync_orders',
                nonce: TrustscriptAdmin.nonce,
                days: $('#trustscript-sync-days').val()
            };

            $.post(TrustscriptAdmin.ajax_url, data, function(response) {
                $btn.prop('disabled', false).empty();
                const icon = document.createElement('span');
                icon.className = 'dashicons dashicons-update';
                $btn.append(icon).append(document.createTextNode(' ' + TrustscriptAdmin.i18n.syncButton));
                
                if (response.success) {
                    const processed = response.data.processed || 0;
                    const reviewsPublished = response.data.reviews_published || 0;
                    const ordersSynced = response.data.orders_synced || 0;
                    const ordersSkipped = response.data.orders_skipped || 0;
                    
                    $count.text(processed);
                    $results.fadeIn();
                    
                    let fullMessage = response.data.message;
                    
                    if (reviewsPublished > 0 || ordersSynced > 0 || ordersSkipped > 0) {
                        fullMessage += '\n\n📊 ' + TrustscriptAdmin.i18n.syncBreakdown;
                        if (reviewsPublished > 0) fullMessage += '\n✅ ' + reviewsPublished + ' ' + TrustscriptAdmin.i18n.syncReviewsPublished;
                        if (ordersSynced > 0) fullMessage += '\n📤 ' + ordersSynced + ' ' + TrustscriptAdmin.i18n.syncOrdersSent;
                        if (ordersSkipped > 0) fullMessage += '\n⏭️ ' + ordersSkipped + ' ' + TrustscriptAdmin.i18n.syncOrdersSkipped;
                    }
                    
                    const statusDiv = createStatusBadge(fullMessage, 'success');
                    if (reviewsPublished > 0 || ordersSynced > 0 || ordersSkipped > 0) {
                        statusDiv.style.whiteSpace = 'pre-wrap';
                        statusDiv.style.lineHeight = '1.8';
                    }
                    $status.empty().append(statusDiv);
                    setTimeout(function() { $status.empty(); }, 8000);
                } else {
                    var message = (response.data && response.data.message) ? response.data.message : TrustscriptAdmin.i18n.syncFailed;
                    $status.empty().append(createStatusBadge(message, 'danger'));
                }
            }).fail(function() {
                $btn.prop('disabled', false).empty();
                const icon = document.createElement('span');
                icon.className = 'dashicons dashicons-update';
                $btn.append(icon).append(document.createTextNode(' ' + TrustscriptAdmin.i18n.syncButton));
                $status.empty().append(createStatusBadge(TrustscriptAdmin.i18n.networkError, 'danger'));
            });
        });
    }

    $(document).ready(function() {
        initReviews();
    });

})(jQuery);
