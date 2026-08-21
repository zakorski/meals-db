<?php
/**
 * Purchase Orders tab — draft workflow list + detail (spec 2026-07-10).
 *
 * Lifecycle (status ENUM value → operator label):
 *   planned=Draft → placed=Approved → arrived=Received → reconciled,
 *   with cancelled available from Draft. Legacy task-created POs
 *   (payload IS NULL) render read-only; the deleted legacy task chain
 *   (place_po / confirm_po_arrival / physical_count) no longer exists —
 *   legacy POs are display-only and their lifecycle is considered closed.
 *
 * Interactivity lives in assets/js/purchase-orders.js, fed by the JSON
 * island #mealsdb-po-admin-data (no inline script blocks).
 *
 * @package MealsDB
 */
defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

$status_filter = isset($_GET['po_status']) ? sanitize_key(wp_unslash((string) $_GET['po_status'])) : '';
$po_id  = isset($_GET['po_id']) ? (int) $_GET['po_id'] : 0;
$action = isset($_GET['action']) ? sanitize_key(wp_unslash((string) $_GET['action'])) : '';

$service  = new MealsDB_Purchase_Orders();
$base_url = admin_url('admin.php?page=mealsdb-purchase-orders');

/** Render the shared JSON island + wrap-up for JS. */
$mealsdb_po_render_island = static function (array $extra = []) use ($base_url): void {
    $island = array_merge([
        'nonce'       => wp_create_nonce(MealsDB_Ajax_Purchase_Orders::NONCE_ACTION),
        'ajaxUrl'     => admin_url('admin-ajax.php'),
        'baseUrl'     => $base_url,
        'targetWeeks' => MealsDB_Purchase_Orders::COVERAGE_TARGET_WEEKS,
        'floorWeeks'  => MealsDB_Purchase_Orders::COVERAGE_FLOOR_WEEKS,
        'i18n'        => [
            'confirmApprove'   => __('Approve this purchase order? Approved orders are locked (un-approve requires an audited reason).', 'meals-db'),
            'confirmAccept'    => __('Mark this purchase order as accepted? The vendor has confirmed it, so ordered quantities will be ADDED to inventory now.', 'meals-db'),
            'confirmReceive'   => __('Mark this purchase order as received? Stock was already committed at Accept — this only records arrival.', 'meals-db'),
            'confirmCancel'    => __('Cancel this draft purchase order?', 'meals-db'),
            'confirmComplete'  => __('Complete reconciliation? Stock will be corrected for every adjusted row and the purchase order will be locked.', 'meals-db'),
            'promptExpectedArrival' => __('Expected arrival date (YYYY-MM-DD), or leave blank for the computed default — OK approves:', 'meals-db'),
            'invalidDate'      => __('Enter a date as YYYY-MM-DD, or leave blank for the default.', 'meals-db'),
            'promptUnapprove'  => __('Enter a reason for un-approving (required — it is audited):', 'meals-db'),
            'promptUnaccept'   => __('Enter a reason for un-accepting (required — it reverses inventory and is audited):', 'meals-db'),
            'reasonRequired'   => __('A reason is required.', 'meals-db'),
            'noteRequired'     => __('A note is required for adjusted rows.', 'meals-db'),
            'requestFailed'    => __('Request failed.', 'meals-db'),
            'saving'           => __('Saving…', 'meals-db'),
            'generating'       => __('Generating…', 'meals-db'),
            'draftSaveFailed'  => __('Could not save the draft purchase order.', 'meals-db'),
            'was'              => __('was: %s', 'meals-db'),
            'belowTarget'      => __('Below 9-week coverage target (%s wks)', 'meals-db'),
            'belowFloor'       => __('Below 7-week safety floor (%s wks)', 'meals-db'),
        ],
    ], $extra);
    echo '<script type="application/json" id="mealsdb-po-admin-data">'
        . wp_json_encode($island, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . '</script>';
};

/** Coverage cell HTML: number + warning badge. Shared by draft + reconcile renders. */
$mealsdb_po_coverage_cell = static function (?float $weeks): string {
    if ($weeks === null) {
        return '<td class="mealsdb-po-coverage" data-coverage="">&mdash;</td>';
    }
    $badge = '';
    if ($weeks < MealsDB_Purchase_Orders::COVERAGE_FLOOR_WEEKS) {
        $badge = '<span class="mealsdb-po-flag mealsdb-po-crit" title="'
            . esc_attr(sprintf(__('Below 7-week safety floor (%s wks)', 'meals-db'), number_format_i18n($weeks, 1)))
            . '">!</span>';
    } elseif ($weeks < MealsDB_Purchase_Orders::COVERAGE_TARGET_WEEKS) {
        $badge = '<span class="mealsdb-po-flag mealsdb-po-warn" title="'
            . esc_attr(sprintf(__('Below 9-week coverage target (%s wks)', 'meals-db'), number_format_i18n($weeks, 1)))
            . '">!</span>';
    }
    return '<td class="mealsdb-po-coverage" data-coverage="' . esc_attr((string) $weeks) . '">'
        . esc_html(number_format_i18n($weeks, 1)) . ' ' . $badge . '</td>';
};

// ===========================================================================
// Detail view
// ===========================================================================
if ($po_id > 0) {
    $po = $service->get_with_payload($po_id);
    if ($po === null) {
        echo '<div class="notice notice-error"><p>' . esc_html__('Purchase order not found.', 'meals-db') . '</p></div>';
        echo '<p><a class="button" href="' . esc_url($base_url) . '">&larr; ' . esc_html__('Back to list', 'meals-db') . '</a></p>';
        return;
    }

    $is_workflow = is_array($po['payload']);
    $status      = (string) $po['status'];
    $mode        = 'locked';
    if ($is_workflow && $status === MealsDB_Purchase_Orders::STATUS_PLANNED) {
        $mode = 'draft';
    } elseif ($is_workflow && $status === MealsDB_Purchase_Orders::STATUS_ARRIVED && $action === 'reconcile') {
        $mode = 'reconcile';
    }

    $engine        = class_exists('MealsDB_Task_Engine') ? new MealsDB_Task_Engine() : null;
    $related_tasks = $engine ? $engine->query_tasks([
        'related_entity_type' => 'po',
        'related_entity_id'   => $po_id,
        'status'              => ['pending', 'in_progress', 'deferred', 'completed', 'skipped', 'abandoned'],
    ]) : [];
    ?>
    <div id="mealsdb-po-detail" class="mealsdb-po-detail" data-mode="<?php echo esc_attr($mode); ?>" data-po-id="<?php echo (int) $po_id; ?>">
        <p><a class="button" href="<?php echo esc_url($base_url); ?>">&larr; <?php esc_html_e('Back to list', 'meals-db'); ?></a></p>
        <h2>
            <?php echo esc_html(sprintf(__('Purchase Order %s', 'meals-db'), $po['po_number'])); ?>
            <span class="mealsdb-po-status mealsdb-po-status-<?php echo esc_attr($status); ?>">
                <?php echo esc_html(MealsDB_Purchase_Orders::status_label($status)); ?>
            </span>
        </h2>

        <?php if ($is_workflow && $mode === 'locked' && $status === MealsDB_Purchase_Orders::STATUS_PLACED): ?>
            <div class="notice notice-info"><p><?php esc_html_e('This purchase order is approved and is shown read-only. Accept it (vendor confirmed) to commit inventory, or un-approve to make changes.', 'meals-db'); ?></p></div>
        <?php elseif ($is_workflow && $mode === 'locked' && $status === MealsDB_Purchase_Orders::STATUS_ACCEPTED): ?>
            <div class="notice notice-info"><p><?php esc_html_e('This purchase order is accepted and its stock is committed. Mark it received when it arrives, or un-accept to reverse the inventory commit.', 'meals-db'); ?></p></div>
        <?php elseif ($is_workflow && $mode === 'locked' && $status === MealsDB_Purchase_Orders::STATUS_ARRIVED): ?>
            <div class="notice notice-info"><p><?php esc_html_e('This purchase order has been received. Open Reconcile to record what actually arrived.', 'meals-db'); ?></p></div>
        <?php elseif ($mode === 'reconcile'): ?>
            <div class="notice notice-warning"><p><?php esc_html_e('Reconcile mode: adjust the received case counts with the +/− buttons. Any adjusted row requires a note (e.g. "Two cases damaged in transit"). Stock is corrected only when you complete the reconciliation.', 'meals-db'); ?></p></div>
        <?php elseif (!$is_workflow): ?>
            <div class="notice notice-info"><p><?php esc_html_e('This purchase order was created by the task workflow and is shown read-only here.', 'meals-db'); ?></p></div>
        <?php endif; ?>

        <table class="form-table" role="presentation">
            <tbody>
                <tr><th><?php esc_html_e('Supplier', 'meals-db'); ?></th>
                    <td><?php echo esc_html((string) ($po['supplier'] ?? '')); ?></td></tr>
                <tr><th><?php esc_html_e('Placed Date', 'meals-db'); ?></th>
                    <td><?php echo esc_html((string) ($po['placed_date'] ?? '—')); ?></td></tr>
                <tr><th><?php esc_html_e('Accepted', 'meals-db'); ?></th>
                    <td><?php echo esc_html((string) ($po['accepted_at'] ?? '—')); ?></td></tr>
                <tr><th><?php esc_html_e('Expected Arrival', 'meals-db'); ?></th>
                    <td><?php echo esc_html((string) ($po['expected_arrival'] ?? '—')); ?></td></tr>
                <tr><th><?php esc_html_e('Arrival', 'meals-db'); ?></th>
                    <td><?php echo esc_html((string) ($po['arrival_date'] ?? '—')); ?></td></tr>
                <tr><th><?php esc_html_e('Reconciled', 'meals-db'); ?></th>
                    <td><?php echo esc_html((string) ($po['reconciled_at'] ?? '—')); ?></td></tr>
                <?php if ($is_workflow): ?>
                <tr><th><?php esc_html_e('Edits', 'meals-db'); ?></th>
                    <td id="mealsdb-po-edit-count"><?php echo (int) ($po['edit_count'] ?? 0); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php
        if ($is_workflow) {
            $sched_base = !empty($po['placed_date']) ? (string) $po['placed_date'] : gmdate('Y-m-d');
            $sched = MealsDB_Purchase_Orders::po_schedule_from_order_date($sched_base);
            if ($sched !== null):
                $sched_is_preview = empty($po['placed_date']);
        ?>
        <table class="form-table mealsdb-po-schedule" role="presentation">
            <tbody>
                <tr><th colspan="2"><h3 style="margin:0;">
                    <?php echo $sched_is_preview
                        ? esc_html__('Order schedule (preview — set at approval)', 'meals-db')
                        : esc_html__('Order schedule', 'meals-db'); ?>
                </h3></th></tr>
                <tr><th><?php esc_html_e('Order date (T)', 'meals-db'); ?></th>
                    <td><?php echo esc_html($sched['order_date']);
                        if ($sched['is_off_cycle']): ?>
                        <span class="mealsdb-po-flag mealsdb-po-warn" title="<?php esc_attr_e('Off-cycle: order date is not a Tuesday', 'meals-db'); ?>">!</span>
                        <em><?php esc_html_e('off-cycle (not a Tuesday)', 'meals-db'); ?></em>
                        <?php endif; ?></td></tr>
                <tr><th><?php esc_html_e('Inventory in system by (T+8)', 'meals-db'); ?></th>
                    <td><?php echo esc_html($sched['inventory_due']); ?></td></tr>
                <tr><th><?php esc_html_e('Apetito ships (T+10)', 'meals-db'); ?></th>
                    <td><?php echo esc_html($sched['ship_date']); ?></td></tr>
                <tr><th><?php esc_html_e('Expected arrival (T+13)', 'meals-db'); ?></th>
                    <td><?php echo esc_html($sched['expected_arrival']); ?></td></tr>
                <tr><th><?php esc_html_e('Next order (T+28)', 'meals-db'); ?></th>
                    <td><?php echo esc_html($sched['next_order_date']); ?></td></tr>
            </tbody>
        </table>
        <?php endif; } ?>

        <?php if ($is_workflow): ?>
            <p class="mealsdb-po-detail-actions">
                <?php if ($status === MealsDB_Purchase_Orders::STATUS_PLANNED): ?>
                    <?php
                    // Source the default/bounds from the SAME schedule the panel
                    // above renders ($sched / $sched_base, computed at ~line 152)
                    // so the hint can never state a different date than the
                    // Expected arrival (T+13) the operator is looking at. The
                    // directive asked for this explicitly; recomputing '+13 days'
                    // here drifts on any PO whose order date isn't today.
                    $arr_min     = ($sched_base !== '') ? $sched_base : gmdate('Y-m-d');
                    $arr_default = ($sched !== null) ? (string) $sched['expected_arrival'] : gmdate('Y-m-d', strtotime($arr_min . ' +13 days'));
                    $arr_max     = gmdate('Y-m-d', strtotime($arr_min . ' +1 year'));
                    ?>
                    <label for="mealsdb-po-expected-arrival"><?php esc_html_e('Expected arrival:', 'meals-db'); ?></label>
                    <input type="date" id="mealsdb-po-expected-arrival"
                        min="<?php echo esc_attr($arr_min); ?>"
                        max="<?php echo esc_attr($arr_max); ?>"
                        value="<?php echo esc_attr($arr_default); ?>" />
                    <span class="description" id="mealsdb-po-arrival-hint"><?php
                        /* translators: %s: the computed default arrival date (T+13). */
                        echo esc_html(sprintf(__('Leave blank to use the computed default (%s).', 'meals-db'), $arr_default));
                    ?></span>
                    <button type="button" class="button button-primary mealsdb-po-action" data-po-action="approve" data-po-id="<?php echo (int) $po_id; ?>"><?php esc_html_e('Approve', 'meals-db'); ?></button>
                    <button type="button" class="button mealsdb-po-action" data-po-action="cancel" data-po-id="<?php echo (int) $po_id; ?>"><?php esc_html_e('Cancel draft', 'meals-db'); ?></button>
                <?php elseif ($status === MealsDB_Purchase_Orders::STATUS_PLACED): ?>
                    <button type="button" class="button button-primary mealsdb-po-action" data-po-action="accept" data-po-id="<?php echo (int) $po_id; ?>"><?php esc_html_e('Accept', 'meals-db'); ?></button>
                    <button type="button" class="button mealsdb-po-action" data-po-action="unapprove" data-po-id="<?php echo (int) $po_id; ?>"><?php esc_html_e('Un-approve', 'meals-db'); ?></button>
                <?php elseif ($status === MealsDB_Purchase_Orders::STATUS_ACCEPTED): ?>
                    <button type="button" class="button button-primary mealsdb-po-action" data-po-action="receive" data-po-id="<?php echo (int) $po_id; ?>"><?php esc_html_e('Mark received', 'meals-db'); ?></button>
                    <button type="button" class="button mealsdb-po-action" data-po-action="unaccept" data-po-id="<?php echo (int) $po_id; ?>"><?php esc_html_e('Un-accept', 'meals-db'); ?></button>
                <?php elseif ($status === MealsDB_Purchase_Orders::STATUS_ARRIVED && $mode !== 'reconcile'): ?>
                    <a class="button button-primary" href="<?php echo esc_url(add_query_arg(['po_id' => $po_id, 'action' => 'reconcile'], $base_url)); ?>"><?php esc_html_e('Reconcile', 'meals-db'); ?></a>
                <?php elseif ($mode === 'reconcile'): ?>
                    <button type="button" class="button button-primary" id="mealsdb-po-complete-reconcile" data-po-id="<?php echo (int) $po_id; ?>"><?php esc_html_e('Complete reconciliation', 'meals-db'); ?></button>
                <?php endif; ?>
                <button type="button" class="button" id="mealsdb-po-export-csv"><?php esc_html_e('Export CSV', 'meals-db'); ?></button>
                <span id="mealsdb-po-action-msg" role="status"></span>
            </p>
        <?php endif; ?>

        <h3><?php esc_html_e('Items', 'meals-db'); ?></h3>
        <?php if (!$is_workflow): ?>
            <?php if (empty($po['items'])): ?>
                <p><em><?php esc_html_e('No items on this PO.', 'meals-db'); ?></em></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr>
                        <th><?php esc_html_e('SKU', 'meals-db'); ?></th>
                        <th><?php esc_html_e('Product', 'meals-db'); ?></th>
                        <th><?php esc_html_e('Qty Ordered', 'meals-db'); ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($po['items'] as $item): ?>
                            <tr>
                                <td><?php echo esc_html((string) ($item['sku'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($item['product_name'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($item['quantity_ordered'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php else: ?>
            <?php
            $rows     = $po['payload']['current'];
            $received = is_array($po['payload']['received'] ?? null) ? $po['payload']['received'] : [];
            $generated_by_sku = [];
            foreach ($po['payload']['generated'] as $g) {
                $generated_by_sku[(string) $g['sku']] = (int) $g['cases'];
            }
            $total_cases = 0;
            $total_units = 0;
            ?>
            <table class="widefat striped mealsdb-po-grid" id="mealsdb-po-grid">
                <thead><tr>
                    <th><?php esc_html_e('SKU', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Product', 'meals-db'); ?></th>
                    <th class="num"><?php esc_html_e('Adj/Wk', 'meals-db'); ?></th>
                    <?php // Reconcile's Stock column intentionally shows the PO's
                          // GENERATION-TIME snapshot (current_stock from the draft),
                          // NOT live inventory. Reconcile confirms what the vendor
                          // shipped against what was ordered/accepted, so it must
                          // show the PO's own quantities. Do NOT switch this to a
                          // live stock read (v560 — confirmed correct by the operator). ?>
                    <th class="num"><?php esc_html_e('Stock', 'meals-db'); ?></th>
                    <th class="num"><?php esc_html_e('Case size', 'meals-db'); ?></th>
                    <th class="num"><?php echo $mode === 'reconcile' ? esc_html__('Ordered', 'meals-db') : esc_html__('Cases', 'meals-db'); ?></th>
                    <?php if ($mode === 'reconcile'): ?>
                        <th class="num"><?php esc_html_e('Received', 'meals-db'); ?></th>
                        <th><?php esc_html_e('Note (required if adjusted)', 'meals-db'); ?></th>
                    <?php endif; ?>
                    <th class="num"><?php esc_html_e('Order qty', 'meals-db'); ?></th>
                    <th class="num"><?php esc_html_e('Coverage (wks)', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Forecast note', 'meals-db'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $row):
                    $sku        = (string) $row['sku'];
                    $cases      = (int) $row['cases'];
                    if ($mode === 'reconcile' && $cases <= 0) {
                        continue; // zero-case rows were never ordered
                    }
                    $rc         = isset($received[$sku]['received_cases']) ? (int) $received[$sku]['received_cases'] : $cases;
                    $note       = isset($received[$sku]['note']) ? (string) $received[$sku]['note'] : '';
                    $shown      = $mode === 'reconcile' ? $rc : $cases;
                    $total_cases += $shown;
                    $total_units += $shown * (int) $row['case_size'];
                    $gen        = $generated_by_sku[$sku] ?? $cases;
                    ?>
                    <tr data-sku="<?php echo esc_attr($sku); ?>"
                        data-case-size="<?php echo (int) $row['case_size']; ?>"
                        data-adjusted-weekly="<?php echo esc_attr((string) $row['adjusted_weekly']); ?>"
                        data-stock="<?php echo (int) $row['current_stock']; ?>"
                        data-generated-cases="<?php echo (int) $gen; ?>"
                        data-ordered-cases="<?php echo (int) $cases; ?>">
                        <td><?php echo esc_html($sku); ?></td>
                        <td><?php echo esc_html((string) $row['product_name']); ?></td>
                        <td class="num"><?php echo esc_html(number_format_i18n((float) $row['adjusted_weekly'], 2)); ?></td>
                        <td class="num"><?php echo (int) $row['current_stock']; ?></td>
                        <td class="num"><?php echo (int) $row['case_size']; ?></td>
                        <td class="num mealsdb-po-ordered">
                            <?php if ($mode === 'draft'): ?>
                                <span class="mealsdb-po-stepper">
                                    <button type="button" class="button mealsdb-po-step" data-step="-1" aria-label="<?php esc_attr_e('One case fewer', 'meals-db'); ?>">&minus;</button>
                                    <span class="mealsdb-po-cases" data-cases="<?php echo (int) $cases; ?>"><?php echo (int) $cases; ?></span>
                                    <button type="button" class="button mealsdb-po-step" data-step="1" aria-label="<?php esc_attr_e('One case more', 'meals-db'); ?>">+</button>
                                </span>
                            <?php else: ?>
                                <?php echo (int) $cases; ?>
                            <?php endif; ?>
                            <?php if ($cases !== $gen): ?>
                                <div class="mealsdb-po-was"><?php echo esc_html(sprintf(__('was: %s', 'meals-db'), $gen)); ?></div>
                            <?php endif; ?>
                        </td>
                        <?php if ($mode === 'reconcile'): ?>
                            <td class="num">
                                <span class="mealsdb-po-stepper">
                                    <button type="button" class="button mealsdb-po-step" data-step="-1" aria-label="<?php esc_attr_e('One case fewer', 'meals-db'); ?>">&minus;</button>
                                    <span class="mealsdb-po-cases" data-cases="<?php echo (int) $rc; ?>"><?php echo (int) $rc; ?></span>
                                    <button type="button" class="button mealsdb-po-step" data-step="1" aria-label="<?php esc_attr_e('One case more', 'meals-db'); ?>">+</button>
                                </span>
                            </td>
                            <td>
                                <input type="text" class="mealsdb-po-note regular-text" maxlength="500"
                                    value="<?php echo esc_attr($note); ?>"
                                    placeholder="<?php esc_attr_e('Why does this differ?', 'meals-db'); ?>"
                                    <?php echo $rc === $cases ? 'style="display:none;"' : ''; ?> />
                            </td>
                        <?php endif; ?>
                        <td class="num mealsdb-po-orderqty"><?php echo (int) ($shown * (int) $row['case_size']); ?></td>
                        <?php echo $mealsdb_po_coverage_cell(MealsDB_Purchase_Orders::coverage_weeks($row, $shown)); // phpcs:ignore WordPress.Security.EscapeOutput -- helper escapes internally ?>
                        <td><?php echo esc_html((string) $row['seasonal_note']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr>
                    <th colspan="5"><?php esc_html_e('TOTAL', 'meals-db'); ?></th>
                    <th class="num" id="mealsdb-po-total-cases"><?php echo (int) $total_cases;
                        if (class_exists('MealsDB_Operational_Constants') && MealsDB_Operational_Constants::APETITO_CASES_PER_PALLET > 0) {
                            $per_pallet_detail = (int) MealsDB_Operational_Constants::APETITO_CASES_PER_PALLET;
                            echo ' <span id="mealsdb-po-total-pallets">(' . esc_html(number_format_i18n($total_cases / $per_pallet_detail, 2)) . ' pal)</span>';
                        } else {
                            echo ' <span id="mealsdb-po-total-pallets"></span>';
                        }
                    ?></th>
                    <?php if ($mode === 'reconcile'): ?><th></th><th></th><?php endif; ?>
                    <th class="num" id="mealsdb-po-total-units"><?php echo (int) $total_units; ?></th>
                    <th></th><th></th>
                </tr></tfoot>
            </table>
        <?php endif; ?>

        <?php if (!empty($related_tasks)): ?>
            <h3><?php esc_html_e('Related Tasks', 'meals-db'); ?></h3>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th><?php esc_html_e('Type', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Status', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Due', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Open', 'meals-db'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($related_tasks as $task): ?>
                        <?php
                        $def = MealsDB_Task_Registry::get($task['task_type']);
                        $label = $def['label'] ?? $task['task_type'];
                        $detail_url = admin_url('admin.php?page=mealsdb-tasks&action=detail&task_id=' . (int) $task['task_id']);
                        ?>
                        <tr>
                            <td><?php echo esc_html($label); ?></td>
                            <td><code><?php echo esc_html($task['status']); ?></code></td>
                            <td><?php echo esc_html($task['next_run_date']); ?></td>
                            <td><a class="button button-small" href="<?php echo esc_url($detail_url); ?>"><?php esc_html_e('Open', 'meals-db'); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if (!empty($po['notes'])): ?>
            <h3><?php esc_html_e('Notes', 'meals-db'); ?></h3>
            <pre style="background:#f7f7f7;padding:12px;border:1px solid #ddd;"><?php echo esc_html((string) $po['notes']); ?></pre>
        <?php endif; ?>
    </div>
    <?php
    $mealsdb_po_render_island([
        'poId'       => $po_id,
        'poNumber'   => (string) $po['po_number'],
        'mode'       => $mode,
        'palletSize' => class_exists('MealsDB_Operational_Constants') ? (int) MealsDB_Operational_Constants::APETITO_CASES_PER_PALLET : 0,
    ]);
    return;
}

// ===========================================================================
// List view
// ===========================================================================
$filters = [];
if ($status_filter !== '') {
    $filters['status'] = [$status_filter];
}
$rows = $service->query($filters);
?>
<div id="mealsdb-po-list" class="mealsdb-po-list">
    <h2><?php esc_html_e('Purchase Orders', 'meals-db'); ?></h2>

    <div class="mealsdb-po-controls" style="margin-bottom:12px;">
        <button type="button" class="button button-primary" id="mealsdb-po-generate">
            <?php esc_html_e('Generate draft PO', 'meals-db'); ?>
        </button>
    </div>

    <p class="description"><?php esc_html_e('Generate creates a seasonally-adjusted, pallet-optimized draft and opens it for review. Approve locks a draft; Accept commits it to inventory (vendor confirmed); Mark received records arrival; Reconcile records what actually arrived.', 'meals-db'); ?></p>

    <details style="margin-bottom:12px;">
        <summary class="description" style="cursor:pointer;"><?php esc_html_e('How the forecast works', 'meals-db'); ?></summary>
        <p class="description"><?php esc_html_e('Fixed model, validated by back-test: 12-week recency-weighted history, 6-week order horizon plus a 3-week demand-proportional safety buffer (9 weeks of coverage), seasonal index clamped to 0.3–3.0. The order is snapped to whole Apetito pallets (75 cases): filled up if the partial pallet is at least a third full, otherwise trimmed — within a 7–52 week coverage guard. Not configurable.', 'meals-db'); ?></p>
    </details>

    <div style="margin-bottom:12px;">
        <label><strong><?php esc_html_e('Status:', 'meals-db'); ?></strong></label>
        <select onchange="window.location.href=this.value">
            <option value="<?php echo esc_url($base_url); ?>"><?php esc_html_e('All', 'meals-db'); ?></option>
            <?php foreach (MealsDB_Purchase_Orders::ALLOWED_STATUSES as $s): ?>
                <option value="<?php echo esc_url(add_query_arg(['po_status' => $s], $base_url)); ?>"
                    <?php selected($status_filter, $s); ?>>
                    <?php echo esc_html(MealsDB_Purchase_Orders::status_label($s)); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <span id="mealsdb-po-action-msg" role="status"></span>
    </div>

    <?php if (empty($rows)): ?>
        <p><em><?php esc_html_e('No purchase orders yet.', 'meals-db'); ?></em></p>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th><?php esc_html_e('PO #', 'meals-db'); ?></th>
                <th><?php esc_html_e('Supplier', 'meals-db'); ?></th>
                <th><?php esc_html_e('Status', 'meals-db'); ?></th>
                <th class="num"><?php esc_html_e('Cases', 'meals-db'); ?></th>
                <th class="num"><?php esc_html_e('Rows', 'meals-db'); ?></th>
                <th class="num"><?php esc_html_e('Edits', 'meals-db'); ?></th>
                <th><?php esc_html_e('Created', 'meals-db'); ?></th>
                <th><?php esc_html_e('Approved', 'meals-db'); ?></th>
                <th><?php esc_html_e('Received', 'meals-db'); ?></th>
                <th><?php esc_html_e('Actions', 'meals-db'); ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $po): ?>
                    <?php
                    $rid        = (int) $po['po_id'];
                    $detail_url = add_query_arg(['po_id' => $rid], $base_url);
                    $payload    = null;
                    if (isset($po['payload']) && is_string($po['payload']) && $po['payload'] !== '') {
                        $decoded = json_decode($po['payload'], true);
                        $payload = (is_array($decoded) && isset($decoded['current'])) ? $decoded : null;
                    }
                    $is_wf = is_array($payload);
                    $cases = 0;
                    if ($is_wf) {
                        foreach ($payload['current'] as $r) { $cases += (int) ($r['cases'] ?? 0); }
                    }
                    // Legacy items store UNITS, not cases — the column shows — for them.
                    $st = (string) $po['status'];
                    ?>
                    <tr>
                        <td><strong><a href="<?php echo esc_url($detail_url); ?>"><?php echo esc_html((string) $po['po_number']); ?></a></strong></td>
                        <td><?php echo esc_html((string) ($po['supplier'] ?? '')); ?></td>
                        <td><span class="mealsdb-po-status mealsdb-po-status-<?php echo esc_attr($st); ?>"><?php echo esc_html(MealsDB_Purchase_Orders::status_label($st)); ?></span><?php if (!$is_wf): ?> <em class="mealsdb-po-legacy"><?php esc_html_e('(task)', 'meals-db'); ?></em><?php endif; ?></td>
                        <td class="num"><?php
                            if ($is_wf) {
                                echo (int) $cases;
                                if (class_exists('MealsDB_Operational_Constants') && MealsDB_Operational_Constants::APETITO_CASES_PER_PALLET > 0) {
                                    $per_pallet = (int) MealsDB_Operational_Constants::APETITO_CASES_PER_PALLET;
                                    echo ' <span class="mealsdb-po-pallets">(' . esc_html(number_format_i18n($cases / $per_pallet, 2)) . ' pal)</span>';
                                }
                            } else {
                                echo '&mdash;';
                            }
                        ?></td>
                        <td class="num"><?php echo $is_wf ? (int) count($payload['current']) : '&mdash;'; ?></td>
                        <td class="num"><?php echo $is_wf ? (int) ($po['edit_count'] ?? 0) : '&mdash;'; ?></td>
                        <td><?php echo esc_html((string) ($po['created_at'] ?? '—')); ?></td>
                        <td><?php echo esc_html((string) ($po['approved_at'] ?? ($po['placed_date'] ?? '—'))); ?></td>
                        <td><?php echo esc_html((string) ($po['received_at'] ?? ($po['arrival_date'] ?? '—'))); ?></td>
                        <td>
                            <a class="button button-small" href="<?php echo esc_url($detail_url); ?>"><?php esc_html_e('Review', 'meals-db'); ?></a>
                            <?php if ($is_wf && $st === MealsDB_Purchase_Orders::STATUS_PLANNED): ?>
                                <button type="button" class="button button-small mealsdb-po-action" data-po-action="approve" data-po-id="<?php echo $rid; ?>"><?php esc_html_e('Approve', 'meals-db'); ?></button>
                                <button type="button" class="button button-small mealsdb-po-action" data-po-action="cancel" data-po-id="<?php echo $rid; ?>"><?php esc_html_e('Cancel', 'meals-db'); ?></button>
                            <?php elseif ($is_wf && $st === MealsDB_Purchase_Orders::STATUS_PLACED): ?>
                                <button type="button" class="button button-small mealsdb-po-action" data-po-action="accept" data-po-id="<?php echo $rid; ?>"><?php esc_html_e('Accept', 'meals-db'); ?></button>
                                <button type="button" class="button button-small mealsdb-po-action" data-po-action="unapprove" data-po-id="<?php echo $rid; ?>"><?php esc_html_e('Un-approve', 'meals-db'); ?></button>
                            <?php elseif ($is_wf && $st === MealsDB_Purchase_Orders::STATUS_ACCEPTED): ?>
                                <button type="button" class="button button-small mealsdb-po-action" data-po-action="receive" data-po-id="<?php echo $rid; ?>"><?php esc_html_e('Mark received', 'meals-db'); ?></button>
                                <button type="button" class="button button-small mealsdb-po-action" data-po-action="unaccept" data-po-id="<?php echo $rid; ?>"><?php esc_html_e('Un-accept', 'meals-db'); ?></button>
                            <?php elseif ($is_wf && $st === MealsDB_Purchase_Orders::STATUS_ARRIVED): ?>
                                <a class="button button-small" href="<?php echo esc_url(add_query_arg(['po_id' => $rid, 'action' => 'reconcile'], $base_url)); ?>"><?php esc_html_e('Reconcile', 'meals-db'); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php
$mealsdb_po_render_island(['mode' => 'list']);
