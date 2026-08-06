# Dashboard Preferences — Merchant API (Frontend Integration)

Backs the FOH dashboard's **Settings → Preferences** page (`moretable-web-app/src/components/pages/settings/perference-section.tsx`). Distinct from `MerchantRestaurantSettingsController`'s `/settings` endpoint, which covers business-facing restaurant details (name, website, address, rewards) — this resource is for display preferences on the FOH dashboard itself.

**Live API reference:** Scramble docs → group **Merchant Restaurant Settings** (same routes and schemas as this document).

---

## Overview

One boolean today. More dashboard-only toggles can be added to this same resource later (the Preferences page also shows "Display guest full name", "Show guest preferences too", and "Always display server sections on floor plan" — those three are still local-only mock UI, not backed by this endpoint, since only `display_recommended_table_assignment` was asked for so far).

| Field | Type | Default | Effect |
|---|---|---|---|
| `display_recommended_table_assignment` | boolean | `false` | When `true`, the FOH dashboard's floor plan (`HomeRight.tsx`, `/dashboard`) only renders tables that currently have a reservation for the selected date/shift, instead of every table on the floor. |

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
    "display_recommended_table_assignment": false
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
    "display_recommended_table_assignment": true
  }
}
```

---

## Storage

Plain boolean column on `restaurants` (`display_recommended_table_assignment`, default `false`) — added in `2026_08_06_230717_add_display_recommended_table_assignment_to_restaurants_table`. Restaurant-wide, not per-user: every staff member viewing the dashboard for a given restaurant sees the same filter state.

---

## Frontend: how the filter is computed (`HomeRight.tsx`)

The floor plan already fetches every table on the active floor (`useFohFloor`) regardless of this preference. When `display_recommended_table_assignment` is `true`, the component filters that list client-side rather than requesting a different set from the API — no new query params on the FOH `GET` endpoints were needed, since every reservation payload already carries its assigned `table_label`.

A table stays visible when **either** is true:

1. It's tied to a reservation in the **Reservations**, **Arrived**, or **Seated** buckets for the selected date/shift (`useFohReservations`, `useFohArrived`, `useFohSeated` — matched by `table.table_label`). Finished and Removed (cancelled/no-show) are deliberately excluded — those tables are free again / never happened.
2. It's currently in **`cleaning`** status (`live_status === "cleaning"`, set server-side when a reservation completes). Cleaning tables always stay visible regardless of the filter, since staff still need to see and clear them via the existing "Mark ready" action (`TableReadyCard`) — once marked ready, if the table isn't otherwise assigned, it drops out of view on the next refetch.

This computation is independent of the pre-existing `occupiedTables` map used for floor-plan tile *coloring* (Seated-only, per an earlier explicit design decision documented in `moretable-web-app/AGENTS.md`) — a plain booked-but-not-yet-arrived reservation's table now stays *visible* under this filter, but still renders in its normal (uncolored) state, not tinted.

**Not applied to `/dashboard/assign/[id]`'s floor plan** (`AssignRight.tsx`) — that page exists specifically to pick a table for a guest who doesn't have one yet, so filtering down to only already-assigned tables would remove the free tables staff need to choose from. Scoped to the main dashboard's floor plan only, per explicit product decision.
