/**
 * Shared on-page notice renderer (directive GUI-NOTICES).
 *
 * Replaces disruptive native window.alert() popups for INFORMATIONAL messages
 * (errors + successes) with dismissible, accessible WP-admin-styled notices.
 * Lives as a global (window.MealsDBNotice) so every plugin admin script can
 * call it; enqueued as a dependency of those scripts so it is always present.
 *
 * Why on-page beats alert(): alert() blocks the page, vanishes on dismiss,
 * isn't tied to the field, and is invisible to screen readers. The
 * role="status"/aria-live="polite" region below is the accessibility win and
 * must be preserved. The 7 native confirm() guards are intentionally NOT
 * routed through here (see the directive's "Out of scope").
 *
 * @package MealsDB
 */
(function (w, $) {
    // Renders a dismissible on-page notice. Finds a container in priority order,
    // else injects one at the top of the plugin page wrap.
    function mealsNotice(level, message, opts) {
        opts = opts || {};
        var cls = { success: 'notice-success', error: 'notice-error',
                    warning: 'notice-warning', info: 'notice-info' }[level] || 'notice-info';
        // Preferred explicit target, else a known status region, else the WP page heading.
        var $target = opts.$target
            || $('#mealsdb-notice-region').first()
            || $('.mealsdb-status').first();
        var $wrap = ($target && $target.length) ? $target : $('.wrap').first();
        // Build the notice (WP admin notice styling) with an aria-live region for accessibility.
        var $n = $('<div>', { 'class': 'notice ' + cls + ' is-dismissible mealsdb-notice',
                              'role': 'status', 'aria-live': 'polite' })
                   .append($('<p>').text(message));
        if (opts.$target && opts.$target.length) { opts.$target.empty().append($n).show(); }
        else { $wrap.prepend($n); }
        // Auto-dismiss successes/infos after a few seconds; keep errors/warnings until dismissed.
        if (level === 'success' || level === 'info') {
            w.setTimeout(function () { $n.fadeOut(300, function () { $(this).remove(); }); }, 4000);
        }
        // Clicking anywhere on the notice (or its dismiss button) removes it.
        $n.on('click', function () { $(this).remove(); });
        return $n;
    }
    w.MealsDBNotice = mealsNotice;            // global accessor for all plugin scripts
})(window, jQuery);
