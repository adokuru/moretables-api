# Permission matrix — what each access-config permission actually does

> **Session paused here** — the matrix below is complete and verified. What
> follows is unfinished: the user's next ask (lock the 5 default access
> configs — Principal Admin fully checked and uneditable, the other 4 locked
> to their current permission sets so "their name work well for their
> roles") wasn't implemented yet. Pick up at "Next steps" at the bottom.



The 12 permissions assignable via the Access Config editor
(`PERMISSION_LABELS`, `moretable-web-app/src/lib/api/accounts/accounts.types.ts`),
what they unlock in `/dashboard` (front of house) vs `/admin` (back of
house), and whether it's a read or a write. Built by grepping every
`hasRestaurantPermission()` / `FormRequest::authorize()` call in the
codebase — not inferred from the label text, which turned out to matter (see
below).

## Read this first: half of these permissions aren't enforced anywhere

`billing.manage`, `integrations.manage`, `marketing.manage`,
`communications.manage`, `messaging.manage`, `policies.manage`,
`audit_logs.view`, and `reporting.export` — **8 of the 12** — appear only in
`MerchantAccessConfigController.php`'s allow-list (what a custom config is
permitted to store) and nowhere else. No controller, no `FormRequest`, no
middleware ever calls `hasRestaurantPermission('billing.manage', ...)` or any
of the other seven. They're real, storable, displayed values — just
functionally inert today.

What actually gates billing/menus/policies/gallery/media/onboarding/rewards/
guest-communication/surveys/restaurant-profile mutations is one blunt
permission: **`restaurants.manage`**. What gates reading almost all of the
same surfaces is **`restaurants.view`**. So right now, unchecking "Policies"
but leaving "Restaurant Settings" (`restaurants.manage`) checked on a custom
config does not actually restrict that user from touching policies — they
still can, via the same `restaurants.manage` grant that lets them touch
everything else in that bucket. The checkbox is honest about *intent*, not
about *effect*.

This is not a security hole (nothing is under-protected — every mutating
endpoint does require *some* permission), but it is a real product gap: the
UI promises finer-grained control than the backend currently delivers. Worth
knowing before deciding how much weight to put on the granular checkboxes
either in documentation or in product decisions like locking default
configs.

## The matrix

| Permission | Label (UI) | Dashboard | Admin | Read/Write | What it actually gates |
|---|---|:-:|:-:|---|---|
| `reservations.manage` | Reservations Management | ✅ | — | Write | Creating/editing/canceling/arriving/seating reservations (`MerchantReservationController`), Guestbook edits (create/update/delete guest, update preferences — `GuestbookController`), creating shift notes (`FrontOfHouseShiftNoteController::store`), broadcasting messages to guests (`MerchantRestaurantBroadcastController`), adding a server (`StoreRestaurantServerRequest`). |
| `tables.manage` | Floor Configurations | ✅ | ✅ | Both | **Dashboard**: viewing/assigning tables on the live floor plan, dashboard-preferences update (the "recommended table assignment" toggle). **Admin**: `availability-planning`'s Floor Plan + Table Combinations tabs — full CRUD on dining areas, dining spots, tables, table combinations, table status. |
| `audit_logs.view` | Reporting View | — | — | *(not enforced)* | Nothing currently checks this. |
| `reporting.export` | Reporting Exporting Data | — | — | *(not enforced)* | Nothing currently checks this — including the actual CSV export endpoints (guest survey responses, reporting exports), which are gated by `restaurants.view`/`reservations.view` instead. |
| `restaurants.manage` | Restaurant Settings | (edge case) | ✅ | Write | **The workhorse write permission.** Billing checkout/upgrade/verify, restaurant profile update, menu category/item/media CRUD, gallery CRUD, restaurant media CRUD, internal notes write, guest-communication settings write, guest survey CRUD, booking + cancellation policy write, shift + special-day CRUD, reward rules write, onboarding step writes, restaurant-settings update, widget settings. One dashboard edge case: `FrontOfHouseShiftNoteController::destroy` also accepts this (a manager-override path for deleting any shift note, not just your own — see that controller). |
| `integrations.manage` | Integrations | — | — | *(not enforced)* | Nothing currently checks this — there's no dedicated integrations controller yet. |
| `marketing.manage` | Marketing | — | — | *(not enforced)* | Nothing currently checks this. |
| `restaurants.view` | Restaurant Profile | ✅ | ✅ | Read | **The workhorse read permission.** Nearly every `GET` across both dashboard and admin: billing show/invoices, restaurant profile/settings, menus/gallery/media, guest-communication, guest surveys, booking/cancellation policy, reward rules, onboarding data, shifts/special-days, widget settings, dashboard-preferences. |
| `billing.manage` | Billing | — | — | *(not enforced)* | Nothing currently checks this — real billing writes require `restaurants.manage` instead (see above). |
| `communications.manage` | Communications Channels | — | — | *(not enforced)* | Nothing currently checks this. |
| `messaging.manage` | Direct Messaging | — | — | *(not enforced)* | Nothing currently checks this. |
| `policies.manage` | Policies | — | — | *(not enforced)* | Nothing currently checks this — real policy writes require `restaurants.manage` instead (routed through the `AuthorizesRestaurantManageOnboarding` trait on `UpdateRestaurantBookingPolicyRequest`/`StoreRestaurantCancellationPolicyRequest`/`UpdateRestaurantCancellationPolicyRequest`). |

Two more permissions exist and *are* fully enforced, but aren't in the
12-item assignable list above (they're either always-on for certain default
configs or handled specially):

| Permission | Dashboard | Admin | What it gates |
|---|:-:|:-:|---|
| `waitlist.manage` | ✅ | — | Everything waitlist: the dashboard's waitlist column, availability alerts, and every waitlist-entry action (arrive, notify, cancel, assign table, ...) — `FrontOfHouseController`/`MerchantWaitlistController`. |
| `staff.manage` | ✅ | ✅ | **Both.** Dashboard's User Management "Add"/"Edit" (`MerchantRestaurantStaffController`), and `/admin/accounts`' entire Users tab *and* the Access Config tab itself (creating/editing/deleting access configs also requires `staff.manage`, not `restaurants.manage` — see `MerchantAccessConfigController`). Deliberately excluded from `Permission::adminSectionPermissions()` (`ADMIN_ACCESS_CONTROL.md`) — it unlocks a dashboard feature, not `/admin` itself. |

## Default access configs today (`RestaurantAccessConfig::defaults()`)

| Config | Permissions |
|---|---|
| Principal Admin | `restaurants.view`, `restaurants.manage`, `reservations.view`, `reservations.manage`, `waitlist.manage`, `tables.manage`, `staff.manage`, `audit_logs.view` |
| Operations | `restaurants.view`, `reservations.view`, `reservations.manage`, `waitlist.manage`, `tables.manage`, `staff.manage` |
| Analytics & Reporting | `restaurants.view`, `reservations.view`, `audit_logs.view` |
| Marketing & Growth | `restaurants.view`, `restaurants.manage` |
| Guest Relations | `restaurants.view`, `reservations.view` |

Note Principal Admin is **missing** `reporting.export` even though its own
description string says "Reporting & exports" — a pre-existing inconsistency
between the description text and the actual permissions array, unrelated to
the enforcement gap above (this one's just a data omission, easy to fix if
Principal Admin's set is being revisited anyway).

## See also

`ADMIN_ACCESS_CONTROL.md` — how `restaurants.manage` and the rest of the
"admin section" permissions get turned into the frontend's `canAccessAdmin`
flag.

## Next steps — locking the 5 default access configs (not started)

**The ask**: Principal Admin's access config should show every permission
checked and not allow unchecking any of them ("select all, can't uncheck").
The other 4 defaults (Operations, Analytics & Reporting, Marketing & Growth,
Guest Relations) should be locked the same way, fixed to their *current*
permission sets (the table above, "Default access configs today") — so an
owner can't accidentally redefine what "Operations" means by unchecking a
box, and the name stays trustworthy. Presumably enforced in
`AccessConfigModal.tsx` (disable the checkboxes when editing a default
config) — not investigated yet.

**My take, for when we pick this up**: good idea, worth doing regardless of
the "8 permissions aren't enforced yet" finding above — it's about UI
honesty (Principal Admin should visibly *be* "everything," not just mostly
be it) and future-proofing (once those 8 permissions do get real
enforcement later, the defaults' semantics should already be locked in
rather than silently drifting). Principal Admin's "select all" should
include all 12, including the currently-decorative ones — no reason to
leave it half-checked just because enforcement hasn't caught up yet.

**What I'd checked before pausing** — relevant for whoever implements this:

- `MerchantAccessConfigController::update()` (line ~129) has **no
  `is_default` check at all** — right now, anyone with `staff.manage` can
  rename Principal Admin, edit its description, or change its permissions
  array via a direct API call, regardless of what the frontend UI allows.
  Locking this only in the frontend (disabling checkboxes) would be
  cosmetic — the backend needs its own guard too if this is meant to be a
  real rule, not just a UI nicety.
- `MerchantAccessConfigController::destroy()` (line ~177) only blocks
  deletion when staff are currently assigned to that config
  (`abort_if($accessConfig->userRoles()->exists(), 422, ...)`) — it does
  **not** specifically protect `is_default` configs from deletion once
  unassigned. So today, an unassigned "Principal Admin" config *can* be
  deleted outright. Whether that should also be locked is part of the same
  question below.
- `RestaurantAccessConfig.is_default: boolean` already exists on the model
  and is set correctly at creation (`true` for the 5 seeded defaults,
  `false` for anything an owner creates) — the flag needed to gate all of
  this is already there, just unused for authorization.

**Open questions to resolve before implementing** (didn't get to ask):

1. Lock just the *permission checkboxes*, or also the *name*/*description*
   fields? The ask says "make the default fixed and uncheckable," which
   reads as permissions specifically, but if the name stays editable
   someone could rename "Operations" to something else while keeping its
   permission set — which undercuts "so their name work well for their
   roles" a little differently than unchecking boxes would.
2. Should "locked" also mean *can't be deleted*, closing the gap found
   above? Or is delete-when-unassigned an intentionally-kept escape hatch
   (e.g. a restaurant that genuinely never wants a "Guest Relations" tier
   at all)?
3. Separate, bigger question, not required for the lock itself but raised
   by the same investigation: do you want the 8 currently-decorative
   permissions (billing/integrations/marketing/communications/messaging/
   policies/audit_logs.view/reporting.export) wired up to real backend
   enforcement at some point? That's a materially larger task (new
   permission checks across ~10 controllers) and wasn't asked for yet —
   flagging it here since it surfaced during this research, not proposing
   to do it unprompted.
