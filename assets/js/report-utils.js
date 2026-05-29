/**
 * Shared helpers for report views (fee reconciliation, order errors, etc.).
 *
 * Exposes window.MealsDBReport so each view's script can depend on this
 * file via wp_enqueue_script() and reuse a single implementation of the
 * CSV-injection quoting rules. Previously each view inlined its own
 * csvCell()/csvRow()/exportCsv() — duplicated code means any fix to the
 * quoting rules has to be made in N places, and the review that led to
 * this refactor caught exactly that drift risk.
 */
(function ($) {
    'use strict';

    // HTML-escape a value for safe insertion via .html(). Uses the
    // DOM's own textContent → innerHTML round-trip so we never have to
    // maintain our own entity table.
    function esc(str) {
        var d = document.createElement('div');
        d.textContent = str == null ? '' : String(str);
        return d.innerHTML;
    }

    // Format a numeric value as "%.2f". Returns "NaN" for garbage
    // input, which matches parseFloat's behaviour and makes bad data
    // visible instead of silently becoming 0.00.
    function fmt(val) {
        return parseFloat(val).toFixed(2);
    }

    // Render a single CSV cell. Two concerns:
    //   1. Excel/LibreOffice formula injection — any cell whose first
    //      char is =, +, -, @, tab, or CR is re-evaluated when the
    //      file is opened. Prefix a single quote to force literal
    //      interpretation. The spreadsheet hides the leading quote.
    //   2. RFC 4180 quoting — any cell that contains a double-quote,
    //      comma, CR, or LF must be wrapped in "..." with internal
    //      double-quotes doubled up. Without this, a client name like
    //      "Smith, John" splits into two cells and corrupts the row.
    var FORMULA_TRIGGERS = '=+-@\t\r';
    // QW-3: a well-formed number (incl. negative money like -10.24) is not a
    // formula — exempt it from the leading-char guard so negative amounts
    // aren't corrupted into text ('-10.24). Mirrors MealsDB_CSV::NUMERIC_VALUE.
    // Anchored: "-2+3" is NOT numeric and stays quoted as an injection vector.
    var NUMERIC_VALUE = /^[-+]?\d+(\.\d+)?$/;
    function csvCell(value) {
        if (value === null || value === undefined) {
            return '';
        }
        var str = String(value);
        if (str.length
            && !NUMERIC_VALUE.test(str)
            && FORMULA_TRIGGERS.indexOf(str.charAt(0)) !== -1) {
            str = "'" + str;
        }
        if (/[",\r\n]/.test(str)) {
            str = '"' + str.replace(/"/g, '""') + '"';
        }
        return str;
    }

    function csvRow(cells) {
        return cells.map(csvCell).join(',') + '\n';
    }

    // Trigger a browser download for a string of CSV content.
    function exportCsv(csvString, filename) {
        var blob = new Blob([csvString], { type: 'text/csv;charset=utf-8;' });
        var url  = URL.createObjectURL(blob);
        var a    = document.createElement('a');
        a.href     = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    // Render a message into a `<div class="notice">` container,
    // swapping the notice-{info,success,warning,error} state class.
    function showStatus($container, msg, type) {
        $container.show()
            .removeClass('notice-info notice-success notice-error notice-warning')
            .addClass('notice-' + type)
            .html($('<p>').text(msg == null ? '' : String(msg)));
    }

    window.MealsDBReport = {
        esc: esc,
        fmt: fmt,
        csvCell: csvCell,
        csvRow: csvRow,
        exportCsv: exportCsv,
        showStatus: showStatus
    };
})(jQuery);
