<?php
/**
 * Admin Invoice Generation Page View
 *
 * @package MealsDB
 * @since 1.0.249
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <div class="mealsdb-invoice-container" style="max-width: 800px;">
        <div class="card" style="margin-top: 20px;">
            <h2 class="title">Generate Government Invoice</h2>

            <form id="mealsdb-invoice-form" style="padding: 20px;">
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="invoice_type">Invoice Type</label>
                            </th>
                            <td>
                                <select name="invoice_type" id="invoice_type" class="regular-text" required>
                                    <option value="">-- Select Invoice Type --</option>
                                    <optgroup label="SDNB (Social Development NB)">
                                        <option value="sdnb_legacy">SDNB - Legacy Zone-Based Format</option>
                                        <option value="sdnb_portal">SDNB - New Portal Format</option>
                                    </optgroup>
                                    <optgroup label="VAC (Veterans Affairs Canada)">
                                        <option value="vac_csv">VAC - CSV Format</option>
                                        <option value="vac_pdf">VAC - PDF Format</option>
                                    </optgroup>
                                </select>
                                <p class="description">Select the type of invoice to generate.</p>
                            </td>
                        </tr>

                        <tr id="zone_row" style="display: none;">
                            <th scope="row">
                                <label for="zone">Zone</label>
                            </th>
                            <td>
                                <select name="zone" id="zone" class="regular-text">
                                    <option value="">-- Select Zone --</option>
                                    <?php foreach ($zones as $zone_code): ?>
                                        <option value="<?php echo esc_attr($zone_code); ?>">
                                            <?php echo esc_html($zone_code); ?>
                                            <?php if ($zone_code === 'M'): ?>
                                                (Moncton)
                                            <?php elseif ($zone_code === 'S'): ?>
                                                (Sussex)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Required for SDNB legacy invoices only.</p>
                            </td>
                        </tr>

                        <tr id="weeks_row" style="display: none;">
                            <th scope="row">
                                <label for="weeks_in_month">Number of Wednesdays</label>
                            </th>
                            <td>
                                <input type="number" name="weeks_in_month" id="weeks_in_month" class="small-text" min="1" max="6" value="4">
                                <p class="description">The number of Wednesdays in the billing month. Used to calculate weekly client allowances.</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="start_date">Start Date</label>
                            </th>
                            <td>
                                <input type="text" name="start_date" id="start_date" class="regular-text datepicker" required placeholder="YYYY-MM-DD">
                                <p class="description">First day of the billing period.</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="end_date">End Date</label>
                            </th>
                            <td>
                                <input type="text" name="end_date" id="end_date" class="regular-text datepicker" required placeholder="YYYY-MM-DD">
                                <p class="description">Last day of the billing period.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary button-large" id="generate_invoice_btn">
                        <span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
                        Generate Invoice
                    </button>
                </p>

                <div id="invoice_message" style="display: none; margin-top: 10px;"></div>
            </form>
        </div>

        <div class="card" style="margin-top: 20px;">
            <h2 class="title">Invoice Type Information</h2>
            <div style="padding: 20px;">
                <h3>SDNB - Legacy Zone-Based Format</h3>
                <p>
                    Generate invoices for SDNB clients using the legacy zone-based CSV format.
                    This format includes detailed transaction data with service IDs, requisition IDs,
                    and individual client information organized by delivery zone.
                </p>
                <ul>
                    <li><strong>Output:</strong> CSV file</li>
                    <li><strong>Clients:</strong> SDNB clients with legacy billing enabled</li>
                    <li><strong>Zone Required:</strong> Yes (M=Moncton, S=Sussex, etc.)</li>
                </ul>

                <h3>SDNB - New Portal Format</h3>
                <p>
                    Generate invoices for SDNB clients using the new portal CSV format.
                    This simplified format uses Service Confirmation Item IDs and Service Request IDs.
                </p>
                <ul>
                    <li><strong>Output:</strong> CSV file</li>
                    <li><strong>Clients:</strong> SDNB clients with new portal billing</li>
                    <li><strong>Zone Required:</strong> No</li>
                </ul>

                <h3>VAC - CSV Format</h3>
                <p>
                    Generate invoices for Veterans Affairs Canada in CSV format.
                    This format includes meal allowances, side items, and detailed billing calculations.
                </p>
                <ul>
                    <li><strong>Output:</strong> CSV file</li>
                    <li><strong>Clients:</strong> Veteran clients</li>
                    <li><strong>Zone Required:</strong> No</li>
                </ul>

                <h3>VAC - PDF Format</h3>
                <p>
                    Generate invoices for Veterans Affairs Canada in PDF format.
                    Creates a multi-page PDF document with one page per veteran showing detailed billing information.
                </p>
                <ul>
                    <li><strong>Output:</strong> PDF file</li>
                    <li><strong>Clients:</strong> Veteran clients</li>
                    <li><strong>Zone Required:</strong> No</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<style>
    .mealsdb-invoice-container .card {
        background: #fff;
        border: 1px solid #ccd0d4;
        box-shadow: 0 1px 1px rgba(0,0,0,.04);
    }

    .mealsdb-invoice-container .card h2.title {
        margin: 0;
        padding: 12px 20px;
        border-bottom: 1px solid #eee;
        font-size: 14px;
        font-weight: 600;
    }

    .mealsdb-invoice-container h3 {
        margin-top: 20px;
        margin-bottom: 10px;
        font-size: 13px;
        font-weight: 600;
    }

    .mealsdb-invoice-container h3:first-of-type {
        margin-top: 0;
    }

    .mealsdb-invoice-container ul {
        margin-left: 20px;
    }

    #invoice_message.notice {
        padding: 10px 15px;
        margin: 0;
    }

    #generate_invoice_btn .dashicons {
        margin-top: 3px;
    }
</style>
