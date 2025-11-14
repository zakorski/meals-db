(function ($) {
    $(function () {
        const ajaxEndpoint = (typeof mealsdb !== 'undefined' && mealsdb.ajaxUrl)
            ? mealsdb.ajaxUrl
            : (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
        const ajaxNonce = (typeof mealsdb !== 'undefined' && mealsdb.nonce) ? mealsdb.nonce : '';
        const messages = (typeof window.mealsdbClientActions !== 'undefined')
            ? window.mealsdbClientActions
            : {};
        const deleteErrorMessage = messages.deleteError || 'Unable to delete the client.';

        const $modal = $('#mealsdb-delete-client-modal');
        if (!$modal.length) {
            return;
        }

        const $body = $('body');
        const $backdrop = $modal.find('.mealsdb-modal__backdrop');
        const $cancelButton = $('#mealsdb-delete-client-cancel');
        const $confirmButton = $('#mealsdb-delete-client-confirm');
        const $clientName = $('#mealsdb-delete-client-name');
        const $clientNameWrapper = $clientName.closest('.mealsdb-modal__client-name');
        const $confirmationInput = $('#mealsdb-delete-client-confirmation');
        let activeClientId = null;

        const resetModalState = () => {
            activeClientId = null;
            $clientName.text('');
            $clientNameWrapper.attr('data-has-name', 'false');
            $confirmationInput.val('');
            updateConfirmState();
        };

        const openModal = (clientId, clientName) => {
            activeClientId = clientId;
            const safeName = (clientName || '').toString();

            if (safeName.length) {
                $clientName.text(safeName);
                $clientNameWrapper.attr('data-has-name', 'true');
            } else {
                $clientName.text('');
                $clientNameWrapper.attr('data-has-name', 'false');
            }

            $modal.attr('aria-hidden', 'false').addClass('is-visible');
            $body.addClass('mealsdb-modal-open');
            $confirmationInput.trigger('focus');
        };

        const closeModal = () => {
            $modal.attr('aria-hidden', 'true').removeClass('is-visible');
            $body.removeClass('mealsdb-modal-open');
            resetModalState();
        };

        const updateConfirmState = () => {
            const userValue = ($confirmationInput.val() || '').trim();
            const isMatch = userValue === 'YES';

            if (isMatch) {
                $confirmButton.prop('disabled', false).addClass('button-link-delete');
            } else {
                $confirmButton.prop('disabled', true).removeClass('button-link-delete');
            }
        };

        $(document).on('click', '.mealsdb-delete-client', function () {
            const $button = $(this);
            const clientIdRaw = $button.data('clientId');
            const clientId = parseInt(clientIdRaw, 10);

            if (!Number.isInteger(clientId) || clientId <= 0) {
                return;
            }

            const clientName = $button.data('clientName') || '';
            resetModalState();
            openModal(clientId, clientName);
        });

        const handleClose = (event) => {
            event.preventDefault();
            closeModal();
        };

        $backdrop.on('click', handleClose);
        $cancelButton.on('click', handleClose);

        $(document).on('keydown', function (event) {
            if (event.key === 'Escape' && $modal.hasClass('is-visible')) {
                closeModal();
            }
        });

        $confirmationInput.on('input', updateConfirmState);

        $confirmButton.on('click', function () {
            if (!activeClientId || !$modal.hasClass('is-visible')) {
                return;
            }

            if (!ajaxEndpoint || !ajaxNonce) {
                window.alert(deleteErrorMessage);
                return;
            }

            if ($confirmButton.prop('disabled')) {
                return;
            }

            $confirmButton.prop('disabled', true);

            $.post(ajaxEndpoint, {
                action: 'mealsdb_delete_client',
                nonce: ajaxNonce,
                client_id: activeClientId
            }, function (response) {
                if (response && response.success) {
                    window.location.reload();
                } else {
                    const errorMessage = response && response.data && response.data.message
                        ? response.data.message
                        : deleteErrorMessage;
                    window.alert(errorMessage);
                    updateConfirmState();
                }
            }).fail(function () {
                window.alert(deleteErrorMessage);
                updateConfirmState();
            });
        });

        updateConfirmState();
    });
})(jQuery);
