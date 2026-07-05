/**
 * Task list view — bulk skip/defer actions.
 *
 * Extracted verbatim from the inline <script> formerly in views/tasks-list.php
 * (per CLAUDE.md's ban on inline <script> logic blocks > 20 lines). The only
 * change is that server-side values (nonce, ajaxUrl, i18n strings) are now read
 * from the JSON data island #mealsdb-tasks-list-data instead of being
 * interpolated from PHP.
 *
 * Behaviour is unchanged: checkbox selection drives the enabled state of the two
 * bulk buttons, which POST mealsdb_tasks_bulk_skip / mealsdb_tasks_bulk_defer
 * and reload on success.
 *
 * @package MealsDB
 */
(function ($) {
    'use strict';

    var _el = document.getElementById('mealsdb-tasks-list-data');
    var data = _el ? JSON.parse(_el.textContent || '{}') : {};

    var nonce = data.nonce || '';
    var ajaxUrl = data.ajaxUrl || '';

    function collectSelected() {
        return $('.mealsdb-task-select:checked').map(function () { return this.value; }).get();
    }

    function refreshBulkState() {
        var any = collectSelected().length > 0;
        $('#mealsdb-tasks-bulk-skip,#mealsdb-tasks-bulk-defer').prop('disabled', !any);
    }

    $('.mealsdb-select-all').on('change', function () {
        var checked = this.checked;
        $(this).closest('table').find('.mealsdb-task-select').prop('checked', checked);
        refreshBulkState();
    });

    $(document).on('change', '.mealsdb-task-select', refreshBulkState);

    $('#mealsdb-tasks-bulk-skip').on('click', function () {
        var ids = collectSelected();
        if (!ids.length) return;
        if (!confirm(data.confirmSkip || 'Skip selected tasks?')) return;
        $.post(ajaxUrl, {
            action: 'mealsdb_tasks_bulk_skip',
            nonce: nonce,
            task_ids: JSON.stringify(ids)
        }).done(function (resp) {
            window.location.reload();
        }).fail(function () {
            alert(data.skipFailed || 'Bulk skip failed.');
        });
    });

    $('#mealsdb-tasks-bulk-defer').on('click', function () {
        var ids = collectSelected();
        if (!ids.length) return;
        $.post(ajaxUrl, {
            action: 'mealsdb_tasks_bulk_defer',
            nonce: nonce,
            task_ids: JSON.stringify(ids)
        }).done(function (resp) {
            window.location.reload();
        }).fail(function () {
            alert(data.deferFailed || 'Bulk defer failed.');
        });
    });
})(jQuery);
