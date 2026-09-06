jQuery(document).ready(function($) {

    const ajaxEndpoint = (typeof mealsdb !== 'undefined' && mealsdb.ajaxUrl)
        ? mealsdb.ajaxUrl
        : (typeof ajaxurl !== 'undefined' ? ajaxurl : '');

    const clientMessages = (typeof window.mealsdbClientActions !== 'undefined')
        ? window.mealsdbClientActions
        : {};

    const genericErrorMessage = clientMessages.genericError || 'An unexpected error occurred. Please try again.';
    const toggleErrorMessage = clientMessages.toggleError || genericErrorMessage;

    const setBusyState = ($element, busy) => {
        if (!$element || !$element.length) {
            return;
        }

        $element.prop('disabled', !!busy);
        $element.toggleClass('is-busy', !!busy);
    };

    // -----------------------------
    // 👥 View Clients table actions
    // -----------------------------
    const $clientsTable = $('.mealsdb-client-table');
    if ($clientsTable.length) {
        $(document).on('click', '.mealsdb-client-toggle-status', function () {
            const $button = $(this);
            const clientIdRaw = $button.data('clientId');
            const clientId = parseInt(clientIdRaw, 10);

            if (!Number.isInteger(clientId) || clientId <= 0 || !ajaxEndpoint || typeof mealsdb === 'undefined') {
                return;
            }

            const isActive = parseInt($button.data('active'), 10) === 1;
            const action = isActive ? 'mealsdb_deactivate_client' : 'mealsdb_activate_client';

            // v561 ITEM 4a: deactivating a client with recent non-cancelled
            // orders returns needs_confirm; we ask the operator and retry with
            // confirm=1. `confirmed` carries that flag on the retry.
            function doToggle(confirmed) {
                setBusyState($button, true);
                const data = { action: action, nonce: mealsdb.nonce, client_id: clientId };
                if (confirmed) { data.confirm = '1'; }
                $.post(ajaxEndpoint, data, function (response) {
                    if (response && response.success) {
                        const newStatusRaw = response.data && response.data.active;
                        const newStatus = parseInt(newStatusRaw, 10) === 1 ? 1 : 0;
                        const label = newStatus === 1
                            ? ($button.data('labelDeactivate') || clientMessages.deactivateLabel || $button.text())
                            : ($button.data('labelActivate') || clientMessages.activateLabel || $button.text());

                        $button.data('active', newStatus);
                        if (label) {
                            $button.text(label);
                        }

                        $button.closest('tr').toggleClass('mealsdb-client-row-inactive', newStatus === 0);
                        return;
                    }
                    const d = response && response.data;
                    if (d && d.needs_confirm && !confirmed) {
                        setBusyState($button, false);
                        window.MealsDBConfirm.confirm({
                            title: 'Please confirm',
                            message: d.message || ''
                        }).then(function (ok) {
                            if (ok) {
                                doToggle(true); // operator acknowledged → retry
                            }
                        });
                        return;
                    }
                    MealsDBNotice('error', (d && d.message) ? d.message : toggleErrorMessage);
                }).fail(function () {
                    MealsDBNotice('error', toggleErrorMessage);
                }).always(function () {
                    setBusyState($button, false);
                });
            }

            doToggle(false);
        });
    }

    // -----------------------------
    // 📅 Date Picker
    // -----------------------------
    if ($.fn.datepicker) {
        const currentYear = new Date().getFullYear();
        const maxYear = currentYear + 5;

        $('.mealsdb-datepicker').datepicker({
            dateFormat: 'yy-mm-dd',
            changeMonth: true,
            changeYear: true,
            yearRange: `1900:${maxYear}`
        });
    }

    // -----------------------------
    // 📞 Phone Formatter
    // -----------------------------
    $('.phone-mask').on('input', function () {
        let val = $(this).val().replace(/\D/g, '').substring(0, 10);
        let formatted = val;
        if (val.length > 6)
            formatted = `(${val.substring(0,3)})-${val.substring(3,6)}-${val.substring(6,10)}`;
        else if (val.length > 3)
            formatted = `(${val.substring(0,3)})-${val.substring(3)}`;
        else if (val.length > 0)
            formatted = `(${val}`;
        $(this).val(formatted);
    });

    // -----------------------------
    // 🇨🇦 Postal Code Formatter
    // -----------------------------
    $('.postal-mask').on('input', function () {
        const val = $(this).val().toUpperCase().replace(/[^A-Z0-9]/g, '').substring(0, 6);
        $(this).val(val);
    });

    // -----------------------------
    // 🧾 Client Form Interactions
    // -----------------------------
    const $clientForm = $('#mealsdb-client-form');
    if ($clientForm.length) {
        const $customerTypeSelect = $('#client_type');
        const normalizeType = (value) => (value || '').toString().trim().toLowerCase();
        // FOLLOW-UP DIRECTIVE B (ITEM 1): required-field validation is CREATE-only.
        // On the edit form no field is `required` (the browser must not block a
        // correction to a long-standing record), so the type-aware requiring
        // below is suppressed entirely in edit mode.
        const isEditMode = ($clientForm.data('formMode') || '').toString() === 'edit';

        const toggleInteractiveState = ($container, shouldEnable) => {
            $container.find('input, select, textarea, button').each(function () {
                const $field = $(this);
                if ($field.is('[data-keep-enabled]')) {
                    return;
                }

                if (shouldEnable) {
                    $field.prop('disabled', false);
                } else {
                    $field.prop('disabled', true);
                }
            });
        };

        const toggleClientTypeSections = (typeValue) => {
            const normalized = normalizeType(typeValue);
            $clientForm.toggleClass('mealsdb-client-type-selected', normalized.length > 0);

            $clientForm.find('[data-client-type]').each(function () {
                const $row = $(this);
                const allowedRaw = ($row.data('clientType') || '').toString().toLowerCase();
                if (!allowedRaw) {
                    toggleInteractiveState($row, true);
                    $row.show();
                    return;
                }

                const allowedTypes = allowedRaw.split(',').map((item) => item.trim()).filter(Boolean);
                const shouldShow = allowedTypes.length === 0 || allowedTypes.includes('all') || allowedTypes.includes(normalized);
                toggleInteractiveState($row, shouldShow);

                if (shouldShow) {
                    $row.show();
                } else {
                    $row.hide();
                }
            });

            $clientForm.find('[data-required-for]').each(function () {
                const $row = $(this);
                const allowedRaw = ($row.data('requiredFor') || '').toString().toLowerCase();
                const allowedTypes = allowedRaw.split(',').map((item) => item.trim()).filter(Boolean);
                // Edit mode → never required (create-only validation).
                const shouldRequire = !isEditMode && allowedTypes.includes(normalized);

                $row.toggleClass('mealsdb-required-disabled', !shouldRequire);
                $row.find('[data-base-required]').each(function () {
                    const $input = $(this);
                    if (shouldRequire) {
                        $input.prop('required', true).attr('aria-required', 'true');
                    } else {
                        $input.prop('required', false).removeAttr('aria-required');
                    }
                });
            });

            // Belt-and-suspenders: in edit mode strip `required` from every
            // baseline-required input too (the PHP already omits the attribute,
            // but this guarantees no field can block the save if markup changes).
            if (isEditMode) {
                $clientForm.find('[data-base-required]').prop('required', false).removeAttr('aria-required');
            }
        };

        const syncAltContactName = () => {
            const first = ($('#alt_contact_first_name').val() || '').trim();
            const last = ($('#alt_contact_last_name').val() || '').trim();
            const combined = [first, last].filter(Boolean).join(' ');
            $('#alt_contact_name').val(combined);
        };

        $('#delivery-address-toggle').on('change', function () {
            const show = $(this).is(':checked');
            const $container = $('#delivery-address-fields');
            if (show) {
                $container.slideDown();
            } else {
                $container.slideUp();
            }
        });

        $('#alternate-contact-toggle').on('change', function () {
            const show = $(this).is(':checked');
            const $container = $('#alternate-contact-fields');
            if (show) {
                $container.slideDown();
            } else {
                $container.slideUp();
                $container.find('input[type="text"], input[type="email"]').val('');
                $('#alt_contact_name').val('');
            }
        });

        $('#alt_contact_first_name, #alt_contact_last_name').on('input', syncAltContactName);

        $customerTypeSelect.on('change', function () {
            toggleClientTypeSections($(this).val());
        });

        syncAltContactName();
        toggleClientTypeSections($customerTypeSelect.val());
    }
// 💾 Save to Draft
    // -----------------------------
    $('#mealsdb-save-draft').on('click', function () {
        const $form = $('#mealsdb-client-form');
        const formData = $form.serialize();
        $.post(ajaxurl, {
            action: 'mealsdb_save_draft',
            nonce: mealsdb.nonce,
            form_data: formData
        }, function (res) {
            if (res.success) {
                const draftIdRaw = res.data && res.data.draft_id;
                const draftId = draftIdRaw !== undefined ? parseInt(draftIdRaw, 10) : NaN;
                if (!Number.isNaN(draftId) && draftId > 0) {
                    let $draftInput = $form.find('input[name="draft_id"]');
                    if ($draftInput.length === 0) {
                        $draftInput = $('<input>', { type: 'hidden', name: 'draft_id' }).appendTo($form);
                    }
                    $draftInput.val(draftId);
                }

                const message = res.data && res.data.message ? res.data.message : 'Draft saved!';
                MealsDBNotice('success', message);
            } else {
                const error = res.data && res.data.message ? res.data.message : 'Failed to save draft.';
                MealsDBNotice('error', error);
            }
        });
    });

    // -----------------------------
    // 🔁 Sync One Field
    // -----------------------------
    $('.sync-field').on('click', function () {
        const $row = $(this).closest('tr');
        const selected = $row.find('input[type=radio]:checked').val();
        const field = $row.data('field');
        const woo_id = $row.data('woo');
        const clientIdRaw = $row.data('client');
        const client_id = Number.isInteger(clientIdRaw) ? clientIdRaw : parseInt(clientIdRaw, 10);
        const source = $row.find('.mealsdb-a');
        const target = $row.find('.mealsdb-b');
        const direction = selected === 'woocommerce' ? 'woocommerce' : 'meals_db';
        const value = (selected === 'meals_db')
            ? (source.data('value') ?? source.text())
            : (target.data('value') ?? target.text());

        $.post(ajaxurl, {
            action: 'mealsdb_sync_field',
            nonce: mealsdb.nonce,
            woo_user_id: woo_id,
            field: field,
            client_id: Number.isNaN(client_id) ? 0 : client_id,
            direction: direction,
            value: value
        }, function (res) {
            if (res.success) {
                MealsDBNotice('success', 'Synced: ' + field);
                $row.fadeOut();
            } else {
                MealsDBNotice('error', 'Sync failed.');
            }
        });
    });

    // -----------------------------
    // 🔗 Link Meals DB Client to WordPress User
    // -----------------------------
    $(document).on('click', '.mealsdb-link-user', function () {
        const $button = $(this);
        const clientIdRaw = $button.data('clientId');
        const wpUserIdRaw = $button.data('wpUserId');
        const wpUserNameRaw = $button.data('wpUserName');
        const decodeHtml = (value) => {
            if (typeof value !== 'string') {
                return '';
            }
            const textarea = document.createElement('textarea');
            textarea.innerHTML = value;
            return textarea.value;
        };
        const clientId = parseInt(clientIdRaw, 10);
        const wpUserId = parseInt(wpUserIdRaw, 10);
        const decodedName = decodeHtml(wpUserNameRaw);
        const wpUserName = decodedName !== ''
            ? decodedName.trim()
            : null;

        if (!Number.isInteger(clientId) || clientId <= 0 || !Number.isInteger(wpUserId) || wpUserId <= 0) {
            MealsDBNotice('error', 'Invalid link request.');
            return;
        }

        const confirmName = wpUserName ?? 'this WordPress user';
        const confirmMessage = `Link this client to WordPress user ${confirmName}? This cannot be undone.`;

        window.MealsDBConfirm.confirm({
            title: 'Link client',
            message: confirmMessage,
            confirmLabel: 'Link',
            destructive: true
        }).then(function (ok) {
            if (!ok) { return; }

            $button.prop('disabled', true);

            $.post(ajaxurl, {
                action: 'mealsdb_link_client',
                nonce: mealsdb.nonce,
                client_id: clientId,
                wp_user_id: wpUserId
            }, function (res) {
                if (res && res.success) {
                    window.location.reload();
                    return;
                } else {
                    const errorMessage = res && res.data && res.data.message ? res.data.message : 'Failed to link client.';
                    MealsDBNotice('error', errorMessage);
                }
            }).fail(function () {
                MealsDBNotice('error', 'Failed to link client.');
            }).always(function () {
                $button.prop('disabled', false);
            });
        });
    });

    // -----------------------------
    // 🔁 Sync All Selected Fields
    // -----------------------------
    $('#mealsdb-sync-all').on('click', function () {
        // Double-submit guard: a rapid second click while the first
        // batch of POSTs is still in flight would issue every sync_field
        // request twice. Each is rate-limited server-side, but the
        // duplicate noise blows the per-user quota for no reason and
        // obscures real failures in the audit log. Disable the button
        // until every in-flight request resolves.
        const $btn = $(this);
        if ($btn.prop('disabled')) {
            return;
        }
        $btn.prop('disabled', true);

        const pending = [];
        $('.mealsdb-mismatch-row').each(function () {
            const $row = $(this);
            const selected = $row.find('input[type=radio]:checked').val();
            if (!selected) return;

            const field = $row.data('field');
            const woo_id = $row.data('woo');
            const clientIdRaw = $row.data('client');
            const client_id = Number.isInteger(clientIdRaw) ? clientIdRaw : parseInt(clientIdRaw, 10);
            const source = $row.find('.mealsdb-a');
            const target = $row.find('.mealsdb-b');
            const direction = selected === 'woocommerce' ? 'woocommerce' : 'meals_db';
            const value = (selected === 'meals_db')
                ? (source.data('value') ?? source.text())
                : (target.data('value') ?? target.text());

            pending.push($.post(ajaxurl, {
                action: 'mealsdb_sync_field',
                nonce: mealsdb.nonce,
                woo_user_id: woo_id,
                field: field,
                client_id: Number.isNaN(client_id) ? 0 : client_id,
                direction: direction,
                value: value
            }, function (res) {
                if (res.success) {
                    $row.fadeOut();
                }
            }));
        });

        if (pending.length === 0) {
            $btn.prop('disabled', false);
            return;
        }

        $.when.apply($, pending).always(function () {
            $btn.prop('disabled', false);
        });
    });

    // -----------------------------
    // 🚫 Toggle Ignore
    // -----------------------------
    $('.mealsdb-ignore-toggle').on('change', function () {
        const $row = $(this).closest('tr');
        const field = $row.data('field');
        const source = $row.find('.mealsdb-a').data('value') ?? $row.find('.mealsdb-a').text();
        const target = $row.find('.mealsdb-b').data('value') ?? $row.find('.mealsdb-b').text();
        const ignored = $(this).is(':checked');

        $.post(ajaxurl, {
            action: 'mealsdb_toggle_ignore',
            nonce: mealsdb.nonce,
            field: field,
            source: source,
            target: target,
            ignored: ignored
        }, function (res) {
            if (res.success) {
                // Optional: toast or fade
            } else {
                MealsDBNotice('error', 'Ignore action failed.');
            }
        });
    });

    // -----------------------------
    // 🔍 Show Only Mismatches
    // -----------------------------
    $('#mealsdb-show-only-diffs').on('change', function () {
        const showOnly = $(this).is(':checked');
        $('.mealsdb-mismatch-row').each(function () {
            const $row = $(this);
            const aEl = $row.find('.mealsdb-a');
            const bEl = $row.find('.mealsdb-b');
            const a = (aEl.data('value') ?? aEl.text()).toString().trim();
            const b = (bEl.data('value') ?? bEl.text()).toString().trim();
            if (showOnly && a === b) {
                $row.hide();
            } else {
                $row.show();
            }
        });
    });

    // -----------------------------
    // ⬆️ Updates tab (Git + DB)
    // -----------------------------
    const $updatesScreen = $('#mealsdb-updates');
    if ($updatesScreen.length) {
        const $status = $('#mealsdb-updates-status');
        const $log = $('#mealsdb-updates-log');
        const $checkButton = $('#mealsdb-check-updates');
        const $pullButton = $('#mealsdb-run-update');
        const $dbButton = $('#mealsdb-update-database');
        const $fetchProductsButton = $('#mealsdb-fetch-products');
        let hasCheckedUpdates = false;
        let lastCheckData = null;

        const coerceBoolean = (value) => {
            if (typeof value === 'boolean') {
                return value;
            }

            if (typeof value === 'string') {
                const normalized = value.trim().toLowerCase();
                if (['', '0', 'false', 'no', 'off'].includes(normalized)) {
                    return false;
                }
                if (['1', 'true', 'yes', 'on'].includes(normalized)) {
                    return true;
                }
            }

            return Boolean(value);
        };

        const normalizeCheckData = (data) => {
            const normalized = { ...data };

            normalized.has_updates = coerceBoolean(normalized.has_updates);
            normalized.can_auto_update = coerceBoolean(normalized.can_auto_update);
            normalized.is_dirty = coerceBoolean(normalized.is_dirty);

            return normalized;
        };

        const refreshPullButtonState = () => {
            if (!$pullButton.length) {
                return;
            }

            if (!hasCheckedUpdates) {
                $pullButton.prop('disabled', true);
                return;
            }

            const data = lastCheckData || {};
            const canAutoUpdate = data.can_auto_update;
            const hasUpdates = data.has_updates;
            const isDirty = data.is_dirty;

            if (!canAutoUpdate) {
                $pullButton.prop('disabled', true);
                return;
            }

            if (hasUpdates && !isDirty) {
                $pullButton.prop('disabled', false);
            } else {
                $pullButton.prop('disabled', true);
            }
        };

        refreshPullButtonState();

        // Delegates to the shared MealsDBNotice renderer (directive GUI-NOTICES),
        // pinning the updates page's own $status element as the target so this
        // page looks unchanged. Falls back to the old inline rendering if the
        // shared helper somehow isn't loaded.
        const showNotice = (level, message) => {
            if (typeof window.MealsDBNotice === 'function') {
                window.MealsDBNotice(level, message, { $target: $status });
                return;
            }
            const classes = ['notice-info', 'notice-success', 'notice-error', 'notice-warning'];
            $status.removeClass(classes.join(' '));
            let className = 'notice-info';
            switch (level) {
                case 'success':
                    className = 'notice-success';
                    break;
                case 'error':
                    className = 'notice-error';
                    break;
                case 'warning':
                    className = 'notice-warning';
                    break;
                default:
                    className = 'notice-info';
            }
            $status.addClass(className).text(message).show();
        };

        const setLog = (content) => {
            if (content && content.length) {
                $log.text(content).show();
            } else {
                $log.hide().text('');
            }
        };

        const toggleLoading = ($button, isLoading) => {
            if (!$button || !$button.length) {
                return;
            }

            if (isLoading) {
                if (!$button.data('original-text')) {
                    $button.data('original-text', $button.text());
                }
                $button.data('mealsdb-disabled-before-loading', $button.prop('disabled'));
                $button.prop('disabled', true).addClass('mealsdb-is-loading');
            } else {
                const original = $button.data('original-text');
                if (original) {
                    $button.text(original);
                }
                const wasDisabled = $button.data('mealsdb-disabled-before-loading');
                $button.prop('disabled', typeof wasDisabled === 'boolean' ? wasDisabled : false);
                $button.removeData('mealsdb-disabled-before-loading');
                $button.removeClass('mealsdb-is-loading');
            }
        };

        const handleErrorResponse = (res) => {
            const errorMessage = res && res.data && res.data.message
                ? res.data.message
                : 'An unexpected error occurred.';
            showNotice('error', errorMessage);
            if (res && res.data && res.data.stderr) {
                setLog(res.data.stderr);
            }
        };

        const runPull = () => {
            toggleLoading($pullButton, true);
            showNotice('info', 'Pulling latest changes...');
            setLog('');

            $.post(ajaxurl, {
                action: 'mealsdb_run_update',
                nonce: mealsdb.nonce
            }).done((res) => {
                if (res.success) {
                    const data = normalizeCheckData(res.data || {});
                    showNotice('success', data.message || 'Plugin updated successfully.');
                    setLog(data.output || data.log || '');
                    hasCheckedUpdates = false;
                    lastCheckData = null;
                    refreshPullButtonState();
                } else {
                    handleErrorResponse(res);
                    refreshPullButtonState();
                }
            }).fail(() => {
                showNotice('error', 'Failed to communicate with the server.');
                refreshPullButtonState();
            }).always(() => {
                toggleLoading($pullButton, false);
            });
        };

        $checkButton.on('click', function (event) {
            event.preventDefault();
            hasCheckedUpdates = false;
            lastCheckData = null;
            refreshPullButtonState();
            toggleLoading($checkButton, true);
            showNotice('info', 'Checking for updates...');
            setLog('');

            $.post(ajaxurl, {
                action: 'mealsdb_check_updates',
                nonce: mealsdb.nonce
            }).done((res) => {
                if (res.success) {
                    const data = normalizeCheckData(res.data || {});
                    showNotice('success', data.message || 'Check complete.');

                    const summaryParts = [];
                    if (data.branch) {
                        summaryParts.push(`Branch: ${data.branch}`);
                    }
                    if (data.current_commit) {
                        summaryParts.push(`Current commit: ${data.current_commit}`);
                    }
                    if (data.remote_commit) {
                        summaryParts.push(`Remote commit: ${data.remote_commit}`);
                    }
                    if (data.current_version) {
                        summaryParts.push(`Installed version: ${data.current_version}`);
                    }
                    if (data.latest_version) {
                        summaryParts.push(`Latest version: ${data.latest_version}`);
                    }
                    if (data.release_url) {
                        summaryParts.push(`Latest release: ${data.release_url}`);
                    }
                    if (data.repository_url) {
                        summaryParts.push(`Repository: ${data.repository_url}`);
                    }

                    setLog(summaryParts.join('\n'));

                    lastCheckData = data;
                    hasCheckedUpdates = true;

                    if (data.has_updates && data.can_auto_update && data.is_dirty) {
                        showNotice('warning', 'Updates are available, but local changes must be committed or stashed first.');
                    } else if (data.has_updates && !data.can_auto_update) {
                        showNotice('warning', 'Automatic updates are not available for this installation.');
                    }

                    refreshPullButtonState();
                } else {
                    handleErrorResponse(res);
                    hasCheckedUpdates = false;
                    lastCheckData = null;
                    refreshPullButtonState();
                }
            }).fail(() => {
                showNotice('error', 'Failed to communicate with the server.');
                hasCheckedUpdates = false;
                lastCheckData = null;
                refreshPullButtonState();
            }).always(() => {
                toggleLoading($checkButton, false);
            });
        });

        $pullButton.on('click', function (event) {
            event.preventDefault();
            if ($pullButton.prop('disabled')) {
                return;
            }
            runPull();
        });

        $dbButton.on('click', function (event) {
            event.preventDefault();
            toggleLoading($dbButton, true);
            showNotice('info', 'Running database maintenance...');
            setLog('');

            $.post(ajaxurl, {
                action: 'mealsdb_update_database',
                nonce: mealsdb.nonce
            }).done((res) => {
                if (res.success) {
                    const data = res.data || {};
                    showNotice('success', data.message || 'Database updated.');
                } else {
                    handleErrorResponse(res);
                }
            }).fail(() => {
                showNotice('error', 'Failed to communicate with the server.');
            }).always(() => {
                toggleLoading($dbButton, false);
            });
        });

        $fetchProductsButton.on('click', function (event) {
            event.preventDefault();

            if (!$fetchProductsButton.length) {
                return;
            }

            toggleLoading($fetchProductsButton, true);
            showNotice('info', 'Fetching WooCommerce products...');
            setLog('');

            $.post(ajaxurl, {
                action: 'mealsdb_fetch_products',
                nonce: mealsdb.nonce
            }).done((res) => {
                if (res.success) {
                    const data = res.data || {};
                    const summaryParts = [];

                    if (typeof data.created !== 'undefined') {
                        summaryParts.push(`Created: ${data.created}`);
                    }
                    if (typeof data.missing_count !== 'undefined') {
                        summaryParts.push(`Missing products: ${data.missing_count}`);
                    }
                    if (typeof data.woocommerce_count !== 'undefined') {
                        summaryParts.push(`WooCommerce products: ${data.woocommerce_count}`);
                    }
                    if (typeof data.existing_count !== 'undefined') {
                        summaryParts.push(`Existing plugin products: ${data.existing_count}`);
                    }

                    setLog(summaryParts.join('\n'));
                    showNotice('success', data.message || 'Products fetched.');
                } else {
                    handleErrorResponse(res);
                }
            }).fail(() => {
                showNotice('error', 'Failed to communicate with the server.');
            }).always(() => {
                toggleLoading($fetchProductsButton, false);
            });
        });
    }
});
