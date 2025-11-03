jQuery(document).ready(function($) {

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
        let val = $(this).val().toUpperCase().replace(/[^A-Z0-9]/g, '').substring(0, 6);
        val = val.replace(/^(.{3})(.{1,3})$/, '$1 $2');
        $(this).val(val);
    });

    // -----------------------------
    // 🪜 Multi-Step Client Form
    // -----------------------------
    const $clientForm = $('#mealsdb-client-form');
    if ($clientForm.length) {
        const $steps = $clientForm.find('.mealsdb-step');
        const $indicatorItems = $clientForm.find('.mealsdb-step-indicator li');
        const $initialTypeSelect = $('#mealsdb-client-type-initial');
        const $customerTypeSelect = $('#customer_type');
        const $step1Next = $clientForm.find('.mealsdb-step[data-step="1"] .mealsdb-next-step');
        let currentStep = parseInt($clientForm.data('initialStep'), 10) || 1;

        const normalizeType = (value) => (value || '').toString().trim().toLowerCase();

        const updateStepIndicator = (step) => {
            $indicatorItems.each(function () {
                const $item = $(this);
                const itemStep = parseInt($item.data('step'), 10);
                const isActive = itemStep === step;
                $item.toggleClass('is-active', isActive);
                $item.toggleClass('is-complete', itemStep < step);
            });
        };

        const showStep = (step) => {
            const safeStep = Math.min(Math.max(step, 1), $steps.length);
            currentStep = safeStep;
            $steps.removeClass('is-active').attr('aria-hidden', 'true');
            const $target = $steps.filter(`[data-step="${safeStep}"]`);
            $target.addClass('is-active').attr('aria-hidden', 'false');
            updateStepIndicator(safeStep);
        };

        const toggleClientTypeSections = (typeValue) => {
            const normalized = normalizeType(typeValue);

            $clientForm.find('[data-client-type]').each(function () {
                const $row = $(this);
                const allowedRaw = ($row.data('clientType') || '').toString().toLowerCase();
                if (!allowedRaw) {
                    $row.show();
                    return;
                }

                const allowedTypes = allowedRaw.split(',').map((item) => item.trim()).filter(Boolean);
                const shouldShow = allowedTypes.includes('all') || allowedTypes.includes(normalized);
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

        const ensureStep1ButtonState = () => {
            const value = $initialTypeSelect.val();
            const hasSelection = value && value.length > 0;
            $step1Next.prop('disabled', !hasSelection);
        };

        // Navigation controls
        $clientForm.on('click', '.mealsdb-next-step', function (event) {
            event.preventDefault();
            const target = parseInt($(this).data('stepTarget'), 10);
            const nextStep = Number.isNaN(target) ? currentStep + 1 : target;
            if (nextStep > 1 && !$customerTypeSelect.val()) {
                showStep(1);
                return;
            }
            showStep(nextStep);
        });

        $clientForm.on('click', '.mealsdb-prev-step', function (event) {
            event.preventDefault();
            const target = parseInt($(this).data('stepTarget'), 10);
            const prevStep = Number.isNaN(target) ? currentStep - 1 : target;
            showStep(prevStep);
        });

        // Client type syncing
        $initialTypeSelect.on('change', function () {
            const value = $(this).val();
            $customerTypeSelect.val(value).trigger('change');
            ensureStep1ButtonState();
        });

        $customerTypeSelect.on('change', function () {
            const value = $(this).val();
            if ($initialTypeSelect.val() !== value) {
                $initialTypeSelect.val(value);
            }
            ensureStep1ButtonState();
            toggleClientTypeSections(value);
        });

        // Delivery address toggle
        $('#delivery-address-toggle').on('change', function () {
            const show = $(this).is(':checked');
            const $container = $('#delivery-address-fields');
            if (show) {
                $container.slideDown();
            } else {
                $container.slideUp();
            }
        });

        // Alternate contact toggle
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

        // Step indicator click (optional direct navigation)
        $indicatorItems.on('click', function () {
            const step = parseInt($(this).data('step'), 10);
            if (!step) return;
            if (step > 1 && !$customerTypeSelect.val()) {
                showStep(1);
                return;
            }
            showStep(step);
        });

        // Initialise state
        syncAltContactName();
        if (!$initialTypeSelect.val() && $customerTypeSelect.val()) {
            $initialTypeSelect.val($customerTypeSelect.val());
        }
        toggleClientTypeSections($customerTypeSelect.val());
        ensureStep1ButtonState();
        showStep(currentStep);
    }

    // -----------------------------
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
});