define([
    'Magento_Checkout/js/view/payment/default',
    'jquery',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Checkout/js/model/payment/additional-validators',
    'mage/url'
], function (Component, $, fullScreenLoader, additionalValidators, urlBuilder) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Shubo_BogPayment/payment/shubo-bog'
        },

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
                if (response.success && response.redirect_url) {
                    window.location.href = response.redirect_url;
                } else {
                    fullScreenLoader.stopLoader();
                    self.messageContainer.addErrorMessage({
                        message: response.message || 'Unable to initiate payment.'
                    });
                }
            }).fail(function () {
                fullScreenLoader.stopLoader();
                self.messageContainer.addErrorMessage({
                    message: 'Unable to connect to payment service.'
                });
            });

            return false;
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
