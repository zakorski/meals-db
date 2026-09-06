/**
 * Ignored Conflicts admin view.
 *
 * Extracted from an inline <script> block in views/ignored.php per the
 * CLAUDE.md rule against inline logic blocks > 20 lines. Behavior is a
 * verbatim port: the only values the inline script interpolated from PHP
 * (the nonce, and the fail-message string) now come from the JSON data
 * island #mealsdb-ignored-data. The AJAX action, selectors, event
 * binding, and DOM manipulation are unchanged.
 */
(function ($) {
    "use strict";

    var _el = document.getElementById("mealsdb-ignored-data");
    var data = _el ? JSON.parse(_el.textContent || "{}") : {};

    // ajaxurl is defined globally in wp-admin; prefer the island value but
    // fall back to the WP global so a missing key can't break the button.
    var ajaxUrl = data.ajaxUrl || window.ajaxurl;
    var failMessage = data.failMessage || "Failed to unignore.";

    $(document).ready(function () {
        $('.unignore-btn').on('click', function () {
            const $btn = $(this);
            const rowId = $btn.data('id');
            const field = $btn.data('field');
            const source = $btn.data('source');
            const target = $btn.data('target');

            $.post(ajaxUrl, {
                action: 'mealsdb_toggle_ignore',
                nonce: data.nonce,
                field: field,
                source: source,
                target: target,
                ignored: false
            }, function (response) {
                if (response.success) {
                    $('#ignore-row-' + rowId).fadeOut();
                } else {
                    window.MealsDBNotice('error', failMessage);
                }
            });
        });
    });
})(jQuery);
