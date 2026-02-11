/**
 * Starsender Gravity Forms Admin Script
 */

(function($) {
    'use strict';

    const SGFAdmin = {
        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Bind events
         */
        bindEvents: function() {
            $('#sgf-test-btn').on('click', this.testConnection);
        },

        /**
         * Test connection with Starsender API
         */
        testConnection: function() {
            const $btn = $(this);
            const $result = $('#sgf-test-result');
            const api_key = $('#sgf_api_key').val();
            const admin_numbers = $('#sgf_admin_numbers').val();

            // Validate inputs
            if (!api_key.trim()) {
                SGFAdmin.showResult($result, 'error', sgfAdmin.strings.error + ' ' + sgfAdmin.strings.apiKeyRequired);
                return;
            }

            if (!admin_numbers.trim()) {
                SGFAdmin.showResult($result, 'error', sgfAdmin.strings.error + ' ' + sgfAdmin.strings.adminNumberRequired);
                return;
            }

            // Show loading state
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update-alt rotating"></span> ' + sgfAdmin.strings.testing);
            $result.slideUp();

            // Send AJAX request
            $.ajax({
                url: sgfAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sgf_test_connection',
                    nonce: sgfAdmin.nonce,
                    api_key: api_key,
                    admin_numbers: admin_numbers
                },
                success: function(response) {
                    if (response.success) {
                        SGFAdmin.showResult($result, 'success', response.data.message);
                    } else {
                        SGFAdmin.showResult($result, 'error', (response.data && response.data.message) ? response.data.message : sgfAdmin.strings.error);
                    }
                },
                error: function(xhr, status, error) {
                    SGFAdmin.showResult($result, 'error', sgfAdmin.strings.error + ': ' + error);
                },
                complete: function() {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-controls-play"></span> ' + sgfAdmin.strings.testConnection);
                }
            });
        },

        /**
         * Show result message
         */
        showResult: function($element, type, message) {
            const $notice = $element.find('.notice');
            $notice.removeClass('notice-success notice-error notice-warning')
                   .addClass('notice-' + type)
                   .html(message);
            $element.slideDown();
        }
    };

    /**
     * Restore default template function
     */
    window.sgfRestoreDefaultTemplate = function() {
        const defaultTemplate = `📝 *New Form Submission*

*Form:* {form_title}
*Date:* {submission_date}

{fields}

---
_Sent via Starsender for Gravity Forms_`;

        if (confirm(sgfAdmin.strings.restoreTemplate)) {
            $('#sgf_message_template').val(defaultTemplate);
        }
    };

    /**
     * Restore default customer template function
     */
    window.sgfRestoreDefaultCustomerTemplate = function() {
        const defaultTemplate = `📋 *Copy of Your Submission*

Thank you for submitting the form "*{form_title}*".

Here is a copy of your submission:

{fields}

---
_Sent via Starsender for Gravity Forms_`;

        if (confirm(sgfAdmin.strings.restoreTemplate)) {
            $('#sgf_customer_message_template').val(defaultTemplate);
        }
    };

    /**
     * Document ready
     */
    $(document).ready(function() {
        SGFAdmin.init();
    });

})(jQuery);
