/**
 * TrustScript Review Requests Page
 * @author TrustScript
 * @since 1.0.0
 */
(function ($) {
    'use strict';

    var currentPage = 1;
    var isLoading   = false;

    var STATUS_CFG = {
        published: { label: 'Published',   badge: 'success'   },
        pending:   { label: 'Pending',     badge: 'warning'   },
        scheduled: { label: 'Scheduled',   badge: 'warning'   },
        'opt-out': { label: 'Opt-Out',     badge: 'secondary' },
        'consent_pending': { label: 'Awaiting Consent', badge: 'info' },
    };

    function escapeHtml(text) {
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text == null ? '' : text).replace(/[&<>"']/g, function (m) { return map[m]; });
    }

    function loadPage(page) {
        if (isLoading) { return; }
        isLoading   = true;
        currentPage = page;

        $('#review-requests-loading').show();
        $('#review-requests-list').hide();
        $('#review-requests-empty').hide();

        $.post(
            TrustscriptAdmin.ajax_url,
            {
                action:     'trustscript_fetch_review_requests',
                nonce:      TrustscriptAdmin.nonce,
                page:       page,
                search:     $('#rr-search').val().trim(),
                status:     $('#rr-status-filter').val(),
                date_range: $('#rr-date-filter').val(),
            },
            function (response) {
                isLoading = false;
                $('#review-requests-loading').hide();

                if (!response.success || !response.data) {
                    $('#review-requests-empty')
                        .html(
                            '<div style="text-align:center;padding:40px 20px;">' +
                            '<div style="font-size:13px;color:#d32f2f;margin-bottom:8px;">Failed to load orders</div>' +
                            '<div style="font-size:12px;color:#999;">Please try again or refresh the page.</div>' +
                            '</div>'
                        )
                        .show();
                    return;
                }

                var data = response.data;

                renderStats(data.stats || {});

                if (!data.orders || !data.orders.length) {
                    $('#review-requests-empty').show();
                    return;
                }

                var start = (data.page - 1) * data.perPage + 1;
                var end   = Math.min(data.page * data.perPage, data.total);
                $('#rr-results-info').html(
                    'Showing <strong>' + start + '–' + end + '</strong> of <strong>' + data.total + '</strong> orders'
                );

                renderTable(data.orders);
                renderPagination(data.page, data.pages);

                $('#review-requests-list').show();
            }
        ).fail(function () {
            isLoading = false;
            $('#review-requests-loading').hide();
            $('#review-requests-empty')
                .html(
                    '<div style="text-align:center;padding:40px 20px;">' +
                    '<div style="font-size:13px;color:#d32f2f;margin-bottom:8px;">Network error</div>' +
                    '<div style="font-size:12px;color:#999;">Please check your connection and try again.</div>' +
                    '</div>'
                )
                .show();
        });
    }

    function renderStats(stats) {
        $('#rr-stat-total').text(stats.total     != null ? stats.total     : 0);
        $('#rr-stat-scheduled').text(stats.scheduled != null ? stats.scheduled : 0);
        $('#rr-stat-pending').text(stats.pending   != null ? stats.pending   : 0);
        $('#rr-stat-published').text(stats.published != null ? stats.published : 0);
        $('#rr-stat-optout').text(stats.optOut    != null ? stats.optOut    : 0);
        $('#rr-stat-consent-pending').text(stats.consentPending != null ? stats.consentPending : 0);
    }

    function renderTable(orders) {
        var html = '';

        orders.forEach(function (order) {
            var statusColors = {
                'published': { bg: '#ecfdf5', color: '#10b981' },
                'pending': { bg: '#eff6ff', color: '#3b82f6' },
                'scheduled': { bg: '#fffbeb', color: '#f59e0b' },
                'opt-out': { bg: '#faf5ff', color: '#8b5cf6' },
                'consent_pending': { bg: '#fef3c7', color: '#b45309' }
            };
            var cfg = statusColors[order.status] || { bg: '#f3f4f6', color: '#6b7280' };
            var statusLabel = STATUS_CFG[order.status] ? STATUS_CFG[order.status].label : (order.status.charAt(0).toUpperCase() + order.status.slice(1));

            var sentHtml;
            if (order.status === 'consent_pending') {
                sentHtml = '<span style="color:#b45309;font-size:11px;">Awaiting confirmation</span>';
            } else if (order.status === 'scheduled' && order.scheduledFor) {
                sentHtml = '<span title="Scheduled send time" style="color:#999;">' + escapeHtml(order.scheduledFor) + '</span>';
            } else if (order.sentDate) {
                sentHtml = '<span style="color:#999;">' + escapeHtml(order.sentDate) + '</span>';
            } else {
                sentHtml = '<span style="color:#ddd;">—</span>';
            }

            var productHtml = order.productUrl
                ? '<a href="' + escapeHtml(order.productUrl) + '" target="_blank" style="color:#2563eb;text-decoration:none;font-weight:500;">' + escapeHtml(order.productName) + '</a>'
                : '<span style="font-weight:500;">' + escapeHtml(order.productName) + '</span>';

            var actionHtml;
            if (order.status === 'published' && order.productUrl) {
                actionHtml =
                    '<a href="' + escapeHtml(order.productUrl) + '" target="_blank" class="trustscript-action-link" ' +
                    'title="View product page">View</a>';
            } else {
                actionHtml = '<span style="color:#ddd;">—</span>';
            }

            var consentColors = {
                'consent-na':        { bg: '#f3f4f6', color: '#6b7280' },
                'consent-confirmed': { bg: '#ecfdf5', color: '#10b981' },
                'consent-pending':   { bg: '#fef3c7', color: '#b45309' },
                'consent-declined':  { bg: '#fef2f2', color: '#dc2626' }
            };
            var consentCfg = consentColors[order.consentClass] || { bg: '#f3f4f6', color: '#6b7280' };
            var tooltipText = 'Country: ' + escapeHtml(order.consentCountry);
            if (order.consentSubtext) {
                tooltipText += ' (' + escapeHtml(order.consentSubtext) + ')';
            }
            
            var consentHtml = '<span style="background:' + consentCfg.bg + ';color:' + consentCfg.color + ';padding:4px 8px;border-radius:4px;font-size:11px;font-weight:600;display:inline-block;cursor:help;" title="' + escapeHtml(tooltipText) + '">' + escapeHtml(order.consentDisplay) + '</span>';

            html += '<tr style="border-bottom:1px solid #f3f4f6;hover:background:#fafafa;">';
            html += '<td style="padding:12px;"><a href="' + escapeHtml(order.orderAdminUrl) + '" style="color:#2563eb;text-decoration:none;font-weight:500;">' + escapeHtml(order.orderId) + '</a></td>';
            html += '<td style="padding:12px;color:#666;">' + escapeHtml(order.customerName) + '</td>';
            html += '<td style="padding:12px;">' + productHtml + '</td>';
            html += '<td style="padding:12px;color:#999;font-size:12px;">' + escapeHtml(order.orderDate) + '</td>';
            html += '<td style="padding:12px;font-size:12px;">' + sentHtml + '</td>';
            html += '<td style="padding:12px;">' + consentHtml + '</td>';
            html += '<td style="padding:12px;"><span style="background:' + cfg.bg + ';color:' + cfg.color + ';padding:4px 8px;border-radius:4px;font-size:11px;font-weight:600;display:inline-block;">' + escapeHtml(statusLabel) + '</span></td>';
            html += '<td style="padding:12px;text-align:center;">' + actionHtml + '</td>';
            html += '</tr>';
        });

        $('#review-requests-tbody').html(html);
    }

    function renderPagination(currentPg, totalPages) {
        if (totalPages <= 1) {
            $('#rr-pagination').hide();
            return;
        }

        $('#rr-pagination').show();
        $('#rr-pagination-info').text('Page ' + currentPg + ' of ' + totalPages);

        var html    = '';
        var maxBtns = 5;
        var startPg = Math.max(1, currentPg - Math.floor(maxBtns / 2));
        var endPg   = Math.min(totalPages, startPg + maxBtns - 1);
        if (endPg - startPg < maxBtns - 1) {
            startPg = Math.max(1, endPg - maxBtns + 1);
        }

        if (currentPg > 1) {
            html += btn(currentPg - 1, '← Previous', false);
        }

        if (startPg > 1) {
            html += btn(1, '1', false);
            if (startPg > 2) { html += '<span style="padding:0 8px;color:#ddd;">…</span>'; }
        }

        for (var i = startPg; i <= endPg; i++) {
            html += btn(i, i, i === currentPg);
        }

        if (endPg < totalPages) {
            if (endPg < totalPages - 1) { html += '<span style="padding:0 8px;color:#ddd;">…</span>'; }
            html += btn(totalPages, totalPages, false);
        }

        if (currentPg < totalPages) {
            html += btn(currentPg + 1, 'Next →', false);
        }

        $('#rr-pagination-buttons').html(html);
        $(document).off('click.rrpag').on('click.rrpag', '.trustscript-page-btn', function () {
            var pg = parseInt($(this).data('page'), 10);
            loadPage(pg);
            $('html, body').animate({ scrollTop: $('#review-requests-list').offset().top - 20 }, 300);
        });
    }

    function btn(page, label, isActive) {
        var style = isActive
            ? 'background:#2563eb;color:#fff;border:1px solid #2563eb;'
            : 'background:#fff;color:#1a1a1a;border:1px solid #e5e7eb;';
        var ariaAttr = isActive ? ' aria-current="page"' : '';
        return '<button class="trustscript-page-btn" data-page="' + page + '" style="' + style + 'padding:6px 12px;border-radius:4px;font-size:12px;font-weight:500;cursor:pointer;transition:all 0.2s;"' + ariaAttr + '>' + label + '</button> ';
    }

    function resetAndLoad() {
        currentPage = 1;
        loadPage(1);
    }

    $(document).ready(function () {
        loadPage(1);

        $('#rr-apply-filters').on('click', resetAndLoad);

        $('#rr-search').on('keypress', function (e) {
            if (e.which === 13) { resetAndLoad(); }
        });

        $('#rr-status-filter, #rr-date-filter').on('change', resetAndLoad);

        $('#refresh-review-requests').on('click', function () {
            var $btn = $(this);
            var originalText = $btn.text();
            $btn.prop('disabled', true).text('Refreshing...');
            loadPage(currentPage);
            setTimeout(function () {
                $btn.prop('disabled', false).text(originalText);
            }, 1500);
        });
    });

})(jQuery);