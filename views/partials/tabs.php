<?php
$active = $_GET['tab'] ?? 'sync';

$tabs = [
    'sync' => 'Sync Dashboard',
    'add' => 'Add New Client',
    'drafts' => 'Drafts',
    'ignored' => 'Ignored Conflicts',
];

echo '<nav class="nav-tab-wrapper">';
foreach ($tabs as $key => $label) {
    $class = ($active === $key) ? 'nav-tab nav-tab-active' : 'nav-tab';
    $url = admin_url('admin.php?page=meals-db&tab=' . $key);
    echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
}
echo '</nav>';
