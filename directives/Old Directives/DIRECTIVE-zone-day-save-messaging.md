# Directive — Surface zone-day propagation counts on Settings save (fix C8)

## Problem (confirmed in code)
When an operator changes a zone's delivery day on Settings → Zone Delivery Schedule and clicks Save
Settings, the propagation to clients runs correctly, but the UI only ever says **"Settings saved."** — it
does NOT report how many clients were updated or which zones changed. An operator gets no confirmation
that a schedule edit re-routed clients (and no sanity-check that they didn't move more clients than
intended). GUI test v500 flagged this as C8 (messaging gap; propagation itself verified working).

Root cause: `MealsDB_Ajax_Settings::save_settings()` calls
`MealsDB_Zone_Day::propagate_schedule_change($old_schedule, $schedule)` but **discards its return value**
and always responds `wp_send_json_success(['message' => 'Settings saved.'])`. The stats already exist —
they're just thrown away.

This is a messaging-only fix. Do NOT change propagation logic, the save flow, or any data behavior.

## Reference (v1.0.500)
- `includes/ajax/class-ajax-settings.php::save_settings()`:
  - The zone-schedule block calls `MealsDB_Zone_Day::propagate_schedule_change($old_schedule, $schedule)`
    (~line n+120) WITHOUT capturing the result.
  - Final response: `wp_send_json_success(['message' => 'Settings saved.'])` (end of method).
- `includes/services/class-zone-day.php::propagate_schedule_change()` returns:
  `['changed_zones' => string[], 'clients_updated' => int, 'dropped_zones' => string[]]`
  (`dropped_zones` = zones that had active clients but were removed/blanked — worth surfacing as a
  warning; the method already records a degraded event for them internally).
- `assets/js/settings.js` (~line 413-426): posts `mealsdb_save_settings`; on success does
  `$result.text('Settings saved.'); tint($result, '#46b450');` into `#mealsdb-save-result`.

## Change

### 1. Capture the propagation stats in save_settings()
Where the schedule change is propagated, capture the return value:
```php
$propagation = null;
if ( is_array( $old_schedule ) && class_exists( 'MealsDB_Zone_Day' ) ) {
    $propagation = MealsDB_Zone_Day::propagate_schedule_change( $old_schedule, $schedule );
}
```
Hold `$propagation` in a variable scoped to the whole method (declare it null before the zone block, so
the final response can see it whether or not a schedule was submitted).

### 2. Include the stats in the success response
Replace the final `wp_send_json_success( [ 'message' => 'Settings saved.' ] );` with a response that adds
the propagation summary when a schedule change actually moved clients:
```php
$response = [ 'message' => 'Settings saved.' ];
if ( is_array( $propagation )
    && ( ! empty( $propagation['clients_updated'] ) || ! empty( $propagation['dropped_zones'] ) ) ) {
    $response['propagation'] = [
        'clients_updated' => (int) ( $propagation['clients_updated'] ?? 0 ),
        'changed_zones'   => array_values( (array) ( $propagation['changed_zones'] ?? [] ) ),
        'dropped_zones'   => array_values( (array) ( $propagation['dropped_zones'] ?? [] ) ),
    ];
}
wp_send_json_success( $response );
```
Keep the base message unchanged so nothing else that relies on it breaks. `propagation` is additive.

### 3. Render the summary in settings.js
In the `mealsdb_save_settings` success handler, after setting the base "Settings saved." message, append
the propagation detail when present:
```js
if (resp && resp.success) {
    var msg = 'Settings saved.';
    var p = resp.data && resp.data.propagation;
    if (p) {
        if (p.clients_updated > 0) {
            msg += ' ' + p.clients_updated + ' client' + (p.clients_updated === 1 ? '' : 's')
                 + ' updated' + (p.changed_zones && p.changed_zones.length
                     ? ' (' + p.changed_zones.join(', ') + ')' : '') + '.';
        }
        if (p.dropped_zones && p.dropped_zones.length) {
            msg += ' Warning: ' + p.dropped_zones.length + ' zone(s) with active clients were removed —'
                 + ' those clients now have no delivery day and will not appear on slips until re-zoned.';
        }
    }
    $result.text(msg); tint($result, p && p.dropped_zones && p.dropped_zones.length ? '#dc3232' : '#46b450');
    $('#mealsdb-key-warning').hide();
} else { /* unchanged error branch */ }
```
- If `clients_updated` is 0 and there were no dropped zones (e.g. only a label edit, or a non-schedule
  settings save), the message stays exactly "Settings saved." — no behavior change for the common case.
- Dropped zones (active clients orphaned by a zone removal) tint the result RED as a warning, since that
  is the silent-drop failure mode the whole feature guards against.

## Must NOT change
- `propagate_schedule_change()` logic, the save/merge flow, encryption-key handling, overage validation,
  or the derived-autocorrect toggles. Only capture-and-surface the already-computed stats.
- The base "Settings saved." text must remain for non-schedule saves.

## Verify
```
php -l includes/ajax/class-ajax-settings.php
php tests/test-*.php
```
- Change a zone's day for a zone WITH clients → Save → the result line reads e.g.
  "Settings saved. 5 clients updated (Zone 1)." and the client(s) reflect the new day (unchanged behavior).
- Change only a zone LABEL (not the day) → Save → "Settings saved." with no client count (no day change,
  no clients moved).
- Save settings with no schedule change at all (e.g. toggle shadow mode) → "Settings saved." unchanged.
- Remove/blank a zone that has active clients → Save → red warning naming the dropped zone(s).
- Confirm the client-updated count matches the number actually re-dayed (cross-check one zone).

## Test to add / extend
Extend the zone-day test (or `test-zone-day.php`): assert `propagate_schedule_change()` returns the
expected `clients_updated` / `changed_zones` for a day change, and (if handler-level tests exist) that
`save_settings` includes a `propagation` block in its JSON when a day changed and omits it when only a
label changed.
