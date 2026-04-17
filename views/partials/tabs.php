<?php
defined('ABSPATH') || exit;

$active_tab = isset($active_tab)
    ? $active_tab
    : (isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : 'sync');

if (!isset($tabs) || !is_array($tabs)) {
    $tabs = [
        'sync' => __('Sync Dashboard', 'meals-db'),
        'add' => __('Add New Client', 'meals-db'),
        'drafts' => __('Drafts', 'meals-db'),
        'ignored' => __('Ignored Conflicts', 'meals-db'),
    ];
}

echo '<nav class="nav-tab-wrapper">';
foreach ($tabs as $key => $label) {
    $class = ($active_tab === $key) ? 'nav-tab nav-tab-active' : 'nav-tab';
    $url = admin_url('admin.php?page=mealsdb&tab=' . $key);
    echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
}
echo '</nav>';
