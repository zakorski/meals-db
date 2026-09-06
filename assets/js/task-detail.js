/**
 * Task detail view — form-driven completion / defer / skip flow.
 *
 * Extracted verbatim from the inline <script> that used to live in
 * views/task-detail.php (CLAUDE.md bans inline logic blocks > 20 lines).
 * Server-provided values are read from the JSON data island
 * (#mealsdb-task-detail-data) instead of being interpolated by PHP.
 *
 * Note: this view uses window.MealsDBTaskForm (the shared task-form renderer),
 * NOT window.MealsDBReport — there is no HTML-escape / CSV / number-format
 * reimplementation here to consolidate. The status helper toggles WP notice
 * classes and injects the message via .text(), so it is kept as-is.
 */
(function ($) {
    "use strict";

    var _el = document.getElementById("mealsdb-task-detail-data");
    var data = _el ? JSON.parse(_el.textContent || "{}") : {};

    var nonce   = data.nonce || "";
    var ajaxUrl = data.ajaxUrl || "";
    var taskId  = data.taskId || 0;
    var backUrl = data.baseUrl || "";
    var schema  = data.formSchema || {};
    var payload = data.payload || {};
    var i18n    = data.i18n || {};

    if (window.MealsDBTaskForm) {
        // Values (pre-filled from payload) and the full payload (for
        // repeat_group items_from resolution) are the same object here.
        window.MealsDBTaskForm.render("#mealsdb-task-form", schema, payload, payload);
    }

    function setStatus(msg, type) {
        var $s = $("#mealsdb-task-detail-status");
        $s.removeClass("notice-success notice-error notice-warning").addClass("notice-" + (type || "info"));
        $s.empty().append($("<p>").text(msg)).show(); // .text() — never inject server msg as HTML
    }

    $("#mealsdb-task-complete").on("click", function () {
        if (!window.MealsDBTaskForm) return;
        var formData = window.MealsDBTaskForm.collect("#mealsdb-task-form", schema);
        $.post(ajaxUrl, {
            action: "mealsdb_tasks_complete",
            nonce: nonce,
            task_id: taskId,
            form_data: JSON.stringify(formData)
        }).done(function (resp) {
            if (resp && resp.success) {
                window.location.href = backUrl;
            } else {
                setStatus((resp && resp.data && resp.data.message) || i18n.failComplete || "Failed to complete.", "error");
            }
        }).fail(function (xhr) {
            setStatus((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || i18n.failComplete || "Failed to complete.", "error");
        });
    });

    function defer(newDate) {
        $.post(ajaxUrl, {
            action: "mealsdb_tasks_defer",
            nonce: nonce,
            task_id: taskId,
            new_date: newDate || ""
        }).done(function (resp) {
            if (resp && resp.success) {
                window.location.href = backUrl;
            } else {
                setStatus((resp && resp.data && resp.data.message) || i18n.failDefer || "Failed to defer.", "error");
            }
        }).fail(function () {
            setStatus(i18n.failDefer || "Failed to defer.", "error");
        });
    }

    $("#mealsdb-task-defer-1").on("click", function () { defer(""); });
    $("#mealsdb-task-defer-custom").on("click", function () {
        var d = $("#mealsdb-task-defer-date").val();
        if (!d) { setStatus(i18n.pickDate || "Pick a date first.", "warning"); return; }
        defer(d);
    });

    $("#mealsdb-task-skip").on("click", function () {
        window.MealsDBConfirm.confirm({
            title: i18n.skipTitle || "Skip task",
            message: i18n.skipConfirm || "Skip this task?",
            confirmLabel: i18n.skipConfirmLabel || "Skip"
        }).then(function (ok) {
            if (!ok) { return; }
            $.post(ajaxUrl, {
                action: "mealsdb_tasks_skip",
                nonce: nonce,
                task_id: taskId
            }).done(function (resp) {
                if (resp && resp.success) {
                    window.location.href = backUrl;
                } else {
                    setStatus((resp && resp.data && resp.data.message) || i18n.failSkip || "Failed to skip.", "error");
                }
            }).fail(function () {
                setStatus(i18n.failSkip || "Failed to skip.", "error");
            });
        });
    });
})(jQuery);
