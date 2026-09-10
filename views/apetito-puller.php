<?php
/**
 * View: Apetito Item Puller — rendered by MealsDB_Apetito_Page::render().
 *
 * Two tabs:
 *   - "Pull an item": enter a 5-digit Apetito code, fetch Nutridata, review
 *     parsed details, and create a Draft WooCommerce product.
 *   - "Audit existing (read-only)": walk existing Apetito-SKU products and
 *     compare stored fields against current Nutridata pages, paced at one
 *     request per second.
 *
 * All interactivity is in assets/js/apetito-puller.js. This file is static
 * markup only — per the codebase's "no inline <script> > 20 lines" rule.
 *
 * XSS: this file contains no dynamic server output; every string is a
 * translation function call whose input is a literal. Nothing is escaped
 * separately because nothing reaches the page from user input or the DB.
 */

if (!defined('ABSPATH')) { exit; }
/** @var void — rendered by MealsDB_Apetito_Page::render() */
?>
<div class="wrap mealsdb-apetito">
    <h1><?php echo esc_html__('Apetito Item Puller', 'meals-db'); ?></h1>
    <p class="description">
        <?php echo esc_html__('Enter a 5-digit Apetito code to fetch its Nutridata page, review the parsed details, and create a Draft product. Price and categories are yours to set; product type and tax are derived from the categories you choose.', 'meals-db'); ?>
    </p>

    <h2 class="nav-tab-wrapper">
        <a href="#pull" class="nav-tab nav-tab-active" data-tab="pull"><?php echo esc_html__('Pull an item', 'meals-db'); ?></a>
        <a href="#audit" class="nav-tab" data-tab="audit"><?php echo esc_html__('Audit existing (read-only)', 'meals-db'); ?></a>
    </h2>

    <div id="mealsdb-apetito-pull" class="mealsdb-apetito-tab">
        <p>
            <label for="mealsdb-apetito-code"><strong><?php echo esc_html__('Apetito code', 'meals-db'); ?></strong></label>
            <input type="text" id="mealsdb-apetito-code" class="regular-text" inputmode="numeric" maxlength="5" pattern="[0-9]{5}" placeholder="12212" />
            <button type="button" class="button button-primary" id="mealsdb-apetito-fetch"><?php echo esc_html__('Fetch', 'meals-db'); ?></button>
        </p>
        <div id="mealsdb-apetito-status" role="status" aria-live="polite"></div>
        <div id="mealsdb-apetito-preview" hidden></div>
    </div>

    <div id="mealsdb-apetito-audit" class="mealsdb-apetito-tab" hidden>
        <p class="description"><?php echo esc_html__('Compares existing Apetito products (by SKU) against the current Nutridata pages. Read-only; paced at one request per second.', 'meals-db'); ?></p>
        <p><button type="button" class="button" id="mealsdb-apetito-audit-start"><?php echo esc_html__('Start audit', 'meals-db'); ?></button></p>
        <div id="mealsdb-apetito-audit-progress" aria-live="polite"></div>
        <table class="widefat striped" id="mealsdb-apetito-audit-results" hidden>
            <thead><tr>
                <th><?php echo esc_html__('Code', 'meals-db'); ?></th>
                <th><?php echo esc_html__('Product', 'meals-db'); ?></th>
                <th><?php echo esc_html__('Field', 'meals-db'); ?></th>
                <th><?php echo esc_html__('Stored', 'meals-db'); ?></th>
                <th><?php echo esc_html__('Apetito now', 'meals-db'); ?></th>
            </tr></thead>
            <tbody></tbody>
        </table>
    </div>
</div>
