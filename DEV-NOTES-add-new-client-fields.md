# Add New Client UI Field Inventory

## 1️⃣ Overview
- **Location:** The Add New Client page loads `views/add-client.php`, which delegates to the reusable layout in `views/partials/client-form.php` and the renderer `includes/class-admin-ui.php` (method `render_client_form`). UI behaviors are handled primarily by `assets/js/admin.js` (field gating/requirements, alternate contact + delivery address toggles) and `assets/js/client-type-logic.js` (section-level visibility for SDNB and Veteran types).
- **Client type effects:** Selecting a client type updates field visibility and required flags. Row-level `data-client-type` attributes are shown/hidden by `toggleClientTypeSections()` in `assets/js/admin.js`, while section-level `data-client-type` attributes are toggled in `assets/js/client-type-logic.js`. Required inputs marked with `data-base-required` are only enforced when the current type is listed in the row’s `data-required-for` attribute. SDNB-only and Veteran-only sections hide when another type is selected; “staff” is also supported in the JS toggles even though it is not a selectable option in the UI.

## 2️⃣ Common Fields (All Client Types)
Fields listed here are rendered for SDNB, Veteran, and Private clients (the only options in the type selector). “Required?” reflects runtime enforcement after client type is chosen.

| UI Label | Field Name | Type | Required? | Notes |
| --- | --- | --- | --- | --- |
| Client Type * | `client_type` | select | Yes | Controls conditional visibility/requirements. Options: SDNB, Veteran, Private. |
| First Name * | `first_name` | text | Yes | Marked `data-base-required`. |
| Last Name * | `last_name` | text | Yes | Marked `data-base-required`. |
| Client Email | `client_email` | email | No | Marked `data-base-required` but not gated by `data-required-for`; validation only checks format if provided. |
| WordPress User ID | `wordpress_user_id` | number | No | Optional linking hint in description. |
| Open Date * | `open_date` | date | SDNB/Veteran/Private | Required when type matches `data-required-for`. |
| Phone Number * | `phone_primary` | text | SDNB/Veteran/Private | Phone mask formatter; required when type matches `data-required-for`. |
| Second Phone Number | `phone_secondary` | text | No | Optional secondary phone. |
| Do Not Call Client's Phone | `do_not_call_client_phone` | checkbox | No | Toggles preference to call alternate contact instead. |
| Street # * | `address_street_number` | text | SDNB/Veteran/Private | Base address; required when type matches `data-required-for`. |
| Street Name * | `address_street_name` | text | SDNB/Veteran/Private | Required per type. |
| Apt # * | `address_unit` | text | SDNB/Veteran/Private | Required per type. |
| City * | `address_city` | text | SDNB/Veteran/Private | Required per type. |
| Province * | `address_province` | text | SDNB/Veteran/Private | Required per type. |
| Postal Code * | `address_postal` | text | SDNB/Veteran/Private | Masked input; required per type. |
| Payment Method * | `payment_method` | text | SDNB/Veteran/Private | Required per type. |
| Required Start Date * | `required_start_date` | date | SDNB/Veteran/Private | Required per type. |
| Rate * | `rate_id` | select | SDNB/Veteran/Private | Multi-rate selector; rates stored in `meals_client_rates` table, linked via `default_rate_id` FK on `meals_clients`. |
| Delivery Fee | `delivery_fee` | text | No | Optional monetary field. |
| Freezer Capacity | `freezer_capacity` | text | No | Optional capacity note. |
| Delivery Day * | `delivery_day` | select | SDNB/Veteran/Private | Options populated from allowed values; required per type. |
| Delivery Area Name * | `delivery_area_name` | text | SDNB/Veteran/Private | Required per type. |
| Delivery Area Zone * | `delivery_area_zone` | text | SDNB/Veteran/Private | Required per type. |
| Ordering Contact Method * | `ordering_contact_method` | select | SDNB/Veteran/Private | Options from allowed list; required per type. |
| Ordering Frequency * | `ordering_frequency` | text | SDNB/Veteran/Private | Required per type. |
| Delivery Frequency * | `delivery_frequency` | text | SDNB/Veteran/Private | Required per type. |
| Delivery Initials * | `delivery_initials` | text | SDNB/Veteran/Private | Required for all selectable types; generate/validate buttons present. |
| Dietary Concerns | `diet_concerns` | textarea | No | Free-form notes. |
| Customer Comments | `client_comments` | textarea | No | Free-form notes. |

### Alternate Contact (collapsible)
| UI Label | Field Name | Type | Required? | Notes |
| --- | --- | --- | --- | --- |
| Alternate Contact toggle | _(n/a)_ | checkbox | No | Reveals the sub-fields below. |
| First Name | `(composes alt_contact_name)` | text | No | Combined with last name into hidden `alt_contact_name`. |
| Last Name | `(composes alt_contact_name)` | text | No | Combined with first name into hidden `alt_contact_name`. |
| Phone Number | `alt_contact_phone_primary` | text | No | Phone mask applied. |
| Second Phone Number | `alt_contact_phone_secondary` | text | No | Phone mask applied. |
| Contact Email | `alt_contact_email` | email | No | Optional email. |
| Hidden Full Name | `alt_contact_name` | hidden text | No | Auto-filled from first/last when toggled. |

### Delivery Address (collapsible)
| UI Label | Field Name | Type | Required? | Notes |
| --- | --- | --- | --- | --- |
| Delivery address different… toggle | _(n/a)_ | checkbox | No | Reveals the delivery address sub-fields. |
| Street # | `delivery_address_street_number` | text | No | Optional unless provided. |
| Street Name | `delivery_address_street_name` | text | No | Optional. |
| Apt # | `delivery_address_unit` | text | No | Optional. |
| City | `delivery_address_city` | text | No | Optional. |
| Province | `delivery_address_province` | text | No | Optional. |
| Postal Code | `delivery_address_postal` | text | No | Masked input; optional. |

## 3️⃣ SDNB-Specific Fields
Fields shown only when Client Type = SDNB (section `data-client-type="sdnb"`).

| UI Label | Field Name | Type | Required? | Notes |
| --- | --- | --- | --- | --- |
| Requisition Period | `requisition_period` | select | No (UI) / Yes (server for SDNB) | Options: Day, Week, Month. Server validation lists as required for SDNB. |
| Service Commence Date | `service_commence_date` | date | No | Optional date. |
| Expected Termination Date | `expected_termination_date` | date | No | Optional date. |
| Initial Renewal Termination Date | `initial_renewal_date` | date | No | Optional date. |
| Most Recent Renewal Termination Date | `most_recent_renewal_date` | date | No | Optional date. |
| Client Contributions | `client_contribution` | text | No | Optional amount/description. |
| Individual ID | `individual_id` | text | No | Optional; flagged unique server-side. |
| Gender | `gender` | radio (Male/Female/Other) | No | Optional gender selection. |
| Service Center Charged | `service_center_charged` | text | No | Optional billing center. |
| Vendor # | `vendor_number` | text | Yes (server for SDNB) | Required in PHP for SDNB clients. |
| Service ID | `service_id` | text | Yes (server for SDNB) | Required in PHP for SDNB clients. |
| Requisition ID | `requisition_id` | text | No | Optional; marked unique server-side. |
| Service Name Zone | `service_zone` | select | No | Options sourced from allowed values. |
| Meal Type | `meal_type` | select | No | Options: 1 Course, 2 Course. |

## 4️⃣ Veteran-Specific Fields
Fields shown only when Client Type = Veteran (section `data-client-type="veteran"`).

| UI Label | Field Name | Type | Required? | Notes |
| --- | --- | --- | --- | --- |
| Veteran Health Identification Card # * | `vet_health_card` | text | Yes (UI + server) | Marked `data-base-required`; server lists as required for Veteran clients. |

## 5️⃣ Other Conditional / Optional Fields
- **Date of Birth (`birth_date`)** – Shown only for SDNB and Veteran types via `data-client-type="sdnb,veteran"`; optional.
- **# of Units (`units`)** – Shown and marked required in UI for SDNB and Veteran types; server validation does range checks but does not mark it required by type list.
- **Social Worker Name/Email (`assigned_social_worker`, `social_worker_email`)** – Rendered only for SDNB and Veteran types.
- **Open Date** and several service/delivery fields carry `data-required-for` attributes, making them required only when the client type matches; toggled at runtime by `assets/js/admin.js`.
- **Delivery Initials & Notes section** is hidden when the (non-selectable) “staff” type is chosen in JS; otherwise always shown.

## 6️⃣ Field Name Normalization Notes
- The alternate contact name stored in `alt_contact_name` is composed from the visible first/last inputs; no direct visible field uses this name.
- Service zone is labeled “Service Name Zone” in the SDNB section but posts as `service_zone`.
- Meal selection uses the `meal_type` field even though the database mapping also includes `service_course`; the UI does not expose `service_course` directly.
- Delivery initials use label “Initials for Delivery” but post as `delivery_initials`; uniqueness checks also reference `delivery_initials_index` server-side.

## 7️⃣ Observations (No Recommendations)
- Client type “Staff” is handled in the visibility JS but is not an option in the `client_type` select, so staff-only hiding logic never triggers through the UI.
- Several fields marked required in the UI via `data-required-for` (e.g., `delivery_day`, address fields) are *not* part of the PHP-required list for some types (notably Private clients only require a subset server-side), meaning client-side and server-side required sets differ.
- The `units` field is marked required in the UI for SDNB/Veteran but is not included in the PHP required list, though numeric bounds are validated if provided.
- Validation enforces format for phone/postal/email fields when filled, but optional fields without values pass silently.
