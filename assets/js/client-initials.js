(function ($) {
    'use strict';

    $(function () {
        const $form = $('#mealsdb-client-form');
        if ($form.length === 0) {
            return;
        }

        const $initialsInput = $('#delivery_initials');
        const $generateButton = $('#mealsdb-generate-initials');
        const $validateButton = $('#mealsdb-validate-initials');
        const $message = $form.find('.mealsdb-initials-message');
        const $validationStatus = $('#initials-validation-status');

        if ($initialsInput.length === 0 || $generateButton.length === 0 || $validateButton.length === 0 || $message.length === 0) {
            return;
        }

        const localized = window.mealsdbInitials || {};
        const shared = window.mealsdb || {};
        const ajaxUrl = localized.ajaxUrl || shared.ajaxUrl || window.ajaxurl || '';
        const nonces = localized.nonces || {};
        const messages = localized.messages || {};
        const $customerType = $('#customer_type');

        let validationRequest = null;
        let validatedValue = null;
        let isValid = false;
        let initialsIsValid = false;
        let lastValidatedValue = '';
        let stylesInjected = false;

        const normalize = (value) => (value || '').toString().trim().toUpperCase();

        const toggleButtons = (disabled) => {
            $generateButton.prop('disabled', disabled);
            $validateButton.prop('disabled', disabled);
        };

        const setMessage = (type, text) => {
            $message.removeClass('is-success is-error');

            if (!text) {
                $message.text('');
                return;
            }

            if (type === 'success') {
                $message.addClass('is-success');
            } else if (type === 'error') {
                $message.addClass('is-error');
            }

            $message.text(text);
        };

        const ensureValidCheckStyles = () => {
            if (stylesInjected) {
                return;
            }

            const style = document.createElement('style');
            style.id = 'mealsdb-valid-check-style';
            style.type = 'text/css';
            style.textContent = '.mealsdb-valid-check { color: #2e8540; font-weight: 600; }';
            document.head.appendChild(style);
            stylesInjected = true;
        };

        const markValid = (valid) => {
            isValid = !!valid;
            if (valid) {
                validatedValue = normalize($initialsInput.val());
            } else {
                validatedValue = null;
            }
            $form.data('mealsdbInitialsValid', isValid);

            if (isValid) {
                initialsIsValid = true;
                lastValidatedValue = $initialsInput.val();
                if ($validationStatus.length) {
                    ensureValidCheckStyles();
                    $validationStatus.html('<span class="mealsdb-valid-check">✔ Valid</span>');
                }
            } else {
                initialsIsValid = false;
                lastValidatedValue = '';
                if ($validationStatus.length) {
                    $validationStatus.empty();
                }
            }
        };

        const resetValidation = () => {
            markValid(false);
        };

        const isClientStaff = () => {
            if (!$customerType.length) {
                return false;
            }

            const value = ($customerType.val() || '').toString().toLowerCase();
            return value === 'staff';
        };

        const finishRequest = () => {
            toggleButtons(false);
            validationRequest = null;
        };

        const abortOngoingValidation = () => {
            if (validationRequest && typeof validationRequest.abort === 'function') {
                validationRequest.abort();
            }
            validationRequest = null;
        };

        const extractErrorMessage = (response, fallback) => {
            if (response) {
                if (typeof response.message === 'string' && response.message.length > 0) {
                    return response.message;
                }

                if (response.data && typeof response.data.message === 'string' && response.data.message.length > 0) {
                    return response.data.message;
                }
            }

            return fallback;
        };

        const getClientId = () => {
            const raw = $form.find('input[name="client_id"]').val();
            if (!raw) {
                return null;
            }

            const parsed = parseInt(raw, 10);
            if (Number.isNaN(parsed) || parsed <= 0) {
                return null;
            }

            return parsed;
        };

        const isFieldActive = () => $initialsInput.closest('tr').is(':visible') && !$initialsInput.prop('disabled');

        const isValidationRequired = () => {
            if (!isFieldActive()) {
                return false;
            }

            if (isClientStaff()) {
                return false;
            }

            return normalize($initialsInput.val()).length > 0;
        };

        const performValidation = (options = {}) => {
            const { suppressMessages = false, focusOnError = false } = options;
            const value = normalize($initialsInput.val());

            abortOngoingValidation();

            if (!value) {
                resetValidation();
                if (!suppressMessages) {
                    setMessage('error', messages.empty || 'Enter initials before validating.');
                } else {
                    setMessage(null, '');
                }
                if (focusOnError) {
                    $initialsInput.trigger('focus');
                }
                finishRequest();
                return $.Deferred().reject().promise();
            }

            if (!ajaxUrl || !nonces.validate) {
                resetValidation();
                if (!suppressMessages) {
                    setMessage('error', messages.error || 'An unexpected error occurred. Please try again.');
                }
                if (focusOnError) {
                    $initialsInput.trigger('focus');
                }
                finishRequest();
                return $.Deferred().reject().promise();
            }

            toggleButtons(true);

            if (!suppressMessages) {
                setMessage(null, messages.validating || 'Validating initials…');
            } else {
                setMessage(null, '');
            }

            validationRequest = $.ajax({
                url: ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'mealsdb_validate_initials',
                    nonce: nonces.validate,
                    code: value,
                    client_id: getClientId(),
                },
            });

            validationRequest.done((response) => {
                if (response && response.success) {
                    markValid(true);
                    if (suppressMessages) {
                        setMessage(null, '');
                    } else {
                        setMessage('success', messages.success || 'Initials are valid.');
                    }
                } else {
                    const fallbackMessage = messages.invalid || 'These initials are invalid or already in use.';
                    const message = extractErrorMessage(response, fallbackMessage);
                    markValid(false);
                    setMessage('error', message || fallbackMessage);
                    if (focusOnError) {
                        $initialsInput.trigger('focus');
                    }
                }
            });

            validationRequest.fail((jqXHR, textStatus) => {
                if (textStatus === 'abort') {
                    return;
                }

                markValid(false);
                setMessage('error', messages.error || 'An unexpected error occurred. Please try again.');
                if (focusOnError) {
                    $initialsInput.trigger('focus');
                }
            });

            validationRequest.always(finishRequest);

            return validationRequest;
        };

        $generateButton.on('click', function (event) {
            event.preventDefault();

            if (!ajaxUrl || !nonces.generate) {
                setMessage('error', messages.error || 'An unexpected error occurred. Please try again.');
                return;
            }

            toggleButtons(true);
            setMessage(null, '');

            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'mealsdb_generate_initials',
                    nonce: nonces.generate,
                },
            }).done((response) => {
                if (response && response.success && response.code) {
                    $initialsInput.val(response.code);
                    resetValidation();
                    const validationAttempt = performValidation({ suppressMessages: false });
                    if (!validationAttempt || typeof validationAttempt.always !== 'function') {
                        finishRequest();
                    }
                } else {
                    const fallbackMessage = messages.generateError || messages.error || 'Unable to generate initials. Please try again.';
                    const message = extractErrorMessage(response, fallbackMessage);
                    setMessage('error', message || fallbackMessage);
                    finishRequest();
                }
            }).fail((jqXHR, textStatus) => {
                if (textStatus !== 'abort') {
                    const fallbackMessage = messages.generateError || messages.error || 'Unable to generate initials. Please try again.';
                    setMessage('error', fallbackMessage);
                }
                finishRequest();
            });
        });

        $validateButton.on('click', function (event) {
            event.preventDefault();
            performValidation({ focusOnError: true });
        });

        $initialsInput.on('input', function () {
            const rawValue = $(this).val();
            if (rawValue !== lastValidatedValue) {
                initialsIsValid = false;
                if ($validationStatus.length) {
                    $validationStatus.empty();
                }
            }

            const current = normalize(rawValue);
            if (!current) {
                resetValidation();
                setMessage(null, '');
                return;
            }

            if (isValid && current !== validatedValue) {
                resetValidation();
                const text = messages.required || 'Please validate the initials before submitting.';
                setMessage('error', text);
            }
        });

        const handleClientTypeChange = () => {
            if (!isFieldActive()) {
                setMessage(null, '');
                resetValidation();
                return;
            }

            if (!isValid && normalize($initialsInput.val()).length > 0) {
                const text = messages.required || 'Please validate the initials before submitting.';
                setMessage('error', text);
            }
        };

        if ($customerType.length) {
            $customerType.on('change', handleClientTypeChange);
        }

        $form.on('submit', function (event) {
            const staff = isClientStaff();
            if (!staff && !initialsIsValid) {
                event.preventDefault();
                window.alert('Please validate initials before saving.');
                const text = messages.required || 'Please validate the initials before submitting.';
                setMessage('error', text);
                $initialsInput.trigger('focus');
                return;
            }

            if (!isValidationRequired()) {
                return;
            }

            if (isValid && validatedValue === normalize($initialsInput.val())) {
                return;
            }

            event.preventDefault();
            const text = messages.required || 'Please validate the initials before submitting.';
            setMessage('error', text);
            $initialsInput.trigger('focus');
        });

        if (isValidationRequired()) {
            performValidation({ suppressMessages: true });
        }
    });
})(jQuery);
