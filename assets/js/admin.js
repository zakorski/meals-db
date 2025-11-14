jQuery(document).ready(function($) {

    const ajaxEndpoint = (typeof mealsdb !== 'undefined' && mealsdb.ajaxUrl)
        ? mealsdb.ajaxUrl
        : (typeof ajaxurl !== 'undefined' ? ajaxurl : '');

    const clientMessages = (typeof window.mealsdbClientActions !== 'undefined')
        ? window.mealsdbClientActions
        : {};

    const genericErrorMessage = clientMessages.genericError || 'An unexpected error occurred. Please try again.';
    const toggleErrorMessage = clientMessages.toggleError || genericErrorMessage;
    const deleteErrorMessage = clientMessages.deleteError || genericErrorMessage;
    const deleteSuccessMessage = clientMessages.deleteSuccess || '';
    const emptyStateMessage = clientMessages.emptyState || '';

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
        const $tableBody = $clientsTable.find('tbody');
        const emptyMessage = (() => {
            const raw = $tableBody.data('emptyMessage');
            if (typeof raw === 'string' && raw.trim().length) {
                return raw;
            }
            return emptyStateMessage;
        })();
        const columnCount = $clientsTable.find('thead th').length || 1;
        const $deleteModal = $('#mealsdb-delete-client-modal');
        const $deleteModalConfirm = $('#mealsdb-delete-client-confirm');
        const $deleteModalCancel = $('#mealsdb-delete-client-cancel');
        const $deleteModalBackdrop = $deleteModal.find('.mealsdb-modal__backdrop');
        const $deleteClientName = $('#mealsdb-delete-client-name');
        const $deleteClientNameWrapper = $deleteClientName.closest('.mealsdb-modal__client-name');
        let deleteTargetId = null;
        let $deleteTriggerRow = null;

        const ensureEmptyStateRow = () => {
            const $dataRows = $tableBody.children('tr').not('.mealsdb-client-empty');
            if ($dataRows.length === 0) {
                $tableBody.find('.mealsdb-client-empty').remove();
                if (emptyMessage) {
                    const $emptyRow = $('<tr>', { class: 'mealsdb-client-empty' });
                    $('<td>', {
                        colspan: columnCount,
                        text: emptyMessage
                    }).appendTo($emptyRow);
                    $tableBody.append($emptyRow);
                }
            } else {
                $tableBody.find('.mealsdb-client-empty').remove();
            }
        };

        const openDeleteModal = (clientId, clientName, $row) => {
            deleteTargetId = clientId;
            $deleteTriggerRow = $row;
            const safeName = (clientName || '').toString();

            if (safeName.length) {
                $deleteClientName.text(safeName);
                $deleteClientNameWrapper.attr('data-has-name', 'true');
            } else {
                $deleteClientName.text('');
                $deleteClientNameWrapper.attr('data-has-name', 'false');
            }

            $deleteModal.attr('aria-hidden', 'false').addClass('is-visible');
            $('body').addClass('mealsdb-modal-open');
            setTimeout(() => {
                $deleteModalConfirm.trigger('focus');
            }, 0);
        };

        const closeDeleteModal = () => {
            $deleteModal.attr('aria-hidden', 'true').removeClass('is-visible');
            $('body').removeClass('mealsdb-modal-open');
            $deleteClientName.text('');
            $deleteClientNameWrapper.attr('data-has-name', 'false');
            setBusyState($deleteModalConfirm, false);
            deleteTargetId = null;
            $deleteTriggerRow = null;
        };

        $(document).on('click', '.mealsdb-client-toggle-status', function () {
            const $button = $(this);
            const clientIdRaw = $button.data('clientId');
            const clientId = parseInt(clientIdRaw, 10);

            if (!Number.isInteger(clientId) || clientId <= 0 || !ajaxEndpoint || typeof mealsdb === 'undefined') {
                return;
            }

            const isActive = parseInt($button.data('active'), 10) === 1;
            const action = isActive ? 'mealsdb_deactivate_client' : 'mealsdb_activate_client';

            setBusyState($button, true);

            $.post(ajaxEndpoint, {
                action: action,
                nonce: mealsdb.nonce,
                client_id: clientId
            }, function (response) {
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
                } else {
                    const errorMessage = response && response.data && response.data.message
                        ? response.data.message
                        : toggleErrorMessage;
                    window.alert(errorMessage);
                }
            }).fail(function () {
                window.alert(toggleErrorMessage);
            }).always(function () {
                setBusyState($button, false);
            });
        });

        $(document).on('click', '.mealsdb-client-delete', function () {
            const $button = $(this);
            const clientIdRaw = $button.data('clientId');
            const clientId = parseInt(clientIdRaw, 10);

            if (!Number.isInteger(clientId) || clientId <= 0 || !$deleteModal.length) {
                return;
            }

            const clientName = $button.data('clientName') || '';
            openDeleteModal(clientId, clientName, $button.closest('tr'));
        });

        $deleteModalConfirm.on('click', function () {
            if (!deleteTargetId || !ajaxEndpoint || typeof mealsdb === 'undefined') {
                return;
            }

            setBusyState($deleteModalConfirm, true);

            $.post(ajaxEndpoint, {
                action: 'mealsdb_delete_client',
                nonce: mealsdb.nonce,
                client_id: deleteTargetId
            }, function (response) {
                if (response && response.success) {
                    const afterRemoval = () => {
                        ensureEmptyStateRow();
                        const successMessage = response.data && response.data.message
                            ? response.data.message
                            : deleteSuccessMessage;
                        if (successMessage) {
                            window.alert(successMessage);
                        }
                        closeDeleteModal();
                    };

                    if ($deleteTriggerRow && $deleteTriggerRow.length) {
                        $deleteTriggerRow.fadeOut(150, function () {
                            $(this).remove();
                            afterRemoval();
                        });
                    } else {
                        afterRemoval();
                    }
                } else {
                    const errorMessage = response && response.data && response.data.message
                        ? response.data.message
                        : deleteErrorMessage;
                    window.alert(errorMessage);
                }
            }).fail(function () {
                window.alert(deleteErrorMessage);
            }).always(function () {
                setBusyState($deleteModalConfirm, false);
            });
        });

        const handleModalClose = (event) => {
            event.preventDefault();
            closeDeleteModal();
        };

        $deleteModalCancel.on('click', handleModalClose);
        $deleteModalBackdrop.on('click', handleModalClose);

        $(document).on('keydown', function (event) {
            if (event.key === 'Escape' && $deleteModal.hasClass('is-visible')) {
                closeDeleteModal();
            }
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
        const $customerTypeSelect = $('#customer_type');
        const normalizeType = (value) => (value || '').toString().trim().toLowerCase();

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
                const shouldRequire = allowedTypes.includes(normalized);

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
                alert(message);
            } else {
                const error = res.data && res.data.message ? res.data.message : 'Failed to save draft.';
                alert(error);
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
        const source = $row.find('.mealsdb-a');
        const target = $row.find('.mealsdb-b');
        const value = (selected === 'meals_db')
            ? (source.data('value') ?? source.text())
            : (target.data('value') ?? target.text());

        $.post(ajaxurl, {
            action: 'mealsdb_sync_field',
            nonce: mealsdb.nonce,
            woo_user_id: woo_id,
            field: field,
            value: value
        }, function (res) {
            if (res.success) {
                alert('Synced: ' + field);
                $row.fadeOut();
            } else {
                alert('Sync failed.');
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
            alert('Invalid link request.');
            return;
        }

        const confirmName = wpUserName ?? 'this WordPress user';
        const confirmMessage = `Link this client to WordPress user ${confirmName}? This cannot be undone.`;

        if (!window.confirm(confirmMessage)) {
            return;
        }

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
                alert(errorMessage);
            }
        }).fail(function () {
            alert('Failed to link client.');
        }).always(function () {
            $button.prop('disabled', false);
        });
    });

    // -----------------------------
    // 🔁 Sync All Selected Fields
    // -----------------------------
    $('#mealsdb-sync-all').on('click', function () {
        $('.mealsdb-mismatch-row').each(function () {
            const $row = $(this);
            const selected = $row.find('input[type=radio]:checked').val();
            if (!selected) return;

            const field = $row.data('field');
            const woo_id = $row.data('woo');
            const source = $row.find('.mealsdb-a');
            const target = $row.find('.mealsdb-b');
            const value = (selected === 'meals_db')
                ? (source.data('value') ?? source.text())
                : (target.data('value') ?? target.text());

            $.post(ajaxurl, {
                action: 'mealsdb_sync_field',
                nonce: mealsdb.nonce,
                woo_user_id: woo_id,
                field: field,
                value: value
            }, function (res) {
                if (res.success) {
                    $row.fadeOut();
                }
            });
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
                alert('Ignore action failed.');
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

        const showNotice = (level, message) => {
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
    }
});
