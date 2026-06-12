/**
 * WordPress-user anchor for the Add/Edit Client form (directive GUI-F3F5-v2).
 *
 * The WP-User-ID field gains two buttons:
 *   - Validate  -> mealsdb_validate_wp_user: confirms the ID maps to a real WP
 *                  user and echoes the billing name for visual confirmation;
 *                  flags if that user is already linked to a client (warning,
 *                  not a block — audit MAJ-1 allows a dual-program person).
 *   - Pull Data -> mealsdb_pull_wp_user_data: fills identity/contact/address/
 *                  preference fields from the WP user's usermeta (the migration
 *                  mapping). Gated behind a successful Validate; overwrites the
 *                  mapped fields on the explicit press so the operator reviews
 *                  before saving.
 *
 * The "validated" state is invalidated the moment the ID field is edited, so a
 * stale Pull can't fire against a different (unvalidated) ID. On-page notices
 * (window.MealsDBNotice) replace native alert() per directive GUI-NOTICES.
 */
(function ($) {
    'use strict';

    $(function () {
        var $form = $('#mealsdb-client-form');
        if ($form.length === 0) {
            return;
        }

        var $input = $('#wordpress_user_id');
        var $validateBtn = $('#mealsdb-validate-wp-user');
        var $pullBtn = $('#mealsdb-pull-wp-user');
        var $status = $('#wp-user-validation-status');
        var $message = $form.find('.mealsdb-wp-user-message');

        if ($input.length === 0 || $validateBtn.length === 0 || $pullBtn.length === 0) {
            return;
        }

        var cfg = window.mealsdbWpUser || {};
        var shared = window.mealsdb || {};
        var ajaxUrl = cfg.ajaxUrl || shared.ajaxUrl || window.ajaxurl || '';
        var nonce = cfg.nonce || shared.nonce || '';
        var messages = cfg.messages || {};

        var validatedId = null; // the WP user id the operator has confirmed

        var stylesInjected = false;
        var ensureStyles = function () {
            if (stylesInjected) {
                return;
            }
            var style = document.createElement('style');
            style.id = 'mealsdb-wp-user-check-style';
            style.textContent = '.mealsdb-valid-check { color: #2e8540; font-weight: 600; }'
                + '.mealsdb-warn-flag { color: #b26200; font-weight: 600; }';
            document.head.appendChild(style);
            stylesInjected = true;
        };

        var setMessage = function (type, text) {
            $message.removeClass('is-success is-error is-warning');
            if (!text) {
                $message.text('');
                return;
            }
            if (type === 'success') {
                $message.addClass('is-success');
            } else if (type === 'error') {
                $message.addClass('is-error');
            } else if (type === 'warning') {
                $message.addClass('is-warning');
            }
            $message.text(text);
        };

        var setValidated = function (id, name, alreadyLinked, alreadyLinkedSelf) {
            validatedId = id;
            $form.data('mealsdbWpUserValidated', id);
            $pullBtn.prop('disabled', false);
            ensureStyles();
            var html = '<span class="mealsdb-valid-check">✔ ' + escapeHtml(name) + '</span>';
            // Prefer the server's explicit self-link flag; fall back to comparing the current
            // client_id the form already knows. A self-link is correct and expected, so it reads
            // as reassuring (a check, not a warning); a link to a DIFFERENT client is the real
            // dual-use warning and keeps the "#N" form.
            var isSelfLink = alreadyLinkedSelf === true ||
                (alreadyLinked && currentClientId() && alreadyLinked === currentClientId());
            if (isSelfLink) {
                html += ' <span class="mealsdb-valid-check">'
                    + (messages.alreadyLinkedSelf || 'already linked to this client') + '</span>';
            } else if (alreadyLinked) {
                html += ' <span class="mealsdb-warn-flag">⚠ '
                    + (messages.alreadyLinked || 'already linked to client #') + alreadyLinked + '</span>';
            }
            $status.html(html);
        };

        var resetValidated = function () {
            validatedId = null;
            $form.removeData('mealsdbWpUserValidated');
            $pullBtn.prop('disabled', true);
            $status.empty();
        };

        // HTML-text escaper. Prefer the shared MealsDBReport.esc (consolidation
        // goal, audit STR-2); fall back to an identical DOM round-trip if
        // report-utils didn't load, so escaping is never disabled.
        function escapeHtml(value) {
            if (window.MealsDBReport && typeof window.MealsDBReport.esc === 'function') {
                return window.MealsDBReport.esc(value);
            }
            return $('<div>').text(value == null ? '' : String(value)).html();
        }

        var currentId = function () {
            var raw = ($input.val() || '').toString().trim();
            if (!/^\d+$/.test(raw)) {
                return 0;
            }
            var n = parseInt(raw, 10);
            return (isNaN(n) || n <= 0) ? 0 : n;
        };

        // The client currently being edited. The hidden client_id input carries it on the Edit
        // form; it's absent/0 on the Add form, where there is no "current" client to be "this".
        var currentClientId = function () {
            var n = parseInt(($('input[name="client_id"]').val() || '').toString().trim(), 10);
            return (isNaN(n) || n <= 0) ? 0 : n;
        };

        var extractError = function (response, fallback) {
            if (response && response.data && typeof response.data.message === 'string' && response.data.message) {
                return response.data.message;
            }
            if (response && typeof response.message === 'string' && response.message) {
                return response.message;
            }
            return fallback;
        };

        $validateBtn.on('click', function (event) {
            event.preventDefault();
            var id = currentId();
            if (!id) {
                resetValidated();
                setMessage('error', messages.enterId || 'Enter a positive WordPress User ID.');
                return;
            }
            if (!ajaxUrl || !nonce) {
                setMessage('error', messages.error || 'An unexpected error occurred. Please try again.');
                return;
            }

            $validateBtn.prop('disabled', true);
            setMessage(null, messages.validating || 'Validating…');

            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: { action: 'mealsdb_validate_wp_user', nonce: nonce, wp_user_id: id, client_id: currentClientId() }
            }).done(function (response) {
                if (response && response.success && response.data) {
                    setValidated(id, response.data.name || ('#' + id), response.data.already_linked || null, response.data.already_linked_self === true);
                    setMessage('success', (messages.validated || 'Confirmed:') + ' ' + (response.data.name || ('#' + id)));
                } else {
                    resetValidated();
                    setMessage('error', extractError(response, messages.notFound || 'No WordPress user with that ID.'));
                }
            }).fail(function (jqXHR, textStatus) {
                if (textStatus === 'abort') {
                    return;
                }
                resetValidated();
                setMessage('error', messages.error || 'An unexpected error occurred. Please try again.');
            }).always(function () {
                $validateBtn.prop('disabled', false);
            });
        });

        $pullBtn.on('click', function (event) {
            event.preventDefault();
            var id = currentId();
            if (!id || id !== validatedId) {
                // Defensive: button should be disabled unless validated, but
                // never pull from an unvalidated/changed id.
                resetValidated();
                setMessage('error', messages.validateFirst || 'Validate the WordPress User ID before pulling data.');
                return;
            }

            $pullBtn.prop('disabled', true);
            setMessage(null, messages.pulling || 'Loading data from the WordPress user…');

            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: { action: 'mealsdb_pull_wp_user_data', nonce: nonce, wp_user_id: id }
            }).done(function (response) {
                if (response && response.success && response.data && response.data.fields) {
                    var fields = response.data.fields;
                    var applied = applyFields(fields);
                    var name = response.data.name || ('#' + id);
                    MealsDBNotice('success', (messages.populated || 'Populated')
                        + ' ' + applied + ' '
                        + (messages.populatedFields || 'fields from WP user')
                        + ' ' + name + ' — ' + (messages.reviewSave || 'review and save.'));
                    setMessage('success', (messages.populated || 'Populated') + ' ' + applied + ' ' + (messages.fieldsLower || 'fields.'));
                } else {
                    setMessage('error', extractError(response, messages.pullFailed || 'Unable to load data from the WordPress user.'));
                }
            }).fail(function (jqXHR, textStatus) {
                if (textStatus === 'abort') {
                    return;
                }
                setMessage('error', messages.error || 'An unexpected error occurred. Please try again.');
            }).always(function () {
                // Re-enable only while the id is still the validated one.
                $pullBtn.prop('disabled', currentId() !== validatedId);
            });
        });

        // Drop the pulled values into the form. Explicit press = overwrite, so
        // the operator can review before saving. Fires change/input so
        // dependent logic (client-type sections, datepickers) reacts.
        function applyFields(fields) {
            var applied = 0;
            Object.keys(fields).forEach(function (name) {
                var $field = $form.find('[name="' + name + '"]');
                if ($field.length === 0) {
                    return;
                }
                $field.val(fields[name]).trigger('change').trigger('input');
                applied++;
            });
            return applied;
        }

        // Any edit to the ID invalidates a prior validation — you can't pull
        // from an id you haven't confirmed.
        $input.on('input change', function () {
            if (validatedId !== null && currentId() !== validatedId) {
                resetValidated();
                setMessage(null, '');
            }
        });

        // Block submit if the WP user hasn't been validated in this session.
        // Server-side save() is the authoritative gate (it re-checks existence);
        // this is the friendly client-side nudge so the operator doesn't round-
        // trip through a failed save. A pre-existing edit (the field already had
        // a value on load and the operator didn't touch it) is allowed through —
        // the server still validates it.
        $form.on('submit', function (event) {
            var id = currentId();
            if (!id) {
                event.preventDefault();
                MealsDBNotice('error', messages.requiredOnSave || 'A WordPress User ID is required. Use Validate to confirm it.');
                setMessage('error', messages.requiredOnSave || 'A WordPress User ID is required. Use Validate to confirm it.');
                $input.trigger('focus');
            }
        });
    });
})(jQuery);
