/* Receivables — off-cycle payment entry (K17 ITEM 4). Posts the two entry
 * forms to the ledger AJAX endpoints and reflects the new balance. Reload for
 * the refreshed outstanding-balances table (kept simple — entry is the job). */
(function ($) {
    'use strict';
    var cfg = window.mealsdbLedger || {};
    var i18n = cfg.i18n || {};

    function tint($el, ok) { $el.css('color', ok ? '#1a7f37' : '#b32d2e'); }

    function submit(action, data, $btn, $result) {
        data.action = action;
        data.nonce = cfg.nonce;
        $btn.prop('disabled', true);
        $result.text(i18n.saving || 'Saving…');
        tint($result, true);
        $.post(cfg.ajaxUrl, data).done(function (resp) {
            if (resp && resp.success) {
                var bal = resp.data && resp.data.balance ? ' (balance $' + resp.data.balance + ')' : '';
                $result.text((i18n.saved || 'Recorded.') + bal);
                tint($result, true);
            } else {
                $result.text((resp && resp.data && resp.data.message) || i18n.error || 'Error');
                tint($result, false);
            }
        }).fail(function () {
            $result.text(i18n.error || 'Error');
            tint($result, false);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    }

    $(function () {
        $('#mrl-client-save').on('click', function () {
            submit('mealsdb_ledger_client_payment', {
                client_id: $('#mrl-client-id').val(),
                amount: $('#mrl-client-amount').val(),
                date: $('#mrl-client-date').val(),
                method: $('#mrl-client-method').val(),
                reference: $('#mrl-client-reference').val()
            }, $(this), $('#mrl-client-result'));
        });

        $('#mrl-prog-save').on('click', function () {
            submit('mealsdb_ledger_program_remittance', {
                payer_id: $('#mrl-prog-payer').val(),
                draft_id: $('#mrl-prog-draft').val(),
                amount: $('#mrl-prog-amount').val(),
                date: $('#mrl-prog-date').val(),
                reference: $('#mrl-prog-reference').val()
            }, $(this), $('#mrl-prog-result'));
        });
    });
})(jQuery);
