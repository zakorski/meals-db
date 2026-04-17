(function ($) {
    'use strict';

    const preload =
        typeof window.mealsdb_qo_preload === 'object' && window.mealsdb_qo_preload !== null
            ? window.mealsdb_qo_preload
            : { products: [], categories: [] };
    const QO_PRODUCTS = Array.isArray(preload.products) ? preload.products : [];
    const QO_CATEGORIES = Array.isArray(preload.categories) ? preload.categories : [];

    let lastRequest = null;

    function setLastRequest(callback) {
        lastRequest = typeof callback === 'function' ? callback : null;
    }

    const QuickOrder = {
            state: {
                categories: [],
                activeCategoryId: null,
                activeCategorySlug: null,
                categoryProducts: {},
                renderedProducts: {},
                cart: {},
                hasLoadedClone: false,
                isCloning: false,
                cloneOrderId: null,
                currentClientId: null,
                currentClientType: '',
                currentClientAllergens: [],
                taxRate: 0,
                taxableClientTypes: [],
                missingCloneItems: [],
            },

        init() {
            this.cacheElements();
            this.loadConfigurationFromGlobals();
            if (!this.$products || !this.$summary) {
                return;
            }

            this.state.categoryProducts = { all: QO_PRODUCTS };
            this.state.activeCategorySlug = 'all';

            this.bindEvents();
            this.renderSummary();
            this.initialiseCategories();
            this.renderProducts(QO_PRODUCTS);
            this.maybeLoadClonedOrder();
        },

        cacheElements() {
            this.$root = $('.mealsdb-quick-order');
            this.$categories = $('#mealsdb-qo-categories');
            this.$products = $('#mealsdb-quick-order-products');
            this.$grid = $('#mealsdb-qo-grid');
            this.$summary = $('#mealsdb-quick-order-summary');
            this.$summaryContent = this.$summary.find('.mealsdb-quick-order__summary-content');
            this.$summaryEmpty = $('#mealsdb-quick-order-summary-empty');
            this.$summaryContent = $('#mealsdb-quick-order-summary-content');
            this.$summaryClient = $('#mealsdb-quick-order-summary-client');
            this.$summaryDate = $('#mealsdb-quick-order-summary-date');
            this.$summaryRate = $('#mealsdb-quick-order-summary-rate');
            this.$search = $('#mealsdb_qo_search');
            this.$clientSearch = $('#mealsdb_qo_client_search');
            this.$clientDropdown = $('#mealsdb_qo_client_dropdown');
            this.$clientSelect = $('#client_id');
            this.$orderDate = $('#mealsdb-quick-order-date');
            this.$rateSelect = $('#mealsdb-quick-order-rate');
            this.$rateContainer = $('#mealsdb-quick-order-rate-container');
            this.$createOrder = $('#qo-create-order');
            this.$orderSuccess = $('#qo-order-success');
            this.$qoItemsCount = $('#mealsdb-quick-order-summary-items');
            this.$qoSubtotal = $();
            this.$qoTax = $();
            this.$qoTotal = $('#mealsdb-quick-order-summary-total');

            this.$notices = $('<div class="mealsdb-quick-order__notices" />');
            this.$summary.prepend(this.$notices);

            if (this.$createOrder && this.$createOrder.length) {
                const existingSpinner = this.$createOrder.find('.qo-spinner');
                if (existingSpinner.length) {
                    this.$createOrderSpinner = existingSpinner;
                } else {
                    this.$createOrderSpinner = $('<span>', {
                        class: 'qo-spinner',
                        'aria-hidden': 'true',
                    });
                    this.$createOrder.append(this.$createOrderSpinner);
                }

                this.$createOrderSpinner.hide();
            }
        },

        loadConfigurationFromGlobals() {
            const config = window.mealsdbQuickOrder || {};

            this.state.taxRate = this.normaliseTaxRate(
                config.tax && typeof config.tax.rate !== 'undefined' ? config.tax.rate : config.taxRate
            );

            const configuredTypes = Array.isArray(config.tax && config.tax.taxableTypes)
                ? config.tax.taxableTypes
                : config.taxableClientTypes;
            this.state.taxableClientTypes = this.normaliseClientTypeList(configuredTypes);

            const initialType = typeof config.clientType !== 'undefined' ? config.clientType : '';
            this.state.currentClientType = this.normaliseClientType(initialType);
        },

        isSuccessfulResponse(response) {
            return response && response.success !== false;
        },

        getResponsePayload(response) {
            if (response && typeof response === 'object' && response.data && typeof response.data === 'object') {
                return response.data;
            }

            return response || {};
        },

        getResponseMessage(response, fallback = '') {
            const payload = this.getResponsePayload(response);
            if (payload && payload.message) {
                return payload.message;
            }

            if (response && response.message) {
                return response.message;
            }

            return fallback;
        },

        bindEvents() {
            if (this.$clientSelect && this.$clientSelect.length) {
                this.$clientSelect.on('change', () => {
                    this.handleClientSelectionChange();
                });
            }

            $(document)
                .off('click', '.mealsdb-qo-tile')
                .on('click', '.mealsdb-qo-tile', function () {
                    $(this).focus();
                });

            $(document)
                .off('click', '.mealsdb-qo-btn')
                .on('click', '.mealsdb-qo-btn', (event) => {
                    event.preventDefault();
                    const $button = $(event.currentTarget);
                    const productId = parseInt($button.closest('.mealsdb-quick-order__product').data('productId'), 10);
                    if (!Number.isInteger(productId) || productId <= 0) {
                        return;
                    }

                    if ($button.hasClass('mealsdb-quick-order__qty-increase')) {
                        this.incrementProduct(productId);
                    } else if ($button.hasClass('mealsdb-quick-order__qty-decrease')) {
                        this.decrementProduct(productId);
                    }
                });

            this.$products.on('change', '.mealsdb-quick-order__qty-input', (event) => {
                const $input = $(event.currentTarget);
                const productId = parseInt($input.closest('.mealsdb-quick-order__product').data('productId'), 10);
                if (!Number.isInteger(productId) || productId <= 0) {
                    $input.val(0);
                    return;
                }

                const value = parseInt($input.val(), 10);
                const quantity = Number.isInteger(value) && value > 0 ? value : 0;
                this.setProductQuantity(productId, quantity);
            });

            if (this.$createOrder && this.$createOrder.length) {
                this.$createOrder.on('click', (event) => {
                    event.preventDefault();
                    this.showCreateOrderSpinner();
                    qoShowToast('Submitting order...', 'info');
                    this.handleCreateOrder(this.$createOrder);
                });
            }

            if (this.$orderSuccess && this.$orderSuccess.length) {
                this.$orderSuccess.on('click', '.qo-order-create-another', (event) => {
                    event.preventDefault();
                    this.handleCreateAnotherOrder();
                });

                this.$orderSuccess.on('click', '.qo-order-return', (event) => {
                    event.preventDefault();
                    this.handleReturnToQuickOrder();
                });
            }

            if (this.$orderDate && this.$orderDate.length) {
                this.$orderDate.on('change', () => {
                    const value = this.$orderDate.val();
                    if (this.$summaryDate && this.$summaryDate.length) {
                        this.$summaryDate.text(value ? value : this.translate('Not set'));
                    }
                });
            }

            if (this.$rateSelect && this.$rateSelect.length) {
                this.$rateSelect.on('change', () => {
                    this.updateSummaryRate();
                    this.refreshProductPriceDisplay();
                    this.renderSummary();
                });
            }

            if (this.$search && this.$search.length) {
                let searchTimer = null;
                this.$search.on('input', () => {
                    clearTimeout(searchTimer);
                    const keyword = (this.$search.val() || '').trim();
                    if (keyword.length < 2) {
                        if (keyword.length === 0 && this.state.activeCategorySlug) {
                            this.activateCategory(this.state.activeCategorySlug);
                        }
                        return;
                    }
                    searchTimer = setTimeout(() => {
                        this.searchProducts(keyword);
                    }, 300);
                });
            }
        },

        searchProducts(keyword) {
            this.renderProductsLoading();

            // Deselect category tabs during search
            if (this.$categories && this.$categories.length) {
                this.$categories.find('.qo-tab').removeClass('active');
            }

            $.ajax({
                url: this.getAjaxUrl(),
                method: 'GET',
                dataType: 'json',
                data: {
                    action: 'mealsdb_qo_search_products',
                    keyword: keyword,
                    nonce: this.getSecurityNonce(),
                },
            }).done((response) => {
                const payload = this.getResponsePayload(response);

                if (!this.isSuccessfulResponse(response) || !Array.isArray(payload.products)) {
                    const message = this.getResponseMessage(response, 'Search failed.');
                    this.renderProductsError(message);
                    return;
                }

                this.renderProducts(payload.products);
            }).fail(() => {
                this.renderProductsError('Search failed. Check connection.');
            });
        },

        initialiseCategories() {
            if (Array.isArray(QO_CATEGORIES) && QO_CATEGORIES.length) {
                this.state.categories = QO_CATEGORIES;
                this.renderCategories();

                if (!this.state.categories.length) {
                    this.renderProducts([]);
                }

                return;
            }

            this.fetchCategories();
        },

        fetchCategories() {
            this.setCategoriesLoadingState(true);

            const retryRequest = () => this.fetchCategories();

            $.ajax({
                url: this.getAjaxUrl(),
                method: 'GET',
                dataType: 'json',
                data: {
                    action: 'mealsdb_qo_get_categories',
                    nonce: this.getSecurityNonce(),
                },
            }).done((response) => {
                const payload = this.getResponsePayload(response);

                if (!this.isSuccessfulResponse(response) || !Array.isArray(payload.categories)) {
                    const message = this.getResponseMessage(response, 'Unable to load categories.');
                    this.renderCategoriesError(message);
                    qoShowToast(response && response.success === false ? message || 'An error occurred.' : message, 'error');
                    return;
                }

                this.state.categories = payload.categories;
                this.renderCategories();

                if (!this.state.categories.length) {
                    this.renderProducts([]);
                }
            }).fail(() => {
                this.renderCategoriesError('Unable to load categories.');
                qoShowToast('Unable to load category. Check connection.', 'error');
                qoShowToast('Network error: could not complete request.', 'error');
                setLastRequest(retryRequest);
                jQuery('#mealsdb-qo-toast').one('click', () => {
                    if (lastRequest) {
                        lastRequest();
                    }
                });
                qoShowToast('Connection error — click to retry.', 'warning');
            }).always(() => {
                this.setCategoriesLoadingState(false);
            });
        },

        maybeLoadClonedOrder() {
            const cloneOrderId = this.getCloneOrderId();
            if (!Number.isInteger(cloneOrderId) || cloneOrderId <= 0) {
                return;
            }

            if (this.state.hasLoadedClone || this.state.isCloning) {
                return;
            }

            this.state.hasLoadedClone = true;
            this.state.cloneOrderId = cloneOrderId;
            this.loadClonedOrder(cloneOrderId);
        },

        loadClonedOrder(orderId) {
            const nonce = this.getSecurityNonce('cloneOrder');
            if (!nonce) {
                return;
            }

            this.state.isCloning = true;
            this.addNotice(this.getCloneMessage('cloneLoading', 'Loading products from the selected order…'));

            const retryRequest = () => this.loadClonedOrder(orderId);

            $.ajax({
                url: this.getAjaxUrl(),
                method: 'GET',
                dataType: 'json',
                data: {
                    action: 'mealsdb_qo_clone_get_order',
                    nonce: nonce,
                    order_id: orderId,
                },
            }).done((response) => {
                const payload = this.getResponsePayload(response);

                if (!this.isSuccessfulResponse(response) || !payload) {
                    const message = this.getResponseMessage(
                        response,
                        this.getCloneMessage('cloneFailed', 'Unable to load products from the selected order.')
                    );
                    this.addNotice(message, 'error');
                    qoShowToast(response && response.success === false ? message || 'An error occurred.' : message, 'error');
                    return;
                }

                const parsedItems = this.normaliseClonedItems(payload.items, payload.products);
                const hasItems = parsedItems.available.length > 0;
                const hasMissing = parsedItems.missing.length > 0;

                if (!hasItems && !hasMissing) {
                    const emptyMessage =
                        payload.message ||
                        this.getCloneMessage(
                            'cloneNoItems',
                            'The selected order does not contain any products that can be cloned.'
                        );
                    this.addNotice(emptyMessage, 'error');
                    return;
                }

                if (payload.client_id) {
                    this.applyClonedClient(payload.client_id, payload.client_type, payload.client_name);

                    // Pre-select the rate from the cloned order after rates load.
                    const cloneRateId = payload.rate_id ? parseInt(payload.rate_id, 10) : null;
                    if (cloneRateId && cloneRateId > 0) {
                        this.fetchClientRates(parseInt(payload.client_id, 10), cloneRateId);
                    }
                }

                if (payload.order_date && this.$orderDate && this.$orderDate.length) {
                    this.$orderDate.val(payload.order_date);
                }

                if (hasItems) {
                    this.applyClonedItems(parsedItems.available);
                }

                this.setMissingCloneItems(hasMissing ? parsedItems.missing : []);
                this.renderUnavailableTilesFromState();

                const successMessage =
                    payload.message ||
                    this.getCloneMessage('cloneLoaded', 'Products from the selected order have been added to Quick Order.');
                const orderLabel = payload.order_number || payload.order_id || orderId;
                const bannerMessage = orderLabel
                    ? this.getCloneMessage('cloneLoaded', `Loaded from order #${orderLabel}.`)
                    : successMessage;
                this.addNotice(successMessage || bannerMessage, 'success');
                this.scrollToSummaryPanel();
            }).fail((jqXHR) => {
                let message = this.getCloneMessage('cloneFailed', 'Unable to load products from the selected order.');
                if (jqXHR && jqXHR.responseJSON) {
                    if (jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                        message = jqXHR.responseJSON.data.message;
                    } else if (jqXHR.responseJSON.message) {
                        message = jqXHR.responseJSON.message;
                    }
                }
                this.addNotice(message, 'error');
                qoShowToast(message, 'error');
                qoShowToast('Network error: could not complete request.', 'error');
                setLastRequest(retryRequest);
                jQuery('#mealsdb-qo-toast').one('click', () => {
                    if (lastRequest) {
                        lastRequest();
                    }
                });
                qoShowToast('Connection error — click to retry.', 'warning');
            }).always(() => {
                this.state.isCloning = false;
                this.state.cloneOrderId = 0;
                if (window.mealsdbQuickOrder) {
                    window.mealsdbQuickOrder.cloneOrderId = 0;
                }
            });
        },

        applyClonedClient(clientId, clientType = '', clientName = '') {
            if (!Number.isInteger(clientId) || clientId <= 0 || !this.$clientSelect || !this.$clientSelect.length) {
                return;
            }

            const safeName = clientName || this.translate('Client #%s').replace('%s', clientId);
            const type = this.normaliseClientType(clientType);
            this.state.currentClientId = clientId;
            this.state.currentClientType = type;

            this.$clientSelect.val(clientId);
            this.$clientSelect.data('clientType', type);

            if (this.$clientSearch && this.$clientSearch.length) {
                this.$clientSearch.val(safeName);
            }

            this.$clientSelect.trigger('change');

            this.updateSummaryPanel();
        },

        normaliseClonedItems(rawItems, productData = {}) {
            const available = [];
            const missing = [];

            if (!rawItems) {
                return { available, missing };
            }

            const isArrayInput = Array.isArray(rawItems);
            const entries = isArrayInput ? rawItems : Object.entries(rawItems);

            entries.forEach((entry) => {
                let productId = 0;
                let quantity = 0;
                let product = null;

                if (isArrayInput) {
                    const data = entry || {};
                    productId = data.product_id ? parseInt(data.product_id, 10) : 0;
                    quantity = data.quantity ? parseInt(data.quantity, 10) : 0;
                    product = data.product || (productData && productData[productId] ? productData[productId] : null);
                } else {
                    productId = entry && entry[0] ? parseInt(entry[0], 10) : 0;
                    quantity = entry && entry[1] ? parseInt(entry[1], 10) : 0;
                    product = productData && productData[productId] ? productData[productId] : null;
                }

                if (!Number.isInteger(productId) || productId <= 0 || !Number.isInteger(quantity) || quantity <= 0) {
                    return;
                }

                if (product) {
                    available.push({ product, quantity });
                } else {
                    missing.push({ product_id: productId, quantity });
                }
            });

            return { available, missing };
        },

        setMissingCloneItems(missingItems) {
            if (!Array.isArray(missingItems)) {
                this.state.missingCloneItems = [];
            } else {
                this.state.missingCloneItems = missingItems;
            }
            this.renderUnavailableTilesFromState();
        },

        renderUnavailableTilesFromState() {
            if (!this.$products || !this.$products.length) {
                return;
            }

            const missing = Array.isArray(this.state.missingCloneItems) ? this.state.missingCloneItems : [];
            let $missingContainer = this.$products.find('#mealsdb-qo-missing');

            if (!missing.length) {
                $missingContainer.remove();
                return;
            }

            if (!$missingContainer.length) {
                $missingContainer = $('<div id="mealsdb-qo-missing" class="mealsdb-qo-missing" />');
                this.$products.append($missingContainer);
            } else {
                $missingContainer.empty();
            }

            missing.forEach((item) => {
                const $tile = this.buildUnavailableTile(item);
                if ($tile) {
                    $missingContainer.append($tile);
                }
            });
        },

        buildUnavailableTile(item) {
            if (!item || !item.product_id) {
                return null;
            }

            const productId = parseInt(item.product_id, 10);
            if (!Number.isInteger(productId) || productId <= 0) {
                return null;
            }

            const $tile = $('<div class="mealsdb-qo-tile mealsdb-qo-tile--unavailable selected" />').attr({
                'data-product-id': productId,
                tabindex: 0,
            });
            const $product = $('<article class="mealsdb-quick-order__product" />').attr('data-product-id', productId);
            const $content = $('<div class="mealsdb-quick-order__product-content" />');
            $content.append(
                $('<h3 class="mealsdb-quick-order__product-title" />').text(
                    item.name || this.translate('Product #') + productId
                )
            );
            $content.append(
                $('<div class="mealsdb-quick-order__product-price" />').text(
                    this.translate('Unavailable')
                )
            );

            $product.addClass('is-unavailable');
            $product.append($content);
            $tile.append($product);
            return $tile;
        },

        scrollToSummaryPanel() {
            if (this.$summary && this.$summary.length && typeof this.$summary[0].scrollIntoView === 'function') {
                this.$summary[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        },

        getCloneOrderId() {
            let candidate = this.getCloneOrderIdFromUrl();
            if (Number.isInteger(candidate) && candidate > 0) {
                return candidate;
            }

            candidate = this.state.cloneOrderId;
            if (Number.isInteger(candidate) && candidate > 0) {
                return candidate;
            }

            if (window.mealsdbQuickOrder && typeof window.mealsdbQuickOrder.cloneOrderId !== 'undefined') {
                candidate = parseInt(window.mealsdbQuickOrder.cloneOrderId, 10);
                if (Number.isInteger(candidate) && candidate > 0) {
                    return candidate;
                }
            }

            if (this.$root && this.$root.length) {
                candidate = parseInt(this.$root.attr('data-clone-order-id'), 10);
                if (Number.isInteger(candidate) && candidate > 0) {
                    return candidate;
                }
            }

            return 0;
        },

        getCloneOrderIdFromUrl() {
            if (typeof window === 'undefined' || !window.location || !window.location.search) {
                return 0;
            }

            const params = new URLSearchParams(window.location.search);
            const value = params.get('clone_order');
            const fallback = params.get('clone_order_id');
            const candidate = value !== null ? value : fallback;
            const parsed = parseInt(candidate, 10);
            return Number.isInteger(parsed) && parsed > 0 ? parsed : 0;
        },

        setCategoriesLoadingState(isLoading) {
            if (!this.$categories || !this.$categories.length) {
                return;
            }

            this.$categories.toggleClass('is-loading', !!isLoading);
            if (isLoading) {
                this.$categories.html('<p>Loading categories…</p>');
            }
        },

        renderCategories() {
            if (!this.$categories || !this.$categories.length) {
                return;
            }

            if (!Array.isArray(this.state.categories) || !this.state.categories.length) {
                this.$categories.html('<p>No categories were found.</p>');
                return;
            }

            const self = this;

            this.$categories.empty();

            const $tabsWrap = $('<div>', { class: 'mealsdb-qo-tabs' });

            const $allButton = $('<button>', {
                type: 'button',
                class: 'qo-tab',
                text: 'All',
            }).attr({
                'data-cat': 'all',
                'data-cat-id': 0,
            });

            if (this.state.activeCategorySlug === 'all') {
                $allButton.addClass('active');
            }

            $tabsWrap.append($allButton);

            // Virtual "Sides" tab combining cereal, dessert, soup, muffin, and thickened.
            const sidesSlugs = ['cereal', 'dessert', 'soup', 'muffin', 'thickened'];
            const hasSidesCategories = this.state.categories.some(
                (cat) => sidesSlugs.indexOf(this.normaliseCategorySlug(cat.slug)) !== -1
            );
            if (hasSidesCategories) {
                const $sidesButton = $('<button>', {
                    type: 'button',
                    class: 'qo-tab',
                    text: 'Sides',
                }).attr({
                    'data-cat': 'sides',
                    'data-cat-id': 0,
                });

                if (this.state.activeCategorySlug === 'sides') {
                    $sidesButton.addClass('active');
                }

                $tabsWrap.append($sidesButton);
            }

            this.state.categories.forEach((category) => {
                const categoryId = parseInt(category.id, 10);
                if (!Number.isInteger(categoryId) || categoryId <= 0) {
                    return;
                }

                const categorySlug = this.normaliseCategorySlug(category.slug || categoryId);

                const $button = $('<button>', {
                    type: 'button',
                    class: 'qo-tab',
                    text: category.name || `Category #${categoryId}`,
                }).attr({
                    'data-cat': categorySlug,
                    'data-cat-id': categoryId,
                });

                if (this.state.activeCategorySlug === categorySlug) {
                    $button.addClass('active');
                }

                $tabsWrap.append($button);
            });

            // Bind tab clicks to load category via AJAX.
            $tabsWrap.on('click', '.qo-tab', function () {
                const slug = $(this).data('cat');
                $tabsWrap.find('.qo-tab').removeClass('active');
                $(this).addClass('active');
                self.loadCategory(slug);
            });

            this.$categories.append($tabsWrap);

            // Auto-activate the first tab if none is active.
            const $tabs = $tabsWrap.find('.qo-tab');
            const hasActiveTab = $tabs.filter('.active').length > 0;
            if (!hasActiveTab && $tabs.first().length) {
                $tabs.first().addClass('active');
                this.loadCategory($tabs.first().data('cat'));
            }
        },

        loadCategory(categorySlug) {
            const slug = this.normaliseCategorySlug(categorySlug);
            if (!slug) {
                return null;
            }

            if (slug === this.state.activeCategorySlug) {
                return null;
            }

            if (this.$search && this.$search.length) {
                this.$search.val('');
            }

            const $grid = this.$grid && this.$grid.length ? this.$grid : $('#mealsdb-qo-grid');
            const $fadeTarget = $grid.length ? $grid : this.$products;
            if ($fadeTarget && $fadeTarget.length) {
                $fadeTarget.stop(true, true).fadeTo(100, 0.3);
            }

            const finalizeFade = () => {
                const $latestGrid = this.$grid && this.$grid.length ? this.$grid : $('#mealsdb-qo-grid');
                const $target = $latestGrid.length ? $latestGrid : this.$products;
                if ($target && $target.length) {
                    $target.stop(true, true).fadeTo(150, 1);
                }
            };

            const request = this.activateCategory(slug);

            if (request && typeof request.always === 'function') {
                request.always(finalizeFade);
            } else {
                finalizeFade();
            }

            return request;
        },

        renderCategoriesError(message) {
            if (!this.$categories || !this.$categories.length) {
                return;
            }

            this.$categories.html(`<p class="error">${this.escapeHtml(message || 'Unable to load categories.')}</p>`);
        },

        activateCategory(categorySlug) {
            const slug = this.normaliseCategorySlug(categorySlug);
            const category = this.getCategoryBySlug(slug);
            const categoryId = category && typeof category.id !== 'undefined' ? parseInt(category.id, 10) : null;

            this.state.activeCategorySlug = slug;
            this.state.activeCategoryId = Number.isInteger(categoryId) ? categoryId : null;
            this.renderCategories();

            const preloadedProducts = this.getPreloadedProductsForCategory(slug, categoryId);
            if (Array.isArray(preloadedProducts) && preloadedProducts.length) {
                this.state.categoryProducts = this.state.categoryProducts || {};
                this.state.categoryProducts[slug] = preloadedProducts;
                this.renderProducts(preloadedProducts);
                if ($ && $.Deferred) {
                    return $.Deferred().resolve().promise();
                }
                return null;
            }

            if (this.state.categoryProducts && Array.isArray(this.state.categoryProducts[slug])) {
                this.renderProducts(this.state.categoryProducts[slug]);
                if ($ && $.Deferred) {
                    return $.Deferred().resolve().promise();
                }
                return null;
            }

            // Virtual categories (e.g. "sides") have no real category ID; show empty state.
            if (slug === 'sides') {
                this.renderProducts([]);
                if ($ && $.Deferred) {
                    return $.Deferred().resolve().promise();
                }
                return null;
            }

            if (Number.isInteger(categoryId) && categoryId > 0) {
                return this.fetchProductsByCategory(categoryId, slug);
            }

            this.renderProductsError('Unable to load products.');
            if ($ && $.Deferred) {
                return $.Deferred().reject().promise();
            }

            return null;
        },

        fetchProductsByCategory(categoryId, categorySlug = null) {
            this.renderProductsLoading();

            const cacheKey = categorySlug || categoryId;

            const retryRequest = () => this.fetchProductsByCategory(categoryId, categorySlug);

            return $.ajax({
                url: this.getAjaxUrl(),
                method: 'GET',
                dataType: 'json',
                data: {
                    action: 'mealsdb_qo_get_products_by_category',
                    category_id: categoryId,
                    nonce: this.getSecurityNonce(),
                },
            }).done((response) => {
                const payload = this.getResponsePayload(response);

                if (!this.isSuccessfulResponse(response) || !Array.isArray(payload.products)) {
                    const message = this.getResponseMessage(response, 'Unable to load products.');
                    this.renderProductsError(message);
                    qoShowToast(response && response.success === false ? message || 'An error occurred.' : message, 'error');
                    return;
                }

                this.state.categoryProducts = this.state.categoryProducts || {};
                this.state.categoryProducts[cacheKey] = payload.products;
                this.renderProducts(payload.products);
            }).fail(() => {
                this.renderProductsError('Unable to load products.');
                qoShowToast('Unable to load category. Check connection.', 'error');
                qoShowToast('Network error: could not complete request.', 'error');
                setLastRequest(retryRequest);
                jQuery('#mealsdb-qo-toast').one('click', () => {
                    if (lastRequest) {
                        lastRequest();
                    }
                });
                qoShowToast('Connection error — click to retry.', 'warning');
            });
        },

        fetchAllProducts() {
            this.renderProductsLoading();

            if (this.state.categoryProducts && Array.isArray(this.state.categoryProducts['all'])) {
                this.renderProducts(this.state.categoryProducts['all']);
                return $.Deferred().resolve().promise();
            }

            return $.ajax({
                url: this.getAjaxUrl(),
                method: 'GET',
                dataType: 'json',
                data: {
                    action: 'mealsdb_qo_get_all_products',
                    nonce: this.getSecurityNonce(),
                },
            }).done((response) => {
                const payload = this.getResponsePayload(response);

                if (!this.isSuccessfulResponse(response) || !Array.isArray(payload.products)) {
                    const message = this.getResponseMessage(response, 'Unable to load products.');
                    this.renderProductsError(message);
                    return;
                }

                this.state.categoryProducts = this.state.categoryProducts || {};
                this.state.categoryProducts['all'] = payload.products;
                this.renderProducts(payload.products);
            }).fail(() => {
                this.renderProductsError('Unable to load products.');
            });
        },

        normaliseCategorySlug(slug) {
            if (slug === null || typeof slug === 'undefined') {
                return '';
            }

            return String(slug).trim().toLowerCase();
        },

        getCategoryBySlug(slug) {
            const normalizedSlug = this.normaliseCategorySlug(slug);
            if (!normalizedSlug || !Array.isArray(this.state.categories)) {
                return null;
            }

            for (let index = 0; index < this.state.categories.length; index += 1) {
                const category = this.state.categories[index];
                const categorySlug = this.normaliseCategorySlug(category && (category.slug || category.id));
                if (categorySlug === normalizedSlug) {
                    return category;
                }
            }

            return null;
        },

        getPreloadedProductsForCategory(categorySlug, categoryId = null) {
            const slug = this.normaliseCategorySlug(categorySlug);
            if (!slug || !Array.isArray(QO_PRODUCTS) || !QO_PRODUCTS.length) {
                return null;
            }

            if (slug === 'all') {
                return QO_PRODUCTS;
            }

            const sidesSlugs = ['cereal', 'dessert', 'soup', 'muffin', 'thickened'];
            const matchSlugs = slug === 'sides' ? sidesSlugs : [slug];

            const matches = QO_PRODUCTS.filter((product) => {
                if (!product) {
                    return false;
                }

                // Check primary category
                if (product.category) {
                    const productSlug = this.normaliseCategorySlug(product.category.slug || product.category.id);
                    if (matchSlugs.indexOf(productSlug) !== -1) {
                        return true;
                    }

                    if (slug !== 'sides' && Number.isInteger(categoryId)) {
                        const productCategoryId =
                            typeof product.category.id !== 'undefined'
                                ? parseInt(product.category.id, 10)
                                : null;

                        if (Number.isInteger(productCategoryId) && productCategoryId === categoryId) {
                            return true;
                        }
                    }
                }

                // Check category_slugs array for multi-category products
                if (Array.isArray(product.category_slugs)) {
                    for (let i = 0; i < product.category_slugs.length; i++) {
                        if (matchSlugs.indexOf(this.normaliseCategorySlug(product.category_slugs[i])) !== -1) {
                            return true;
                        }
                    }
                }

                return false;
            });

            return matches.length ? matches : null;
        },

        renderProductsLoading() {
            if (this.$products && this.$products.length) {
                this.$products.html('<p>Loading products…</p>');
            }
        },

        renderProductsError(message) {
            if (this.$products && this.$products.length) {
                this.$products.html(`<p class="error">${this.escapeHtml(message || 'Unable to load products.')}</p>`);
            }
        },

        renderProducts(products) {
            if (!this.$products || !this.$products.length) {
                return;
            }

            this.state.renderedProducts = {};
            const list = Array.isArray(products) ? products : [];

            if (!list.length) {
                const message = 'No products found in this category.';
                this.$products.html(`<p>${this.escapeHtml(message)}</p>`);
                return;
            }

            let gridHtml = '<div class="mealsdb-quick-order__product-grid mealsdb-qo-grid" id="mealsdb-qo-grid">';

            list.forEach((product) => {
                const productId = product && product.product_id ? parseInt(product.product_id, 10) : 0;
                if (!Number.isInteger(productId) || productId <= 0) {
                    return;
                }

                const quantity = this.state.cart[productId] ? this.state.cart[productId].quantity : 0;
                let formattedPrice;
                if (this.isGovernmentInvoiced()) {
                    formattedPrice = '';
                } else {
                    formattedPrice = this.formatPrice(product.price || 0);
                }
                this.state.renderedProducts[productId] = product;
                gridHtml += this.buildProductTileHTML(product, productId, quantity, formattedPrice);
            });

            gridHtml += '</div>';

            this.$products.html(gridHtml);
            this.$grid = this.$products.find('#mealsdb-qo-grid');
            this.syncCartToVisibleProducts();
            this.renderUnavailableTilesFromState();
            this.updateProductRestrictionStates();
        },

        buildProductTileHTML(product, productId, quantity, formattedPrice) {
            const safeName = this.escapeHtml(product.name || `Product #${productId}`);
            const safePrice = this.escapeHtml(formattedPrice);
            const isSelected = quantity > 0;
            const selectedClass = isSelected ? ' selected' : '';
            const categorySlugs = Array.isArray(product && product.category_slugs)
                ? product.category_slugs
                      .map((slug) => this.normaliseCategorySlug(slug))
                      .filter((slug) => slug !== '')
                : [];
            if (!categorySlugs.length) {
                const fallbackSlug = this.normaliseCategorySlug(product && product.category ? product.category.slug : '');
                if (fallbackSlug) {
                    categorySlugs.push(fallbackSlug);
                }
            }
            const dataCategories = this.escapeAttribute(categorySlugs.join(' ').trim());
            const imageHtml =
                product.image_url && typeof product.image_url === 'string'
                    ? `<div class="mealsdb-quick-order__product-image"><img src="${this.escapeAttribute(
                          product.image_url
                      )}" alt="${this.escapeAttribute(product.name || 'Product image')}" class="mealsdb-qo-image" loading="lazy"></div>`
                    : '';
            const isRestricted = this.isProductRestricted(product);
            const restrictionClass = isRestricted ? ' mealsdb-qo-tile--restricted' : '';
            const restrictionTitle = this.translate('Client allergy restricted');
            const metaHtml = this.buildProductMeta(product);

            const disabledAttr = isRestricted ? ' disabled aria-disabled="true"' : '';
            const disableTitleAttr = isRestricted ? ` title="${this.escapeAttribute(restrictionTitle)}"` : '';
            const inputDisabled = isRestricted ? ' disabled aria-disabled="true"' : '';
            const restrictionNote = isRestricted
                ? `<div class="mealsdb-qo-restriction" role="status">${this.escapeHtml(restrictionTitle)}</div>`
                : '';

            return `
                <div class="mealsdb-qo-tile qo-product${selectedClass}${restrictionClass}" tabindex="0" data-cat="${dataCategories}">
                    <div class="mealsdb-quick-order__product${selectedClass}" data-product-id="${this.escapeAttribute(
                        productId
                    )}">
                        ${imageHtml}
                        <div class="mealsdb-quick-order__product-content">
                            <h3 class="mealsdb-quick-order__product-title qo-product-name">${safeName}</h3>
                            <div class="mealsdb-quick-order__product-price">${safePrice}</div>
                            ${metaHtml}
                            <div class="mealsdb-quick-order__product-actions mealsdb-qo-qty-controls">
                                <button type="button" class="button mealsdb-quick-order__qty-decrease mealsdb-qo-btn" aria-label="Decrease quantity">-</button>
                                <input type="number" min="0" class="small-text mealsdb-quick-order__qty-input mealsdb-qo-qty" value="${this.escapeAttribute(
                                    quantity
                                )}"${inputDisabled}>
                                <button type="button" class="button mealsdb-quick-order__qty-increase mealsdb-qo-btn" aria-label="Increase quantity"${disabledAttr}${disableTitleAttr}>+</button>
                            </div>
                            ${restrictionNote}
                        </div>
                    </div>
                </div>`;
        },

        buildProductMeta(product) {
            const metaParts = [];
            const mainIngredient = product && product.main_ingredient ? String(product.main_ingredient).trim() : '';
            const dietaryTags = Array.isArray(product && product.dietary_tags ? product.dietary_tags : [])
                ? product.dietary_tags.filter((tag) => !!tag)
                : [];

            if (mainIngredient) {
                metaParts.push(
                    `<div class="mealsdb-qo-meta__ingredient">${this.escapeHtml(this.translate('Main ingredient:'))} ${this.escapeHtml(mainIngredient)}</div>`
                );
            }

            if (dietaryTags.length) {
                const badges = dietaryTags
                    .map((tag) => `<span class="mealsdb-qo-badge">${this.escapeHtml(tag)}</span>`)
                    .join('');
                metaParts.push(`<div class="mealsdb-qo-meta__badges">${badges}</div>`);
            }

            if (!metaParts.length) {
                return '';
            }

            return `<div class="mealsdb-qo-meta">${metaParts.join('')}</div>`;
        },

        isProductRestricted(product, clientAllergens = null) {
            const clientList = Array.isArray(clientAllergens)
                ? this.normaliseAllergenList(clientAllergens)
                : this.normaliseAllergenList(this.state.currentClientAllergens);
            const productAllergens = this.normaliseAllergenList(
                product && Array.isArray(product.allergen_flags) ? product.allergen_flags : []
            );

            if (!clientList.length || !productAllergens.length) {
                return false;
            }

            return clientList.some((value) => productAllergens.includes(value));
        },

        normaliseAllergenList(values) {
            if (!Array.isArray(values)) {
                return [];
            }

            return values
                .map((value) => (value !== null && typeof value !== 'undefined' ? String(value).trim().toLowerCase() : ''))
                .filter((value) => value !== '');
        },

        updateProductRestrictionStates() {
            if (!this.$products || !this.$products.length) {
                return;
            }

            const clientAllergens = this.normaliseAllergenList(this.state.currentClientAllergens);

            Object.keys(this.state.renderedProducts || {}).forEach((key) => {
                const productId = parseInt(key, 10);
                if (!Number.isInteger(productId) || productId <= 0) {
                    return;
                }

                const product = this.state.renderedProducts[productId];
                const restricted = this.isProductRestricted(product, clientAllergens);

                if (restricted && this.state.cart[productId]) {
                    this.setProductQuantity(productId, 0);
                }

                const $product = this.$products.find(`.mealsdb-quick-order__product[data-product-id="${productId}"]`);
                if ($product && $product.length) {
                    this.applyRestrictionState($product, restricted);
                }
            });
        },

        applyRestrictionState($product, restricted) {
            const restrictionTitle = this.translate('Client allergy restricted');
            const $tile = $product.closest('.mealsdb-qo-tile');
            $tile.toggleClass('mealsdb-qo-tile--restricted', !!restricted);
            $product.toggleClass('is-restricted', !!restricted);

            const $increase = $product.find('.mealsdb-quick-order__qty-increase');
            const $input = $product.find('.mealsdb-quick-order__qty-input');
            const $restriction = $product.find('.mealsdb-qo-restriction');

            $increase.prop('disabled', !!restricted);
            $input.prop('disabled', !!restricted);

            if (restricted) {
                $increase.attr('title', restrictionTitle);
                if (!$restriction.length) {
                    $product.find('.mealsdb-quick-order__product-content').append(
                        `<div class="mealsdb-qo-restriction" role="status">${this.escapeHtml(restrictionTitle)}</div>`
                    );
                }
            } else {
                $increase.removeAttr('title');
                $restriction.remove();
            }
        },

        incrementProduct(productId) {
            const current = this.state.cart[productId] ? this.state.cart[productId].quantity : 0;
            this.setProductQuantity(productId, current + 1);
        },

        decrementProduct(productId) {
            const current = this.state.cart[productId] ? this.state.cart[productId].quantity : 0;
            this.setProductQuantity(productId, Math.max(current - 1, 0));
        },

        setProductQuantity(productId, quantity) {
            if (!Number.isInteger(productId) || productId <= 0) {
                return;
            }

            const product = this.findProduct(productId);
            if (!product) {
                return;
            }

            if (!Number.isInteger(quantity) || quantity < 0) {
                quantity = 0;
            }

            if (quantity === 0) {
                delete this.state.cart[productId];
            } else {
                this.state.cart[productId] = {
                    product: product,
                    quantity: quantity,
                };
            }

            const $product = this.$products.find(`.mealsdb-quick-order__product[data-product-id="${productId}"]`);
            if ($product.length) {
                const $input = $product.find('.mealsdb-quick-order__qty-input');
                const existingValue = parseInt($input.val(), 10);
                if (existingValue !== quantity) {
                    $input.val(quantity);
                }

                const isSelected = quantity > 0;
                if ($product.hasClass('selected') !== isSelected) {
                    $product.toggleClass('selected', isSelected);
                }

                const $tile = $product.closest('.mealsdb-qo-tile');
                if ($tile.length && $tile.hasClass('selected') !== isSelected) {
                    $tile.toggleClass('selected', isSelected);
                }
            }

            this.renderSummary();
            this.updateAllocationWithCart();
        },

        applyClonedItems(items) {
            if (!Array.isArray(items)) {
                return;
            }

            const cart = {};

            items.forEach((entry) => {
                if (!entry || !entry.product) {
                    return;
                }

                const product = entry.product;
                const productId = product && product.product_id ? parseInt(product.product_id, 10) : 0;
                const quantity = entry && entry.quantity ? parseInt(entry.quantity, 10) : 0;

                if (!Number.isInteger(productId) || productId <= 0 || !Number.isInteger(quantity) || quantity <= 0) {
                    return;
                }

                if (cart[productId]) {
                    const existingQuantity = parseInt(cart[productId].quantity, 10) || 0;
                    cart[productId].quantity = existingQuantity + quantity;
                } else {
                    cart[productId] = {
                        product: product,
                        quantity: quantity,
                    };
                }
            });

            this.state.cart = cart;
            this.renderSummary();
            this.updateAllocationWithCart();
            this.syncCartToVisibleProducts();
        },

        findProduct(productId) {
            if (this.state.cart[productId]) {
                return this.state.cart[productId].product;
            }

            if (this.state.renderedProducts && this.state.renderedProducts[productId]) {
                return this.state.renderedProducts[productId];
            }

            if (this.state.categoryProducts) {
                const categoryIds = Object.keys(this.state.categoryProducts);
                for (let i = 0; i < categoryIds.length; i += 1) {
                    const id = categoryIds[i];
                    const list = this.state.categoryProducts[id];
                    if (!Array.isArray(list)) {
                        continue;
                    }

                    for (let j = 0; j < list.length; j += 1) {
                        const product = list[j];
                        const currentId = product && product.product_id ? parseInt(product.product_id, 10) : 0;
                        if (currentId === productId) {
                            return product;
                        }
                    }
                }
            }

            return null;
        },

        renderSummary() {
            if (!this.$summaryContent || !this.$summaryContent.length) {
                this.updateSummaryPanel();
                return;
            }

            const items = Object.keys(this.state.cart).map((productId) => this.state.cart[productId]);

            if (!items.length) {
                if (this.$summaryContent && this.$summaryContent.length) {
                    this.$summaryContent.empty().attr('hidden', 'hidden').hide();
                }
                if (this.$summaryEmpty && this.$summaryEmpty.length) {
                    this.$summaryEmpty.removeAttr('hidden').show();
                }
                this.updateSummaryPanel();
                return;
            }

            if (this.$summaryEmpty && this.$summaryEmpty.length) {
                this.$summaryEmpty.attr('hidden', 'hidden').hide();
            }
            if (this.$summaryContent && this.$summaryContent.length) {
                this.$summaryContent.removeAttr('hidden').show();
            }

            let totalQuantity = 0;
            let totalPrice = 0;

            const $list = $('<ul class="mealsdb-quick-order__summary-list" />');

            const govInvoiced = this.isGovernmentInvoiced();

            items.forEach((entry) => {
                if (!entry || !entry.product) {
                    return;
                }

                const quantity = parseInt(entry.quantity, 10) || 0;
                const price = govInvoiced ? 0 : parseFloat(entry.product.price || 0);
                totalQuantity += quantity;
                totalPrice += quantity * price;

                const $item = $('<li class="mealsdb-quick-order__summary-item" />');
                $item.append($('<span class="mealsdb-quick-order__summary-item-name" />').text(entry.product.name || 'Product'));
                $item.append($('<span class="mealsdb-quick-order__summary-item-qty" />').text(`× ${quantity}`));
                if (!govInvoiced) {
                    const lineTotal = this.formatPrice(quantity * price);
                    $item.append($('<span class="mealsdb-quick-order__summary-item-total" />').text(lineTotal));
                }
                $list.append($item);
            });

            const $footer = $('<div class="mealsdb-quick-order__summary-footer" />');
            $footer.append($('<div class="mealsdb-quick-order__summary-total-qty" />').text(`Items: ${totalQuantity}`));
            if (!govInvoiced) {
                $footer.append($('<div class="mealsdb-quick-order__summary-total-price" />').text(`Total: ${this.formatPrice(totalPrice)}`));
            }

            this.$summaryContent.empty().append($list, $footer);

            this.updateSummaryPanel();
        },

        syncCartToVisibleProducts() {
            if (!this.$products || !this.$products.length) {
                return;
            }

            this.$products.find('.mealsdb-quick-order__product').each((index, element) => {
                const $product = $(element);
                const productId = parseInt($product.data('productId'), 10);
                if (!Number.isInteger(productId) || productId <= 0) {
                    return;
                }

                const entry = this.state.cart && this.state.cart[productId] ? this.state.cart[productId] : null;
                const quantity = entry ? parseInt(entry.quantity, 10) || 0 : 0;

                const $input = $product.find('.mealsdb-quick-order__qty-input');
                const currentValue = parseInt($input.val(), 10);
                if (currentValue !== quantity) {
                    $input.val(quantity);
                }
            });
        },

        handleCreateOrder(createButton = null) {
            if (!this.$createOrder || !this.$createOrder.length) {
                return;
            }

            this.clearNotices();
            this.hideOrderSuccess();

            const clientIdRaw = this.$clientSelect && this.$clientSelect.length ? this.$clientSelect.val() : '';
            const clientId = parseInt(clientIdRaw, 10);
            const orderDate = this.$orderDate && this.$orderDate.length ? this.$orderDate.val() : '';
            const items = Object.values(this.state.cart || {}).filter((entry) => entry && entry.quantity > 0);
            const rateId = this.$rateSelect && this.$rateSelect.length ? parseInt(this.$rateSelect.val(), 10) || 0 : 0;

            if (!Number.isInteger(clientId) || clientId <= 0 || !orderDate || !items.length) {
                qoShowToast('Please select a client, date, and at least one product.', 'error');
                this.clearCreateOrderLoading(createButton);
                return;
            }

            const payloadItems = items.map((entry) => ({
                product_id: entry.product.product_id,
                quantity: entry.quantity,
            }));

            this.setCreateOrderBusy(true);

            $.ajax({
                url: this.getAjaxUrl(),
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'mealsdb_qo_create_order',
                    nonce: this.getSecurityNonce('createOrder'),
                    client_id: clientId,
                    date: orderDate,
                    items: payloadItems,
                    rate_id: rateId,
                },
            }).done((response) => {
                if (!this.isSuccessfulResponse(response)) {
                    const message = this.getResponseMessage(response, 'Error creating order. Please try again.');
                    qoShowToast(message, 'error');
                    return;
                }

                const payload = this.getResponsePayload(response);
                const orderIdRaw =
                    (payload && (payload.order_id || payload.orderId)) ||
                    response.order_id ||
                    response.orderId;
                const orderId = parseInt(orderIdRaw, 10);
                const orderLink =
                    (payload && (payload.order_link || payload.orderLink)) ||
                    response.order_link ||
                    response.orderLink ||
                    '';
                const successMessage = this.getResponseMessage(response, 'Order created successfully!');

                qoShowToast(successMessage, 'success');
                this.showOrderSuccess(successMessage, orderId, orderLink);

                jQuery('html, body').animate({ scrollTop: jQuery('#qo-order-success').offset().top - 30 }, 300);
            }).fail(() => {
                qoShowToast('Error creating order. Please try again.', 'error');
            }).always(() => {
                this.setCreateOrderBusy(false);
                this.clearCreateOrderLoading(createButton);
            });
        },

        createOrderSuccessMessage(message, orderId) {
            const escapedMessage = this.escapeHtml(message || 'Order created successfully.');
            if (!Number.isInteger(orderId) || orderId <= 0) {
                return `<span>${escapedMessage}</span>`;
            }

            const orderUrl = this.buildOrderAdminLink(orderId);
            const escapedUrl = this.escapeAttribute(orderUrl);
            return `<span>${escapedMessage} <a href="${escapedUrl}" target="_blank" rel="noopener noreferrer">View order #${orderId}</a>.</span>`;
        },

        buildOrderAdminLink(orderId) {
            if (!Number.isInteger(orderId) || orderId <= 0) {
                return '#';
            }

            const baseUrl = window.ajaxurl ? window.ajaxurl.replace(/admin-ajax\.php/i, 'post.php') : (window.location.origin + '/wp-admin/post.php');
            return `${baseUrl}?post=${orderId}&action=edit`;
        },

        setCreateOrderBusy(isBusy) {
            if (!this.$createOrder || !this.$createOrder.length) {
                return;
            }

            this.$createOrder.prop('disabled', !!isBusy);
            this.$createOrder.attr('aria-busy', isBusy ? 'true' : 'false');
        },

        showCreateOrderSpinner() {
            if (!this.$createOrder || !this.$createOrder.length || !this.$createOrderSpinner) {
                return;
            }

            this.$createOrder.addClass('loading');
            this.$createOrderSpinner.show();
        },

        clearCreateOrderLoading(createButton) {
            if (!this.$createOrder || !this.$createOrderSpinner) {
                return;
            }

            this.$createOrder.removeClass('loading');
            this.$createOrderSpinner.hide();
        },

        clearNotices() {
            if (this.$notices && this.$notices.length) {
                this.$notices.empty();
            }
        },

        hideOrderSuccess() {
            if (this.$orderSuccess && this.$orderSuccess.length) {
                this.$orderSuccess.stop(true, true).hide().empty();
            }
        },

        showOrderSuccess(message, orderId, orderLink = '') {
            if (!this.$orderSuccess || !this.$orderSuccess.length) {
                this.addNotice(this.createOrderSuccessMessage(message, orderId), 'success', true);
                return;
            }

            const rawMessage = message || 'Order created successfully.';
            let safeMessage = this.escapeHtml(rawMessage);
            const trimmedMessage = safeMessage.replace(/\s+$/u, '');
            const needsPunctuation = !/[.!?]$/u.test(trimmedMessage);
            if (needsPunctuation) {
                safeMessage = `${trimmedMessage}.`;
            } else {
                safeMessage = trimmedMessage;
            }
            let orderLinkHtml = '';
            const resolvedOrderLink =
                orderLink && typeof orderLink === 'string' ? orderLink.trim() : this.buildOrderAdminLink(orderId);

            if (resolvedOrderLink) {
                const orderUrl = this.escapeAttribute(resolvedOrderLink);
                const viewOrderText = this.translate('View order #%s');
                const orderText = this.escapeHtml(viewOrderText.replace('%s', orderId || ''));
                orderLinkHtml = ` <a href="${orderUrl}" target="_blank" rel="noopener noreferrer">${orderText}</a>`;
            }

            const successMessage = `<p class="qo-order-success__message">${safeMessage}${orderLinkHtml ? `${orderLinkHtml}.` : ''}</p>`;
            const actionButtons = [
                {
                    className: 'button button-primary qo-order-create-another',
                    label: this.translate('Create Another Order'),
                },
                {
                    className: 'button qo-order-return',
                    label: this.translate('Return to Quick Order'),
                },
            ]
                .map((button) => `<button type="button" class="${this.escapeAttribute(button.className)}">${this.escapeHtml(button.label)}</button>`)
                .join('');

            const actionsWrapper = `<div class="qo-order-success__actions">${actionButtons}</div>`;

            this.$orderSuccess
                .html(successMessage + actionsWrapper)
                .stop(true, true)
                .fadeIn(150);
        },

        handleCreateAnotherOrder() {
            this.hideOrderSuccess();
            if (this.$clientSelect && this.$clientSelect.length) {
                this.$clientSelect.trigger('focus');
            }
        },

        handleReturnToQuickOrder() {
            this.hideOrderSuccess();
            if (this.$root && this.$root.length && typeof this.$root[0].scrollIntoView === 'function') {
                this.$root[0].scrollIntoView({
                    behavior: 'smooth',
                    block: 'start',
                });
            }
        },

        addNotice(message, type = 'info', allowHtml = false) {
            if (!this.$notices || !this.$notices.length) {
                return;
            }

            const classes = ['notice'];
            if (type === 'error') {
                classes.push('notice-error');
            } else if (type === 'success') {
                classes.push('notice-success');
            } else {
                classes.push('notice-info');
            }

            const $notice = $('<div />', {
                class: classes.join(' '),
            });

            if (allowHtml) {
                $notice.html(message);
            } else {
                $notice.text(message);
            }

            this.$notices.empty().append($notice);
        },

        handleClientSelectionChange(clientData = null) {
            if (!this.$clientSelect || !this.$clientSelect.length) {
                return;
            }

            let clientType = '';
            let clientId = null;
            let clientAllergens = [];

            if (clientData && typeof clientData === 'object') {
                const parsedId = parseInt(clientData.id, 10);
                clientId = Number.isInteger(parsedId) && parsedId > 0 ? parsedId : null;
                clientType = clientData.client_type || clientData.client_type || '';

                if (Array.isArray(clientData.allergens)) {
                    clientAllergens = clientData.allergens;
                } else if (Array.isArray(clientData.allergen_flags)) {
                    clientAllergens = clientData.allergen_flags;
                }
            } else {
                const selectedValue = this.$clientSelect.val();
                const parsedId = parseInt(selectedValue, 10);
                clientId = Number.isInteger(parsedId) && parsedId > 0 ? parsedId : null;

                clientType = this.$clientSelect.data('clientType') || '';
                const selectedAllergens = this.$clientSelect.data('clientAllergens');
                if (Array.isArray(selectedAllergens)) {
                    clientAllergens = selectedAllergens;
                }
            }

            this.state.currentClientId = clientId;
            this.state.currentClientType = this.normaliseClientType(clientType);
            this.state.currentClientAllergens = this.normaliseAllergenList(clientAllergens);

            if (this.$summaryClient && this.$summaryClient.length) {
                let label = 'Not selected';

                if (clientId) {
                    let name = '';
                    if (this.$clientSearch && this.$clientSearch.length) {
                        name = this.$clientSearch.val();
                    }
                    if (name && name.trim().length) {
                        label = name;
                    } else {
                        label = this.translate('Client #%s').replace('%s', clientId);
                    }
                }

                this.$summaryClient.text(label);
            }

            if (window.mealsdbQuickOrder) {
                window.mealsdbQuickOrder.clientType = this.state.currentClientType;
            }

            this.updateProductRestrictionStates();
            this.refreshProductPriceDisplay();
            this.updateSummaryPanel();
            this.renderSummary();
            this.fetchClientRates(clientId);
            this.fetchClientAllocation(clientId);

            $(document).trigger('mealsdb_update_summary');
        },

        fetchClientRates(userId, preselectRateId) {
            if (!Number.isInteger(userId) || userId <= 0) {
                this.clearClientRates();
                return;
            }

            $.ajax({
                url: this.getAjaxUrl(),
                method: 'GET',
                dataType: 'json',
                data: {
                    action: 'mealsdb_get_client_rates',
                    nonce: this.getSecurityNonce(),
                    user_id: userId,
                },
            }).done((response) => {
                const payload = this.getResponsePayload(response);

                if (!this.isSuccessfulResponse(response) || !payload) {
                    this.clearClientRates();
                    return;
                }

                const rates = Array.isArray(payload.rates) ? payload.rates : [];
                if (!rates.length) {
                    this.clearClientRates();
                    return;
                }

                this.populateRateSelector(rates, preselectRateId || payload.default_rate_id);
            }).fail(() => {
                this.clearClientRates();
            });
        },

        populateRateSelector(rates, defaultRateId) {
            if (!this.$rateSelect || !this.$rateSelect.length) {
                return;
            }

            this.$rateSelect.empty();
            this.$rateSelect.append($('<option>', { value: '0', text: '\u2014 Select rate \u2014' }));

            rates.forEach((r) => {
                const label = r.label + ' \u2014 $' + r.rate;
                this.$rateSelect.append($('<option>', {
                    value: r.rate_id,
                    text: label,
                    'data-rate': r.rate,
                }));
            });

            if (defaultRateId) {
                this.$rateSelect.val(String(defaultRateId));
            }

            if (this.$rateContainer && this.$rateContainer.length) {
                this.$rateContainer.show();
            }

            this.updateSummaryRate();
        },

        clearClientRates() {
            if (this.$rateSelect && this.$rateSelect.length) {
                this.$rateSelect.empty();
                this.$rateSelect.append($('<option>', { value: '0', text: '\u2014 Select rate \u2014' }));
            }

            if (this.$rateContainer && this.$rateContainer.length) {
                this.$rateContainer.hide();
            }

            this.updateSummaryRate();
        },

        updateSummaryRate() {
            if (!this.$summaryRate || !this.$summaryRate.length) {
                return;
            }

            if (!this.$rateSelect || !this.$rateSelect.length) {
                this.$summaryRate.text(this.translate('Not set'));
                return;
            }

            const selected = this.$rateSelect.find(':selected');
            const rateVal = selected.data('rate');

            if (!rateVal && parseInt(this.$rateSelect.val(), 10) === 0) {
                this.$summaryRate.text(this.translate('Not set'));
                return;
            }

            const rate = parseFloat(rateVal);
            if (!Number.isFinite(rate) || rate < 0) {
                this.$summaryRate.text(this.translate('Not set'));
                return;
            }

            this.$summaryRate.text(this.formatPrice(rate));
        },

        normaliseClientType(value) {
            if (typeof value === 'undefined' || value === null) {
                return '';
            }

            const trimmed = String(value).trim();
            return trimmed ? trimmed.toUpperCase() : '';
        },

        isGovernmentInvoiced() {
            const ct = this.state.currentClientType || '';
            return ct === 'SDNB' || ct === 'VETERAN';
        },

        normaliseClientTypeList(values) {
            if (!Array.isArray(values)) {
                return ['PRIVATE'];
            }

            const mapped = values
                .map((value) => this.normaliseClientType(value))
                .filter((value, index, array) => value !== '' && array.indexOf(value) === index);

            return mapped.length ? mapped : ['PRIVATE'];
        },

        normaliseTaxRate(rawRate) {
            let rate = parseFloat(rawRate);

            if (!Number.isFinite(rate)) {
                return 0;
            }

            if (rate < 0) {
                rate = 0;
            }

            if (rate > 1) {
                rate /= 100;
            }

            return rate;
        },

        getApplicableTaxRate() {
            const baseRate = Number.isFinite(this.state.taxRate) ? this.state.taxRate : 0;
            if (baseRate <= 0) {
                return 0;
            }

            const clientType = this.state.currentClientType || '';
            if (!clientType) {
                return 0;
            }

            const taxableTypes = Array.isArray(this.state.taxableClientTypes) ? this.state.taxableClientTypes : [];

            if (taxableTypes.length > 0) {
                return taxableTypes.includes(clientType) ? baseRate : 0;
            }

            return clientType === 'PRIVATE' ? baseRate : 0;
        },

        getCurrencyPrecision() {
            const currencySettings = window.wcSettings && window.wcSettings.currency ? window.wcSettings.currency : null;
            return currencySettings && typeof currencySettings.precision === 'number' ? currencySettings.precision : 2;
        },

        updateSummaryPanel() {
            const items = Object.values(this.state.cart || {});
            let totalItems = 0;
            let subtotal = 0;
            const govInvoiced = this.isGovernmentInvoiced();

            items.forEach((entry) => {
                if (!entry || !entry.product) {
                    return;
                }

                const quantity = parseInt(entry.quantity, 10) || 0;
                const price = govInvoiced ? 0 : parseFloat(entry.product.price || 0);

                if (quantity <= 0 || !Number.isFinite(price)) {
                    return;
                }

                totalItems += quantity;
                subtotal += quantity * price;
            });

            const taxRate = govInvoiced ? 0 : this.getApplicableTaxRate();
            const precision = this.getCurrencyPrecision();
            const factor = Math.pow(10, precision);
            const taxAmount = Math.round((subtotal * taxRate + Number.EPSILON) * factor) / factor;
            const total = Math.round((subtotal + taxAmount + Number.EPSILON) * factor) / factor;

            if (this.$qoItemsCount && this.$qoItemsCount.length) {
                this.$qoItemsCount.text(totalItems);
            }

            if (this.$qoSubtotal && this.$qoSubtotal.length) {
                this.$qoSubtotal.text(govInvoiced ? '' : this.formatPrice(subtotal));
                this.$qoSubtotal.toggle(!govInvoiced);
            }

            if (this.$qoTax && this.$qoTax.length) {
                this.$qoTax.text(govInvoiced ? '' : this.formatPrice(taxAmount));
                this.$qoTax.toggle(!govInvoiced);
            }

            if (this.$qoTotal && this.$qoTotal.length) {
                this.$qoTotal.text(govInvoiced ? '' : this.formatPrice(total));
                this.$qoTotal.toggle(!govInvoiced);
            }
        },

        refreshProductPriceDisplay() {
            if (!this.$products || !this.$products.length) {
                return;
            }

            const govInvoiced = this.isGovernmentInvoiced();
            const self = this;

            this.$products.find('.mealsdb-quick-order__product-price').each(function () {
                const $el = $(this);
                if (govInvoiced) {
                    $el.text('').hide();
                } else {
                    const $product = $el.closest('.mealsdb-quick-order__product');
                    const productId = parseInt($product.data('productId'), 10);
                    const product = self.state.renderedProducts[productId];
                    const price = product ? parseFloat(product.price || 0) : 0;
                    $el.text(self.formatPrice(price)).show();
                }
            });
        },

        fetchClientAllocation(userId) {
            if (!Number.isInteger(userId) || userId <= 0) {
                this.clearAllocationDisplay();
                return;
            }

            $.ajax({
                url: this.getAjaxUrl(),
                method: 'GET',
                dataType: 'json',
                data: {
                    action: 'mealsdb_qo_get_client_allocation',
                    nonce: this.getSecurityNonce(),
                    user_id: userId,
                },
            }).done((response) => {
                if (response && response.success) {
                    this.state.clientType = response.client_type || null;
                    this.state.allocation = response.allocation || null;
                    this.state.clientFees = response.fees || null;
                    this.state.nextDelivery = response.next_delivery || null;
                    this.state.straddlesMonth = response.straddles_month || false;
                    this.renderAllocationPanel();

                    if (['SDNB', 'Veteran'].includes(response.client_type)) {
                        this.hideProductPrices();
                    } else {
                        this.showProductPrices();
                    }
                }
            });
        },

        renderAllocationPanel() {
            const $panel = $('#mealsdb-qo-allocation');
            if (!$panel.length) return;

            const alloc = this.state.allocation;
            if (!alloc) {
                $panel.hide();
                return;
            }

            // All values flow through escapeHtml() for defense in depth
            // and toFixed() guards against the server omitting a numeric
            // field (which would otherwise raise TypeError).
            const esc = this.escapeHtml.bind(this);
            const intFmt = (v) => esc(String(parseInt(v, 10) || 0));
            const billingMonth   = esc(alloc.billing_month || '');
            const usedMains      = intFmt(alloc.used_mains);
            const permittedMains = intFmt(alloc.permitted_mains);
            const remainingMains = intFmt(alloc.remaining_mains);
            const usedSides      = intFmt(alloc.used_sides);
            const permittedSides = intFmt(alloc.permitted_sides);
            const remainingSides = intFmt(alloc.remaining_sides);
            const overageMains   = parseInt(alloc.overage_mains, 10) || 0;
            const overageSides   = parseInt(alloc.overage_sides, 10) || 0;
            const nextDelivery   = this.state.nextDelivery ? esc(String(this.state.nextDelivery)) : '';

            $panel.html(
                '<h3>Monthly Allowance (' + billingMonth + ')</h3>' +
                '<div class="allocation-row">' +
                    '<span>Mains:</span>' +
                    '<span>' + usedMains + ' / ' + permittedMains + ' used</span>' +
                    '<span>(' + remainingMains + ' remaining)</span>' +
                '</div>' +
                '<div class="allocation-row">' +
                    '<span>Sides:</span>' +
                    '<span>' + usedSides + ' / ' + permittedSides + ' used</span>' +
                    '<span>(' + remainingSides + ' remaining)</span>' +
                '</div>' +
                (overageMains > 0 ? '<div class="allocation-warning">\u26A0 ' + overageMains + ' mains over allowance</div>' : '') +
                (overageSides > 0 ? '<div class="allocation-warning">\u26A0 ' + overageSides + ' sides over allowance</div>' : '') +
                (nextDelivery ? '<div class="allocation-delivery">Next delivery: ' + nextDelivery + '</div>' : '') +
                (this.state.straddlesMonth ? '<div class="allocation-straddle">\u26A0 This delivery straddles the month boundary</div>' : '')
            ).show();

            if (this.state.clientFees) {
                var fees = this.state.clientFees;
                var deliveryFee  = (parseFloat(fees.delivery_fee) || 0).toFixed(2);
                var contribution = (parseFloat(fees.client_contribution) || 0).toFixed(2);
                var collectTotal = (parseFloat(fees.collect_total) || 0).toFixed(2);
                var feesHtml = '<div class="allocation-fees"><h4>Fees for this order</h4>';
                feesHtml += '<div>Delivery Fee: $' + esc(deliveryFee) + '</div>';
                if (fees.contribution_due) {
                    feesHtml += '<div>Client Contribution: $' + esc(contribution) + ' (first delivery this month)</div>';
                } else {
                    feesHtml += '<div>Client Contribution: already applied this month</div>';
                }
                feesHtml += '<div><strong>Collect: $' + esc(collectTotal) + '</strong></div>';
                feesHtml += '</div>';
                $panel.append(feesHtml);
            }
        },

        clearAllocationDisplay() {
            const $panel = $('#mealsdb-qo-allocation');
            if ($panel.length) {
                $panel.empty().hide();
            }
            this.state.allocation = null;
            this.state.clientType = null;
            this.state.clientFees = null;
        },

        hideProductPrices() {
            $('.mealsdb-qo-tile__price, .mealsdb-quick-order__summary-total').hide();
        },

        showProductPrices() {
            $('.mealsdb-qo-tile__price, .mealsdb-quick-order__summary-total').show();
        },

        updateAllocationWithCart() {
            const alloc = this.state.allocation;
            if (!alloc) return;

            let cartMains = 0;
            let cartSides = 0;

            Object.values(this.state.cart || {}).forEach((entry) => {
                if (!entry || !entry.product || entry.quantity <= 0) return;
                if (entry.product.product_type === 'meal') {
                    cartMains += entry.quantity;
                } else if (entry.product.product_type === 'side') {
                    cartSides += entry.quantity;
                }
            });

            const projectedMains = alloc.used_mains + cartMains;
            const projectedSides = alloc.used_sides + cartSides;

            const $panel = $('#mealsdb-qo-allocation');
            if (!$panel.length) return;

            $panel.find('.allocation-row').eq(0).find('span').eq(1)
                .text(projectedMains + ' / ' + alloc.permitted_mains + ' used (' + cartMains + ' in cart)');
            $panel.find('.allocation-row').eq(1).find('span').eq(1)
                .text(projectedSides + ' / ' + alloc.permitted_sides + ' used (' + cartSides + ' in cart)');
        },

        getAjaxUrl() {
            if (window.mealsdbQuickOrder && window.mealsdbQuickOrder.ajaxUrl) {
                return window.mealsdbQuickOrder.ajaxUrl;
            }
            if (typeof window.ajaxurl === 'string') {
                return window.ajaxurl;
            }
            return ''; // Fallback.
        },

        getCloneMessage(key, fallback = '') {
            const config = window.mealsdbQuickOrder && window.mealsdbQuickOrder.messages ? window.mealsdbQuickOrder.messages : null;
            if (config && typeof config[key] !== 'undefined' && config[key] !== null) {
                return config[key];
            }

            return fallback;
        },

        getSecurityNonce(type) {
            const config = window.mealsdbQuickOrder || {};
            const quickOrderNonces = config.nonces || {};
            const quickOrderNonce = config.nonce || '';
            const globalNonce = window.mealsdb && window.mealsdb.nonce ? window.mealsdb.nonce : '';

            if (type === 'createOrder' && quickOrderNonces.createOrder) {
                return quickOrderNonces.createOrder;
            }

            if (type === 'cloneOrder' && quickOrderNonces.cloneOrder) {
                return quickOrderNonces.cloneOrder;
            }

            if (quickOrderNonce) {
                return quickOrderNonce;
            }

            if (globalNonce) {
                return globalNonce;
            }

            return '';
        },

        formatPrice(amount) {
            let value = parseFloat(amount);
            if (!Number.isFinite(value)) {
                value = 0;
            }

            const currencySettings = window.wcSettings && window.wcSettings.currency ? window.wcSettings.currency : null;
            const precision = currencySettings && typeof currencySettings.precision === 'number' ? currencySettings.precision : 2;
            const currencyCode = currencySettings && currencySettings.code ? currencySettings.code : 'USD';
            const locale = (currencySettings && currencySettings.locale) || (navigator.language || 'en-US');

            try {
                const formatter = new window.Intl.NumberFormat(locale, {
                    style: 'currency',
                    currency: currencyCode,
                    minimumFractionDigits: precision,
                    maximumFractionDigits: precision,
                });
                return formatter.format(value);
            } catch (error) {
                // Log the error for debugging purposes
                console.error('Currency formatting failed:', {
                    error: error.message,
                    locale: locale,
                    currencyCode: currencyCode,
                    value: value
                });
                // Fallback to basic formatting
                const symbol = currencySettings && currencySettings.symbol ? currencySettings.symbol : '$';
                return `${symbol}${value.toFixed(precision)}`;
            }
        },

        escapeHtml(text) {
            if (text === null || typeof text === 'undefined') {
                return '';
            }

            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        escapeAttribute(text) {
            return this.escapeHtml(text).replace(/`/g, '&#096;');
        },

        translate(text) {
            if (window.wp && window.wp.i18n && typeof window.wp.i18n.__ === 'function') {
                return window.wp.i18n.__(text, 'meals-db');
            }

            return text;
        },
    };

    function qoShowToast(message, type = 'info') {
        let toast = jQuery('#mealsdb-qo-toast');
        if (!toast.length) {
            jQuery('body').append('<div id="mealsdb-qo-toast"></div>');
            toast = jQuery('#mealsdb-qo-toast');
        }

        let bg = '#323232'; // default
        if (type === 'error') bg = '#d63638'; // WordPress red
        if (type === 'success') bg = '#00a32a'; // WordPress green
        if (type === 'warning') bg = '#ffb900';

        toast.css('background', bg);
        toast.text(message);
        toast.addClass('show');

        setTimeout(() => {
            toast.removeClass('show');
        }, 3000);
    }

    jQuery(document).on('click', '#qo-start-new', function () {
        // Clear cart
        QuickOrder.state.cart = {};

        // Clear tile highlights
        jQuery('.mealsdb-qo-tile').removeClass('selected');

        // Reset quantities
        jQuery('.mealsdb-qo-qty').text('0');

        // Reset summary
        QuickOrder.updateSummaryPanel();

        // Clear success UI
        jQuery('#qo-order-success').hide().empty();

        qoShowToast('Ready for new order.', 'info');
    });

    const $document = jQuery(document);
    $document.off('keydown.mealsdb-qo-enter-create');
    $document.on('keydown.mealsdb-qo-enter-create', function (event) {
        if (event.key !== 'Enter') {
            return;
        }

        const $searchBar = jQuery('#mealsdb_qo_search');
        if ($searchBar.length && $searchBar.is(':focus')) {
            return;
        }

        const tag = event.target && event.target.tagName ? event.target.tagName.toLowerCase() : '';
        const isFormField = ['input', 'textarea', 'select', 'button', 'option'].includes(tag);
        const isEditable = event.target && event.target.isContentEditable;

        if (isFormField || isEditable) {
            return;
        }

        const $createOrder = jQuery('#qo-create-order');
        if ($createOrder.length) {
            event.preventDefault();
            $createOrder.trigger('click');
        }
    });

    $document.off('keydown.mealsdb-qo-escape');
    $document.on('keydown.mealsdb-qo-escape', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        const $search = jQuery('#mealsdb_qo_search');
        if ($search.length && $search.is(':focus') && $search.val().length > 0) {
            $search.val('');
            $search.trigger('input');
            return;
        }

        const $cloneBanner = jQuery('#qo-clone-banner');
        if ($cloneBanner.length && $cloneBanner.is(':visible')) {
            $cloneBanner.fadeOut();
        }
    });

    $document.off('keydown.mealsdb-qo-shortcuts');
    $document.on('keydown.mealsdb-qo-shortcuts', function (event) {
        const $target = jQuery(event.target);
        const tag = event.target && event.target.tagName ? event.target.tagName.toLowerCase() : '';
        const isFormField = ['input', 'textarea', 'select', 'button', 'option'].includes(tag);
        const isEditable = event.target && event.target.isContentEditable;

        if (isFormField || isEditable) {
            return;
        }

        const $search = jQuery('#mealsdb_qo_search');
        if (event.key === '/' && $search.length && !$search.is(':focus')) {
            event.preventDefault();
            $search.focus().select();
        }
    });

    $document.off('keydown.mealsdb-qo-tiles');
    $document.on('keydown.mealsdb-qo-tiles', '.mealsdb-qo-tile', function (event) {
        const $tiles = jQuery('.mealsdb-qo-tile:visible');
        const index = $tiles.index(this);

        const $grid = jQuery('#mealsdb-qo-grid');
        let cols = 4;

        if ($grid.length && typeof window !== 'undefined' && window.getComputedStyle) {
            const template = window.getComputedStyle($grid[0]).gridTemplateColumns;
            if (template) {
                const columnCount = template.split(' ').filter(Boolean).length;
                if (columnCount > 0) {
                    cols = columnCount;
                }
            }
        }

        if (event.key === 'ArrowRight') {
            const $next = $tiles.eq(index + 1);
            if ($next.length) {
                event.preventDefault();
                $next.focus();
            }
        }

        if (event.key === 'ArrowLeft') {
            const $prev = $tiles.eq(index - 1);
            if ($prev.length) {
                event.preventDefault();
                $prev.focus();
            }
        }

        if (event.key === 'ArrowDown') {
            const $down = $tiles.eq(index + cols);
            if ($down.length) {
                event.preventDefault();
                $down.focus();
            }
        }

        if (event.key === 'ArrowUp') {
            const $up = $tiles.eq(index - cols);
            if ($up.length) {
                event.preventDefault();
                $up.focus();
            }
        }

        if (event.key === '+') {
            event.preventDefault();
            jQuery(this).find('.mealsdb-qo-btn[data-action="plus"]').click();
        }

        if (event.key === '-') {
            event.preventDefault();
            jQuery(this).find('.mealsdb-qo-btn[data-action="minus"]').click();
        }
    });

    jQuery(function ($) {
        const search = $('#mealsdb_qo_client_search');
        const dropdown = $('#mealsdb_qo_client_dropdown');
        const hidden = $('#client_id');
        let clientSearchTimer = null;
        let clientSearchXhr = null;

        $(document).on('mealsdb_update_summary', function() {
            const clientName = $('#mealsdb_qo_client_search').val();
            const clientId   = $('#client_id').val();
            const $clientLabel = $('#mealsdb-quick-order-summary-client');

            if (!$clientLabel.length) {
                return;
            }

            if (clientId && clientName) {
                $clientLabel.text(clientName);
            } else {
                $clientLabel.text('Not selected');
            }
        });

        // Hide dropdown when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.mealsdb-client-combobox').length) {
                dropdown.hide();
            }
        });

        // AJAX client search with debounce
        search.on('keyup', function() {
            const term = search.val().trim();

            if (clientSearchTimer) {
                clearTimeout(clientSearchTimer);
            }

            if (term.length < 2) {
                dropdown.empty().hide();
                return;
            }

            clientSearchTimer = setTimeout(function() {
                if (clientSearchXhr) {
                    clientSearchXhr.abort();
                }

                clientSearchXhr = $.ajax({
                    url: (typeof mealsdbQuickOrder !== 'undefined' && mealsdbQuickOrder.ajaxUrl)
                        ? mealsdbQuickOrder.ajaxUrl
                        : (typeof ajaxurl !== 'undefined' ? ajaxurl : ''),
                    method: 'GET',
                    dataType: 'json',
                    data: {
                        action: 'mealsdb_qo_search_clients',
                        term: term,
                        nonce: (typeof mealsdbQuickOrder !== 'undefined' && mealsdbQuickOrder.nonces && mealsdbQuickOrder.nonces.cloneOrder)
                            ? mealsdbQuickOrder.nonces.cloneOrder
                            : (typeof mealsdb !== 'undefined' ? mealsdb.nonce : ''),
                    },
                }).done(function(response) {
                    dropdown.empty();
                    const clients = (response && response.clients) ? response.clients : [];

                    if (!clients.length) {
                        dropdown.append('<div class="client-option--empty" style="padding:6px 10px;color:#888;">No clients found</div>');
                    } else {
                        $.each(clients, function(_, client) {
                            $('<div class="client-option"></div>')
                                .attr('data-id', client.wp_user_id)
                                .attr('data-name', client.name)
                                .text(client.name)
                                .appendTo(dropdown);
                        });
                    }

                    dropdown.show();
                }).fail(function(xhr, status) {
                    // Surface the failure rather than leaving the dropdown
                    // silently empty on a network error / 5xx.
                    if (status === 'abort') {
                        return;
                    }
                    dropdown.empty()
                        .append('<div class="client-option--empty" style="padding:6px 10px;color:#a00;">Search failed. Please try again.</div>')
                        .show();
                });
            }, 300);
        });

        // Show dropdown on focus if it has results
        search.on('focus click', function() {
            if (dropdown.children().length) {
                dropdown.show();
            }
        });

        // When selecting a client
        dropdown.on('click', '.client-option', function() {
            const id = $(this).data('id');
            const name = $(this).data('name');

            if (!id) {
                return;
            }

            search.val(name);
            hidden.val(id);
            hidden.data('clientType', '');

            dropdown.hide();

            $(document).trigger('mealsdb_update_summary');
            hidden.trigger('change');
        });
    });

    $(function () {
        if (typeof mealsdbQuickOrder === 'undefined') {
            return;
        }

        QuickOrder.init();
    });
})(jQuery);
