(function ($) {
    'use strict';

    const QuickOrder = {
        init() {
            this.cacheElements();
            this.bindEvents();
        },

        cacheElements() {
            this.$container = $('#mealsdb-quick-order-products');
            this.$summary = $('#mealsdb-quick-order-summary');
        },

        bindEvents() {
            // Placeholder for future event bindings.
        },
    };

    $(function () {
        if (typeof mealsdbQuickOrder === 'undefined') {
            return;
        }

        QuickOrder.init();
    });
})(jQuery);
