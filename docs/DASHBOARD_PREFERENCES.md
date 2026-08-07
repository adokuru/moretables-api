# Dashboard Preferences — Merchant API (Frontend Integration)

Backs the FOH dashboard's **Settings → Preferences** page (`moretable-web-app/src/components/pages/settings/perference-section.tsx`). Distinct from `MerchantRestaurantSettingsController`'s `/settings` endpoint, which covers business-facing restaurant details (name, website, address, rewards) — this resource is for display preferences on the FOH dashboard itself.

**Live API reference:** Scramble docs → group **Merchant Restaurant Settings** (same routes and schemas as this document).

---

## Overview

Three booleans today. One toggle on the Preferences page — "Always display server sections on floor plan" — is still local-only mock UI, not backed by this endpoint, since it wasn't asked for yet.

| Field | Type | Default | Effect |
|---|---|---|---|
| `display_recommended_table_assignment` | boolean | `false` | When `true`, the FOH dashboard's floor plan (`HomeRight.tsx`, `/dashboard`) only renders tables that currently have a reservation for the selected date/shift, instead of every table on the floor. |
| `display_guest_full_name` | boolean | `true` | When `false`, every guest-name display across the dashboard (and guest-book, timelines, assign page, shift overview) shows first name only instead of the full name. Defaults `true` to preserve pre-existing behavior. |
| `show_guest_preferences` | boolean | `false` | When `true`, `GuestCard` shows a badge when the guest has allergies or dietary restrictions on file (from their Guestbook profile). Defaults `false` since this UI didn't exist before — opt-in rather than surfacing new guest-profile info unprompted. |

---

## Base URL & auth

| Item | Value |
|---|---|
| Base path | `/api/v1/merchant/restaurants/{restaurantId}/dashboard-preferences` |
| Auth | `Authorization: Bearer {sanctum_token}` |
| Content-Type | `application/json` |

### Middleware

Same group as `/settings`: `auth:sanctum`, `merchant.access`, `merchant.billing.active`.

### Permissions

| Route | Permission |
|---|---|
| `GET dashboard-preferences` | `restaurants.view` |
| `PATCH dashboard-preferences` | `tables.manage` |

`tables.manage` (not `restaurants.manage`) was chosen for the update route deliberately — this is a floor-plan display preference, not a business setting, and `tables.manage` is already granted to regular front-of-house staff (`RestaurantStaff`, `Operations`) as well as managers/owners, per `RoleAndPermissionSeeder`. `restaurants.manage` would have restricted it to managers only.

---

## Endpoints

### `GET dashboard-preferences`

```json
{
  "preferences": {
    "display_recommended_table_assignment": false,
    "display_guest_full_name": true,
    "show_guest_preferences": false
  }
}
```

### `PATCH dashboard-preferences`

Request body — all fields optional (`sometimes` validation), send only what changed:

```json
{
  "display_recommended_table_assignment": true
}
```

Response:

```json
{
  "message": "Preferences updated successfully.",
  "preferences": {
    "display_recommended_table_assignment": true,
    "display_guest_full_name": true,
    "show_guest_preferences": false
  }
}
```

---

## Storage

Plain boolean columns on `restaurants`:
- `display_recommended_table_assignment` (default `false`) — `2026_08_06_230717_add_display_recommended_table_assignment_to_restaurants_table`.
- `display_guest_full_name` (default `true`), `show_guest_preferences` (default `false`) — `2026_08_06_233009_add_guest_display_preferences_to_restaurants_table`.

Restaurant-wide, not per-user: every staff member viewing the dashboard for a given restaurant sees the same preference state.

---

## Frontend: how the filter is computed (`HomeRight.tsx`)

The floor plan already fetches every table on the active floor (`useFohFloor`) regardless of this preference. When `display_recommended_table_assignment` is `true`, the component filters that list client-side rather than requesting a different set from the API — no new query params on the FOH `GET` endpoints were needed, since every reservation payload already carries its assigned `table_label`.

A table stays visible when **either** is true:

1. It's tied to a reservation in the **Reservations**, **Arrived**, or **Seated** buckets for the selected date/shift (`useFohReservations`, `useFohArrived`, `useFohSeated` — matched by `table.table_label`). Finished and Removed (cancelled/no-show) are deliberately excluded — those tables are free again / never happened.
2. It's currently in **`cleaning`** status (`live_status === "cleaning"`, set server-side when a reservation completes). Cleaning tables always stay visible regardless of the filter, since staff still need to see and clear them via the existing "Mark ready" action (`TableReadyCard`) — once marked ready, if the table isn't otherwise assigned, it drops out of view on the next refetch.

This computation is independent of the pre-existing `occupiedTables` map used for floor-plan tile *coloring* (Seated-only, per an earlier explicit design decision documented in `moretable-web-app/AGENTS.md`) — a plain booked-but-not-yet-arrived reservation's table now stays *visible* under this filter, but still renders in its normal (uncolored) state, not tinted.

**Not applied to `/dashboard/assign/[id]`'s floor plan** (`AssignRight.tsx`) — that page exists specifically to pick a table for a guest who doesn't have one yet, so filtering down to only already-assigned tables would remove the free tables staff need to choose from. Scoped to the main dashboard's floor plan only, per explicit product decision.

---

## Frontend: `display_guest_full_name`

Pure formatting, computed at render time — the underlying `Guest.name` (and the raw API response) always carries the full name; nothing is truncated in storage or in edit-form prefills (`EditWaitlistSidebar.tsx`'s editable name field, `EditReservationSidebar.tsx`'s read-only-but-full name, both deliberately untouched). `formatGuestName(fullName, showFullName)` (`dashboard-helper.tsx`) returns the first whitespace-delimited token when `showFullName` is `false`, otherwise the string unchanged.

Applied at every place a guest's own name renders as UI text: the FOH board (`GuestCard.tsx` — covers every board column and all 5 `*DetailPanel.tsx` files, which all render a `GuestCard` internally), the swipe-undo preview (`UndoableStatusPreview.tsx`), the 3 "Remove/Notify this guest?" confirm-modal messages (`list-view/page.tsx`, `AdminFrontLeft.tsx`, `NoticeMeLeft.tsx`), Guestbook's sidebar list and detail header, the Assign page's diner name, Shift Overview's reservation rows, and the Timelines grid's reservation blocks.

**Deliberately not applied** to `/admin/guest-communication` (`SelectGuestsModal.tsx`, `reservation.tsx`) — that feature reads from a separate guest-communication endpoint/type, not the FOH `Guest` type this preference is scoped to, and wasn't part of what was asked ("the dashboard, and other pages" was interpreted as FOH-guest-facing pages, not the admin marketing/broadcast surface). Revisit if that's wanted too.

## Frontend: `show_guest_preferences`

Backed by data that was already flowing through every FOH list response before this feature — `guest_contact.preferences` (`ReservationResource`/`GuestContactResource`, both `whenLoaded('guestContact')`) — no new backend fetch, no N+1 risk. That column stores either a flat `string[]` tag list or a keyed object (`{seating_preference, dietary_restrictions, allergies, ...}` — see `GuestbookController::updatePreferences`), depending on which feature last wrote to it (documented in `moretable-web-app/AGENTS.md`'s "two incompatible shapes" section). `getAllergies`/`getDietaryRestrictions` (`dashboard-helper.tsx`) mirror the existing `getSeatingPreference` guard: only read the keyed shape, return `undefined` for the flat-array shape.

`GuestCard.tsx` shows a badge (lucide `TriangleAlert`, red) in its existing preferences slot when `show_guest_preferences` is `true` and the guest has allergies and/or dietary restrictions on file — `title` tooltip lists the specifics. Shares the slot's pre-existing 2-badge cap (`MAX_PREFERENCE_BADGES`) rather than widening the card; placed second in priority order (after the partially-arrived badge, before online-reservation/notes/other tags) since allergy info is safety-relevant.
