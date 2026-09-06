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
                nextDatesSeq: 0,
                // FOLLOW-UP DIRECTIVE C (ITEM 1): guards the client-allocation
                // fetch against an out-of-order response overwriting a newer
                // client's context/allowance/zone panel with an older one's.
                allocationSeq: 0,
                cloneOrderId: null,
                // Directive 1 (ITEM 2): the parked draft being completed in place.
                // 0 = normal (new order) mode. Distinct from cloneOrderId, which
                // builds a NEW order and is cleared after the read completes.
                reopenOrderId: 0,
                hasLoadedReopen: false,
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
            this.maybeLoadReopenOrder();
            this.maybeLoadClonedOrder();
        },

        cacheElements() {
            this.$root = $('.mealsdb-quick-order');
            this.$categories = $('#mealsdb-qo-categories');
            this.$products = $('#mealsdb-quick-order-products');
            this.$grid = $('#mealsdb-qo-grid');
            this.$summary = $('#mealsdb-quick-order-summary');
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

            // Directive 2 (ITEM 6): per-line +/- and remove in the order summary.
            // Delegated because the summary list is re-rendered on every change.
            $(document).on('click', '.mealsdb-qo-line-inc, .mealsdb-qo-line-dec, .mealsdb-qo-line-remove', (event) => {
                event.preventDefault();
                const $btn = $(event.currentTarget);
                const productId = parseInt($btn.closest('[data-product-id]').data('productId'), 10);
                if (!Number.isInteger(productId) || productId <= 0) {
                    return;
                }
                if ($btn.hasClass('mealsdb-qo-line-inc')) {
                    this.incrementProduct(productId);
                } else if ($btn.hasClass('mealsdb-qo-line-dec')) {
                    this.decrementProduct(productId);
                } else {
                    this.setProductQuantity(productId, 0);
                }
            });

            // Directive 2 (ITEM 3): quantities are typed ONLY. Block the two
            // accidental-change vectors on every qty field (picker AND summary):
            // arrow keys and the mouse wheel. The explicit +/- steppers are
            // deliberate clicks and stay. In a phone-order workflow a silent
            // wheel/arrow change is not noticed until the packer sees it.
            $(document).on('keydown', '.mealsdb-quick-order__qty-input', (event) => {
                if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
                    event.preventDefault();
                }
            });
            // Wheel must be a non-passive native listener or preventDefault is
            // ignored (delegated wheel handlers default to passive). Only block
            // when the field is focused — an unfocused number input ignores the
            // wheel anyway, so the page still scrolls normally over the grid.
            document.addEventListener(
                'wheel',
                (event) => {
                    const target = event.target;
                    if (
                        target &&
                        target.classList &&
                        target.classList.contains('mealsdb-quick-order__qty-input') &&
                        document.activeElement === target
                    ) {
                        event.preventDefault();
                    }
                },
                { passive: false }
            );

            if (this.$createOrder && this.$createOrder.length) {
                this.$createOrder.on('click', (event) => {
                    event.preventDefault();
                    this.showCreateOrderSpinner();
                    qoShowToast('Submitting order...', 'info');
                    this.handleCreateOrder(this.$createOrder, { saveAsDraft: false });
                });
            }

            // Directive 1 (ITEM 1): Save as Draft parks the order (server assigns
            // wc-checkout-draft; no fee/allocation/stock/slip/invoice effect).
            const $saveDraft = $('#qo-save-draft');
            if ($saveDraft.length) {
                $saveDraft.on('click', (event) => {
                    event.preventDefault();
                    qoShowToast('Saving draft...', 'info');
                    this.handleCreateOrder($saveDraft, { saveAsDraft: true });
                });
            }

            // Directive 1 (ITEM 3): Clear Order empties the basket but keeps the
            // client selected.
            const $clearOrder = $('#qo-clear-order');
            if ($clearOrder.length) {
                $clearOrder.on('click', (event) => {
                    event.preventDefault();
                    this.clearCart();
                    qoShowToast('Order cleared.', 'info');
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
                    this.updateSummaryDate();
                    // Re-derive the rule-default next dates from the
                    // new order date.
                    const clientId = this.$clientSelect && this.$clientSelect.length
                        ? parseInt(this.$clientSelect.val(), 10) : 0;
                    if (clientId > 0) {
                        this.fetchNextDates(clientId);
                    }
                });
            }

            $(document).on('click', '#mealsdb-qo-next-reset', () => {
                const defaults = (this.state && this.state.nextDatesDefaults) || {};
                $('#mealsdb-qo-next-order-date').val(defaults.order || '');
                $('#mealsdb-qo-next-delivery-date').val(defaults.delivery || '');
            });

            // Manual delivery-date override: re-check the soft warning on
            // every edit. Advisory only — never disables Create.
            $(document).on('change input', '#mealsdb-qo-delivery-date', () => {
                this.refreshDeliveryDateWarning();
            });

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

        // Single source of truth for the Order Summary's date line. Callable
        // after a programmatic prefill so the summary reflects the field
        // WITHOUT firing the $orderDate 'change' handler — that handler also
        // re-runs fetchNextDates() (a network round-trip that rewrites the
        // delivery-date field), so triggering it just to refresh the summary
        // would be a redundant fetch on the prefill path. Keep this display-only.
        updateSummaryDate() {
            if (this.$summaryDate && this.$summaryDate.length) {
                const value = this.$orderDate && this.$orderDate.length ? this.$orderDate.val() : '';
                this.$summaryDate.text(value ? value : this.translate('Not set'));
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

        // Directive 1 (ITEM 2): reopen a parked draft into the form. Reading the
        // draft reuses the clone read path (same client/items/date payload); the
        // ONLY difference is that reopenOrderId is remembered so Create completes
        // that order in place instead of creating a new one. loadClonedOrder()
        // clears cloneOrderId on completion but leaves reopenOrderId intact.
        maybeLoadReopenOrder() {
            const reopenOrderId = this.getReopenOrderId();
            if (!Number.isInteger(reopenOrderId) || reopenOrderId <= 0) {
                return;
            }

            if (this.state.hasLoadedReopen || this.state.isCloning) {
                return;
            }

            this.state.hasLoadedReopen = true;
            this.state.reopenOrderId = reopenOrderId;

            // Relabel the primary action so the operator knows this completes an
            // existing order rather than creating a new one.
            if (this.$createOrder && this.$createOrder.length) {
                this.$createOrder.text(this.translate('Complete Order'));
            }

            this.loadClonedOrder(reopenOrderId);
        },

        getReopenOrderId() {
            const params =
                (typeof window !== 'undefined' && window.location && window.location.search)
                    ? new URLSearchParams(window.location.search)
                    : null;
            if (params) {
                const parsed = parseInt(params.get('reopen_order'), 10);
                if (Number.isInteger(parsed) && parsed > 0) {
                    return parsed;
                }
            }

            if (Number.isInteger(this.state.reopenOrderId) && this.state.reopenOrderId > 0) {
                return this.state.reopenOrderId;
            }

            if (window.mealsdbQuickOrder && typeof window.mealsdbQuickOrder.reopenOrderId !== 'undefined') {
                const fromConfig = parseInt(window.mealsdbQuickOrder.reopenOrderId, 10);
                if (Number.isInteger(fromConfig) && fromConfig > 0) {
                    return fromConfig;
                }
            }

            if (this.$root && this.$root.length) {
                const fromAttr = parseInt(this.$root.attr('data-reopen-order-id'), 10);
                if (Number.isInteger(fromAttr) && fromAttr > 0) {
                    return fromAttr;
                }
            }

            return 0;
        },

        // Directive 1 (ITEMS 3 & 4): empty the basket in place — remove every
        // line, reset the tiles/inputs, and recalculate the summary. The
        // selected client is deliberately preserved (Clear Order does not
        // deselect; changing client is what deselects). No page reload.
        clearCart() {
            this.state.cart = {};

            if (this.$products && this.$products.length) {
                this.$products
                    .find('.mealsdb-quick-order__qty-input')
                    .val(0);
                this.$products
                    .find('.mealsdb-quick-order__product.selected')
                    .removeClass('selected');
            }
            // Tile-variant rendering uses different hooks; reset those too so
            // both product layouts clear consistently.
            $('.mealsdb-qo-tile.selected').removeClass('selected');
            $('.mealsdb-qo-qty').text('0');

            this.renderSummary();
            this.updateSummaryPanel();
            this.updateAllocationWithCart();
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
                    // Keep the Order Summary in sync with the prefilled value
                    // (display-only; see updateSummaryDate — avoids re-firing
                    // the change handler's fetchNextDates round-trip).
                    this.updateSummaryDate();
                    // Recompute Next Order / Next Delivery from the CLONED date:
                    // the change-triggered fetch ran before this date was set, so
                    // the panel showed pre-clone values. Call directly, never via a
                    // change event — change re-enters fetchNextDates and one clone
                    // call site is inside that handler (recursion). Override stays
                    // empty (skipDeliveryPrefill).
                    if (payload.client_id) {
                        this.fetchNextDates(parseInt(payload.client_id, 10), { skipDeliveryPrefill: true });
                    }
                }

                // FOLLOW-UP DIRECTIVE C (ITEM 5): restore the one-time delivery
                // date when REOPENING a draft, so completing it keeps its delivery
                // date instead of placing an order with none. Reopen only — a
                // clone's delivery-date behaviour is a separate open decision, so
                // it is deliberately left as-is. fetchNextDates above uses
                // skipDeliveryPrefill so it won't clobber this.
                if (this.state.reopenOrderId && payload.delivery_date) {
                    const $dd = $('#mealsdb-qo-delivery-date');
                    if ($dd.length) {
                        $dd.val(payload.delivery_date);
                        this.refreshDeliveryDateWarning();
                    }
                }

                if (hasItems) {
                    this.applyClonedItems(parsedItems.available);
                }

                this.setMissingCloneItems(hasMissing ? parsedItems.missing : []);
                this.renderUnavailableTilesFromState();

                // Monthly Allowance can read empty after a clone if the
                // change-triggered allocation fetch is raced by the subsequent
                // clone rendering. Re-fetch explicitly with the resolved client id
                // as the last writer (idempotent). fetchClientAllocation also
                // drives hide/showProductPrices off client_type, so this re-asserts
                // v553 government price suppression on the clone path.
                if (payload.client_id) {
                    this.fetchClientAllocation(parseInt(payload.client_id, 10));
                }

                // Notice must reflect what actually landed in the cart — a
                // cloned order can resolve to zero cart items (all source
                // products delisted), and reporting that as success is the
                // false-success bug this path guards against. addNotice shows
                // ONE notice at a time (empty().append), so combine into a
                // single message per case rather than stacking two calls.
                const missingCount = parsedItems.missing.length;

                if (hasItems) {
                    // ITEM 3: the localized `cloneLoaded` string is always defined
                    // (class-admin-ui.php), so getCloneMessage never falls through
                    // to an order-labelled variant — dropped the dead
                    // payload.order_number/order_id lookup. The `payload.message ||`
                    // guard stays as a forward-compatible default for a server that
                    // may later send a message.
                    const successMessage =
                        payload.message ||
                        this.getCloneMessage('cloneLoaded', 'Products from the selected order have been added to Quick Order.');

                    if (hasMissing) {
                        // Partial load — resolvable items are in the cart, but
                        // some source products are no longer available. Say so
                        // in the same notice so a partial clone can't pass for
                        // a complete one.
                        const partialSuffix = this.getCloneMessage(
                            'clonePartial',
                            `${missingCount} product(s) from the source order are no longer available and were not added.`
                        );
                        this.addNotice(`${successMessage} ${partialSuffix}`, 'warning');
                    } else {
                        this.addNotice(successMessage, 'success');
                    }
                } else {
                    // hasMissing && !hasItems: nothing landed in the cart.
                    // Emit an error, never a success notice.
                    const noneMessage = this.getCloneMessage(
                        'cloneAllUnavailable',
                        `No products from the source order could be added — ${missingCount} product(s) are no longer available.`
                    );
                    this.addNotice(noneMessage, 'error');
                }
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

            // Directive 3 (ITEM 1): "Mains" and "Sides" are DERIVED tabs, from
            // product_type ('meal' / 'side'), not WooCommerce categories. They sit
            // immediately after All and before the category tabs. Shown only when
            // products of that type exist. The remaining category tabs follow in
            // the order PHP returns them (the configured tab sequence).
            const products = Array.isArray(QO_PRODUCTS) ? QO_PRODUCTS : [];
            const hasType = (type) => products.some((p) => p && p.product_type === type);
            const appendVirtualTab = (slug, label) => {
                const $btn = $('<button>', { type: 'button', class: 'qo-tab', text: label })
                    .attr({ 'data-cat': slug, 'data-cat-id': 0 });
                if (this.state.activeCategorySlug === slug) {
                    $btn.addClass('active');
                }
                $tabsWrap.append($btn);
            };
            if (hasType('meal')) {
                appendVirtualTab('mains', 'Mains');
            }
            if (hasType('side')) {
                appendVirtualTab('sides', 'Sides');
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

            // Virtual categories (derived "mains"/"sides") have no real category
            // ID; if nothing matched, show an empty state rather than erroring.
            if (slug === 'sides' || slug === 'mains') {
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

            // Directive 3 (ITEM 1): the derived Mains/Sides tabs filter by
            // product_type, not by category. Every other tab filters by slug.
            if (slug === 'mains' || slug === 'sides') {
                const wantType = slug === 'mains' ? 'meal' : 'side';
                const typeMatches = QO_PRODUCTS.filter(
                    (product) => product && product.product_type === wantType
                );
                return typeMatches.length ? typeMatches : null;
            }

            const matchSlugs = [slug];

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

            // Directive 2 (ITEMS 1 & 2): stock lines + out-of-stock (available <= 0)
            // red state. The colour keys on AVAILABLE, not current — a product with
            // stock but everything committed is effectively out. Tiles stay
            // selectable regardless (a warning, not a block).
            const stockHtml = this.buildProductStock(product);
            const available = (product && product.available_stock !== null && typeof product.available_stock !== 'undefined')
                ? parseInt(product.available_stock, 10)
                : null;
            const outOfStockClass = (available !== null && !Number.isNaN(available) && available <= 0)
                ? ' mealsdb-qo-tile--out-of-stock'
                : '';

            return `
                <div class="mealsdb-qo-tile qo-product${selectedClass}${restrictionClass}${outOfStockClass}" tabindex="0" data-cat="${dataCategories}">
                    <div class="mealsdb-quick-order__product${selectedClass}" data-product-id="${this.escapeAttribute(
                        productId
                    )}">
                        ${imageHtml}
                        <div class="mealsdb-quick-order__product-content">
                            <h3 class="mealsdb-quick-order__product-title qo-product-name">${safeName}</h3>
                            <div class="mealsdb-quick-order__product-price">${safePrice}</div>
                            ${stockHtml}
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

        // Directive 2 (ITEM 1): two clearly-labelled figures per product —
        // "Available" (current minus everything committed on unfulfilled orders,
        // the number that answers "can I promise this today") and the raw
        // in-stock count for reference. A product that does not manage stock
        // shows an explicit "not tracked" rather than a misleading 0.
        buildProductStock(product) {
            const hasCurrent =
                product && product.current_stock !== null && typeof product.current_stock !== 'undefined';
            if (!hasCurrent) {
                return `<div class="mealsdb-qo-stock mealsdb-qo-stock--untracked">${this.escapeHtml(
                    this.translate('Stock: not tracked')
                )}</div>`;
            }

            const current = parseInt(product.current_stock, 10) || 0;
            const available =
                product.available_stock !== null && typeof product.available_stock !== 'undefined'
                    ? parseInt(product.available_stock, 10)
                    : current;
            const outClass = available <= 0 ? ' mealsdb-qo-stock--out' : '';

            return (
                `<div class="mealsdb-qo-stock${outClass}">` +
                `<span class="mealsdb-qo-stock__avail">${this.escapeHtml(this.translate('Available'))}: ${available}</span> ` +
                `<span class="mealsdb-qo-stock__current">${this.escapeHtml(this.translate('in stock'))}: ${current}</span>` +
                `</div>`
            );
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

                const lineProductId = parseInt(entry.product.product_id, 10) || 0;
                const $item = $('<li class="mealsdb-quick-order__summary-item" />')
                    .attr('data-product-id', lineProductId);
                $item.append($('<span class="mealsdb-quick-order__summary-item-name" />').text(entry.product.name || 'Product'));

                // Directive 2 (ITEM 6): per-line +/- and remove, editable in place.
                // These mutate client-side cart state synchronously via
                // setProductQuantity (which re-renders the summary and totals), so
                // there is no debounced/shared-payload race — five fast + clicks
                // land as five increments. (Contrast the PO screen's debounced
                // stepper defect this deliberately avoids.)
                const $controls = $('<span class="mealsdb-quick-order__summary-item-controls" />');
                $controls.append(
                    $('<button type="button" class="button mealsdb-qo-line-btn mealsdb-qo-line-dec" aria-label="Decrease quantity" />').text('-')
                );
                $controls.append($('<span class="mealsdb-quick-order__summary-item-qty" />').text(quantity));
                $controls.append(
                    $('<button type="button" class="button mealsdb-qo-line-btn mealsdb-qo-line-inc" aria-label="Increase quantity" />').text('+')
                );
                $controls.append(
                    $('<button type="button" class="button-link mealsdb-qo-line-remove" aria-label="Remove line" />').text('×')
                );
                $item.append($controls);

                if (!govInvoiced) {
                    const lineTotal = this.formatPrice(quantity * price);
                    $item.append($('<span class="mealsdb-quick-order__summary-item-total" />').text(lineTotal));
                }
                $list.append($item);
            });

            const $footer = $('<div class="mealsdb-quick-order__summary-footer" />');
            $footer.append($('<div class="mealsdb-quick-order__summary-total-qty" />').text(`Items: ${totalQuantity}`));
            if (!govInvoiced) {
                $footer.append($('<div class="mealsdb-quick-order__summary-total-price" />').text(`Subtotal (before tax): ${this.formatPrice(totalPrice)}`));
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

        handleCreateOrder(createButton = null, options = {}) {
            if (!this.$createOrder || !this.$createOrder.length) {
                return;
            }

            const saveAsDraft = !!(options && options.saveAsDraft);
            const reopenOrderId =
                Number.isInteger(this.state.reopenOrderId) && this.state.reopenOrderId > 0
                    ? this.state.reopenOrderId
                    : 0;

            this.clearNotices();
            this.hideOrderSuccess();

            const clientIdRaw = this.$clientSelect && this.$clientSelect.length ? this.$clientSelect.val() : '';
            const clientId = parseInt(clientIdRaw, 10);
            const orderDate = this.$orderDate && this.$orderDate.length ? this.$orderDate.val() : '';
            const deliveryDate = $('#mealsdb-qo-delivery-date').val() || '';
            const items = Object.values(this.state.cart || {}).filter((entry) => entry && entry.quantity > 0);
            const rateId = this.$rateSelect && this.$rateSelect.length ? parseInt(this.$rateSelect.val(), 10) || 0 : 0;

            if (!Number.isInteger(clientId) || clientId <= 0 || !items.length) {
                qoShowToast('Please select a client and at least one product.', 'error');
                this.clearCreateOrderLoading(createButton);
                return;
            }

            // The actual submit, gated below behind the in-page date-sanity
            // confirm. Extracted into a closure so nothing is posted before the
            // (asynchronous) confirm resolves. Arrow fn preserves `this`.
            const submit = () => {
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
                    // Directive 1 (ITEM 1): park as draft — server assigns
                    // wc-checkout-draft and fires none of the placement effects.
                    save_as_draft: saveAsDraft ? 1 : 0,
                    // Directive 1 (ITEM 2): complete a reopened draft in place
                    // (same order id → processing) rather than create a new order.
                    reopen_order_id: reopenOrderId,
                    next_order_date: $('#mealsdb-qo-next-order-date').val() || '',
                    next_delivery_date: $('#mealsdb-qo-next-delivery-date').val() || '',
                    // One-time delivery-date override for THIS order only
                    // ('' = none; server writes _delivery_date when valid).
                    delivery_date: deliveryDate,
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
                // Directive 1 (ITEMS 1/2): tailor the confirmation to what
                // actually happened — a parked draft, a completed reopen, or a
                // fresh order. The server echoes is_draft.
                const isDraftResp = !!(payload && payload.is_draft);
                let successMessage;
                if (isDraftResp) {
                    successMessage = this.translate('Draft saved — not placed. No allocation, stock or slip effect.');
                } else if (reopenOrderId) {
                    successMessage = this.translate('Draft completed — order placed.');
                    // The draft is now a placed order; a second Create must not
                    // try to reopen it. Drop reopen mode and restore the label.
                    this.state.reopenOrderId = 0;
                    if (this.$createOrder && this.$createOrder.length) {
                        this.$createOrder.text(this.translate('Create Order'));
                    }
                    // FOLLOW-UP DIRECTIVE C (ITEM 5): after a completion the form
                    // clears fully — NO client and NO items — so pressing Create
                    // again cannot place a second order for the same client.
                    // clearCart() empties the basket (it deliberately keeps the
                    // client), so also deselect the client: clearing #client_id and
                    // firing change cascades through handleClientSelectionChange to
                    // reset the context/allowance/zone panels and the summary.
                    this.clearCart();
                    if (this.$clientSelect && this.$clientSelect.length) {
                        this.$clientSelect.val('').data('clientType', '').data('clientAllergens', []);
                    }
                    if (this.$clientSearch && this.$clientSearch.length) {
                        this.$clientSearch.val('');
                    }
                    this.handleClientSelectionChange();
                } else {
                    successMessage = this.getResponseMessage(response, 'Order created successfully!');
                }

                // U07-quick-order-4: the server saves the order even when it is
                // SHORT of what was entered — lines whose product no longer
                // resolves are dropped, and any per-line qty over 100 is clamped.
                // On a meal-delivery billing path that means a client may not get
                // food, so warn instead of a plain "success" toast. The success
                // banner (showOrderSuccess) still confirms the order was created;
                // qoShowToast reuses a single toast element, so show the warning
                // in place of the success toast rather than have it overwritten.
                const droppedItems =
                    (payload && payload.dropped_items) || response.dropped_items || [];
                const clampedItems =
                    (payload && payload.clamped_items) || response.clamped_items || [];
                const hasDropped = Array.isArray(droppedItems) && droppedItems.length > 0;
                const hasClamped = Array.isArray(clampedItems) && clampedItems.length > 0;

                if (hasDropped || hasClamped) {
                    const parts = [];
                    if (hasDropped) {
                        parts.push(`${droppedItems.length} item(s) could not be added and were dropped`);
                    }
                    if (hasClamped) {
                        parts.push(`${clampedItems.length} line(s) were reduced to the 100-per-line limit`);
                    }
                    qoShowToast(`Order saved, but ${parts.join('; ')}. Review the order before delivery.`, 'warning');
                } else {
                    qoShowToast(successMessage, 'success');
                }

                // The override is one-time-only: clear it after a
                // successful create so it can't silently ride along on
                // the operator's next order.
                $('#mealsdb-qo-delivery-date').val('');
                this.refreshDeliveryDateWarning();

                this.showOrderSuccess(successMessage, orderId, orderLink);

                // J1-quick-order-js-1: #qo-order-success is not present in every
                // rendered view — when it's absent showOrderSuccess() falls back to
                // addNotice() into $notices. Calling .offset().top on an empty
                // jQuery set returns undefined and throws a TypeError inside this
                // .done() callback, which previously skipped the .always() cleanup
                // and left the Create button stuck disabled with its spinner after
                // a *successful* order. Scroll to whichever element actually rendered.
                const $scrollTarget =
                    this.$orderSuccess && this.$orderSuccess.length ? this.$orderSuccess : this.$notices;
                if ($scrollTarget && $scrollTarget.length && $scrollTarget.offset()) {
                    jQuery('html, body').animate({ scrollTop: $scrollTarget.offset().top - 30 }, 300);
                }
            }).fail(() => {
                qoShowToast('Error creating order. Please try again.', 'error');
            }).always(() => {
                this.setCreateOrderBusy(false);
                this.clearCreateOrderLoading(createButton);
            });
            }; // end submit()

            // Directive 1 (ITEM 5): sanity-check the dates — WARN, do not block —
            // now an in-page modal (native confirm() removed). Empty OR past order/
            // delivery date lists the issues; the operator confirms to proceed
            // (retroactive entry is legitimate). Skipped for a draft (a parked
            // order is explicitly incomplete). The weekday-mismatch advisory is
            // unaffected and still never blocks.
            if (!saveAsDraft) {
                const dateWarnings = this.dateSanityWarnings(orderDate, deliveryDate);
                if (dateWarnings.length) {
                    window.MealsDBConfirm.confirm({
                        title: this.translate('Check the dates'),
                        message: dateWarnings.concat([this.translate('Create this order anyway?')]),
                        confirmLabel: this.translate('Create anyway')
                    }).then((proceed) => {
                        if (!proceed) {
                            // On cancel, focus the first offending field — the
                            // empty Order Date is the usual culprit.
                            if (!orderDate && this.$orderDate && this.$orderDate.length) {
                                this.$orderDate.trigger('focus');
                            }
                            this.clearCreateOrderLoading(createButton);
                            return;
                        }
                        submit();
                    });
                    return; // submit() runs from the .then above
                }
            }
            submit();
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

            // HPOS-exclusive: orders live in wc_orders, so the edit screen is
            // admin.php?page=wc-orders&action=edit&id=ID. The legacy
            // post.php?post=ID URL does not open a wc_orders record under HPOS.
            // This fallback only runs when the server omits order_link.
            const baseUrl = window.ajaxurl ? window.ajaxurl.replace(/admin-ajax\.php/i, 'admin.php') : (window.location.origin + '/wp-admin/admin.php');
            return `${baseUrl}?page=wc-orders&action=edit&id=${orderId}`;
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
            } else if (type === 'warning') {
                classes.push('notice-warning');
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
                // Accept the canonical snake_case key, then a camelCase
                // clientType fallback. The original duplicated `client_type`
                // twice, making the second operand dead (a caller passing
                // camelCase silently got '').
                clientType = clientData.client_type || clientData.clientType || '';

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

            // Directive 1 (ITEM 4): switching to a DIFFERENT client empties the
            // basket automatically — no confirmation prompt (Zak, 2026-09-04) —
            // so one client's items can never be ordered against another. Guarded
            // so it does NOT fire while a clone/reopen is populating the form
            // (that path sets the client then adds items right after), nor on the
            // first selection, nor on a re-fire of the same client.
            const previousClientId = this.state.currentClientId;
            if (
                !this.state.isCloning
                && previousClientId
                && clientId
                && previousClientId !== clientId
            ) {
                this.clearCart();
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
            // FOLLOW-UP DIRECTIVE C (ITEM 1): re-render the WHOLE client context on
            // a client change. Clear the previous client's context/allowance/fees
            // and zone up front so nothing stale can linger while (or if) the
            // refresh is in flight — a stale panel is worse than an empty one.
            // fetchClientAllocation repopulates for the new client and is
            // sequence-guarded against an out-of-order response.
            this.clearAllocationDisplay();
            const $zoneCell = $('#mealsdb-quick-order-summary-zone');
            if ($zoneCell.length) {
                $zoneCell.text(this.translate('—'));
            }
            this.fetchClientAllocation(clientId);
            // While cloning, don't let the change-triggered fetch prefill the
            // one-time delivery override (it must stay empty after a clone).
            this.fetchNextDates(clientId, { skipDeliveryPrefill: this.state.isCloning });

            $(document).trigger('mealsdb_update_summary');
        },

        /**
         * Fetch the client's stored next_order_date / next_delivery_date
         * and the "rule defaults" (order date + configured frequency), and
         * populate the next-cycle panel.
         */
        fetchNextDates(userId, options = {}) {
            const skipDeliveryPrefill = !!options.skipDeliveryPrefill;
            if (!Number.isInteger(userId) || userId <= 0) {
                $('#mealsdb-qo-next-dates').hide();
                return;
            }
            const seq = ++this.state.nextDatesSeq;
            const self = this;
            $.ajax({
                url: this.getAjaxUrl(),
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'mealsdb_qo_get_next_dates',
                    nonce: this.getSecurityNonce(),
                    client_id: userId,
                    order_date: this.$orderDate ? (this.$orderDate.val() || '') : '',
                },
            }).done(function(resp) {
                // Discard a superseded response: only the most recent
                // fetchNextDates may write the panel. This kills the clone race —
                // the change-triggered fetch (pre-clone date) and the clone's
                // fetch (cloned date) are both in flight; last-issued wins,
                // last-to-RESOLVE no longer does (v558 ITEM 2).
                if (seq !== self.state.nextDatesSeq) { return; }
                if (!resp || !resp.success) return;
                const d = resp.data || {};
                self.state.nextDatesDefaults = {
                    order: d.rule_default_order || '',
                    delivery: d.rule_default_delivery || '',
                };
                // Delivery-date override (directive Section A.1): prefill
                // the per-order delivery date with the client's computed
                // next_delivery_date; blank when no cadence (the slip
                // pipeline then falls back to the computed occurrence).
                // SKIPPED when cloning — the one-time override must stay empty
                // after a clone (v550 TEST G). The option is captured in this
                // closure, so it survives async completion (state.isCloning is
                // reset in the clone .always() before this .done() runs).
                if (!skipDeliveryPrefill) {
                    self.state.clientDeliveryDay = d.has_client ? (d.delivery_day || '') : '';
                    $('#mealsdb-qo-delivery-date').val(d.has_client ? (d.next_delivery_date || '') : '');
                    self.refreshDeliveryDateWarning();
                }
                // Prefill the required Order Date so Create Order doesn't silently
                // no-op (the create guard requires it, but nothing populated it).
                // Order date is normally "today", NOT the future cadence date, so
                // default to today's LOCAL date. Only fill when empty — never
                // overwrite a value the operator already typed.
                if (self.$orderDate && self.$orderDate.length && !self.$orderDate.val()) {
                    const now = new Date();
                    const todayYmd = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
                    self.$orderDate.val(todayYmd);
                    // Sync the summary display for the just-prefilled date.
                    // Display-only on purpose: we are already inside
                    // fetchNextDates()'s done handler, so triggering 'change'
                    // here would recursively re-fetch next dates.
                    self.updateSummaryDate();
                }
                const $panel = $('#mealsdb-qo-next-dates');
                if (!d.has_client) { $panel.hide(); return; }
                $panel.show();
                // Prefill with the computed "after this order" date
                // (rule_default_*) so the operator sees what the dates will
                // become; fall back to the stored value only if no compute.
                $('#mealsdb-qo-next-order-date').val(d.rule_default_order || d.next_order_date || '');
                $('#mealsdb-qo-next-delivery-date').val(d.rule_default_delivery || d.next_delivery_date || '');
                $('#mealsdb-qo-next-order-default').text(
                    d.rule_default_order ? 'Normally: ' + d.rule_default_order : ''
                );
                $('#mealsdb-qo-next-delivery-default').text(
                    d.rule_default_delivery ? 'Normally: ' + d.rule_default_delivery : ''
                );
            });
        },

        /**
         * Client-side mirror of MealsDB_Delivery_Date_Advisor::warning_for()
         * (soft-warn, don't block): past date or off-day gets an advisory
         * string, '' when the date looks fine. expectedDay is the client's
         * canonical delivery_day (lowercase) or '' → Mon–Fri fallback.
         */
        deliveryDateWarning(ymd, expectedDay) {
            const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ymd || '');
            if (!m) {
                // FOLLOW-UP DIRECTIVE C (ITEM 2): an EMPTY delivery date is the case
                // that matters most — an order with no delivery date gets no slip
                // and no allocation, so it silently does not happen. Warn inline
                // like the past/weekday cases (non-blocking). A malformed-but-
                // non-empty value stays silent (the field is mid-edit).
                return (ymd || '').trim() === ''
                    ? 'Heads up: no delivery date set — this order gets no slip and no allocation. Saving anyway is allowed.'
                    : '';
            }
            const dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            const weekday = dayNames[new Date(Date.UTC(+m[1], +m[2] - 1, +m[3])).getUTCDay()];

            const now = new Date();
            const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;

            const parts = [];
            if (ymd < today) {
                parts.push(`${ymd} is in the past.`);
            }
            const expected = (expectedDay || '').toLowerCase();
            if (expected) {
                if (weekday.toLowerCase() !== expected) {
                    const expectedLabel = expected.charAt(0).toUpperCase() + expected.slice(1);
                    parts.push(`${ymd} is a ${weekday} — this client's deliveries run on ${expectedLabel}.`);
                }
            } else if (weekday === 'Saturday' || weekday === 'Sunday') {
                parts.push(`${ymd} is a ${weekday} — no delivery runs that day.`);
            }
            return parts.length ? `Heads up: ${parts.join(' ')} Saving anyway is allowed.` : '';
        },

        // Directive 1 (ITEM 5): build the empty/past date warnings shown on
        // Create. Empty OR in-the-past, for either field, names the field and
        // why. Returns [] when both dates are set and today-or-future (→ no
        // confirm, Create proceeds straight through). Date-only comparison in
        // the site/browser local zone; a delivery date equal to today is fine.
        dateSanityWarnings(orderDate, deliveryDate) {
            const warnings = [];
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            const parseYmd = (value) => {
                if (!value) {
                    return null;
                }
                const parts = String(value).split('-');
                if (parts.length !== 3) {
                    return null;
                }
                const d = new Date(
                    parseInt(parts[0], 10),
                    parseInt(parts[1], 10) - 1,
                    parseInt(parts[2], 10)
                );
                d.setHours(0, 0, 0, 0);
                return Number.isNaN(d.getTime()) ? null : d;
            };

            if (!orderDate) {
                warnings.push(this.translate('Order Date is not set.'));
            } else {
                const od = parseYmd(orderDate);
                if (od && od < today) {
                    warnings.push(this.translate('Order Date is in the past (%s).').replace('%s', orderDate));
                }
            }

            if (!deliveryDate) {
                warnings.push(this.translate('Delivery Date is not set — this order will use the computed delivery date.'));
            } else {
                const dd = parseYmd(deliveryDate);
                if (dd && dd < today) {
                    warnings.push(this.translate('Delivery Date is in the past (%s).').replace('%s', deliveryDate));
                }
            }

            return warnings;
        },

        refreshDeliveryDateWarning() {
            const $warning = $('#mealsdb-qo-delivery-date-warning');
            if (!$warning.length) {
                return;
            }
            const warning = this.deliveryDateWarning(
                $('#mealsdb-qo-delivery-date').val() || '',
                (this.state && this.state.clientDeliveryDay) || ''
            );
            $warning.text(warning).toggle(warning !== '');
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

        // CURRENTLY UNUSED by the summary (ITEM 1): the summary shows a pre-tax
        // subtotal, not an estimated total. Kept — with state.taxRate /
        // state.taxableClientTypes and the mealsdb_quick_order_tax_settings filter —
        // pending verification that the meals `taxable` column agrees with each
        // product's WooCommerce tax class; a per-product estimate is the likely
        // follow-up. Do not mistake this for live math.
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
            let taxableBase = 0;
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
                const lineValue = quantity * price;
                subtotal += lineValue;
                // Directive 2 (ITEM 4): only TAXABLE lines feed the tax base. This
                // is the fix for the old flat-rate estimate that taxed every line
                // regardless of taxability (staging #28530). The per-product
                // `taxable` flag now travels in the payload, so the estimate can
                // respect it. Still display-only — WooCommerce remains the
                // authority for the stored order's tax.
                if (Number(entry.product.taxable) === 1) {
                    taxableBase += lineValue;
                }
            });

            const precision = this.getCurrencyPrecision();
            const factor = Math.pow(10, precision);
            const round = (n) => Math.round((n + Number.EPSILON) * factor) / factor;

            const subtotalDisplay = round(subtotal);
            const taxRate = govInvoiced ? 0 : this.getApplicableTaxRate();
            const taxDisplay = round(taxableBase * taxRate);
            const afterTaxDisplay = round(subtotalDisplay + taxDisplay);

            if (this.$qoItemsCount && this.$qoItemsCount.length) {
                this.$qoItemsCount.text(totalItems);
            }

            if (this.$qoTotal && this.$qoTotal.length) {
                this.$qoTotal.text(govInvoiced ? '' : this.formatPrice(subtotalDisplay));
                this.$qoTotal.toggle(!govInvoiced);
            }

            const $tax = $('#mealsdb-quick-order-summary-tax');
            if ($tax.length) {
                $tax.text(govInvoiced ? '' : this.formatPrice(taxDisplay));
            }
            const $afterTax = $('#mealsdb-quick-order-summary-aftertax');
            if ($afterTax.length) {
                $afterTax.html(govInvoiced ? '' : `<strong>${this.escapeHtml(this.formatPrice(afterTaxDisplay))}</strong>`);
            }

            // Directive 2 (ITEM 4) + government suppression: hide the whole money
            // block for SDNB/Veteran so no price is reintroduced and no label is
            // left stranded beside an empty figure.
            $('#mealsdb-qo-subtotal-row').toggle(!govInvoiced);
            $('#mealsdb-qo-tax-row').toggle(!govInvoiced);
            $('#mealsdb-qo-aftertax-row').toggle(!govInvoiced);
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

            // FOLLOW-UP DIRECTIVE C (ITEM 1): sequence-guard so an older client's
            // response (arriving after a newer selection) can never overwrite the
            // panel with stale data — the bug that left the previous client's
            // context/allowance/zone on screen after a switch.
            const seq = ++this.state.allocationSeq;

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
                if (seq !== this.state.allocationSeq) {
                    return; // a newer client was selected — ignore this stale response
                }
                if (response && response.success) {
                    this.state.allocation = response.allocation || null;
                    this.state.clientFees = response.fees || null;
                    this.state.nextDelivery = response.next_delivery || null;
                    this.state.straddlesMonth = response.straddles_month || false;
                    // Directive 2 (ITEM 5): show the operational delivery ZONE
                    // (delivery_area_name = "Zone 1..6", which decides the delivery
                    // day), NOT the M/S service centre (delivery_area_zone, a
                    // billing construct). Explicit "No zone" when blank — a zoneless
                    // client gets no delivery date and no packing slip, and that
                    // should be visible while the order is taken.
                    const $zone = $('#mealsdb-quick-order-summary-zone');
                    if ($zone.length) {
                        const zoneVal = response.delivery_area_name;
                        $zone.text((zoneVal === null || zoneVal === undefined || zoneVal === '')
                            ? this.translate('No zone')
                            : String(zoneVal));
                    }
                    this.renderClientContext(response.client_context || null);
                    this.renderAllocationPanel();

                    if (['SDNB', 'Veteran'].includes(response.client_type)) {
                        this.hideProductPrices();
                    } else {
                        this.showProductPrices();
                    }
                } else {
                    // A non-success response must not leave the previous client's
                    // panel showing — clear rather than go stale (Directive C ITEM 1).
                    this.clearAllocationDisplay();
                }
            }).fail(() => {
                if (seq === this.state.allocationSeq) {
                    this.clearAllocationDisplay();
                }
            });
        },

        // Directive 2 (ITEM 7): render the client-context panel — the reference
        // fields the current POS shows (notes, dietary, contacts, plus payment /
        // service-term / frequency), so the order-taker doesn't need both systems
        // open. All values are escaped before insertion. do_not_call is honoured
        // visually: the client's OWN numbers are flagged rather than shown as
        // ordinary contacts. Empty client → explicit empty state, no layout break.
        renderClientContext(context) {
            const $panel = $('#mealsdb-qo-client-context');
            if (!$panel.length) {
                return;
            }
            if (!context || typeof context !== 'object') {
                $panel.hide().empty();
                return;
            }

            const esc = this.escapeHtml.bind(this);
            const t = this.translate.bind(this);
            const rows = [];
            const addRow = (label, value) => {
                let v = (value === null || typeof value === 'undefined') ? '' : String(value).trim();
                if (v === '' || v === '0000-00-00') {
                    return;
                }
                rows.push(
                    `<div class="mealsdb-qo-context__row">` +
                    `<span class="mealsdb-qo-context__label">${esc(label)}</span> ` +
                    `<span class="mealsdb-qo-context__value">${esc(v)}</span>` +
                    `</div>`
                );
            };

            // Contacts. do_not_call flags the client's OWN numbers only.
            const dnc = !!context.do_not_call;
            const ownLabel = (base) => (dnc ? `${base} — ${t('DO NOT CALL')}` : base);
            addRow(ownLabel(t('Phone 1')), context.phone_1);
            addRow(ownLabel(t('Phone 2')), context.phone_2);
            addRow(t('Alt contact 1'), context.alt_phone_1);
            addRow(t('Alt contact 2'), context.alt_phone_2);

            // The operational must-sees.
            addRow(t('Dietary needs'), context.dietary);
            addRow(t('Notes'), context.notes);
            addRow(t('Notes to provider'), context.notes_to_provider);

            // POS reference fields.
            addRow(t('Payment method'), context.payment_method);
            addRow(t('Province'), context.province);
            if (context.allowance_mains !== null && typeof context.allowance_mains !== 'undefined') {
                addRow(t('Mains allowance'), context.allowance_mains);
            }
            if (context.allowance_sides !== null && typeof context.allowance_sides !== 'undefined') {
                addRow(t('Sides allowance'), context.allowance_sides);
            }
            addRow(t('Service start'), context.service_commence_date);
            addRow(t('Service term date'), context.service_term_date);
            addRow(t('Last delivery'), context.last_delivery_date);
            if (context.ordering_frequency) {
                addRow(t('Order frequency (days)'), context.ordering_frequency);
            }
            if (context.delivery_frequency) {
                addRow(t('Delivery frequency (days)'), context.delivery_frequency);
            }

            const dncBanner = dnc
                ? `<div class="mealsdb-qo-context__dnc">${esc(t("DO NOT CALL this client's own number"))}</div>`
                : '';
            const body = rows.length
                ? rows.join('')
                : `<div class="mealsdb-qo-context__empty">${esc(t('No client details on file.'))}</div>`;

            $panel
                .html(`<h3 class="mealsdb-qo-context__title">${esc(t('Client details'))}</h3>${dncBanner}${body}`)
                .show();
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
            // Directive 2 (ITEM 7): the context panel is client-scoped — clear it
            // alongside the allocation when there is no client.
            const $context = $('#mealsdb-qo-client-context');
            if ($context.length) {
                $context.empty().hide();
            }
            this.state.allocation = null;
            this.state.clientFees = null;
        },

        hideProductPrices() {
            $('.mealsdb-quick-order__summary-total').hide();
        },

        showProductPrices() {
            $('.mealsdb-quick-order__summary-total').show();
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
                                .attr('data-client-type', client.client_type || '')
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
            // jQuery camel-cases data-client-type -> clientType. Carry the real
            // type through so isGovernmentInvoiced() can suppress prices for
            // SDNB/Veteran clients. Empty stays '' -> fails OPEN (prices show).
            const type = $(this).data('clientType') || '';

            if (!id) {
                return;
            }

            search.val(name);
            hidden.val(id);
            hidden.data('clientType', type);

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
