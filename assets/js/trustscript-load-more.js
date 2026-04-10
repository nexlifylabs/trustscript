/**
 * TrustScript Unified Load More Functionality
 *
 * @package TrustScript
 * @since 1.0.0
 */

(function($) {
    'use strict';

    var TrustScriptLoadMore = {

        init: function() {
            this.bindClientPagination();
            this.bindServerPagination();
        },

        bindClientPagination: function() {
            $(document).on('click', '.trustscript-load-more-btn[data-mode="client"]', function(e) {
                e.preventDefault();
                TrustScriptLoadMore.handleClientLoadMore(this);
            });
        },

        bindServerPagination: function() {
            $(document).on('click', '.trustscript-load-more-btn[data-mode="server"]', function(e) {
                e.preventDefault();
                TrustScriptLoadMore.handleServerLoadMore(this);
            });
        },

        handleClientLoadMore: function(button) {
            var $button = $(button);
            var targetId = $button.closest('.trustscript-load-more-wrapper').data('target');
            var $wrapper = $('#' + targetId);
            var increment = parseInt($wrapper.attr('data-increment')) || 5;
            var total = parseInt($wrapper.attr('data-total')) || 0;
            var $visibleReviews = $wrapper.find('.trustscript-review-wrapper:not(.trustscript-review-hidden)');
            var currentCount = $visibleReviews.length;
            var $hiddenReviews = $wrapper.find('.trustscript-review-wrapper.trustscript-review-hidden');
            var showCount = Math.min(increment, $hiddenReviews.length);

            $hiddenReviews.slice(0, showCount).removeClass('trustscript-review-hidden').hide().fadeIn(400);

            var newVisibleCount = currentCount + showCount;
            if (newVisibleCount >= total) {
                $button.fadeOut(300, function() {
                    $(this).closest('.trustscript-load-more-wrapper').remove();
                });
            }
        },

        handleServerLoadMore: function(button) {
            var $button = $(button);
            var $container = $button.closest('.trustscript-load-more-wrapper').prev();
            var page = parseInt($button.data('page') || 1) + 1;
            var builderId = $button.data('builder-id') || 'generic';
            var options = $container.data('options') || {};
            var nonce = $button.data('nonce') || trustscriptLoadMore.nonce;

            $button.prop('disabled', true).text(trustscriptLoadMore.i18n.loading || 'Loading...');

            $.ajax({
                url: trustscriptLoadMore.ajaxurl,
                type: 'POST',
                data: {
                    action: 'trustscript_load_more_reviews_' + builderId,
                    nonce: nonce,
                    page: page,
                    options: JSON.stringify(options)
                },
                success: function(response) {
                    if (response.success && response.data.html) {
                        $container.append(response.data.html);
                        $button.data('page', page);

                        if (!response.data.has_more) {
                            $button.fadeOut(300, function() {
                                $(this).closest('.trustscript-load-more-wrapper').remove();
                            });
                        } else {
                            $button.prop('disabled', false).text(trustscriptLoadMore.i18n.loadMore || 'Load More Reviews');
                        }
                    } else {
                        $button.fadeOut(300, function() {
                            $(this).closest('.trustscript-load-more-wrapper').remove();
                        });
                    }
                },
                error: function() {
                    $button.prop('disabled', false).text(trustscriptLoadMore.i18n.loadMore || 'Load More Reviews');
                }
            });
        }
    };

    $(document).ready(function() {
        TrustScriptLoadMore.init();
    });

    window.TrustScriptLoadMore = TrustScriptLoadMore;

})(jQuery);