/**
 * Shared in-page modal helper (directive "Replace every native browser dialog").
 *
 * Exposes window.MealsDBConfirm with three Promise-returning entry points that
 * replace the native confirm() / prompt() / alert() — which are browser chrome
 * an automated agent cannot see or click:
 *
 *   MealsDBConfirm.confirm({title, message, confirmLabel, cancelLabel, destructive}) → Promise<boolean>
 *   MealsDBConfirm.prompt({title, message, defaultValue, required, placeholder})     → Promise<string|null>
 *   MealsDBConfirm.alert({title, message, level})                                    → Promise<void>
 *
 * The dialog is rendered in the page DOM (inside the plugin admin wrap), NOT an
 * iframe/shadow-root/browser dialog, so ordinary selectors reach it. Every
 * element carries a stable data-testid (a contract with the Chrome test tooling):
 *   mealsdb-modal, mealsdb-modal-title, mealsdb-modal-message, mealsdb-modal-input,
 *   mealsdb-modal-confirm, mealsdb-modal-cancel, mealsdb-modal-error.
 *
 * DIRECTIVE K1: the CSS classes live under the `.mealsdb-confirm*` namespace,
 * NOT `.mealsdb-modal*` — the latter belongs to the pre-existing Client Table
 * Modal in admin.css, whose `display:none` (revealed by `.is-visible`) hid this
 * dialog entirely. The `data-testid` values above are UNCHANGED (they keep the
 * `mealsdb-modal-*` spelling) — only the styling hooks moved.
 *
 * Accessibility: role="dialog", aria-modal, aria-labelledby → title; focus moves
 * into the dialog on open and back to the trigger on close; focus is trapped;
 * Escape cancels (matching the native dialog it replaces).
 *
 * One at a time: a request opened while a modal is showing is QUEUED and shown
 * when the current one closes — never stacked.
 *
 * NO native fallback anywhere: if the modal cannot render, it logs and fails
 * CLOSED — confirm → false, prompt → null, alert → resolve — rather than
 * reaching for window.confirm/alert/prompt (which would defeat the point).
 *
 * @package MealsDB
 */
(function (w, $) {
    'use strict';

    var queue = [];        // pending requests, processed one at a time
    var active = null;     // the currently-open request, or null
    var $lastTrigger = null;

    // DIRECTIVE K6 ITEM 1: reliable focus restoration. document.activeElement at
    // open time is not a dependable handle on the control that opened the dialog
    // — a <button>/<a> clicked by mouse does NOT retain focus in every browser,
    // leaving activeElement as <body>, so on close focus was dropped to the top
    // of the document. Track the last element the user actually interacted with
    // (mousedown / keydown, capture phase) and use it as the trigger when
    // activeElement is unhelpful. Self-contained: no call site has to pass the
    // trigger in.
    var lastInteracted = null;
    $(document).on('mousedown.mealsdbconfirm keydown.mealsdbconfirm', function (e) {
        if (e && e.target && e.target !== document && e.target !== document.body) {
            lastInteracted = e.target;
        }
    });

    function esc(s) {
        return $('<div>').text(s === undefined || s === null ? '' : String(s)).html();
    }

    // Resolve the container the modal mounts into. Fails closed (returns null)
    // rather than falling back to a native dialog.
    function container() {
        var $c = $('.wrap').first();
        return ($c && $c.length) ? $c : null;
    }

    function listHtml(items) {
        var lis = items.map(function (m) { return '<li>' + esc(m) + '</li>'; }).join('');
        return '<ul class="mealsdb-confirm__list">' + lis + '</ul>';
    }

    // Build the message body. Accepts:
    //   - a string            → a single <p>
    //   - an array of strings → a single <ul> (the coverage-warning list)
    //   - a MIXED array whose elements are themselves arrays (→ <ul>) or strings
    //     (→ <p>) → blocks rendered in order. This lets a caller show a list of
    //     warnings followed by a prose question that sits OUTSIDE the list
    //     (K6 ITEM 4). Everything is text-escaped.
    function messageHtml(message) {
        if ($.isArray(message)) {
            var hasBlocks = message.some(function (m) { return $.isArray(m); });
            if (hasBlocks) {
                return message.map(function (m) {
                    return $.isArray(m) ? listHtml(m) : '<p>' + esc(m) + '</p>';
                }).join('');
            }
            return listHtml(message);
        }
        return '<p>' + esc(message) + '</p>';
    }

    // Process the next queued request, if any and nothing is open.
    function pump() {
        if (active || !queue.length) {
            return;
        }
        var req = queue.shift();
        render(req);
    }

    // Enqueue a request and return its Promise.
    function enqueue(kind, opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            queue.push({ kind: kind, opts: opts, resolve: resolve });
            pump();
        });
    }

    function close(req, result) {
        if (req.$overlay) {
            req.$overlay.off('keydown.mealsdbmodal').remove();
        }
        $(document).off('keydown.mealsdbmodal-' + req.id);
        active = null;
        // Return focus to whatever opened the modal.
        if ($lastTrigger && $lastTrigger.length) {
            try { $lastTrigger.trigger('focus'); } catch (e) { /* ignore */ }
        }
        req.resolve(result);
        // Show the next queued request (if any) after this one resolves.
        w.setTimeout(pump, 0);
    }

    function render(req) {
        var $c = container();
        if (!$c) {
            // Fail CLOSED — never reach for a native dialog.
            if (w.console && w.console.error) {
                w.console.error('[MealsDB] MealsDBConfirm: no .wrap container to render into; failing closed.');
            }
            active = null;
            req.resolve(req.kind === 'confirm' ? false : (req.kind === 'prompt' ? null : undefined));
            w.setTimeout(pump, 0);
            return;
        }

        active = req;
        req.id = 'm' + (Date.now()) + Math.floor(Math.random() * 1000);
        // Prefer the genuinely-focused element; fall back to the last control the
        // user interacted with (K6 ITEM 1) when a mouse click left focus on body.
        var trigger = (document.activeElement && document.activeElement !== document.body)
            ? document.activeElement
            : lastInteracted;
        $lastTrigger = trigger ? $(trigger) : null;

        var o = req.opts;
        var isPrompt = req.kind === 'prompt';
        var isAlert = req.kind === 'alert';
        var title = o.title || (isAlert ? 'Notice' : (isPrompt ? 'Enter a value' : 'Please confirm'));
        var confirmLabel = o.confirmLabel || 'OK';
        var cancelLabel = o.cancelLabel || 'Cancel';

        var inputHtml = '';
        if (isPrompt) {
            inputHtml =
                '<input type="text" class="regular-text mealsdb-confirm__input" data-testid="mealsdb-modal-input"' +
                ' value="' + esc(o.defaultValue || '') + '"' +
                (o.placeholder ? ' placeholder="' + esc(o.placeholder) + '"' : '') + ' />';
        }

        var actionsHtml = '';
        if (!isAlert) {
            actionsHtml +=
                '<button type="button" class="button mealsdb-confirm__btn-cancel" data-testid="mealsdb-modal-cancel">' +
                esc(cancelLabel) + '</button> ';
        }
        actionsHtml +=
            '<button type="button" class="button button-primary mealsdb-confirm__btn-confirm' +
            (o.destructive ? ' mealsdb-confirm__btn-destructive' : '') +
            '" data-testid="mealsdb-modal-confirm">' + esc(confirmLabel) + '</button>';

        var html =
            '<div class="mealsdb-confirm-overlay" data-testid="mealsdb-modal-overlay">' +
              '<div class="mealsdb-confirm" role="dialog" aria-modal="true"' +
                ' aria-labelledby="mealsdb-modal-title" data-testid="mealsdb-modal" tabindex="-1">' +
                '<h2 class="mealsdb-confirm__title" id="mealsdb-modal-title" data-testid="mealsdb-modal-title">' +
                  esc(title) + '</h2>' +
                '<div class="mealsdb-confirm__message" data-testid="mealsdb-modal-message">' +
                  messageHtml(o.message) + '</div>' +
                inputHtml +
                '<div class="mealsdb-confirm__error" data-testid="mealsdb-modal-error" role="alert" hidden></div>' +
                '<div class="mealsdb-confirm__actions">' + actionsHtml + '</div>' +
              '</div>' +
            '</div>';

        var $overlay = $(html);
        req.$overlay = $overlay;
        $c.append($overlay);

        var $modal = $overlay.find('[data-testid="mealsdb-modal"]');
        var $confirm = $overlay.find('[data-testid="mealsdb-modal-confirm"]');
        var $cancel = $overlay.find('[data-testid="mealsdb-modal-cancel"]');
        var $input = $overlay.find('[data-testid="mealsdb-modal-input"]');
        var $error = $overlay.find('[data-testid="mealsdb-modal-error"]');

        function showError(msg) {
            $error.text(msg).prop('hidden', false);
        }

        function onConfirm() {
            if (isPrompt) {
                var val = String($input.val() != null ? $input.val() : '');
                if (o.required && val.trim() === '') {
                    // The modal itself refuses to submit empty (replaces the
                    // native re-prompt loop) — it does not close and re-open.
                    showError(o.requiredMessage || 'A value is required.');
                    $input.trigger('focus');
                    return;
                }
                close(req, val);
                return;
            }
            close(req, isAlert ? undefined : true);
        }

        function onCancel() {
            // confirm → false, prompt → null, alert has no cancel (Escape resolves void).
            close(req, req.kind === 'confirm' ? false : (req.kind === 'prompt' ? null : undefined));
        }

        $confirm.on('click', onConfirm);
        $cancel.on('click', onCancel);
        // A click on the confirm/cancel is the only way to dismiss besides Escape;
        // clicking the backdrop does NOT cancel (avoids losing a typed prompt).

        // Escape cancels (matching the native dialog). Focus trap: Tab cycles
        // within the modal only.
        var focusables = function () {
            return $modal.find('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')
                .filter(':visible');
        };
        $(document).on('keydown.mealsdbmodal-' + req.id, function (e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                e.preventDefault();
                onCancel();
                return;
            }
            if (e.key === 'Enter' && isPrompt && $input.is(':focus')) {
                e.preventDefault();
                onConfirm();
                return;
            }
            if (e.key === 'Tab' || e.keyCode === 9) {
                var $f = focusables();
                if (!$f.length) { return; }
                var first = $f[0];
                var last = $f[$f.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        });

        // Move focus into the dialog on open — the input for a prompt, else the
        // confirm button.
        if (isPrompt && $input.length) {
            $input.trigger('focus').trigger('select');
        } else {
            $confirm.trigger('focus');
        }
    }

    w.MealsDBConfirm = {
        confirm: function (opts) { return enqueue('confirm', opts); },
        prompt: function (opts) { return enqueue('prompt', opts); },
        alert: function (opts) { return enqueue('alert', opts); }
    };
})(window, jQuery);
