define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'shubo_bog',
        component: 'Shubo_BogPayment/js/view/payment/method-renderer'
    });

    return Component.extend({});
});
