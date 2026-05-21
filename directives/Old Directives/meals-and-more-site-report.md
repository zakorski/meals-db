# Meals and More — Site Report

**Generated:** March 16, 2026 | **Environment:** staging.mealsandmore.ca | **WordPress:** 6.9.4 | **WooCommerce:** 10.6.1

---

## 1. Custom User Fields

The site uses a custom "Custom User Fields" plugin (v1.0 by Enzebra) to add a rich set of client management fields to every user profile. These are organized into two groups:

### SDNB / Client Service Fields

| Field Label | Input Name | Type |
|---|---|---|
| Customer Group | `customer_group` | text |
| Service ID | `service_id` | text |
| Requisition ID | `requisition_id` | text |
| Individual ID | `individual_id` | text |
| Basic Cost | `basic_cost` | text |
| Rate | `rate` | text |
| Payment Method | `payment_method` | text |
| Mains | `mains` | text |
| Sides | `sides` | text |
| Service | `service` | text |
| Commence Date | `commence_date` | date |
| Service Termination Date | `service_termination_date` | date |
| Service Centre Charged | `service_centre_charged` | text |
| Ordering Frequency | `ordering_frequency` | text |
| Ordering Contact Method | `ordering_contact_method` | text |
| Delivery Frequency | `delivery_frequency` | text |
| Freeze Capacity | `freeze_capacity` | text |
| Delivery Fee | `delivery_fee` | number |
| Requisition Units | `requisition_units` | number |
| Last Call Date | `last_call_date` | date |
| Last Order Date | `last_order_date` | datetime-local |
| Dietary Needs | `dietary_needs` | text |
| Client Contribution | `contribution` | text |
| Customer Comments | `customer_comments` | textarea |
| Next Email Date | `next_email_date` | textarea |
| Last Order Date (2nd) | `last_order_date` | textarea |

### WooCommerce Billing Address Fields (with custom addition)

| Field Label | Input Name | Notes |
|---|---|---|
| First name | `billing_first_name` | Standard |
| Last name | `billing_last_name` | Standard |
| Company | `billing_company` | Standard |
| **VAT Number** | `vat_number` | **Custom — added by site** |
| Address line 1 | `billing_address_1` | Standard |
| Address line 2 | `billing_address_2` | Standard |
| City | `billing_city` | Standard |
| Postcode / ZIP | `billing_postcode` | Standard |
| Country / Region | `billing_country` | Standard |
| State / County | `billing_state` | Standard |
| Phone | `billing_phone` | Standard |
| Email address | (WC standard) | Standard |

### WooCommerce Shipping Address Fields

Standard WooCommerce fields: `shipping_first_name`, `shipping_last_name`, `shipping_company`, `shipping_address_1`, `shipping_address_2`, `shipping_city`, `shipping_postcode`, `shipping_country`, `shipping_state`, `shipping_phone`.

---

## 2. Products

**Total Products: 171** (170 Published, 1 Private)

### Pricing Structure

| Price | Usage |
|---|---|
| \$9.05 | Standard main meal price |
| \$4.10 | Side / soup / dessert price |
| \$9.20 | Alternative meal price |
| \$63.35 | Minimum order fee product |
| \$10.00 | Misc fee |
| \$1.00 | Misc / overage unit |
| \$75.00 / \$100.00 / \$150.00 / \$200.00 | Gift card denominations |
| \$0.00 | Internal / zero-price items |

**Currency:** CAD ($) | **Tax:** HST (NB: 15%, NS: 14%)

### Product Categories (27 total)

Categories are hierarchical. Top-level and sub-categories:

**Main** (113 products)
Sub-categories: Beef (23), Chicken & Turkey (26), Diabetic (57), Fish (9), Gluten Free (4), Low Calorie (71), Low Fat (59), Low Sodium (20), Medical (6), Minced (11), Pork (7), Pureed (15), Special Diet (26), Vegan (8), Vegetarian (16)

**Dessert** (24 products) | **Soup** (22 products) — sub: Thickened (7) | **Uncategorized** (33 products) | **Cereal** (2 products) | **Muffin** (2 products) | **FEE** (4 products) | **Gift Cards** (5 products) | **Discontinued** (12 products) — sub: overage (1) | **special-product-category** (1 product)

### Sample Products (illustrative range)

Products follow a naming convention of `[Product Name] #[SKU]`. A representative selection:

- **Mains (\$9.05):** Beef Stew #12008, Chicken Pot Pie #12135, Salmon in Lemon Sauce #12063, Cheese Perogies with Sour Cream Sauce #12206, Vegetarian Chili #10062
- **Soups (\$4.10):** Beef Barley Soup #93007, Chicken Noodle Soup #93355, Cream of Tomato Soup #93023
- **Desserts (\$4.10):** Cherry Cheesecake #14015, Lemon Tart #14056, Strawberry Shortcake #14092
- **Cereals:** Oatmeal #94001, Cream of Wheat Cereal #94000
- **Gift Cards:** \$75, \$100, \$150, \$200
- **Fee Products:** Delivery Fee (Product ID 4122), Client Contribution (Product ID 5675)

---

## 3. Orders

**Total Orders: 16,665**

### Order Status Breakdown

| Status | Count |
|---|---|
| Processing | 11,742 |
| Completed | 3,193 |
| Paid (custom status) | 1,670 |
| Cancelled | 60 |
| Draft | 1 |
| **Total** | **16,665** |

### Order Columns / Fields

The orders list table contains: Order #, Date, Status, Billing (name + address), Ship to, Total, Actions, Invoice, Invoice Action, Origin (sales channel).

### Sales Channels

Orders originate from three channels: **Web admin** (POS/admin-placed), **Checkout** (customer self-serve), and **Point of Sale**.

### Payment Methods

Custom payment methods configured: **eTransfer**, **Cash**, **Invoice**, **Other**, and **N/A**.

### Tax Rates Applied

| Rate Name | Class | Code | Rate |
|---|---|---|---|
| HST | HST | CA-NB-HST-1 | 15% |
| HST | HST | CA-NS-HST-2 | 14% |
| Tax | Standard | CA-NS-TAX-1 | 15% |
| Tax | Standard | CA-NB-TAX-1 | 14% |

### Date Range of Orders

Order history spans from **July 2024** through **April 2026**, with the most recent orders in March/April 2026.

### Sample Order Structure (Order #22985)

- **Customer:** Thomas Cormier | **Total:** \$187.82 | **Status:** Processing
- Contains 24 line items (mains, soups, desserts at their respective prices)
- Items subtotal: \$184.10 + HST: \$3.72
- Customer note support (e.g. "TAKE FROM HOLD")
- Custom order note log is maintained per order

---

## 4. Database Schema

**DB Engine:** MySQL 8.0.45 | **Prefix:** `2xnIt_` | **Total Size:** 453.78 MB (Data: 212.32 MB + Index: 241.46 MB)

**Order Data Store:** HPOS (High Performance Order Storage) via `OrdersTableDataStore` — orders are stored in dedicated custom tables, not `wp_posts`.

### Core WordPress Tables

| Table | Data | Index | Notes |
|---|---|---|---|
| `2xnIt_posts` | 20.52 MB | 5.48 MB | Pages, products, revisions, etc. |
| `2xnIt_postmeta` | 17.52 MB | 10.55 MB | Post/product metadata |
| `2xnIt_usermeta` | 6.52 MB | 9.03 MB | All user custom fields stored here |
| `2xnIt_users` | 0.33 MB | 0.30 MB | Core user accounts |
| `2xnIt_options` | 2.52 MB | 0.13 MB | Site settings |
| `2xnIt_comments` | 17.55 MB | 18.13 MB | Comments (291 total) |
| `2xnIt_commentmeta` | 1.52 MB | 0.58 MB | Comment metadata |
| `2xnIt_terms` | 0.02 MB | 0.03 MB | Taxonomy terms |
| `2xnIt_term_taxonomy` | 0.02 MB | 0.03 MB | Taxonomy definitions |
| `2xnIt_term_relationships` | 0.11 MB | 0.08 MB | Post-to-term relationships |
| `2xnIt_termmeta` | 0.02 MB | 0.03 MB | Term metadata |
| `2xnIt_links` | 0.02 MB | 0.02 MB | Blogroll links |

### WooCommerce HPOS Order Tables (Primary order storage)

| Table | Data | Index | Notes |
|---|---|---|---|
| `2xnIt_wc_orders` | 5.52 MB | 9.98 MB | Core order records |
| `2xnIt_wc_orders_meta` | 11.52 MB | 33.30 MB | Order custom metadata |
| `2xnIt_wc_order_addresses` | 4.52 MB | 7.58 MB | Billing/shipping addresses |
| `2xnIt_wc_order_operational_data` | 2.52 MB | 1.91 MB | Operational order data |
| `2xnIt_wc_order_stats` | 2.52 MB | 5.05 MB | Order statistics |
| `2xnIt_wc_order_product_lookup` | 15.56 MB | 35.61 MB | Product-to-order lookup (largest table) |
| `2xnIt_wc_order_tax_lookup` | 1.52 MB | 0.77 MB | Tax-per-order lookup |
| `2xnIt_wc_order_coupon_lookup` | 0.02 MB | 0.03 MB | Coupon-per-order lookup |
| `2xnIt_woocommerce_order_items` | 10.52 MB | 10.03 MB | Order line items |
| `2xnIt_woocommerce_order_itemmeta` | 74.61 MB | 86.23 MB | Line item metadata (largest overall) |

### WooCommerce Supporting Tables

| Table | Data | Index |
|---|---|---|
| `2xnIt_wc_customer_lookup` | 0.17 MB | 0.17 MB |
| `2xnIt_wc_product_meta_lookup` | 0.05 MB | 0.11 MB |
| `2xnIt_wc_product_attributes_lookup` | 0.08 MB | 0.14 MB |
| `2xnIt_woocommerce_sessions` | 1.52 MB | 0.13 MB |
| `2xnIt_woocommerce_tax_rates` | 0.02 MB | 0.06 MB |
| `2xnIt_woocommerce_tax_rate_locations` | 0.02 MB | 0.03 MB |
| `2xnIt_woocommerce_shipping_zones` | 0.02 MB | 0.00 MB |
| `2xnIt_woocommerce_api_keys` | 0.02 MB | 0.03 MB |
| `2xnIt_wc_admin_notes` | 0.08 MB | 0.00 MB |
| `2xnIt_wc_rate_limits` | 0.02 MB | 0.02 MB |

### Plugin-Specific Tables

| Table(s) | Plugin | Total Size | Notes |
|---|---|---|---|
| `2xnIt_icwp_wpsf_*` (9 tables) | Shield Security | ~8 MB | Bot signals, IP rules, scans, malware |
| `2xnIt_e_submissions*` (5 tables) | Elementor Forms | ~0.02 MB | Form submissions |
| `2xnIt_actionscheduler_*` (4 tables) | Action Scheduler (WC) | ~0.12 MB | Scheduled background jobs |
| `2xnIt_woocommerce_gc_*` (3 tables) | WooCommerce Gift Cards | ~0.02 MB | Gift card data |
| `2xnIt_woostify_*` (7 tables) | Woostify Theme | ~0.93 MB | Filter/product/category indexes |
| `2xnIt_wpforms_*` (4 tables) | WPForms | ~0.02 MB | Form payments and logs |
| `2xnIt_snippets` | Code Snippets | 0.02 MB | Custom code snippets |
| `2xnIt_wpmailsmtp_debug_events` | WP Mail SMTP | 0.52 MB | Email debug log |
| `2xnIt_tinvwl_*` (3 tables) | Wishlist plugin | ~0.02 MB | Wishlists/analytics |
| `2xnIt_smush_dir_images` | Smush Pro | 0.02 MB | Compressed image records |

### Post Type Counts (from WC Status)

| Post Type | Count | Notes |
|---|---|---|
| `shop_order_placehold` | 16,661 | HPOS order placeholders in wp_posts |
| `product` | 171 | WooCommerce products |
| `revision` | 659 | Post revisions |
| `attachment` | 403 | Media attachments |
| `inspire_invoice` | 259 | Generated invoices |
| `elementor_library` | 18 | Elementor templates |
| `page` | 19 | Site pages |
| `post` | 11 | Blog posts |
| `wpcf7_contact_form` | 7 | Contact Form 7 forms |

---

## Summary

This is a meal delivery service operating in the Atlantic Canada region (New Brunswick / Nova Scotia), with ~16,665 orders, 171 products, and a heavily customized WooCommerce installation. The site uses an extensive set of client service tracking fields per user (SDNB data), custom order statuses, custom payment methods, and numerous in-house Enzebra-built plugins for reporting and operations. The database totals 454 MB with the bulk of data concentrated in WooCommerce HPOS order tables and line item metadata.
