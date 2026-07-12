/**
 * Client form — live zone→delivery-day display (spec 2026-07-11).
 *
 * delivery_day is zone-derived and read-only; this keeps the display in
 * sync as the operator changes the zone select. The server re-derives
 * authoritatively on save — this is purely cosmetic.
 */
(function ($) {
    'use strict';

    var el = document.getElementById('mealsdb-zone-day-data');
    if (!el) { return; }
    var zones = {};
    try { zones = JSON.parse(el.textContent || '{}'); } catch (e) { zones = {}; }

    function render() {
        var $out = $('#mealsdb-zone-day-display');
        if (!$out.length) { return; }
        var zone = String($('#delivery_area_name').val() || '');
        var cfg  = zones[zone];
        if (cfg && cfg.day) {
            $out.text(cfg.day + (cfg.label ? ' — ' + cfg.label : ''));
        } else if (zone !== '') {
            $out.text('⚠ zone not in schedule');
        } else {
            $out.text('—');
        }
    }

    $(document).on('change', '#delivery_area_name', render);
    $(render);
})(jQuery);
