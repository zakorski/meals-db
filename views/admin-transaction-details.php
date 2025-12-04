<?php
$transaction_id = isset($_GET['transaction_id']) ? intval($_GET['transaction_id']) : 0;

if ($transaction_id <= 0) {
    echo '<div class="wrap"><p>Invalid transaction ID.</p></div>';
    return;
}

$transaction = MealsDB_Transactions::get_transaction($transaction_id);

if (empty($transaction)) {
    echo '<div class="wrap"><p>Transaction not found.</p></div>';
    return;
}

$items = MealsDB_Transactions::get_transaction_items($transaction_id);

$status          = isset($transaction['status']) ? (string) $transaction['status'] : '';
$allowed_status  = ['Ordered', 'Delivered', 'Cancelled'];
if (!in_array($status, $allowed_status, true)) {
    $status = 'Ordered';
}
$status_lower = strtolower($status);

$client_first_name = isset($transaction['first_name']) ? trim((string) $transaction['first_name']) : '';
$client_last_name  = isset($transaction['last_name']) ? trim((string) $transaction['last_name']) : '';
$client_name       = trim($client_first_name . ' ' . $client_last_name);
if ($client_name === '') {
    $client_name = sprintf(__('Client #%d', 'meals-db'), intval($transaction['client_id'] ?? 0));
}

$order_date    = !empty($transaction['order_date']) ? date('Y-m-d', strtotime($transaction['order_date'])) : '';
$delivery_date = !empty($transaction['delivery_date']) ? date('Y-m-d', strtotime($transaction['delivery_date'])) : '';
$created_at    = !empty($transaction['created_at']) ? date('Y-m-d', strtotime($transaction['created_at'])) : '';
$updated_at    = !empty($transaction['updated_at']) ? date('Y-m-d', strtotime($transaction['updated_at'])) : '';
?>

<div class="wrap mealsdb-transaction-details">
    <h1><?php echo esc_html(sprintf(__('Transaction #%d', 'meals-db'), $transaction_id)); ?></h1>
    <p><a href="<?php echo esc_url(admin_url('admin.php?page=mealsdb-transactions')); ?>" class="button"><?php esc_html_e('Back to Transactions', 'meals-db'); ?></a></p>

    <style>
        .mealsdb-transaction-details .status-label {
            padding: 3px 8px;
            border-radius: 4px;
            color: #fff;
            display: inline-block;
        }
        .mealsdb-transaction-details .status-ordered { background: #0073aa; }
        .mealsdb-transaction-details .status-delivered { background: #46b450; }
        .mealsdb-transaction-details .status-cancelled { background: #dc3232; }
    </style>

    <h2><?php esc_html_e('Summary', 'meals-db'); ?></h2>
    <table class="widefat striped">
        <tr>
            <th><?php esc_html_e('Transaction ID', 'meals-db'); ?></th>
            <td><?php echo esc_html($transaction_id); ?></td>
        </tr>
        <tr>
            <th><?php esc_html_e('Status', 'meals-db'); ?></th>
            <td><span class="status-label status-<?php echo esc_attr($status_lower); ?>"><?php echo esc_html($status); ?></span></td>
        </tr>
        <tr>
            <th><?php esc_html_e('Client', 'meals-db'); ?></th>
            <td><?php echo esc_html($client_name); ?></td>
        </tr>
        <tr>
            <th><?php esc_html_e('Client Type', 'meals-db'); ?></th>
            <td><?php echo esc_html($transaction['client_type'] ?? ''); ?></td>
        </tr>
        <tr>
            <th><?php esc_html_e('Order Date', 'meals-db'); ?></th>
            <td><?php echo esc_html($order_date); ?></td>
        </tr>
        <tr>
            <th><?php esc_html_e('Delivery Date', 'meals-db'); ?></th>
            <td><?php echo esc_html($delivery_date); ?></td>
        </tr>
        <tr>
            <th><?php esc_html_e('Created', 'meals-db'); ?></th>
            <td><?php echo esc_html($created_at); ?></td>
        </tr>
        <tr>
            <th><?php esc_html_e('Last Updated', 'meals-db'); ?></th>
            <td><?php echo esc_html($updated_at); ?></td>
        </tr>
    </table>

    <h2><?php esc_html_e('Client Contact', 'meals-db'); ?></h2>
    <table class="widefat striped">
        <tr>
            <th><?php esc_html_e('Email', 'meals-db'); ?></th>
            <td><?php echo esc_html($transaction['email'] ?? ''); ?></td>
        </tr>
        <tr>
            <th><?php esc_html_e('Phone', 'meals-db'); ?></th>
            <td><?php echo esc_html($transaction['phone'] ?? ''); ?></td>
        </tr>
    </table>

    <h2><?php esc_html_e('Items in Order', 'meals-db'); ?></h2>

    <?php if (empty($items)) : ?>
        <p><?php esc_html_e('No items recorded for this transaction.', 'meals-db'); ?></p>
    <?php else : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Item', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Category', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Main Ingredient', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Quantity', 'meals-db'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item) : ?>
                    <tr>
                        <td><?php echo esc_html($item['product_name'] ?? ''); ?></td>
                        <td><?php echo esc_html($item['category'] ?? ''); ?></td>
                        <td><?php echo esc_html($item['main_ingredient'] ?? ''); ?></td>
                        <td><?php echo esc_html(isset($item['quantity']) ? (int) $item['quantity'] : ''); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
