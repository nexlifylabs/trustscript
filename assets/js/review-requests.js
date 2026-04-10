/**
 * TrustScript Review Requests Page JavaScript
 */
(function($) {
    'use strict';

    let allRequests = [];
    let filteredRequests = [];
    let currentPage = 1;
    const itemsPerPage = 10;

    function initReviewRequests() {
        loadReviewRequests();
        bindRefreshButton();
        bindFilterControls();
    }

    function loadReviewRequests() {
        $('#review-requests-loading').show();
        $('#review-requests-list').hide();
        $('#review-requests-empty').hide();

        $.post(TrustscriptAdmin.ajax_url, {
            action: 'trustscript_fetch_review_requests',
            nonce: TrustscriptAdmin.nonce
        }, function(response) {
            if (response.success && response.data) {
                const data = response.data;

                $('#rr-stat-total').text(data.stats.total || 0);
                $('#rr-stat-pending').text(data.stats.pending || 0);
                $('#rr-stat-approved').text(data.stats.approved || 0);
                $('#rr-stat-conversion').text((data.stats.conversionRate || 0) + '%');

                allRequests = data.requests || [];
                
                applyFilters();
            } 
            $('#review-requests-loading').hide();
        }).fail(function(xhr, status, error) {
            $('#review-requests-loading').hide();
            $('#review-requests-empty').html('<p style="color: #d32f2f;">Failed to load review requests. Please check your API connection.</p>').show();
        });
    }

    function applyFilters() {
        const searchTerm = $('#rr-search').val().toLowerCase().trim();
        const statusFilter = $('#rr-status-filter').val();
        const dateFilter = $('#rr-date-filter').val();
        
        filteredRequests = allRequests.filter(function(req) {
            if (searchTerm) {
                const cleanSearchTerm = searchTerm.replace(/^#/, '');
                
                const searchableText = [
                    req.productName || '',
                    req.sourceOrderId || ''
                ].join(' ').toLowerCase();
                
                if (searchableText.indexOf(searchTerm) === -1 && searchableText.indexOf(cleanSearchTerm) === -1) {
                    return false;
                }
            }
            
            if (statusFilter && req.status !== statusFilter) {
                return false;
            }
            
            if (dateFilter && req.dateObj) {
                const daysAgo = parseInt(dateFilter);
                const filterDate = new Date();
                filterDate.setDate(filterDate.getDate() - daysAgo);
                
                if (new Date(req.dateObj) < filterDate) {
                    return false;
                }
            }
            
            return true;
        });
        
        currentPage = 1;
        renderResults();
    }

    function renderResults() {
        
        if (filteredRequests.length === 0) {
            $('#review-requests-list').hide();
            $('#review-requests-empty').show();
            return;
        }
        
        $('#review-requests-empty').hide();
        $('#review-requests-list').show();
        
        const totalPages = Math.ceil(filteredRequests.length / itemsPerPage);
        const startIndex = (currentPage - 1) * itemsPerPage;
        const endIndex = startIndex + itemsPerPage;
        const pageRequests = filteredRequests.slice(startIndex, endIndex);
        const showing = filteredRequests.length > 0 ? 
            (startIndex + 1) + '-' + Math.min(endIndex, filteredRequests.length) : 0;
        $('#rr-results-info').html(
            '<strong>' + showing + '</strong> of <strong>' + filteredRequests.length + '</strong> requests' +
            (allRequests.length !== filteredRequests.length ? ' (filtered from ' + allRequests.length + ' total)' : '')
        );
        
        renderTable(pageRequests);
        renderPagination(totalPages);
    }

    function renderTable(requests) {
        let html = '';
        
        requests.forEach(function(req) {
            const statusClass = req.status === 'approved' ? 'success' : 'warning';
            const statusLabel = req.status === 'approved' ? 'Approved' : 'Pending';
            
            let projectStatusHtml = '';
            if (req.projectName) {
                projectStatusHtml = '<span class="trustscript-badge trustscript-badge-info">';
                projectStatusHtml += escapeHtml(req.projectName);
                projectStatusHtml += '</span>';
            } else {
                projectStatusHtml = '<span class="trustscript-badge trustscript-badge-secondary">Unknown</span>';
            }
            
            html += '<tr>';
            html += '<td>' + (req.sourceOrderId ? '#' + escapeHtml(req.sourceOrderId) : 'N/A') + '</td>';
            html += '<td>' + escapeHtml(req.productName || 'Review Request') + '</td>';
            html += '<td><span class="trustscript-badge trustscript-badge-' + statusClass + '">' + statusLabel + '</span></td>';
            html += '<td>' + projectStatusHtml + '</td>';
            html += '<td>' + escapeHtml(req.date || 'N/A') + '</td>';
            html += '<td>';
            html += '<a href="' + escapeHtml(req.dashboardUrl) + '" target="_blank" class="trustscript-btn trustscript-btn-sm trustscript-btn-secondary" title="View in TrustScript dashboard">View Details</a>';
            html += '</td>';
            html += '</tr>';
        });
        
        $('#review-requests-tbody').html(html);
    }

    function renderPagination(totalPages) {
        if (totalPages <= 1) {
            $('#rr-pagination').hide();
            return;
        }
        
        $('#rr-pagination').show();
        $('#rr-pagination-info').text('Page ' + currentPage + ' of ' + totalPages);
        
        let buttonsHtml = '';
        if (currentPage > 1) {
            buttonsHtml += '<button class="trustscript-btn trustscript-btn-sm trustscript-btn-secondary rr-page-btn" data-page="' + (currentPage - 1) + '">← Previous</button> ';
        }
        
        const maxButtons = 5;
        let startPage = Math.max(1, currentPage - Math.floor(maxButtons / 2));
        let endPage = Math.min(totalPages, startPage + maxButtons - 1);
        
        if (endPage - startPage < maxButtons - 1) {
            startPage = Math.max(1, endPage - maxButtons + 1);
        }
        
        if (startPage > 1) {
            buttonsHtml += '<button class="trustscript-btn trustscript-btn-sm trustscript-btn-secondary rr-page-btn" data-page="1">1</button> ';
            if (startPage > 2) {
                buttonsHtml += '<span style="padding: 0 8px;">...</span> ';
            }
        }
        
        for (let i = startPage; i <= endPage; i++) {
            const activeClass = i === currentPage ? 'trustscript-btn-primary' : 'trustscript-btn-secondary';
            buttonsHtml += '<button class="trustscript-btn trustscript-btn-sm ' + activeClass + ' rr-page-btn" data-page="' + i + '">' + i + '</button> ';
        }
        
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                buttonsHtml += '<span style="padding: 0 8px;">...</span> ';
            }
            buttonsHtml += '<button class="trustscript-btn trustscript-btn-sm trustscript-btn-secondary rr-page-btn" data-page="' + totalPages + '">' + totalPages + '</button> ';
        }
        
        if (currentPage < totalPages) {
            buttonsHtml += '<button class="trustscript-btn trustscript-btn-sm trustscript-btn-secondary rr-page-btn" data-page="' + (currentPage + 1) + '">Next →</button>';
        }
        
        $('#rr-pagination-buttons').html(buttonsHtml);
        $('.rr-page-btn').on('click', function() {
            currentPage = parseInt($(this).data('page'));
            renderResults();
            
            $('html, body').animate({
                scrollTop: $('#review-requests-list').offset().top - 20
            }, 300);
        });
    }

    function bindFilterControls() {
        $('#rr-apply-filters').on('click', function() {
            applyFilters();
        });
        
        $('#rr-search').on('keypress', function(e) {
            if (e.which === 13) {
                applyFilters();
            }
        });
        
        $('#rr-status-filter, #rr-date-filter').on('change', function() {
            applyFilters();
        });
    }

    function bindRefreshButton() {
        $('#refresh-review-requests').on('click', function() {
            $(this).prop('disabled', true).text(TrustscriptAdmin.i18n.refreshing);
            loadReviewRequests();
            setTimeout(function() {
                $('#refresh-review-requests').prop('disabled', false).text('Refresh');
            }, 1000);
        });
    }

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

    $(document).ready(function() {
        initReviewRequests();
    });

})(jQuery);
