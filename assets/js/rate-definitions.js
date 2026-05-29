/**
 * Rate Definitions admin page (directive DEFINITIONS-1).
 *
 * Thin view over the single save endpoint. The Save button is gated on the
 * confirmation checkbox (confirmation friction — a fat-fingered rate is a
 * wrong government invoice). All state is server-side (the option); on success
 * we update the inputs from the returned effective set.
 *
 * @package MealsDB
 */
(function ($) {
    'use strict';

    var cfg = window.mealsdbRateDefinitions || {};
    var i18n = cfg.i18n || {};

    $(document).ready(function () {
        var $confirm = $('#mealsdb-rate-confirm');
        var $btn = $('#mealsdb-rate-save-btn');
        var $msg = $('#mealsdb-rate-save-msg');

        // Gate the Save button on the confirmation checkbox.
        $confirm.on('change', function () {
            $btn.prop('disabled', !$(this).is(':checked'));
        });

        $btn.on('click', function () {
            if (!$confirm.is(':checked')) {
                $msg.text(i18n.confirm || 'Please confirm.');
                return;
            }

            var rates = {};
            $('.mealsdb-rate-input').each(function () {
                rates[$(this).data('key')] = $(this).val();
            });

            $btn.prop('disabled', true);
            $msg.text(i18n.saving || 'Saving…');

            $.post(cfg.ajaxUrl, {
                action: 'mealsdb_save_rate_definitions',
                nonce: cfg.nonce,
                confirm: '1',
                rates: rates
            }).done(function (resp) {
                if (resp && resp.success && resp.data) {
                    // Reflect the canonical stored values back into the inputs.
                    if (resp.data.rates) {
                        $('.mealsdb-rate-input').each(function () {
                            var key = $(this).data('key');
                            if (resp.data.rates[key] !== undefined) {
                                $(this).val(Number(resp.data.rates[key]).toFixed(2));
                            }
                        });
                    }
                    $msg.text((i18n.saved || 'Saved.') + ' (' + (resp.data.changed || 0) + ')');
                    // Require a fresh confirmation for the next save.
                    $confirm.prop('checked', false);
                    $btn.prop('disabled', true);
                } else {
                    $msg.text((resp && resp.data && resp.data.message) || i18n.genericErr);
                    $btn.prop('disabled', false);
                }
            }).fail(function (xhr) {
                var message = i18n.genericErr;
                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    message = xhr.responseJSON.data.message;
                }
                $msg.text(message);
                $btn.prop('disabled', false);
            });
        });
    });

})(jQuery);
