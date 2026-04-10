/**
 * TrustScript MemberPress Reviews Metabox
 *
 * @package TrustScript
 * @since   1.1.0
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        const enabledCheckbox = document.getElementById('trustscript_reviews_enabled');
        const optionsDiv = document.getElementById('trustscript-reviews-options');
        const modeRadios = document.querySelectorAll('input[name="trustscript_reviews_mode"]');
        const dropdownRow = document.getElementById('trustscript-membership-dropdown');
        const shortcodeRow = document.getElementById('trustscript-shortcode-field');

        if (!enabledCheckbox) return;

        function toggleOptions() {
            if (enabledCheckbox.checked) {
                optionsDiv.classList.remove('trustscript-reviews-options-hidden');
            } else {
                optionsDiv.classList.add('trustscript-reviews-options-hidden');
            }
        }

        function toggleMode() {
            const mode = document.querySelector('input[name="trustscript_reviews_mode"]:checked')?.value;
            if (mode === 'dropdown') {
                dropdownRow.classList.remove('trustscript-hidden');
            } else {
                dropdownRow.classList.add('trustscript-hidden');
            }
            if (mode === 'shortcode') {
                shortcodeRow.classList.remove('trustscript-hidden');
            } else {
                shortcodeRow.classList.add('trustscript-hidden');
            }
        }

        enabledCheckbox.addEventListener('change', toggleOptions);
        modeRadios.forEach(radio => radio.addEventListener('change', toggleMode));
    });

})();
