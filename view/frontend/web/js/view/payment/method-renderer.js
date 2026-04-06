define([
    'Magento_Checkout/js/view/payment/default',
    'jquery',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Checkout/js/action/place-order',
    'Magento_Checkout/js/model/payment/additional-validators',
    'mage/url'
], function (Component, $, fullScreenLoader, placeOrderAction, additionalValidators, urlBuilder) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Shubo_BogPayment/payment/shubo-bog',
            redirectAfterPlaceOrder: false
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

            return config ? config.title : 'BOG iPay';
        },

        /**
         * After place order - create BOG order and redirect to iPay
         */
        afterPlaceOrder: function () {
            var self = this;
            var config = window.checkoutConfig.payment.shubo_bog;

            if (!config || !config.createOrderUrl) {
                self.messageContainer.addErrorMessage({
                    message: 'Payment configuration error. Please try again.'
                });
                return;
            }

            fullScreenLoader.startLoader();

            $.ajax({
                url: config.createOrderUrl,
                type: 'POST',
                dataType: 'json',
                contentType: 'application/json',
                data: JSON.stringify({}),
                success: function (response) {
                    fullScreenLoader.stopLoader();

                    if (response.success && response.redirect_url) {
                        // Redirect to BOG iPay payment page
                        window.location.href = response.redirect_url;
                    } else {
                        self.messageContainer.addErrorMessage({
                            message: response.message || 'Unable to initialize payment. Please try again.'
                        });
                    }
                },
                error: function () {
                    fullScreenLoader.stopLoader();
                    self.messageContainer.addErrorMessage({
                        message: 'An error occurred while connecting to the payment gateway. Please try again.'
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
