/**
 * Schedule rules view — list + create-form interactions.
 *
 * Extracted verbatim from the inline <script> that used to live in
 * views/task-rules.php (per CLAUDE.md: inline logic blocks > 20 lines must be
 * enqueued assets). The only change vs. the inline version is that every value
 * the PHP used to interpolate (nonce, ajaxUrl, user-facing strings) is now read
 * from the JSON data island #mealsdb-task-rules-data.
 *
 * @package MealsDB
 */
(function ($) {
    "use strict";

    var _el = document.getElementById("mealsdb-task-rules-data");
    var data = _el ? JSON.parse(_el.textContent || "{}") : {};

    var nonce   = data.nonce || "";
    var ajaxUrl = data.ajaxUrl || (window.ajaxurl || "");
    var i18n    = data.i18n || {};

    // Pull a translated string from the island with an English fallback so a
    // missing key can never crash the handler.
    function t(key, fallback) {
        return (i18n && typeof i18n[key] === "string") ? i18n[key] : fallback;
    }

    function setStatus(msg, type) {
        var $s = $('#mealsdb-rules-status');
        $s.removeClass('notice-success notice-error notice-warning').addClass('notice-' + (type || 'info'));
        $s.empty().append($('<p>').text(msg)).show(); // .text() — never inject server msg as HTML
    }

    $('#mealsdb-rule-form').on('submit', function (e) {
        e.preventDefault();

        var payload = { action: 'mealsdb_rules_create', nonce: nonce };
        payload.name = $('#mealsdb-rule-name').val();
        payload.task_type = $('#mealsdb-rule-type').val();
        payload.spawn_type = $('#mealsdb-rule-spawn').val();

        try {
            payload.recurrence = $('#mealsdb-rule-recurrence').val();
            JSON.parse(payload.recurrence);
            payload.payload_template = $('#mealsdb-rule-payload').val();
            JSON.parse(payload.payload_template);
            var criteria = $('#mealsdb-rule-criteria').val().trim();
            if (criteria) {
                JSON.parse(criteria);
                payload.query_criteria = criteria;
            }
        } catch (err) {
            // Function replacer avoids String.replace $-pattern interpretation of err.message.
            setStatus(t('invalidJson', 'Invalid JSON: %s').replace('%s', function () { return err.message; }), 'error');
            return;
        }

        var tagsRaw = $('#mealsdb-rule-tags').val().trim();
        if (tagsRaw) {
            payload.tags = JSON.stringify(tagsRaw.split(',').map(function (s) { return s.trim(); }).filter(Boolean));
        }
        var role = $('#mealsdb-rule-role').val();
        if (role) payload.assignee_role = role;

        $.post(ajaxUrl, payload).done(function (resp) {
            if (resp && resp.success) {
                window.location.reload();
            } else {
                setStatus((resp && resp.data && resp.data.message) || t('createFailed', 'Create failed.'), 'error');
            }
        }).fail(function () {
            setStatus(t('createFailed', 'Create failed.'), 'error');
        });
    });

    $(document).on('click', '.mealsdb-rule-run-now', function () {
        var id = $(this).data('rule-id');
        $.post(ajaxUrl, {
            action: 'mealsdb_rules_run_now',
            nonce: nonce,
            rule_id: id
        }).done(function (resp) {
            if (resp && resp.success) {
                setStatus(t('created', 'Created %d tasks.').replace('%d', (resp.data.created || 0)), 'success');
            } else {
                setStatus(t('runNowFailed', 'Run-now failed.'), 'error');
            }
        }).fail(function () {
            setStatus(t('runNowFailed', 'Run-now failed.'), 'error');
        });
    });

    $(document).on('click', '.mealsdb-rule-delete', function () {
        // Capture the row id before the async confirm ($(this) is not valid
        // inside the promise callback).
        var id = $(this).data('rule-id');
        window.MealsDBConfirm.confirm({
            title: t('confirmDeleteTitle', 'Delete rule'),
            message: t('confirmDelete', 'Delete this rule? Existing spawned tasks will remain.'),
            confirmLabel: t('deleteLabel', 'Delete'),
            destructive: true
        }).then(function (ok) {
            if (!ok) { return; }
            $.post(ajaxUrl, {
                action: 'mealsdb_rules_delete',
                nonce: nonce,
                rule_id: id
            }).done(function (resp) {
                if (resp && resp.success) {
                    window.location.reload();
                } else {
                    setStatus(t('deleteFailed', 'Delete failed.'), 'error');
                }
            }).fail(function () {
                setStatus(t('deleteFailed', 'Delete failed.'), 'error');
            });
        });
    });
})(jQuery);
