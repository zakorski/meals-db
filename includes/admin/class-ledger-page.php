<?php
/**
 * Receivables — off-cycle payment entry + outstanding balances (K17 ITEM 4).
 *
 * A deliberately small operator surface: record a private client's off-cycle
 * payment (cheque / e-transfer) and a government program remittance against an
 * invoice (partials welcome), and see who currently carries a balance. Aging
 * (30/60/90) and FIFO application are deferred by the directive; this is entry
 * + current balances only. Financial → gated at manage_options.
 *
 * Author: Fishhorn Design
 * Licensed under the GNU General Public License v3.0 or later.
 */
defined('ABSPATH') || exit;

class MealsDB_Ledger_Page {

    public const PAGE_SLUG = 'mealsdb-receivables';

    public static function init(): void {
        add_action('admin_menu', [self::class, 'register_menu'], 22);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_scripts']);
    }

    public static function register_menu(): void {
        add_submenu_page(
            'mealsdb',
            __('Receivables', 'meals-db'),
            __('Receivables', 'meals-db'),
            'manage_options', // financial tier
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    public static function enqueue_scripts($hook): void {
        if (!is_string($hook) || strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }
        wp_enqueue_script(
            'mealsdb-ledger-js',
            plugins_url('assets/js/ledger.js', dirname(dirname(__FILE__))),
            ['jquery'],
            defined('MEALS_DB_VERSION') ? MEALS_DB_VERSION : false,
            true
        );
        wp_localize_script('mealsdb-ledger-js', 'mealsdbLedger', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(MealsDB_Ajax_Ledger::NONCE_ACTION),
            'i18n'    => [
                'saving'  => __('Saving…', 'meals-db'),
                'saved'   => __('Recorded.', 'meals-db'),
                'error'   => __('Something went wrong. Please try again.', 'meals-db'),
            ],
        ]);
    }

    public static function render(): void {
        MealsDB_Permissions::enforce();
        // Financial surface — admin only, even if the plugin's baseline cap is
        // lower (mirrors the reconciliation reports).
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to view receivables.', 'meals-db'));
        }

        echo '<div class="wrap mealsdb-receivables">';
        echo '<h1>' . esc_html__('Receivables', 'meals-db') . '</h1>';
        echo '<p class="description">' . esc_html__('Record off-cycle payments and remittances, and see current balances. Charges post automatically when a weekly audit or an invoice is finalized.', 'meals-db') . '</p>';

        self::render_forms();
        self::render_balances();

        echo '</div>';
    }

    private static function render_forms(): void {
        echo '<div style="display:flex;gap:32px;flex-wrap:wrap;margin:16px 0;">';

        // Client off-cycle payment.
        echo '<div style="min-width:320px;"><h2>' . esc_html__('Client payment', 'meals-db') . '</h2>';
        echo '<p class="description">' . esc_html__('A private client\'s off-cycle payment (cheque, e-transfer). Reduces their balance.', 'meals-db') . '</p>';
        echo '<p><label>' . esc_html__('Client ID', 'meals-db') . '<br>'
            . '<input type="number" min="1" id="mrl-client-id" class="regular-text" style="width:140px;" /></label></p>';
        echo '<p><label>' . esc_html__('Amount ($)', 'meals-db') . '<br>'
            . '<input type="text" id="mrl-client-amount" class="regular-text" style="width:140px;" placeholder="0.00" /></label></p>';
        echo '<p><label>' . esc_html__('Date', 'meals-db') . '<br>'
            . '<input type="date" id="mrl-client-date" /></label></p>';
        echo '<p><label>' . esc_html__('Method', 'meals-db') . '<br>'
            . '<input type="text" id="mrl-client-method" placeholder="cheque / etransfer / cash" class="regular-text" style="width:220px;" /></label></p>';
        echo '<p><label>' . esc_html__('Reference', 'meals-db') . '<br>'
            . '<input type="text" id="mrl-client-reference" placeholder="' . esc_attr__('cheque no. / e-transfer id', 'meals-db') . '" class="regular-text" style="width:220px;" /></label></p>';
        echo '<p><button type="button" class="button button-primary" id="mrl-client-save">' . esc_html__('Record payment', 'meals-db') . '</button> '
            . '<span id="mrl-client-result" style="margin-left:10px;"></span></p>';
        echo '</div>';

        // Program remittance.
        echo '<div style="min-width:320px;"><h2>' . esc_html__('Program remittance', 'meals-db') . '</h2>';
        echo '<p class="description">' . esc_html__('A government remittance against an invoice. A partial remittance is normal — enter the draft ID it pays.', 'meals-db') . '</p>';
        echo '<p><label>' . esc_html__('Program', 'meals-db') . '<br>'
            . '<select id="mrl-prog-payer"><option value="SDNB">SDNB</option><option value="VAC">VAC</option></select></label></p>';
        echo '<p><label>' . esc_html__('Invoice draft ID', 'meals-db') . '<br>'
            . '<input type="number" min="0" id="mrl-prog-draft" class="regular-text" style="width:140px;" placeholder="' . esc_attr__('(optional — on account)', 'meals-db') . '" /></label></p>';
        echo '<p><label>' . esc_html__('Amount ($)', 'meals-db') . '<br>'
            . '<input type="text" id="mrl-prog-amount" class="regular-text" style="width:140px;" placeholder="0.00" /></label></p>';
        echo '<p><label>' . esc_html__('Date', 'meals-db') . '<br>'
            . '<input type="date" id="mrl-prog-date" /></label></p>';
        echo '<p><label>' . esc_html__('Reference', 'meals-db') . '<br>'
            . '<input type="text" id="mrl-prog-reference" class="regular-text" style="width:220px;" /></label></p>';
        echo '<p><button type="button" class="button button-primary" id="mrl-prog-save">' . esc_html__('Record remittance', 'meals-db') . '</button> '
            . '<span id="mrl-prog-result" style="margin-left:10px;"></span></p>';
        echo '</div>';

        echo '</div>';
    }

    private static function render_balances(): void {
        $ledger   = new MealsDB_Ledger();
        $balances = $ledger->balances_by_payer(true);

        echo '<h2>' . esc_html__('Outstanding balances', 'meals-db') . '</h2>';
        if (empty($balances)) {
            echo '<p><em>' . esc_html__('No outstanding balances.', 'meals-db') . '</em></p>';
            return;
        }

        // Resolve client names in one query for the client payers.
        $names = self::client_names($balances);

        echo '<table class="widefat striped" style="max-width:640px;"><thead><tr>';
        echo '<th>' . esc_html__('Payer', 'meals-db') . '</th>';
        echo '<th style="text-align:right;">' . esc_html__('Balance', 'meals-db') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($balances as $b) {
            $label = ($b['payer_type'] === MealsDB_Ledger::PAYER_PROGRAM)
                ? esc_html($b['payer_id'])
                : esc_html(($names[$b['payer_id']] ?? ('Client #' . $b['payer_id'])) . ' (#' . $b['payer_id'] . ')');
            $amt = MealsDB_Money::format((int) $b['balance_cents']);
            $color = ((int) $b['balance_cents']) > 0 ? '#b32d2e' : '#1a7f37'; // owing vs credit
            echo '<tr><td>' . $label . '</td>'
                . '<td style="text-align:right;color:' . esc_attr($color) . ';">$' . esc_html($amt) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * Batch-resolve display names for the CLIENT payers in a balances list.
     *
     * @param array<int,array{payer_type:string,payer_id:string,balance_cents:int}> $balances
     * @return array<string,string> client_id => "First Last"
     */
    private static function client_names(array $balances): array {
        $ids = [];
        foreach ($balances as $b) {
            if (($b['payer_type'] ?? '') === MealsDB_Ledger::PAYER_CLIENT && ctype_digit((string) $b['payer_id'])) {
                $ids[] = (int) $b['payer_id'];
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) {
            return [];
        }
        global $wpdb;
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT client_id, first_name, last_name FROM `{$table}` WHERE client_id IN ({$placeholders})",
            ...$ids
        ), ARRAY_A);
        $out = [];
        foreach ((array) $rows as $r) {
            $name = trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? ''));
            $out[(string) $r['client_id']] = $name !== '' ? $name : ('Client #' . $r['client_id']);
        }
        return $out;
    }
}
