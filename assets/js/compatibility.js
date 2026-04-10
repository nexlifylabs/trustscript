/**
 * TrustScript Compatibility Notice script
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        $(document).on('click', '.notice[data-notice-id] .notice-dismiss', function(e) {
            var $notice = $(this).closest('.notice[data-notice-id]');
            var noticeId = $notice.data('notice-id');

            if (!noticeId || !TrustScriptCompatibility.nonce) {
                return;
            }

            $.ajax({
                url: TrustScriptCompatibility.ajax_url,
                type: 'POST',
                data: {
                    action: 'trustscript_dismiss_notice',
                    notice_id: noticeId,
                    nonce: TrustScriptCompatibility.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $notice.fadeOut(function() {
                            $(this).remove();
                        });
                    }
                },
            });
        });
    });

})(jQuery);
