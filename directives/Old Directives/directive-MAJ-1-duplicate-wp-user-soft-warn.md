# Directive MAJ-1 — Duplicate wp_user_id: soft-warn a legitimate case (without reintroducing mis-routing)

**Status:** ready to implement — BUT read "The catch" first; this is NOT a pure relaxation.
**Severity:** MAJOR — operational friction. The operator has confirmed a LEGITIMATE case the code
currently blocks: a person who is both an SDNB recipient AND a Veteran maps to one WordPress user
across two client records. Today that link is hard-refused.
**Verified at:** v1.0.406, `includes/services/sync/class-sync-mutate.php` (~line 658), and the
resolver it protects.

---

## THE CURRENT BEHAVIOR (verified)

`link_wp_user()` refuses to link a WP user that's already linked to a *different* client
(sync-mutate ~658–685): a `SELECT ... WHERE wp_column = user_id AND pk <> client_id LIMIT 1`, and
on a hit returns `WP_Error('mealsdb_link_user_already_linked', ...)` after rollback.

## THE CATCH (why this is not a one-line relaxation)

The refusal's own comment explains WHY it exists:

> "multiple clients sharing a WP user yields nondeterministic results in
> `find_government_client_by_wp_user` (which uses LIMIT 1) and silently mis-routes orders."

So the hard-fail is load-bearing: it prevents a `LIMIT 1` resolver from arbitrarily picking ONE
of the two client records when an order comes in, which would silently bill the wrong program.
**If we simply relax the refusal to a warning, we trade a blocked-but-safe state for an
allowed-but-silently-wrong one.** The operator wants the dual-program person *allowed* — which
means the directive must ALSO make order→client resolution deterministic when a WP user maps to
multiple clients. Relaxing the gate without fixing the resolver would be a regression dressed as
a feature.

---

## PRE-FLIGHT VERIFICATION

```bash
cd <plugin-root>

# 1. The refusal site.
grep -n "mealsdb_link_user_already_linked\|already linked to a different client" includes/services/sync/class-sync-mutate.php

# 2. The resolver the refusal protects — HOW does it pick a client for an order?
grep -rn "function find_government_client_by_wp_user\|LIMIT 1" includes/services/ --include=*.php | grep -i "wp_user\|government_client"
#    Read this function. The fix's correctness hinges on how an order knows WHICH
#    of a user's client records it belongs to (program? order contents? a flag?).
#    STOP and surface to the operator if there is NO order-level signal that
#    distinguishes SDNB-vs-Veteran orders — see "The resolution question".

# 3. Other consumers that assume one-client-per-user.
grep -rn "by_wp_user\|client_for_user\|wp_user.*client" includes/ --include=*.php | grep -iv "test" | head
```

---

## THE RESOLUTION QUESTION (resolve before coding the resolver change)

For a dual-program person, an incoming WooCommerce order must route to the RIGHT client record
(SDNB vs Veteran). The directive needs to know what on the order distinguishes them. Candidates,
in order of likelihood — confirm which exists by reading the order/product model:

1. **Product/program signal on the order** — e.g. VAC orders contain veteran-program products,
   SDNB orders contain SDNB products. If a reliable signal exists, the resolver can pick the
   client whose `client_type` matches the order's program. *Preferred — deterministic from order
   contents.*
2. **A per-order client_id / rate_id meta** — the order already carries `mealsdb_rate_id`
   (seen in WC_Order_Query). If that (or a similar meta) pins the client, the resolver uses it
   directly. *Also deterministic.*
3. **No order-level signal exists** — then dual-program routing genuinely cannot be made
   deterministic from the order alone, and this becomes an operator/process decision (e.g.
   separate WP users per program after all). **If pre-flight #2 shows no signal, STOP and raise
   this with the operator rather than shipping a non-deterministic resolver.**

This directive proceeds assuming signal (1) or (2) exists (the codebase already threads
`mealsdb_rate_id` and `client_type`, so it likely does). The implementer MUST confirm before
writing the resolver branch.

---

## THE FIX

### Step 1 — Soft-warn instead of hard-refuse on link
In `link_wp_user()`, when an existing different-client link is found:
- Do NOT return `WP_Error` / rollback. Instead, proceed with the link, AND emit a visible
  operational warning to the STR-LOG trunk (this is an attempt/outcome, NOT a committed-data
  change → the **trunk**, `degraded`/`warning`, not the audit log):

```php
if ($existing_client !== null) {
    // Operator-confirmed legitimate case: a person who is both an SDNB
    // recipient and a Veteran maps to one WP user across two client
    // records. Allow the link, but flag it — and rely on the resolver
    // (Step 2) to route orders deterministically by program.
    if (class_exists('MealsDB_Event_Log')) {
        MealsDB_Event_Log::record([
            'severity'    => 'warning',
            'category'    => 'sync',
            'subsystem'   => 'sync_mutate',
            'event'       => 'link_wp_user.duplicate_allowed',
            'outcome'     => MealsDB_Event_Log::OUTCOME_DEGRADED,
            'message'     => sprintf('WP user %d now linked to multiple clients (existing %d, new %d) — dual-program; resolver routes by program.',
                                     $user_id, (int) $existing_client, $client_id),
            'entity_type' => 'user',
            'entity_id'   => $user_id,
            'context'     => ['existing_client' => (int) $existing_client, 'new_client' => $client_id],
        ]);
    }
    // fall through to the UPDATE (do NOT rollback, do NOT return WP_Error)
}
```

Keep the transaction semantics otherwise intact (the link UPDATE still runs in the same
transaction). The ONLY change is: a duplicate is no longer fatal.

### Step 2 — Make the resolver deterministic for multi-client users
In `find_government_client_by_wp_user` (the `LIMIT 1` resolver), add an order-program parameter
so it picks the client whose program matches the order, instead of an arbitrary `LIMIT 1`:
- Signature gains the program/type (or the order's `mealsdb_rate_id` / detected program).
- Query: `WHERE wp_user_id = %d AND client_type = %s LIMIT 1` (or match on rate_id).
- **Keep a safe fallback:** if the user has exactly ONE client, the program param is irrelevant
  and the single row is returned (preserves today's behavior for the 99% single-program case).
- If the user has multiple clients and NO program match (shouldn't happen, but defensively),
  return the existing `LIMIT 1` result AND emit a `degraded` trunk event
  (`resolver.ambiguous_multi_client`) so the mis-route is at least VISIBLE rather than silent —
  turning the original "silently mis-routes" risk into a logged, greppable event.

Update the resolver's call sites to pass the order program/type. (Find them via pre-flight #3.)

### Step 3 — schema note
Do NOT add a `UNIQUE` constraint on `wp_user_id` (the comment notes the schema doesn't declare
one). Adding it would re-impose the hard block at the DB level. The whole point is that the
column is intentionally non-unique now; routing is resolved at the application layer (Step 2).

---

## TESTS (`tests/test-link-wp-user-dual-program.php`)

- **T-1 duplicate link allowed:** linking a WP user already linked to a different client now
  SUCCEEDS (no WP_Error), and the UPDATE ran. (Contrast the OLD behavior explicitly.)
- **T-2 duplicate link warns:** the allowed-duplicate path emits a `degraded`
  `link_wp_user.duplicate_allowed` trunk event with both client IDs in context.
- **T-3 resolver routes by program:** a user with two clients (SDNB + Veteran) → resolver returns
  the SDNB client for an SDNB-program order and the Veteran client for a veteran-program order.
- **T-4 single-client unchanged:** a user with one client → resolver returns it regardless of the
  program param (the 99% path is untouched).
- **T-5 ambiguous multi-client is logged, not silent:** a user with two clients and no program
  match → resolver returns a result AND emits `resolver.ambiguous_multi_client` degraded event.
- **T-6 no UNIQUE constraint introduced:** (schema test) `wp_user_id` is not declared UNIQUE.

Run new test + FULL suite. Regression-sensitive: anything that links users or resolves
orders→clients (sync tests, order-fees, allocation hooks that look up the client for an order).
Confirm the resolver signature change didn't break a call site (pre-flight #3 list).

---

## ACCEPTANCE CRITERIA

1. Linking a WP user to a second client no longer hard-fails; it succeeds with a `degraded` trunk
   warning naming both clients.
2. `find_government_client_by_wp_user` routes deterministically by order program when a user has
   multiple clients; single-client users behave exactly as before.
3. An unresolvable multi-client case is LOGGED (degraded), never silently arbitrary.
4. No `UNIQUE` constraint added to `wp_user_id`.
5. New test green; full suite green; resolver call sites updated and verified.

---

## OUT OF SCOPE / ESCALATION

- **If pre-flight #2 reveals no order-level program signal**, the resolver cannot be made
  deterministic — STOP and escalate to the operator (the soft-warn alone would be unsafe). Do not
  ship Step 1 without Step 2.
- Merging the two client records into one — explicitly NOT the goal; the operator wants two
  records (different programs, different billing) under one WP user.
- Any change to how WP users are created at checkout — out of scope.
