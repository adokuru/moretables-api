# Permission matrix — what each access-config permission actually does

The 13 permissions assignable via the Access Config editor
(`PERMISSION_LABELS`, `moretable-web-app/src/lib/api/accounts/accounts.types.ts`),
what they unlock in `/dashboard` (front of house) vs `/admin` (back of
house), and whether it's a read or a write. Built by grepping every
`hasRestaurantPermission()` / `hasAnyRestaurantPermission()` /
`FormRequest::authorize()` call in the codebase.

## Every permission is now enforced

As of the roles-and-permissions pass described below, all 13 permissions
gate a real backend check — none are UI-only anymore. Every new check is
**additive**: `restaurants.manage` (and `restaurants.view` for read-only
checks) remains a valid fallback alongside the specific permission, so
existing restaurants relying on the broad "Restaurant Settings"/"Restaurant
Profile" permissions keep working exactly as before. The one deliberate
exception is `reporting.export`, which is intentionally **not** granted by
`reservations.view`/`audit_logs.view` — see its row below.

## The matrix

| Permission | Label (UI) | Dashboard | Admin | Read/Write | What it gates |
|---|---|:-:|:-:|---|---|
| `reservations.manage` | Reservations Management | ✅ | — | Write | Creating/editing/canceling/arriving/seating reservations (`MerchantReservationController`), Guestbook edits, creating shift notes, broadcasting messages to guests (fallback alongside `communications.manage`/`messaging.manage` — see below), adding a server. **Implies and locks `reservations.view` in the Access Config editor** (`AccessConfigModal.tsx`) — same "manage implies view" pairing as `restaurants.manage`/`restaurants.view` below, since editing something you can't view doesn't make sense. |
| `reservations.view` | View Reservations | ✅ | (via `reporting` fallback) | Read | Nearly every FOH `GET` the dashboard depends on — summary, reservations, arrived, waitlist, seated, finished, floor plan, timelines, shift overview, guestbook, etc. (per-endpoint `abort_unless`, not route middleware — see each controller listed under `reservations.manage`'s write counterparts). Without it the dashboard's first fetch 403s and the user is bounced to `/access-denied`; it does not gate the `/admin` section itself, except as one of three fallbacks (alongside `reporting.export`/`audit_logs.view`) for `/admin/reporting`. Was already enforced backend-side and already assignable via the API before this change — it just had no checkbox in `AccessConfigModal.tsx` until now. |
| `tables.manage` | Floor Configurations | ✅ | ✅ | Both | **Dashboard**: viewing/assigning tables on the live floor plan. **Admin**: `availability-planning`'s Floor Plan + Table Combinations tabs — full CRUD on dining areas, dining spots, tables, table combinations, table status. Also part of `Permission::adminSectionPermissions()` — unlocks `/admin` on its own. |
| `audit_logs.view` | Reporting View | — | ✅ | Read | `/admin/reporting`'s view endpoints (`filters`, `shiftOccupancy`, `coverTrends`, `firstTimeVisits`, `guestFrequency`, `reservations`, `turnTimes`, `guestExport` — `MerchantReportingController::authorizeReporting()`), additive alongside the existing `reservations.view` check. Does **not** grant export — see `reporting.export`. |
| `reporting.export` | Reporting Exporting Data | — | ✅ | Write-ish (export) | The 3 CSV export endpoints on `MerchantReportingController` (`exportGuestFrequency`/`exportReservations`/`exportGuestExport`) and `MerchantGuestSurveyController::exportResponses`. **Deliberately its own gate** — only OR'd with `restaurants.manage`, not `reservations.view`/`audit_logs.view`, so viewing and exporting are genuinely distinct capabilities (a user who can see a report can't necessarily download it). |
| `restaurants.manage` | Restaurant Settings | (edge case) | ✅ | Write | **The workhorse write permission**, unchanged. Billing checkout/upgrade/verify, restaurant profile update, menu/gallery/media CRUD, internal notes write, guest-communication settings write, guest survey CRUD, booking + cancellation policy write, shift + special-day CRUD, reward rules write, onboarding step writes, restaurant-settings update, widget settings. Also the universal fallback for every one of the newly-wired permissions below. |
| `integrations.manage` | Integrations | — | ✅ (frontend-only) | n/a | No backend controller exists yet for Integrations — nothing to enforce server-side. Gated purely on the frontend (nav + route guard, `moretable-web-app/src/lib/adminAccess.ts`). |
| `marketing.manage` | Marketing | — | ✅ | Both | `MerchantRewardRuleController` (index/show/store/update/destroy) and its two `FormRequest`s — OR'd alongside `restaurants.view`(read)/`restaurants.manage`(write). This is the only backend surface behind the `/admin/marketing` page (reward points, private dining, campaigns, promos are all UI over the same reward-rule resource). |
| `restaurants.view` | Restaurant Profile | ✅ | ✅ (view-only) | Read | **The workhorse read permission**, unchanged — nearly every `GET` across dashboard and admin. On its own it grants **view-only** access to `/admin/restaurant-profile`; editing that page (and everywhere else `restaurants.manage` already gated) still requires `restaurants.manage` — this was an explicit product decision, not a backend change (see `ADMIN_ACCESS_CONTROL.md`/`access-control.md`). |
| `billing.manage` | Billing | — | ✅ | Both (single tier) | `MerchantBillingController` (`show`/`checkout`/`upgrade`/`verify`/`invoices`/`downloadInvoice`) — OR'd alongside the existing `restaurants.view`/`restaurants.manage` checks. Deliberately single-tier (view + pay together, no separate view-only mode), per product decision. **Frontend nav/route-guard no longer mirrors the `restaurants.manage` OR** (`adminAccess.ts`'s `/admin/billing` rule requires only `billing.manage` now) — a config with just Restaurant Settings checked was showing Billing in the nav, which read as a bug once Marketing/Integrations/Guest Communication's own nav items were already strict (their own dedicated checkbox only, no `restaurants.manage` fallback). The backend OR above is untouched — a restaurant that could reach Billing via `restaurants.manage` before this still can via a direct API call, only the nav affordance was removed. |
| `communications.manage` | Communications Channels | — | ✅ | Both | Maps to the **Guest Email** + **Surveys and Templates** tabs of `/admin/guest-communication`. `MerchantGuestCommunicationController::show`/`updateReservationMessaging`, all of `MerchantGuestSurveyController`, and `MerchantRestaurantBroadcastController::store` when `audience !== "all"` (i.e. the Guest Email tab's "send to selected guests" flow). |
| `messaging.manage` | Direct Messaging | — | ✅ | Both | Maps to the **Broadcast Messaging** tab of `/admin/guest-communication`. `MerchantGuestCommunicationController::show`/`updateAutomatedMessaging`, and `MerchantRestaurantBroadcastController::store` when `audience === "all"`. |
| `policies.manage` | Policies | — | ✅ | Both | Maps to the **Policies** tab of `/admin/restaurant-settings`. `MerchantRestaurantBookingPolicyController`, `MerchantRestaurantCancellationPolicyController`, and their `FormRequest`s (`UpdateRestaurantBookingPolicyRequest`, `Store`/`UpdateRestaurantCancellationPolicyRequest`) — OR'd alongside `restaurants.manage`. |

Two more permissions exist and are fully enforced, but aren't in the
13-item assignable list above (they're either always-on for certain default
configs or handled specially):

| Permission | Dashboard | Admin | What it gates |
|---|:-:|:-:|---|
| `waitlist.manage` | ✅ | — | Everything waitlist: the dashboard's waitlist column, availability alerts, and every waitlist-entry action (arrive, notify, cancel, assign table, ...). |
| `staff.manage` | ✅ | ✅ | **Both.** Dashboard's User Management (removed — staff management is now admin-only, see `moretable-web-app/AGENTS.md`), and `/admin/accounts`' entire Users + Access Config tabs. **Now included in `Permission::adminSectionPermissions()`** (previously deliberately excluded) — a staff.manage-only user can reach `/admin/accounts` directly instead of first needing another admin permission. |

## `Permission::adminSectionPermissions()` — every page-granting permission unlocks `/admin`

Per the per-page gating work below, `canAccessAdmin` now needs to be true for
*any* permission that gates a real `/admin` page — otherwise a user with
e.g. only `audit_logs.view` (the "Analytics & Reporting" default config)
would be bounced out of `/admin` before ever reaching the one page they're
meant to see. The list is now every permission except the 3 purely
dashboard-scoped ones (`reservations.manage`, `reservations.view`,
`waitlist.manage`):

```php
['restaurants.view', 'restaurants.manage', 'tables.manage', 'staff.manage',
 'audit_logs.view', 'reporting.export', 'billing.manage', 'integrations.manage',
 'marketing.manage', 'communications.manage', 'messaging.manage', 'policies.manage']
```

This is a behavior change from before: the "Analytics & Reporting",
"Operations", and "Guest Relations" default access configs — which
previously could not open `/admin` at all — now can, scoped to just the
pages their specific permissions actually unlock (enforced by the
frontend's per-page route guard, `moretable-web-app/src/lib/adminAccess.ts`,
and per-page nav gating, `AdminSidebar.tsx`/`AdminPageSideNav.tsx`).

## Per-page `/admin` gating (frontend)

Previously every `/admin` nav item was visible to any admin-access user
regardless of which specific permission they held (documented in
`access-control.md`'s "Known gaps"). That gap is now closed:

- **Route guard** (`moretable-web-app/src/app/admin/layout.tsx`): redirects
  to `/admin` (not `/dashboard`, since the user does belong in `/admin`)
  if the current path's required permission(s) aren't held — no error
  toast, checked before render.
- **Nav gating** (`AdminSidebar.tsx`/`MobileAdminSidebar.tsx`): a nav item
  the user lacks permission for stays **visible but unclickable** (muted,
  `cursor-not-allowed`, small lock icon) rather than disappearing.
- **Tab gating** (`AdminPageSideNav.tsx`, used by `restaurant-settings`'
  Policies tab and `guest-communication`'s 3 tabs): same unclickable
  treatment, plus a hand-typed `?tab=` URL for a tab the user lacks falls
  back to their first accessible tab instead of rendering the gated content.

Single source of truth for all three: `moretable-web-app/src/lib/adminAccess.ts`.

## Default access configs today (`RestaurantAccessConfig::defaults()`) — unchanged

| Config | Permissions |
|---|---|
| Principal Admin | `restaurants.view`, `restaurants.manage`, `reservations.view`, `reservations.manage`, `waitlist.manage`, `tables.manage`, `staff.manage`, `audit_logs.view` |
| Operations | `restaurants.view`, `reservations.view`, `reservations.manage`, `waitlist.manage`, `tables.manage`, `staff.manage` |
| Analytics & Reporting | `restaurants.view`, `reservations.view`, `audit_logs.view` |
| Marketing & Growth | `restaurants.view`, `restaurants.manage` |
| Guest Relations | `restaurants.view`, `reservations.view` |

**Deliberately not changed as part of this pass.** Every default keeps
working exactly as before because `restaurants.manage`/`restaurants.view`
remain valid fallbacks on every newly-wired check — e.g. Principal Admin
still gets full Billing/Marketing/Integrations/Guest Communication access
via `restaurants.manage`, even though its permissions array doesn't
literally list `billing.manage`/`marketing.manage`/etc. Two things worth
noting if these defaults are revisited later:

- Principal Admin still doesn't literally have `reporting.export` (only
  `audit_logs.view`) — but unlike before, this now has a real effect: its
  own description promises "Reporting & exports," but a Principal Admin
  user still exports reports today only via the `restaurants.manage`
  fallback, not because the permission is actually in their array. Harmless
  today, but worth fixing if the defaults' arrays are ever tightened.
- Operations/Analytics & Reporting/Guest Relations now unlock `/admin`
  (they didn't before, see above) but only for the specific pages their
  permissions grant — e.g. Analytics & Reporting can view Reporting and the
  Restaurant Profile (view-only) but nothing else; Operations can reach
  Accounts (staff.manage) and Availability Planning (tables.manage) but not
  Billing/Marketing/etc.

## Next steps — locking the 5 default access configs (still not started)

**The ask**: Principal Admin's access config should show every permission
checked and not allow unchecking any of them ("select all, can't uncheck").
The other 4 defaults (Operations, Analytics & Reporting, Marketing & Growth,
Guest Relations) should be locked the same way, fixed to their *current*
permission sets (the table above) — so an owner can't accidentally redefine
what "Operations" means by unchecking a box, and the name stays
trustworthy. Presumably enforced in `AccessConfigModal.tsx` (disable the
checkboxes when editing a default config) — not investigated yet. This is
unrelated to (and unblocked by) the enforcement work above — still a
separate, unstarted task.

**What's already true, relevant for whoever implements this:**

- `MerchantAccessConfigController::update()` has **no `is_default` check at
  all** — anyone with `staff.manage` can rename Principal Admin, edit its
  description, or change its permissions array via a direct API call,
  regardless of what the frontend UI allows. Locking this only in the
  frontend would be cosmetic — the backend needs its own guard too.
- `MerchantAccessConfigController::destroy()` only blocks deletion when
  staff are currently assigned to that config — it does **not** specifically
  protect `is_default` configs from deletion once unassigned.
- `RestaurantAccessConfig.is_default: boolean` already exists and is set
  correctly at creation — the flag needed to gate this is already there,
  just unused for authorization.

**Open questions to resolve before implementing:**

1. Lock just the *permission checkboxes*, or also the *name*/*description*
   fields?
2. Should "locked" also mean *can't be deleted*?
3. If Principal Admin's permission array is ever revisited to genuinely
   include all 12 (fixing the `reporting.export` gap noted above), that's a
   data change, not a code change — flag before doing it, since it changes
   live restaurants' effective grants.

## See also

`ADMIN_ACCESS_CONTROL.md` — how the admin-section permissions get turned
into the frontend's `canAccessAdmin` flag.

`moretable-web-app/docs/access-control.md` — the frontend counterpart:
`restaurantPermissions`/`canAccessAdmin`, and the per-page/per-tab gating
described above.
