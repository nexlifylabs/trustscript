/**
 * TrustScript Analytics Page
 */
(function($) {
    'use strict';

    let usageChart = null;

    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    function loadAnalytics() {
        
        $.ajax({
            url: TrustscriptAdmin.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'trustscript_fetch_review_stats',
                nonce: TrustscriptAdmin.nonce
            },
            timeout: 15000,
            success: function(response) {
                
                if (response.success && response.data) {
                    const data = response.data;
                    
                    $('#stat-total-requests').text(data.totalRequests || 0);
                    $('#stat-approved').text(data.approvedReviews || 0);
                    $('#stat-pending').text(data.pendingReviews || 0);
                    $('#stat-conversion').text((data.conversionRate || 0).toFixed(1) + '%');

                    if (data.recentActivity && Array.isArray(data.recentActivity) && data.recentActivity.length > 0) {
                        let activityHtml = '';
                        const recentActivities = data.recentActivity.slice(0, 10);
                        
                        recentActivities.forEach(function(activity) {
                            const statusClass = activity.status === 'approved' ? 'success' : 
                                              activity.status === 'pending' ? 'warning' : 'info';
                            const statusLabel = activity.status ? activity.status.charAt(0).toUpperCase() + activity.status.slice(1) : 'Unknown';
                            
                            let projectInfo = '';
                            if (activity.projectName) {
                                projectInfo = ' • ' + escapeHtml(activity.projectName);
                            }
                            
                            activityHtml += '<div class="trustscript-activity-item">';
                            activityHtml += '<div class="trustscript-activity-dot trustscript-activity-dot-' + statusClass + '"></div>';
                            activityHtml += '<div class="trustscript-activity-content">';
                            activityHtml += '<div class="trustscript-activity-title">' + escapeHtml(activity.productName || 'Review') + projectInfo + '</div>';
                            activityHtml += '<div class="trustscript-activity-time">';
                            activityHtml += '<span class="trustscript-badge trustscript-badge-' + statusClass + '">' + statusLabel + '</span> ';
                            activityHtml += escapeHtml(activity.timestamp || '');
                            activityHtml += '</div>';
                            activityHtml += '</div>';
                            activityHtml += '</div>';
                        });
                        
                        $('#trustscript-recent-activity').html(activityHtml);
                    } else {
                        const noActivityMsg = TrustscriptAdmin.i18n && TrustscriptAdmin.i18n.noActivity ? TrustscriptAdmin.i18n.noActivity : 'No recent activity';
                        const configMsg = TrustscriptAdmin.i18n && TrustscriptAdmin.i18n.configureSettings ? TrustscriptAdmin.i18n.configureSettings : 'Start by configuring review settings';
                        
                        $('#trustscript-recent-activity').html(
                            '<div class="trustscript-activity-item">' +
                            '<div class="trustscript-activity-dot"></div>' +
                            '<div class="trustscript-activity-content">' +
                            '<div class="trustscript-activity-title">' + escapeHtml(noActivityMsg) + '</div>' +
                            '<div class="trustscript-activity-time">' + escapeHtml(configMsg) + '</div>' +
                            '</div>' +
                            '</div>'
                        );
                    }
                } else {
                    showError(response.data && response.data.message ? response.data.message : 'Failed to load analytics data');
                }
            },
            error: function(xhr, status, error) {                
                showError('Failed to load analytics data. Please check your API connection in Settings.');
                $('#stat-total-requests, #stat-approved, #stat-pending').text('0');
                $('#stat-conversion').text('0%');
            }
        });
    }

    function showError(message) {
        const errorDiv = $('#trustscript-analytics-error');
        if (errorDiv.length) {
            errorDiv.html(
                '<div class="trustscript-notice trustscript-notice-error">' +
                '<p>' + escapeHtml(message) + '</p>' +
                '</div>'
            ).show();
        }
    }

    $(document).ready(function() {
        
        loadAnalytics();
        $('#trustscript-refresh-analytics').on('click', function() {
            const refreshingText = TrustscriptAdmin.i18n && TrustscriptAdmin.i18n.refreshing ? TrustscriptAdmin.i18n.refreshing : 'Refreshing...';
            const refreshButtonText = TrustscriptAdmin.i18n && TrustscriptAdmin.i18n.refreshButton ? TrustscriptAdmin.i18n.refreshButton : 'Refresh Analytics';
            
            $(this).prop('disabled', true).text(refreshingText);
            loadAnalytics();
            setTimeout(function() {
                $('#trustscript-refresh-analytics').prop('disabled', false).text(refreshButtonText);
            }, 1000);
        });
    });

})(jQuery);
