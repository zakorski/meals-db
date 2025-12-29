<?php
/**
 * Government Invoice Generator
 *
 * Generates invoices for government agencies (SDNB and Veterans Affairs Canada)
 * in their required CSV and PDF formats.
 *
 * @package MealsDB
 * @since 1.0.249
 */

if (!defined('ABSPATH')) {
    exit;
}

class MealsDB_Invoice_Generator {

    /**
     * Vendor information (hardcoded as per sample)
     */
    const VENDOR_NUMBER = '60835264';
    const VENDOR_NAME = 'Meals and More';
    const VENDOR_ADDRESS = 'PO Box 6382 Sackville NB';
    const HST_NUMBER = '799244819';
    const CONTACT_PERSON = 'Janet O\'Brien';
    const CONTACT_AREA_CODE = '506';
    const CONTACT_PHONE = '5368102';
    const CONTACT_EMAIL = 'janet@mealsandmore.ca';

    /**
     * Service Center information by zone
     */
    private static $service_centers = [
        'M' => [
            'number' => '4801',
            'name' => 'Moncton',
            'address' => '770 Main Street Assumption PL., 5th Floor, Moncton NB E1C 8R3'
        ],
        'S' => [
            'number' => '4802',
            'name' => 'Sussex',
            'address' => 'Sussex Service Center Address'
        ]
    ];

    /**
     * VAC monthly allowances by service frequency
     */
    private static $vac_allowances = [
        'day' => ['mains' => 7, 'amount' => 74.48],
        'week' => ['mains' => 31, 'amount' => 329.84],
        'month' => ['mains' => 124, 'amount' => 1319.36]
    ];

    /**
     * Generate SDNB Legacy Zone-Based Invoice
     *
     * @param string $zone Zone code (M=Moncton, S=Sussex)
     * @param string $start_date Start date (Y-m-d format)
     * @param string $end_date End date (Y-m-d format)
     * @return string CSV content
     */
    public static function generate_sdnb_legacy($zone, $start_date, $end_date) {
        $conn = MealsDB_DB::get_connection();

        // Get service center info
        $service_center = isset(self::$service_centers[$zone]) ? self::$service_centers[$zone] : self::$service_centers['M'];

        // Format invoice number: "2025 Jan 31 M"
        $end_date_obj = new DateTime($end_date);
        $invoice_number = $end_date_obj->format('Y M d') . ' ' . $zone;

        // Query transactions for SDNB legacy clients in this zone
        $query = "
            SELECT
                t.transaction_id,
                c.service_id,
                c.requisition_id,
                c.individual_id,
                c.last_name,
                c.first_name,
                c.client_contribution,
                t.billing_rate,
                t.order_date,
                SUM(ti.quantity) as total_units,
                SUM(ti.line_subtotal) as basic_cost,
                SUM(ti.line_taxes) as tax_amount,
                SUM(ti.line_total) as total_cost
            FROM " . MealsDB_DB::table('transactions') . " t
            INNER JOIN " . MealsDB_DB::table('clients') . " c ON t.client_id = c.client_id
            INNER JOIN " . MealsDB_DB::table('transaction_items') . " ti ON t.transaction_id = ti.transaction_id
            WHERE c.client_type = 'SDNB'
                AND c.use_legacy_billing = 1
                AND c.delivery_area_zone = ?
                AND t.order_date BETWEEN ? AND ?
                AND t.status != 'cancelled'
            GROUP BY t.transaction_id, c.service_id, c.requisition_id, c.individual_id,
                     c.last_name, c.first_name, c.client_contribution, t.billing_rate, t.order_date
            ORDER BY c.last_name, c.first_name
        ";

        $stmt = $conn->prepare($query);
        $stmt->bind_param('sss', $zone, $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();

        $transactions = [];
        $total_invoice_amount = 0;
        $total_tax_amount = 0;

        while ($row = $result->fetch_assoc()) {
            $transactions[] = $row;
            $total_invoice_amount += $row['total_cost'];
            $total_tax_amount += $row['tax_amount'];
        }

        $stmt->close();

        // Build CSV content
        $csv = [];

        // Row 1-2: Blank rows with commas
        $csv[] = str_repeat(',', 99);

        // Row 3: Header with version
        $row3 = array_fill(0, 100, '');
        $row3[0] = '1';
        $row3[1] = 'Social Development';
        $row3[5] = 'Electronic Invoice Datasheet';
        $row3[9] = 'version 36e';
        $csv[] = implode(',', $row3);

        // Row 4: Invoice metadata header row
        $row4 = array_fill(0, 100, '');
        $row4[0] = '1';
        $row4[1] = 'Invoice No.';
        $row4[2] = 'Vendor No.';
        $row4[3] = 'Vendor Name';
        $row4[5] = 'Vendor Address';
        $row4[6] = 'Service Center No';
        $row4[7] = 'Service Center Name';
        $row4[10] = 'Service Center Address';
        $row4[12] = 'Billing Period Start Date';
        $row4[13] = 'Billing Period End Date';
        $row4[14] = 'Tax Indicator';
        $row4[15] = 'HST / GST #';
        $row4[16] = 'Tax Amount';
        $row4[17] = 'Total Invoice Amount';
        $row4[18] = 'Contact Person';
        $row4[20] = 'Contact Area Code';
        $row4[21] = 'Contact Phone No.';
        $row4[22] = 'Contact E-mail';
        $row4[23] = '# of Invoice Lines';
        $csv[] = implode(',', $row4);

        // Row 5: Invoice metadata values
        $row5 = array_fill(0, 100, '');
        $row5[0] = '2';
        $row5[1] = $invoice_number;
        $row5[2] = self::VENDOR_NUMBER;
        $row5[3] = self::VENDOR_NAME;
        $row5[5] = self::VENDOR_ADDRESS;
        $row5[6] = $service_center['number'];
        $row5[7] = $service_center['name'];
        $row5[10] = $service_center['address'];
        $row5[12] = str_replace('-', '', $start_date); // YYYYMMDD format
        $row5[13] = str_replace('-', '', $end_date);
        $row5[14] = 'Full';
        $row5[15] = self::HST_NUMBER;
        $row5[16] = number_format($total_tax_amount, 2, '.', '');
        $row5[17] = number_format($total_invoice_amount, 2, '.', '');
        $row5[18] = self::CONTACT_PERSON;
        $row5[20] = self::CONTACT_AREA_CODE;
        $row5[21] = self::CONTACT_PHONE;
        $row5[22] = self::CONTACT_EMAIL;
        $row5[23] = count($transactions);
        $row5[24] = 'F'; // Unknown flag from sample
        $csv[] = implode(',', $row5);

        // Row 6: Column headers for data rows
        $row6 = array_fill(0, 100, '');
        $row6[0] = '1';
        $row6[1] = 'Service Id';
        $row6[2] = 'Requisition Id';
        $row6[3] = 'Individual Id';
        $row6[4] = 'Client Last Name';
        $row6[5] = 'Client First Name';
        $row6[6] = 'No. of Units';
        $row6[7] = 'Unit Type';
        $row6[8] = 'Rate';
        $row6[9] = 'Basic Cost';
        $row6[10] = 'Total Kilometers - (transportation - home support)';
        $row6[11] = 'Other Cost (transportation - home support)';
        $row6[12] = 'Total Kilometers (transportation - family support worker)';
        $row6[13] = 'Other Cost (transportation - family support worker)';
        $row6[14] = 'Other Cost (transportation - medical)';
        $row6[15] = 'Other Cost (transportation - daycare)';
        $row6[16] = 'Other Cost (transportation - other)';
        $row6[17] = 'Other Cost (meals)';
        $row6[18] = 'Other Cost (sundry)';
        $row6[19] = 'Other Cost  (admin fees)';
        $row6[20] = 'Other Cost (lodging)';
        $row6[21] = 'Other Cost (recreation)';
        $row6[22] = 'Other Cost (parking)';
        $row6[23] = 'Client Contribution';
        $row6[24] = 'Dept. Cost';
        $row6[25] = 'Mileage Cost Indicator';
        $row6[26] = 'Mileage Cost';
        $row6[27] = 'Stat Holiday Units';
        $row6[28] = 'Stat. Holiday Amt';
        $row6[29] = 'Shift Diff. Units';
        $row6[30] = 'Shift Diff. Rate';
        $row6[31] = 'Shift Diff. Cost';
        $row6[32] = 'Shift Diff. Stat Holiday Units';
        $row6[33] = 'Shift Diff. Stat Holiday Cost';
        $row6[34] = 'Tax';
        $row6[35] = 'Total Invoice Line Cost';
        $csv[] = implode(',', $row6);

        // Data rows
        foreach ($transactions as $trans) {
            $row = array_fill(0, 100, '');
            $row[0] = '3';
            $row[1] = $trans['service_id'] ?: '356029'; // Default service ID
            $row[2] = $trans['requisition_id'] ?: '';
            $row[3] = $trans['individual_id'] ?: '';
            $row[4] = $trans['last_name'] ?: '';
            $row[5] = $trans['first_name'] ?: '';
            $row[6] = number_format($trans['total_units'], 2, '.', '');
            $row[7] = 'Meal';
            $row[8] = number_format($trans['billing_rate'], 2, '.', '');
            $row[9] = number_format($trans['basic_cost'], 2, '.', '');
            $row[10] = ''; // Total Kilometers - home support
            $row[11] = ''; // Other Cost - home support
            $row[12] = ''; // Total Kilometers - family support
            $row[13] = ''; // Other Cost - family support
            $row[14] = ''; // Other Cost - medical
            $row[15] = ''; // Other Cost - daycare
            $row[16] = ''; // Other Cost - other
            $row[17] = ''; // Other Cost - meals
            $row[18] = ''; // Other Cost - sundry
            $row[19] = ''; // Other Cost - admin fees
            $row[20] = ''; // Other Cost - lodging
            $row[21] = ''; // Other Cost - recreation
            $row[22] = ''; // Other Cost - parking
            $row[23] = number_format($trans['client_contribution'], 2, '.', '');
            $row[24] = number_format($trans['basic_cost'], 2, '.', ''); // Dept Cost = Basic Cost - Client Contribution
            $row[25] = ''; // Mileage Cost Indicator
            $row[26] = ''; // Mileage Cost
            $row[27] = number_format(0, 2, '.', ''); // Stat Holiday Units
            $row[28] = ''; // Stat Holiday Amount
            $row[29] = ''; // Shift Diff Units
            $row[30] = number_format(0, 2, '.', ''); // Shift Diff Rate
            $row[31] = ''; // Shift Diff Cost
            $row[32] = ''; // Shift Diff Stat Holiday Units
            $row[33] = number_format(0, 2, '.', ''); // Shift Diff Stat Holiday Cost
            $row[34] = ''; // Tax
            $row[35] = number_format($trans['total_cost'], 2, '.', ''); // Total Line Cost
            $row[36] = 'I'; // Unknown flag from sample
            $csv[] = implode(',', $row);
        }

        return implode("\n", $csv);
    }

    /**
     * Generate SDNB New Portal Format Invoice
     *
     * @param string $start_date Start date (Y-m-d format)
     * @param string $end_date End date (Y-m-d format)
     * @return string CSV content
     */
    public static function generate_sdnb_new_portal($start_date, $end_date) {
        $conn = MealsDB_DB::get_connection();

        // Query transactions for SDNB new portal clients
        $query = "
            SELECT
                t.transaction_id,
                c.sdnb_service_request_id,
                CONCAT(UPPER(c.first_name), ' ', UPPER(c.last_name)) as client_name,
                c.client_contribution,
                t.billing_rate,
                SUM(ti.quantity) as total_units,
                SUM(ti.line_taxes) as tax_amount
            FROM " . MealsDB_DB::table('transactions') . " t
            INNER JOIN " . MealsDB_DB::table('clients') . " c ON t.client_id = c.client_id
            INNER JOIN " . MealsDB_DB::table('transaction_items') . " ti ON t.transaction_id = ti.transaction_id
            WHERE c.client_type = 'SDNB'
                AND c.use_legacy_billing = 0
                AND t.order_date BETWEEN ? AND ?
                AND t.status != 'cancelled'
            GROUP BY t.transaction_id, c.sdnb_service_request_id, client_name,
                     c.client_contribution, t.billing_rate
            ORDER BY client_name
        ";

        $stmt = $conn->prepare($query);
        $stmt->bind_param('ss', $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();

        $csv = [];

        // Header row
        $csv[] = 'Service Confirmation Item Id,Product Name,Service Request Id,Client Name,No. Of Units,Unit Type,Rate,Kilometres,Kilometre Rate,Other Cost (transportation),Other Cost (meals),Other Cost (sundry),Other Cost (admin fees),Other Cost (recreation),Other Cost (parking),Client Contribution,Stat Holiday Units,Tax';

        // Data rows
        while ($row = $result->fetch_assoc()) {
            $sci_id = 'SCI-' . str_pad($row['transaction_id'], 8, '0', STR_PAD_LEFT);

            $csv[] = sprintf(
                '%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s',
                $sci_id,
                'Meal Services - Services de repas',
                $row['sdnb_service_request_id'] ?: '',
                $row['client_name'],
                intval($row['total_units']),
                'Meal',
                number_format($row['billing_rate'], 2, '.', ''),
                '', // Kilometres
                '', // Kilometre Rate
                '', // Other Cost (transportation)
                '', // Other Cost (meals)
                '', // Other Cost (sundry)
                '', // Other Cost (admin fees)
                '', // Other Cost (recreation)
                '', // Other Cost (parking)
                '', // Client Contribution
                '', // Stat Holiday Units
                number_format($row['tax_amount'], 2, '.', '')
            );
        }

        $stmt->close();

        return implode("\n", $csv);
    }

    /**
     * Generate VAC CSV Invoice
     *
     * @param string $start_date Start date (Y-m-d format)
     * @param string $end_date End date (Y-m-d format)
     * @return string CSV content
     */
    public static function generate_vac_csv($start_date, $end_date) {
        $conn = MealsDB_DB::get_connection();

        // Query aggregated data per veteran
        $query = "
            SELECT
                c.client_id,
                c.vet_health_card,
                c.last_name,
                c.first_name,
                CONCAT(
                    COALESCE(CONCAT(c.apartment_number, ' - '), ''),
                    COALESCE(c.street_number, ''),
                    ' ',
                    COALESCE(c.street_name, '')
                ) as billing_address,
                c.city as billing_city,
                c.postal_code as billing_postcode,
                c.client_phone_1 as billing_phone,
                c.requisition_period,
                t.billing_rate
            FROM " . MealsDB_DB::table('clients') . " c
            INNER JOIN " . MealsDB_DB::table('transactions') . " t ON c.client_id = t.client_id
            WHERE c.client_type = 'Veteran'
                AND t.order_date BETWEEN ? AND ?
                AND t.status != 'cancelled'
            GROUP BY c.client_id
            ORDER BY c.last_name, c.first_name
        ";

        $stmt = $conn->prepare($query);
        $stmt->bind_param('ss', $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();

        $veterans = [];
        while ($row = $result->fetch_assoc()) {
            $veterans[] = $row;
        }
        $stmt->close();

        // For each veteran, get product breakdown
        $csv = [];

        // Header row
        $csv[] = 'K#,Client Last Name,Client First Name,Billing Address 1,Billing City,Billing Postcode,Billing Phone,Unit Type,Rate,Mains Ordered,Mains Allowance,Bill Mains,BNM Mains,Sides Ordered,Sides Allowance,Desserts,Muffin,Total Tax Sides Ordered,Bill Tax Sides,Overage Tax Sides,Remaining Sides,Cereal,Soup,Total Non-Tax Sides Ordered,Bill Non-Taxable Sides,Overage Non Taxable Sides,Bill Sides,Service,Monthly Allowance,Vet Mains Cost,Allowance Remaining,Sides Cost,Bill HST,New Total,Errors,New User flag';

        // Data rows
        foreach ($veterans as $vet) {
            // Get product breakdown for this veteran
            $products_query = "
                SELECT
                    p.product_type,
                    p.taxable,
                    SUM(ti.quantity) as quantity,
                    SUM(ti.line_subtotal) as subtotal,
                    SUM(ti.line_taxes) as taxes
                FROM " . MealsDB_DB::table('transaction_items') . " ti
                INNER JOIN " . MealsDB_DB::table('products') . " p ON ti.product_id = p.product_id
                INNER JOIN " . MealsDB_DB::table('transactions') . " t ON ti.transaction_id = t.transaction_id
                WHERE t.client_id = ?
                    AND t.order_date BETWEEN ? AND ?
                    AND t.status != 'cancelled'
                GROUP BY p.product_type, p.taxable
            ";

            $prod_stmt = $conn->prepare($products_query);
            $prod_stmt->bind_param('iss', $vet['client_id'], $start_date, $end_date);
            $prod_stmt->execute();
            $prod_result = $prod_stmt->get_result();

            $mains_ordered = 0;
            $sides_ordered_taxable = 0;
            $sides_ordered_nontax = 0;
            $sides_cost = 0;
            $sides_tax = 0;

            while ($prod = $prod_result->fetch_assoc()) {
                if ($prod['product_type'] === 'meal') {
                    $mains_ordered += $prod['quantity'];
                } else if ($prod['product_type'] === 'side') {
                    if ($prod['taxable']) {
                        $sides_ordered_taxable += $prod['quantity'];
                        $sides_cost += $prod['subtotal'];
                        $sides_tax += $prod['taxes'];
                    } else {
                        $sides_ordered_nontax += $prod['quantity'];
                    }
                }
            }
            $prod_stmt->close();

            // Get allowance info
            $service = strtolower($vet['requisition_period'] ?: 'week');
            $allowance_info = isset(self::$vac_allowances[$service]) ?
                self::$vac_allowances[$service] : self::$vac_allowances['week'];

            $mains_allowance = $allowance_info['mains'];
            $monthly_allowance = $allowance_info['amount'];

            // Calculate billing
            $bill_mains = min($mains_ordered, $mains_allowance);
            $bnm_mains = max(0, $mains_ordered - $mains_allowance); // Beyond allowance
            $vet_mains_cost = $bill_mains * $vet['billing_rate'];
            $allowance_remaining = $monthly_allowance - $vet_mains_cost;

            // Sides allowance (example: 10 per period, adjust as needed)
            $sides_allowance = 10;
            $total_sides_ordered = $sides_ordered_taxable + $sides_ordered_nontax;
            $remaining_sides = max(0, $sides_allowance - $total_sides_ordered);

            // Bill taxable sides
            $bill_tax_sides = min($sides_ordered_taxable, $sides_allowance);
            $overage_tax_sides = max(0, $sides_ordered_taxable - $sides_allowance);

            // Bill non-taxable sides
            $bill_nontax_sides = min($sides_ordered_nontax, max(0, $sides_allowance - $sides_ordered_taxable));
            $overage_nontax_sides = max(0, $sides_ordered_nontax - (max(0, $sides_allowance - $sides_ordered_taxable)));

            // Total billing
            $bill_sides = $bill_tax_sides + $bill_nontax_sides;
            $new_total = $vet_mains_cost + $sides_cost + $sides_tax;

            // Check for errors/warnings
            $errors = '';
            // Add error detection logic here if needed

            $csv[] = sprintf(
                '%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s',
                $vet['vet_health_card'] ?: '',
                $vet['last_name'] ?: '',
                $vet['first_name'] ?: '',
                str_replace(',', '', $vet['billing_address']), // Remove commas from address
                $vet['billing_city'] ?: '',
                $vet['billing_postcode'] ?: '',
                $vet['billing_phone'] ?: '',
                'Meal',
                number_format($vet['billing_rate'], 2, '.', ''),
                $mains_ordered,
                $mains_allowance,
                $bill_mains,
                $bnm_mains,
                $sides_ordered_taxable,
                $sides_allowance,
                0, // Desserts (track separately if needed)
                0, // Muffins (track separately if needed)
                $sides_ordered_taxable,
                $bill_tax_sides,
                $overage_tax_sides,
                $remaining_sides,
                0, // Cereal (track separately if needed)
                $sides_ordered_nontax, // Soup counted as non-tax sides
                $sides_ordered_nontax,
                $bill_nontax_sides,
                $overage_nontax_sides,
                $bill_sides,
                $service,
                number_format($monthly_allowance, 2, '.', ''),
                number_format($vet_mains_cost, 2, '.', ''),
                number_format($allowance_remaining, 2, '.', ''),
                number_format($sides_cost, 2, '.', ''),
                number_format($sides_tax, 2, '.', ''),
                number_format($new_total, 2, '.', ''),
                $errors,
                'No' // New user flag
            );
        }

        return implode("\n", $csv);
    }

    /**
     * Generate VAC PDF Invoice
     *
     * Uses TCPDF library to generate multi-page PDF (one page per veteran)
     *
     * @param string $start_date Start date (Y-m-d format)
     * @param string $end_date End date (Y-m-d format)
     * @return string PDF file path (temporary file)
     */
    public static function generate_vac_pdf($start_date, $end_date) {
        // Check if TCPDF is available
        if (!class_exists('TCPDF')) {
            // Try to load WordPress bundled TCPDF if available
            $tcpdf_path = ABSPATH . 'wp-includes/class-tcpdf.php';
            if (file_exists($tcpdf_path)) {
                require_once($tcpdf_path);
            } else {
                throw new Exception('TCPDF library not found. Please install TCPDF to generate PDF invoices.');
            }
        }

        // Get CSV data and parse it
        $csv_content = self::generate_vac_csv($start_date, $end_date);
        $lines = explode("\n", $csv_content);
        $headers = str_getcsv(array_shift($lines)); // Remove header row

        // Create PDF
        $pdf = new TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);

        // Set document information
        $pdf->SetCreator('Meals DB');
        $pdf->SetAuthor(self::VENDOR_NAME);
        $pdf->SetTitle('VAC Invoice - ' . date('Y-m-d'));
        $pdf->SetSubject('Veterans Affairs Canada Invoice');

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Set margins
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);

        // Set font
        $pdf->SetFont('helvetica', '', 10);

        // Process each veteran (one page per veteran)
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;

            $data = str_getcsv($line);

            // Add a page for this veteran
            $pdf->AddPage();

            // Title
            $pdf->SetFont('helvetica', 'B', 16);
            $pdf->Cell(0, 10, 'Veterans Affairs Canada - Meal Invoice', 0, 1, 'C');
            $pdf->Ln(5);

            // Veteran Information
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 8, 'Veteran Information', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 10);

            $pdf->Cell(50, 6, 'Health Card #:', 0, 0);
            $pdf->Cell(0, 6, $data[0], 0, 1);

            $pdf->Cell(50, 6, 'Name:', 0, 0);
            $pdf->Cell(0, 6, $data[2] . ' ' . $data[1], 0, 1);

            $pdf->Cell(50, 6, 'Address:', 0, 0);
            $pdf->Cell(0, 6, $data[3], 0, 1);

            $pdf->Cell(50, 6, 'City:', 0, 0);
            $pdf->Cell(0, 6, $data[4] . ', ' . $data[5], 0, 1);

            $pdf->Cell(50, 6, 'Phone:', 0, 0);
            $pdf->Cell(0, 6, $data[6], 0, 1);

            $pdf->Ln(5);

            // Billing Period
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 8, 'Billing Period', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Cell(0, 6, $start_date . ' to ' . $end_date, 0, 1);
            $pdf->Ln(5);

            // Meal Details
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 8, 'Meal Details', 0, 1, 'L');

            // Table header
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetFillColor(220, 220, 220);
            $pdf->Cell(60, 6, 'Description', 1, 0, 'L', true);
            $pdf->Cell(30, 6, 'Ordered', 1, 0, 'C', true);
            $pdf->Cell(30, 6, 'Allowance', 1, 0, 'C', true);
            $pdf->Cell(30, 6, 'Billed', 1, 0, 'C', true);
            $pdf->Cell(30, 6, 'Amount', 1, 1, 'R', true);

            // Mains row
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell(60, 6, 'Main Meals', 1, 0);
            $pdf->Cell(30, 6, $data[9], 1, 0, 'C');
            $pdf->Cell(30, 6, $data[10], 1, 0, 'C');
            $pdf->Cell(30, 6, $data[11], 1, 0, 'C');
            $pdf->Cell(30, 6, '$' . $data[29], 1, 1, 'R');

            // Sides row
            $pdf->Cell(60, 6, 'Side Items (Taxable)', 1, 0);
            $pdf->Cell(30, 6, $data[13], 1, 0, 'C');
            $pdf->Cell(30, 6, $data[14], 1, 0, 'C');
            $pdf->Cell(30, 6, $data[26], 1, 0, 'C');
            $pdf->Cell(30, 6, '$' . $data[31], 1, 1, 'R');

            // Non-tax sides row
            $pdf->Cell(60, 6, 'Side Items (Non-Taxable)', 1, 0);
            $pdf->Cell(30, 6, $data[23], 1, 0, 'C');
            $pdf->Cell(30, 6, '-', 1, 0, 'C');
            $pdf->Cell(30, 6, $data[24], 1, 0, 'C');
            $pdf->Cell(30, 6, '-', 1, 1, 'R');

            $pdf->Ln(3);

            // Summary
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 8, 'Summary', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 10);

            $pdf->Cell(120, 6, 'Service Frequency:', 0, 0);
            $pdf->Cell(0, 6, ucfirst($data[27]), 0, 1);

            $pdf->Cell(120, 6, 'Monthly Allowance:', 0, 0);
            $pdf->Cell(0, 6, '$' . $data[28], 0, 1);

            $pdf->Cell(120, 6, 'Mains Cost:', 0, 0);
            $pdf->Cell(0, 6, '$' . $data[29], 0, 1);

            $pdf->Cell(120, 6, 'Sides Cost:', 0, 0);
            $pdf->Cell(0, 6, '$' . $data[31], 0, 1);

            $pdf->Cell(120, 6, 'HST:', 0, 0);
            $pdf->Cell(0, 6, '$' . $data[32], 0, 1);

            $pdf->Ln(2);
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(120, 8, 'Total Amount:', 0, 0);
            $pdf->Cell(0, 8, '$' . $data[33], 0, 1);

            $pdf->SetFont('helvetica', '', 10);
            $pdf->Cell(120, 6, 'Allowance Remaining:', 0, 0);
            $pdf->Cell(0, 6, '$' . $data[30], 0, 1);

            // Errors/Notes
            if (!empty($data[34])) {
                $pdf->Ln(5);
                $pdf->SetFont('helvetica', 'B', 10);
                $pdf->SetTextColor(255, 0, 0);
                $pdf->Cell(0, 6, 'Notes: ' . $data[34], 0, 1);
                $pdf->SetTextColor(0, 0, 0);
            }
        }

        // Save to temporary file
        $temp_file = tempnam(sys_get_temp_dir(), 'vac_invoice_') . '.pdf';
        $pdf->Output($temp_file, 'F');

        return $temp_file;
    }
}
