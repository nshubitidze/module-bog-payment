define([
    'Magento_Checkout/js/view/payment/default',
    'jquery',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Checkout/js/model/payment/additional-validators',
    'mage/url',
    'mage/translate'
], function (Component, $, fullScreenLoader, additionalValidators, urlBuilder, $t) {
    'use strict';

    /**
     * BUG-BOG-13: hard-coded safety net. If `window.location.href = …` fails
     * silently (CSP block, popup blocker, extension, slow network), we must
     * not leave the customer with a consumed quote and a spinner. After 10 s
     * we assume the navigation did not happen, abort the BOG session server-
     * side, and re-enable the Place Order button so the customer can retry.
     */
    var REDIRECT_WATCHDOG_MS = 10000;

    return Component.extend({
        defaults: {
            template: 'Shubo_BogPayment/payment/shubo-bog'
        },

        /**
         * Handle to the setTimeout-based watchdog for the post-navigation
         * fallback. Cleared when the tab unloads (the happy path).
         *
         * @type {?number}
         * @private
         */
        _redirectWatchdog: null,

        /**
         * Get payment method code
         * @returns {string}
         */
        getCode: function () {
            return 'shubo_bog';
        },

        /**
         * Check if payment method is active
         * @returns {boolean}
         */
        isActive: function () {
            return true;
        },

        /**
         * Get payment method title from config
         * @returns {string}
         */
        getTitle: function () {
            var config = window.checkoutConfig.payment.shubo_bog;

            return config ? config.title : 'BOG Payments';
        },

        /**
         * Override placeOrder to call our initiate endpoint instead of
         * creating a Magento order. No order is created until the
         * customer completes payment at BOG and returns.
         *
         * BUG-BOG-13 fix:
         *   - Use .done()/.fail() (not .always) so the two branches don't
         *     mix.
         *   - On success, set a 10 s watchdog around the navigation. If the
         *     browser is still on the checkout page when the timeout fires,
         *     the redirect failed silently — call the abort endpoint to wipe
         *     the stored BOG session data and re-enable the UI.
         *   - On failure (network error or backend {success:false}), also
         *     call abort to clear any partial BOG state that may have been
         *     persisted before the error.
         *
         * @param {Object} data
         * @param {Event} event
         * @returns {boolean}
         */
        placeOrder: function (data, event) {
            var self = this;

            if (event) {
                event.preventDefault();
            }

            if (!this.validate() || !additionalValidators.validate()) {
                return false;
            }

            fullScreenLoader.startLoader();

            $.ajax({
                url: urlBuilder.build('shubo_bog/payment/initiate'),
                type: 'POST',
                dataType: 'json',
                data: { form_key: window.FORM_KEY || '' }
            }).done(function (response) {
                if (response && response.success && response.redirect_url) {
                    self._redirectToBog(response.redirect_url);
                    return;
                }

                self._handleInitiateFailure(
                    (response && response.message) || $t('Unable to initiate payment.')
                );
            }).fail(function (xhr) {
                var msg;
                try {
                    var parsed = xhr && xhr.responseJSON;
                    msg = (parsed && parsed.message) || $t('Unable to connect to payment service.');
                } catch (e) {
                    msg = $t('Unable to connect to payment service.');
                }

                if (window.console && typeof window.console.error === 'function') {
                    window.console.error('BOG: initiate AJAX failed', {
                        status: xhr && xhr.status,
                        responseText: xhr && xhr.responseText
                    });
                }

                self._handleInitiateFailure(msg);
            });

            return false;
        },

        /**
         * Navigate the browser to the BOG hosted payment page, with a watchdog
         * that recovers the UI if the navigation never happens.
         *
         * @param {string} url BOG redirect URL
         * @private
         */
        _redirectToBog: function (url) {
            var self = this;

            // If the browser starts unloading (the happy path), cancel the
            // watchdog so we don't run recovery after a successful redirect.
            $(window).one('beforeunload.shuboBog', function () {
                if (self._redirectWatchdog !== null) {
                    window.clearTimeout(self._redirectWatchdog);
                    self._redirectWatchdog = null;
                }
            });

            self._redirectWatchdog = window.setTimeout(function () {
                self._redirectWatchdog = null;
                self._handleNavigationFailure();
            }, REDIRECT_WATCHDOG_MS);

            try {
                window.location.href = url;
            } catch (e) {
                // Synchronous failure — trigger recovery immediately.
                if (self._redirectWatchdog !== null) {
                    window.clearTimeout(self._redirectWatchdog);
                    self._redirectWatchdog = null;
                }
                self._handleNavigationFailure();
            }
        },

        /**
         * Called when the 10 s watchdog fires because the redirect to BOG
         * never took effect. Clears the stored BOG session on the quote so
         * the customer can retry, then re-enables the UI.
         *
         * @private
         */
        _handleNavigationFailure: function () {
            var self = this;

            if (window.console && typeof window.console.warn === 'function') {
                window.console.warn(
                    'BOG: redirect watchdog fired — navigation to BOG did not complete'
                );
            }

            self._callAbortEndpoint().always(function () {
                fullScreenLoader.stopLoader();
                self.messageContainer.addErrorMessage({
                    message: $t(
                        'We could not open the bank payment page. '
                        + 'Please check your popup/ad blocker settings and try again.'
                    )
                });
            });
        },

        /**
         * Called when the initiate AJAX itself failed (network error or
         * {success:false}). Even if the backend didn't persist a BOG session,
         * we call abort defensively to wipe any partial state and reset the
         * UI.
         *
         * @param {string} reason Error message to display.
         * @private
         */
        _handleInitiateFailure: function (reason) {
            var self = this;

            self._callAbortEndpoint().always(function () {
                fullScreenLoader.stopLoader();
                self.messageContainer.addErrorMessage({
                    message: reason || $t('Unable to initiate payment.')
                });
            });
        },

        /**
         * POST to the abortRedirect endpoint so the server clears any BOG
         * session data from the quote. Resolves regardless of outcome: this
         * is a best-effort cleanup, and we must always re-enable the UI.
         *
         * @returns {jqXHR}
         * @private
         */
        _callAbortEndpoint: function () {
            return $.ajax({
                url: urlBuilder.build('shubo_bog/payment/abortRedirect'),
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: window.FORM_KEY || ''
                }
            }).fail(function (xhr) {
                if (window.console && typeof window.console.error === 'function') {
                    window.console.error('BOG: abortRedirect AJAX failed', {
                        status: xhr && xhr.status,
                        responseText: xhr && xhr.responseText
                    });
                }
            });
        },

        /**
         * Get payment method data
         * @returns {Object}
         */
        getData: function () {
            return {
                'method': this.getCode(),
                'additional_data': {}
            };
        }
    });
});
