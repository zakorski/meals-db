(function ($) {
    'use strict';

    const QuickOrder = {
            state: {
                categories: [],
                activeCategoryId: null,
                categoryProducts: {},
                cart: {},
                searchTerm: '',
                isSearching: false,
                hasLoadedClone: false,
                isCloning: false,
                cloneOrderId: null,
                currentClientId: null,
                currentClientType: '',
                taxRate: 0,
                taxableClientTypes: [],
                clientSelectEnhanced: false,
            },

        init() {
            this.cacheElements();
            this.loadConfigurationFromGlobals();
            if (!this.$products || !this.$summary) {
                return;
            }

            this.initialiseClientSelect();
            this.bindEvents();
            this.renderSummary();

            this.fetchCategories();
            this.maybeLoadClonedOrder();
        },

        cacheElements() {
            this.$root = $('.mealsdb-quick-order');
            this.$categories = $('#mealsdb-qo-categories');
            this.$products = $('#mealsdb-quick-order-products');
            this.$summary = $('#mealsdb-quick-order-summary');
            this.$summaryContent = this.$summary.find('.mealsdb-quick-order__summary-content');
            this.$search = $('#mealsdb-quick-order-search');
            this.$clientSelect = $('#mealsdb-qo-client');
            this.$orderDate = $('#mealsdb-quick-order-date');
            this.$createOrder = $('#mealsdb-quick-order-create');
            this.$orderSuccess = $('#qo-order-success');
            this.$qoItemsCount = $('#qo-items-count');
            this.$qoSubtotal = $('#qo-subtotal');
            this.$qoTax = $('#qo-tax');
            this.$qoTotal = $('#qo-total');

            this.$notices = $('<div class="mealsdb-quick-order__notices" />');
            this.$summary.prepend(this.$notices);

            if (this.$createOrder && this.$createOrder.length) {
                const existingSpinner = this.$createOrder.find('.mealsdb-quick-order__spinner');
                if (existingSpinner.length) {
                    this.$createOrderSpinner = existingSpinner;
                } else {
                    this.$createOrderSpinner = $('<span>', {
                        class: 'spinner mealsdb-quick-order__spinner',
                        'aria-hidden': 'true',
                    });
                    this.$createOrder.append(this.$createOrderSpinner);
                }

                this.$createOrderSpinner.removeClass('is-active').hide();
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

        initialiseClientSelect() {
            if (!this.$clientSelect || !this.$clientSelect.length) {
                return;
            }

            if (typeof this.$clientSelect.select2 !== 'function') {
                return;
            }

            const placeholder =
                this.$clientSelect.attr('placeholder') ||
                this.$clientSelect.data('placeholder') ||
                (window.wp && window.wp.i18n ? window.wp.i18n.__('Search clients…', 'meals-db') : 'Search clients…');

            this.$clientSelect.select2({
                width: '100%',
                placeholder,
                minimumInputLength: 2,
                allowClear: true,
                ajax: {
                    url: this.getAjaxUrl(),
                    dataType: 'json',
                    delay: 250,
                    data: (params) => ({
                        action: 'mealsdb_qo_find_clients',
                        search: params && params.term ? params.term.trim() : '',
                        nonce: this.getSecurityNonce(),
                    }),
                    processResults: (response) => {
                        const clients =
                            response && response.success && response.data && Array.isArray(response.data.clients)
                                ? response.data.clients
                                : [];

                        const results = clients.map((client) => ({
                            id: client.id,
                            text: client.name || `Client #${client.id}`,
                            name: client.name || `Client #${client.id}`,
                            first_name: client.first_name || '',
                            last_name: client.last_name || '',
                            email: client.email || '',
                            customer_type: client.customer_type || client.client_type || '',
                        }));

                        return { results };
                    },
                    cache: true,
                },
                templateResult: (data) => this.renderClientTemplate(data),
                templateSelection: (data) => this.renderClientSelection(data),
                escapeMarkup: (markup) => markup,
            });

            this.$clientSelect.on('select2:select', (event) => {
                const clientData = event && event.params ? event.params.data : null;
                this.handleClientSelectionChange(clientData);
            });

            this.$clientSelect.on('select2:clear', () => {
                this.handleClientSelectionChange({ id: null, customer_type: '' });
            });

            this.state.clientSelectEnhanced = true;
        },

        bindEvents() {
            if (this.$clientSelect && this.$clientSelect.length) {
                if (!this.state.clientSelectEnhanced) {
                    this.$clientSelect.on('change', () => {
                        this.handleClientSelectionChange();
                    });
                }
            }

            const debouncedProductSearch = this.debounce((event) => {
                const term = $(event.target).val().trim();
                this.handleProductSearch(term);
            }, 300);

            if (this.$search && this.$search.length) {
                this.$search.on('input', debouncedProductSearch);
            }

            this.$products.on('click', '.mealsdb-quick-order__qty-increase', (event) => {
                event.preventDefault();
                const productId = parseInt($(event.currentTarget).closest('.mealsdb-quick-order__product').data('productId'), 10);
                if (!Number.isInteger(productId) || productId <= 0) {
                    return;
                }
                this.incrementProduct(productId);
            });

            this.$products.on('click', '.mealsdb-quick-order__qty-decrease', (event) => {
                event.preventDefault();
                const productId = parseInt($(event.currentTarget).closest('.mealsdb-quick-order__product').data('productId'), 10);
                if (!Number.isInteger(productId) || productId <= 0) {
                    return;
                }
                this.decrementProduct(productId);
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
                    this.handleCreateOrder();
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
        },

        debounce(callback, delay) {
            let timeoutId;
            return function (...args) {
                const context = this;
                window.clearTimeout(timeoutId);
                timeoutId = window.setTimeout(() => {
                    callback.apply(context, args);
                }, delay);
            };
        },

        renderClientTemplate(data) {
            if (!data || data.loading) {
                return data && data.text ? data.text : '';
            }

            const $container = $('<div class="mealsdb-qo-client-option" />');
            $('<div class="mealsdb-qo-client-option__name" />').text(data.name || data.text || '').appendTo($container);

            const metaItems = [];
            if (data.email) {
                metaItems.push({ className: 'mealsdb-qo-client-option__email', value: data.email });
            }

            const typeLabel = this.normaliseClientType(data.customer_type || data.client_type);
            if (typeLabel) {
                metaItems.push({ className: 'mealsdb-qo-client-option__type', value: typeLabel });
            }

            if (metaItems.length) {
                const $meta = $('<div class="mealsdb-qo-client-option__meta" />');
                metaItems.forEach((item, index) => {
                    if (index > 0) {
                        $('<span class="mealsdb-qo-client-option__separator" />').text('·').appendTo($meta);
                    }
                    $('<span />', { class: item.className }).text(item.value).appendTo($meta);
                });
                $container.append($meta);
            }

            return $container;
        },

        renderClientSelection(data) {
            if (!data) {
                return '';
            }

            const name = data.name || data.text || '';
            const typeLabel = this.normaliseClientType(data.customer_type || data.client_type);

            if (!typeLabel) {
                return name;
            }

            const $container = $('<span class="mealsdb-qo-client-selection" />');
            $('<span class="mealsdb-qo-client-selection__name" />').text(name).appendTo($container);
            $('<span class="mealsdb-qo-client-selection__type" />').text(typeLabel).appendTo($container);
            return $container;
        },

        fetchCategories() {
            this.setCategoriesLoadingState(true);

            $.ajax({
                url: this.getAjaxUrl(),
                method: 'GET',
                dataType: 'json',
                data: {
                    action: 'mealsdb_qo_get_categories',
                    nonce: this.getSecurityNonce(),
                },
            }).done((response) => {
                if (!response || !response.success || !response.data || !Array.isArray(response.data.categories)) {
                    this.renderCategoriesError(response && response.data && response.data.message ? response.data.message : 'Unable to load categories.');
                    return;
                }

                this.state.categories = response.data.categories;
                this.renderCategories();

                if (!this.state.categories.length) {
                    this.renderProducts([]);
                }
            }).fail(() => {
                this.renderCategoriesError('Unable to load categories.');
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

            $.ajax({
                url: this.getAjaxUrl(),
                method: 'GET',
                dataType: 'json',
                data: {
                    action: 'mealsdb_qo_clone_order',
                    nonce: nonce,
                    order_id: orderId,
                },
            }).done((response) => {
                if (!response || !response.success || !response.data) {
                    const message = response && response.data && response.data.message ? response.data.message : this.getCloneMessage('cloneFailed', 'Unable to load products from the selected order.');
                    this.addNotice(message, 'error');
                    return;
                }

                const data = response.data;
                const items = Array.isArray(data.items) ? data.items : [];
                if (!items.length) {
                    const emptyMessage = data.message || this.getCloneMessage('cloneNoItems', 'The selected order does not contain any products that can be cloned.');
                    this.addNotice(emptyMessage, 'error');
                    return;
                }

                this.applyClonedItems(items);

                if (data.order_date && this.$orderDate && this.$orderDate.length && !this.$orderDate.val()) {
                    this.$orderDate.val(data.order_date);
                }

                const successMessage = data.message || this.getCloneMessage('cloneLoaded', 'Products from the selected order have been added to Quick Order.');
                this.addNotice(successMessage, 'success');
            }).fail((jqXHR) => {
                let message = this.getCloneMessage('cloneFailed', 'Unable to load products from the selected order.');
                if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                    message = jqXHR.responseJSON.data.message;
                }
                this.addNotice(message, 'error');
            }).always(() => {
                this.state.isCloning = false;
                this.state.cloneOrderId = 0;
                if (window.mealsdbQuickOrder) {
                    window.mealsdbQuickOrder.cloneOrderId = 0;
                }
            });
        },

        getCloneOrderId() {
            let candidate = this.state.cloneOrderId;
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

            this.$categories.empty();
            this.$categories.attr('role', 'tablist');

            this.state.categories.forEach((category) => {
                const categoryId = parseInt(category.id, 10);
                if (!Number.isInteger(categoryId) || categoryId <= 0) {
                    return;
                }

                const $button = $('<button>', {
                    type: 'button',
                    class: 'button button-secondary mealsdb-qo-cat-tab',
                    text: category.name || `Category #${categoryId}`,
                }).attr({
                    'data-cat': categoryId,
                    role: 'tab',
                    'aria-selected': this.state.activeCategoryId === categoryId ? 'true' : 'false',
                });

                if (this.state.activeCategoryId === categoryId) {
                    $button.addClass('is-active active');
                }

                this.$categories.append($button);
            });

            this.$categories.toggleClass('has-categories', true);

            const $tabs = this.$categories.find('.mealsdb-qo-cat-tab');
            const $firstTab = $tabs.first();
            const hasActiveTab = $tabs.filter('.active, .is-active').length > 0;

            if (!hasActiveTab && $firstTab.length) {
                $firstTab.addClass('active is-active');
                this.loadCategory($firstTab.data('cat'));
            }
        },

        loadCategory(categoryId) {
            const parsedCategoryId = parseInt(categoryId, 10);
            if (!Number.isInteger(parsedCategoryId) || parsedCategoryId <= 0) {
                return null;
            }

            if (parsedCategoryId === this.state.activeCategoryId && !this.state.isSearching) {
                return null;
            }

            this.state.searchTerm = '';
            if (this.$search && this.$search.length) {
                this.$search.val('');
            }

            const $grid = $('#mealsdb-qo-grid');
            const $fadeTarget = $grid.length ? $grid : this.$products;
            if ($fadeTarget && $fadeTarget.length) {
                $fadeTarget.stop(true, true).fadeTo(100, 0.3);
            }

            const finalizeFade = () => {
                const $latestGrid = $('#mealsdb-qo-grid');
                const $target = $latestGrid.length ? $latestGrid : this.$products;
                if ($target && $target.length) {
                    $target.stop(true, true).fadeTo(150, 1);
                }
            };

            const request = this.activateCategory(parsedCategoryId);

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

        activateCategory(categoryId) {
            this.state.activeCategoryId = categoryId;
            this.state.isSearching = false;
            this.renderCategories();

            if (this.state.categoryProducts && Array.isArray(this.state.categoryProducts[categoryId])) {
                this.renderProducts(this.state.categoryProducts[categoryId]);
                if ($ && $.Deferred) {
                    return $.Deferred().resolve().promise();
                }
                return null;
            }

            return this.fetchProductsByCategory(categoryId);
        },

        fetchProductsByCategory(categoryId) {
            this.renderProductsLoading();

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
                if (!response || !response.success || !response.data || !Array.isArray(response.data.products)) {
                    this.renderProductsError(response && response.data && response.data.message ? response.data.message : 'Unable to load products.');
                    return;
                }

                this.state.categoryProducts = this.state.categoryProducts || {};
                this.state.categoryProducts[categoryId] = response.data.products;
                this.renderProducts(response.data.products);
            }).fail(() => {
                this.renderProductsError('Unable to load products.');
            });
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

        handleProductSearch(term) {
            const keyword = term || '';
            this.state.searchTerm = keyword;

            if (keyword.length < 2) {
                this.state.isSearching = false;
                if (Number.isInteger(this.state.activeCategoryId)) {
                    if (this.state.categoryProducts && Array.isArray(this.state.categoryProducts[this.state.activeCategoryId])) {
                        this.renderProducts(this.state.categoryProducts[this.state.activeCategoryId]);
                    } else if (this.state.activeCategoryId) {
                        this.fetchProductsByCategory(this.state.activeCategoryId);
                    }
                }
                return;
            }

            this.state.isSearching = true;
            this.renderProductsLoading();

            if (this.pendingSearchRequest && typeof this.pendingSearchRequest.abort === 'function') {
                this.pendingSearchRequest.abort();
            }

            this.pendingSearchRequest = $.ajax({
                url: this.getAjaxUrl(),
                method: 'GET',
                dataType: 'json',
                data: {
                    action: 'mealsdb_qo_search_products',
                    keyword: keyword,
                    nonce: this.getSecurityNonce(),
                },
            }).done((response) => {
                if (this.state.searchTerm !== keyword) {
                    return;
                }

                if (!response || !response.success || !response.data || !Array.isArray(response.data.products)) {
                    this.renderProductsError(response && response.data && response.data.message ? response.data.message : 'No products found.');
                    return;
                }

                this.renderProducts(response.data.products, { isSearchResults: true });
            }).fail(() => {
                if (this.state.searchTerm === keyword) {
                    this.renderProductsError('Unable to search for products.');
                }
            });
        },

        renderProducts(products, options = {}) {
            if (!this.$products || !this.$products.length) {
                return;
            }

            const list = Array.isArray(products) ? products : [];

            if (!list.length) {
                const message = options.isSearchResults ? 'No products matched your search.' : 'No products found in this category.';
                this.$products.html(`<p>${this.escapeHtml(message)}</p>`);
                return;
            }

            const $grid = $('<div />', {
                class: 'mealsdb-quick-order__product-grid mealsdb-qo-grid',
                id: 'mealsdb-qo-grid',
            });

            list.forEach((product) => {
                const productId = product && product.product_id ? parseInt(product.product_id, 10) : 0;
                if (!Number.isInteger(productId) || productId <= 0) {
                    return;
                }

                const quantity = this.state.cart[productId] ? this.state.cart[productId].quantity : 0;
                const formattedPrice = this.formatPrice(product.price || 0);

                const $tile = $('<div class="mealsdb-qo-tile" />');
                const $product = $('<div class="mealsdb-quick-order__product" />').attr('data-product-id', productId);

                if (product.image_url) {
                    const $imageWrapper = $('<div class="mealsdb-quick-order__product-image" />');
                    $imageWrapper.append($('<img>', {
                        src: product.image_url,
                        alt: product.name || 'Product image',
                        class: 'mealsdb-qo-image',
                        loading: 'lazy',
                    }));
                    $product.append($imageWrapper);
                }

                const $content = $('<div class="mealsdb-quick-order__product-content" />');
                $content.append($('<h3 class="mealsdb-quick-order__product-title" />').text(product.name || `Product #${productId}`));
                $content.append($('<div class="mealsdb-quick-order__product-price" />').text(formattedPrice));

                const $actions = $('<div class="mealsdb-quick-order__product-actions mealsdb-qo-qty-controls" />');
                const $decrease = $('<button type="button" class="button mealsdb-quick-order__qty-decrease mealsdb-qo-btn" aria-label="Decrease quantity">-</button>');
                const $increase = $('<button type="button" class="button mealsdb-quick-order__qty-increase mealsdb-qo-btn" aria-label="Increase quantity">+</button>');
                const $input = $('<input type="number" min="0" class="small-text mealsdb-quick-order__qty-input mealsdb-qo-qty" />').val(quantity);

                $actions.append($decrease, $input, $increase);
                $content.append($actions);
                $product.append($content);

                $product.toggleClass('selected', quantity > 0);
                $tile.toggleClass('selected', quantity > 0);
                $product.data('product', product);
                $tile.append($product);
                $grid.append($tile);
            });

            this.$products.empty().append($grid);
            this.syncCartToVisibleProducts();
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
                $product.find('.mealsdb-quick-order__qty-input').val(quantity);
                $product.toggleClass('selected', quantity > 0);
                $product.closest('.mealsdb-qo-tile').toggleClass('selected', quantity > 0);
            }

            this.renderSummary();
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
            this.syncCartToVisibleProducts();
        },

        findProduct(productId) {
            if (this.state.cart[productId]) {
                return this.state.cart[productId].product;
            }

            if (this.state.isSearching && this.$products) {
                const $product = this.$products.find(`.mealsdb-quick-order__product[data-product-id="${productId}"]`);
                if ($product.length) {
                    return $product.data('product');
                }
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
                this.$summaryContent.html('<p>No products have been added to this order yet.</p>');
                return;
            }

            let totalQuantity = 0;
            let totalPrice = 0;

            const $list = $('<ul class="mealsdb-quick-order__summary-list" />');

            items.forEach((entry) => {
                if (!entry || !entry.product) {
                    return;
                }

                const quantity = parseInt(entry.quantity, 10) || 0;
                const price = parseFloat(entry.product.price || 0);
                totalQuantity += quantity;
                totalPrice += quantity * price;

                const lineTotal = this.formatPrice(quantity * price);
                const $item = $('<li class="mealsdb-quick-order__summary-item" />');
                $item.append($('<span class="mealsdb-quick-order__summary-item-name" />').text(entry.product.name || 'Product'));
                $item.append($('<span class="mealsdb-quick-order__summary-item-qty" />').text(`× ${quantity}`));
                $item.append($('<span class="mealsdb-quick-order__summary-item-total" />').text(lineTotal));
                $list.append($item);
            });

            const $footer = $('<div class="mealsdb-quick-order__summary-footer" />');
            $footer.append($('<div class="mealsdb-quick-order__summary-total-qty" />').text(`Items: ${totalQuantity}`));
            $footer.append($('<div class="mealsdb-quick-order__summary-total-price" />').text(`Total: ${this.formatPrice(totalPrice)}`));

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

                $product.find('.mealsdb-quick-order__qty-input').val(quantity);
            });
        },

        handleCreateOrder() {
            if (!this.$createOrder || !this.$createOrder.length) {
                return;
            }

            this.clearNotices();
            this.hideOrderSuccess();

            const clientIdRaw = this.$clientSelect && this.$clientSelect.length ? this.$clientSelect.val() : '';
            const clientId = parseInt(clientIdRaw, 10);
            const orderDate = this.$orderDate && this.$orderDate.length ? this.$orderDate.val() : '';
            const items = Object.values(this.state.cart || {}).filter((entry) => entry && entry.quantity > 0);

            if (!Number.isInteger(clientId) || clientId <= 0) {
                this.addNotice('Please select a client before creating an order.', 'error');
                return;
            }

            if (!orderDate) {
                this.addNotice('Please select an order date before creating an order.', 'error');
                return;
            }

            if (!items.length) {
                this.addNotice('Please add at least one product to the order.', 'error');
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
                },
            }).done((response) => {
                if (!response || !response.success || !response.data) {
                    this.addNotice(response && response.data && response.data.message ? response.data.message : 'Failed to create the order.', 'error');
                    return;
                }

                const orderId = response.data.order_id ? parseInt(response.data.order_id, 10) : 0;
                const message = response.data.message || 'Order created successfully.';
                this.showOrderSuccess(message, orderId);

                this.state.cart = {};
                this.renderSummary();
                this.$products.find('.mealsdb-quick-order__qty-input').val(0);
            }).fail((jqXHR) => {
                let message = 'Failed to create the order.';
                if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                    message = jqXHR.responseJSON.data.message;
                }
                this.addNotice(message, 'error');
            }).always(() => {
                this.setCreateOrderBusy(false);
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
            this.$createOrder.toggleClass('is-busy', !!isBusy);
            this.$createOrder.attr('aria-busy', isBusy ? 'true' : 'false');

            if (this.$createOrderSpinner && this.$createOrderSpinner.length) {
                this.$createOrderSpinner.toggleClass('is-active', !!isBusy);
                if (isBusy) {
                    this.$createOrderSpinner.show();
                } else {
                    this.$createOrderSpinner.hide();
                }
            }
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

        showOrderSuccess(message, orderId) {
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
            if (Number.isInteger(orderId) && orderId > 0) {
                const orderUrl = this.escapeAttribute(this.buildOrderAdminLink(orderId));
                const viewOrderText = this.translate('View order #%s');
                const orderText = this.escapeHtml(viewOrderText.replace('%s', orderId));
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

            if (clientData && typeof clientData === 'object') {
                const parsedId = parseInt(clientData.id, 10);
                clientId = Number.isInteger(parsedId) && parsedId > 0 ? parsedId : null;
                clientType = clientData.customer_type || clientData.client_type || '';
            } else {
                const selectedValue = this.$clientSelect.val();
                const parsedId = parseInt(selectedValue, 10);
                clientId = Number.isInteger(parsedId) && parsedId > 0 ? parsedId : null;

                const $selected = this.$clientSelect.find('option:selected');
                if ($selected.length) {
                    const selectedData = $selected.data('client');
                    if (selectedData && selectedData.customer_type) {
                        clientType = selectedData.customer_type;
                    } else if (selectedData && selectedData.client_type) {
                        clientType = selectedData.client_type;
                    } else if ($selected.data('clientType')) {
                        clientType = $selected.data('clientType');
                    }
                }
            }

            this.state.currentClientId = clientId;
            this.state.currentClientType = this.normaliseClientType(clientType);

            if (window.mealsdbQuickOrder) {
                window.mealsdbQuickOrder.clientType = this.state.currentClientType;
            }

            this.updateSummaryPanel();
        },

        normaliseClientType(value) {
            if (typeof value === 'undefined' || value === null) {
                return '';
            }

            const trimmed = String(value).trim();
            return trimmed ? trimmed.toUpperCase() : '';
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

            items.forEach((entry) => {
                if (!entry || !entry.product) {
                    return;
                }

                const quantity = parseInt(entry.quantity, 10) || 0;
                const price = parseFloat(entry.product.price || 0);

                if (quantity <= 0 || !Number.isFinite(price)) {
                    return;
                }

                totalItems += quantity;
                subtotal += quantity * price;
            });

            const taxRate = this.getApplicableTaxRate();
            const precision = this.getCurrencyPrecision();
            const factor = Math.pow(10, precision);
            const taxAmount = Math.round((subtotal * taxRate + Number.EPSILON) * factor) / factor;
            const total = Math.round((subtotal + taxAmount + Number.EPSILON) * factor) / factor;

            if (this.$qoItemsCount && this.$qoItemsCount.length) {
                this.$qoItemsCount.text(totalItems);
            }

            if (this.$qoSubtotal && this.$qoSubtotal.length) {
                this.$qoSubtotal.text(this.formatPrice(subtotal));
            }

            if (this.$qoTax && this.$qoTax.length) {
                this.$qoTax.text(this.formatPrice(taxAmount));
            }

            if (this.$qoTotal && this.$qoTotal.length) {
                this.$qoTotal.text(this.formatPrice(total));
            }
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
            const globalNonce = window.mealsdb && window.mealsdb.nonce ? window.mealsdb.nonce : '';
            const quickOrderNonces = window.mealsdbQuickOrder && window.mealsdbQuickOrder.nonces ? window.mealsdbQuickOrder.nonces : {};

            if (type === 'cloneOrder' && quickOrderNonces.cloneOrder) {
                return quickOrderNonces.cloneOrder;
            }

            if (type === 'createOrder' && quickOrderNonces.createOrder) {
                return quickOrderNonces.createOrder;
            }

            if (quickOrderNonces.searchProducts) {
                return quickOrderNonces.searchProducts;
            }

            return globalNonce;
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

    const loadCategory = (categoryId) => {
        if (QuickOrder && typeof QuickOrder.loadCategory === 'function') {
            return QuickOrder.loadCategory(categoryId);
        }

        return null;
    };

    $(document).on('click', '.mealsdb-qo-cat-tab', function () {
        const $tab = $(this);
        $('.mealsdb-qo-cat-tab').removeClass('active is-active');
        $tab.addClass('active is-active');
        loadCategory($tab.data('cat'));
    });

    $(document).on('keydown', function (event) {
        const $tabs = $('.mealsdb-qo-cat-tab');
        if (!$tabs.length) {
            return;
        }

        const $activeTab = $tabs.filter('.active, .is-active').first();
        const currentIndex = $tabs.index($activeTab);

        if (event.key === 'ArrowRight') {
            const $next = $tabs.eq(currentIndex + 1);
            if ($next.length) {
                $next.trigger('click');
            }
        }

        if (event.key === 'ArrowLeft') {
            const $prev = $tabs.eq(currentIndex - 1);
            if ($prev.length) {
                $prev.trigger('click');
            }
        }
    });

    $(function () {
        if (typeof mealsdbQuickOrder === 'undefined') {
            return;
        }

        QuickOrder.init();
    });
})(jQuery);
