<?php
defined('ABSPATH') || exit;

$active_tab = isset($active_tab)
    ? $active_tab
    : (isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : 'sync');

// $tabs is always supplied by the sole includer, MealsDB_Admin_UI::render_tabs(),
// which seeds the canonical 10-entry tab list before the include. A local fallback
// list used to live here but had drifted to a stale 4-entry copy (missing clients,
// slips, po, tasks, po_admin, settings) — a second, wrong source of truth that could
// only render if a future caller forgot to seed $tabs. Removed so render_tabs() is
// the single source of truth for the nav.

echo '<nav class="nav-tab-wrapper">';
foreach ($tabs as $key => $label) {
    $class = ($active_tab === $key) ? 'nav-tab nav-tab-active' : 'nav-tab';
    $url = admin_url('admin.php?page=mealsdb&tab=' . $key);
    echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
}
echo '</nav>';
