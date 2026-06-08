# Restaurant Shifts — Merchant API (Frontend Integration)

Use this doc to build the **Weekly Shifts** settings UI (OpenTable-style): shift windows, turn times, table availability, turn controls, and flow controls.

**Live API reference:** Scramble docs → group **Merchant Shifts** (same routes and schemas as this document).

---

## Overview

| Concept | Description |
|---------|-------------|
| **Shift** | A named service window on a weekday (e.g. “Lunch”, Mon 11:00–15:00). |
| **Turn times** | Reservation length by party size (1–N guests). |
| **Table availability** | Which dining areas / table types are bookable in this shift. |
| **Turn controls** | Minimum turns held back for specific party sizes or tables until a release policy triggers. |
| **Flow controls** | Cover caps per time interval within the shift (kitchen / pacing). |

When a restaurant has **at least one shift**, guest availability and reservations use shift rules instead of legacy meal schedules. **Special days** still override weekly shifts for their dates.

Shifts are **seeded automatically once** when the merchant saves **Business hours** during onboarding (only if the restaurant has zero shifts). After that, shifts are managed only via the endpoints below.

---

## Base URL & auth

| Item | Value |
|------|--------|
| Base path | `/api/v1/merchant/restaurants/{restaurantId}/shifts` |
| Auth | `Authorization: Bearer {sanctum_token}` |
| Content-Type | `application/json` |
| Response wrapper | Laravel API resources → `{ "data": ... }` for single resources and collections |

### Middleware

- **`auth:sanctum`** — required on all merchant routes.
- **`merchant.billing.active`** — shift routes sit behind active billing. If billing is inactive, API returns **402** with a billing payload (same as other merchant features).

### Permissions

| Action | Permission |
|--------|------------|
| `GET` list / show | `restaurants.view` |
| `POST` create | `restaurants.manage` (via form request; onboarding users with manage access included) |
| `PATCH` / `PUT` update | `restaurants.manage` |
| `DELETE` | `restaurants.manage` |

Missing permission → **403**. Shift not belonging to restaurant → **404**.

---

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/shifts` | List all shifts (ordered by `day_of_week`, `sort_order`, `starts_at`) |
| `POST` | `/shifts` | Create shift + optional nested settings |
| `GET` | `/shifts/{shiftId}` | Single shift with nested settings |
| `PATCH` / `PUT` | `/shifts/{shiftId}` | Update shift (partial core fields; see nested replace rules) |
| `DELETE` | `/shifts/{shiftId}` | Delete shift and all nested rows |

`{restaurantId}` and `{shiftId}` are numeric IDs.

---

## Shift object (response)

All read endpoints return this shape inside `data`:

```json
{
  "id": 12,
  "restaurant_id": 3,
  "restaurant_meal_type_id": 5,
  "name": "Dinner",
  "day_of_week": 5,
  "starts_at": "17:00:00",
  "ends_at": "22:00:00",
  "color": "#4F46E5",
  "is_active": true,
  "sort_order": 1,
  "turn_times": [
    { "party_size": 1, "duration_minutes": 90 },
    { "party_size": 2, "duration_minutes": 90 }
  ],
  "table_availability": [
    {
      "id": 44,
      "dining_area_id": 2,
      "table_type": "booth",
      "include_combinations": true,
      "is_reservable": true
    }
  ],
  "turn_controls": [
    {
      "id": 7,
      "rule_type": "party_size",
      "party_size": 8,
      "restaurant_table_id": null,
      "min_turns": 2
    }
  ],
  "flow_controls": {
    "interval_minutes": 15,
    "default_max_covers": 3,
    "release_policy": "hours_before_shift",
    "release_hours_before": 24,
    "intervals": [
      { "id": 9, "starts_at": "17:00:00", "max_covers": 6 }
    ]
  }
}
```

### Field notes

| Field | Notes |
|-------|--------|
| `day_of_week` | **0 = Sunday … 6 = Saturday** (Carbon / PHP convention). |
| `starts_at` / `ends_at` | Stored and returned as time strings (often `HH:MM:SS`). Send **`H:i`** on write (e.g. `"17:00"`). |
| `restaurant_meal_type_id` | Optional link to a meal type (`restaurant_meal_types.id`). Used for labeling / ordering; not required. |
| `color` | Optional hex string, max 7 chars (e.g. `#4F46E5`). |
| `turn_times` | **No `id`** in responses. Rows are replaced entirely on update when `turn_times` is sent. |
| `table_availability` / `turn_controls` / `flow_controls.intervals` | Include **`id`** on read; IDs are **not** used on write (replace semantics). |
| `flow_controls` | Always present on the shift resource. `intervals` is `[]` if not loaded (list/show always load them). |

---

## Enums

### `day_of_week`

| Value | Day |
|-------|-----|
| 0 | Sunday |
| 1 | Monday |
| 2 | Tuesday |
| 3 | Wednesday |
| 4 | Thursday |
| 5 | Friday |
| 6 | Saturday |

### `table_type` (nullable on availability rules)

`regular` · `booth` · `high_top` · `bar` · `communal` · `outdoor`

A rule with **both** `dining_area_id` and `table_type` null applies as a catch-all (used in seeded defaults when there are no tables).

### `turn_controls[].rule_type`

| Value | Required fields |
|-------|-----------------|
| `party_size` | `party_size`, `min_turns` |
| `table` | `restaurant_table_id`, `min_turns` |

### `flow_controls.release_policy`

| Value | Behavior (backend / guest booking) |
|-------|----------------------------------|
| `dont_release` | Turn-controlled inventory is **never** released for online booking. |
| `at_shift_start` | Released when the shift start time is reached (restaurant local date + `starts_at`). |
| `hours_before_shift` | Released `release_hours_before` hours before shift start. Use `release_hours_before` (0–168). |

---

## Create (`POST /shifts`)

### Required

- `name` (string, max 255)
- `day_of_week` (0–6)
- `starts_at`, `ends_at` (`H:i`, `ends_at` must be **after** `starts_at`)

### Optional core fields

- `restaurant_meal_type_id` — must belong to this restaurant
- `color`, `is_active` (default `true`), `sort_order` (default `0`)

### Optional nested (omit = server defaults on create)

| Key | Default when omitted on **create** |
|-----|-------------------------------------|
| `turn_times` | One row per party size `1 … max_party_size` from restaurant policy; each uses `reservation_duration_minutes` (fallback 120). |
| `table_availability` | One row per `(dining_area_id, table_type)` group from active tables; combinations flagged per area. If no tables, one catch-all reservable row. |
| `turn_controls` | Empty |
| `flow_controls` | `interval_minutes: 15`, `default_max_covers: 3`, `release_policy: dont_release`, `intervals: []` |

### Example — minimal create

```http
POST /api/v1/merchant/restaurants/3/shifts
```

```json
{
  "name": "Lunch",
  "day_of_week": 1,
  "starts_at": "11:00",
  "ends_at": "15:00",
  "restaurant_meal_type_id": 5,
  "color": "#10B981"
}
```

**Response:** `201` + full shift in `data`.

### Example — create with full settings

```json
{
  "name": "Dinner",
  "day_of_week": 5,
  "starts_at": "17:00",
  "ends_at": "22:00",
  "turn_times": [
    { "party_size": 2, "duration_minutes": 90 },
    { "party_size": 4, "duration_minutes": 105 },
    { "party_size": 6, "duration_minutes": 120 }
  ],
  "table_availability": [
    {
      "dining_area_id": 2,
      "table_type": "booth",
      "include_combinations": true,
      "is_reservable": true
    },
    {
      "dining_area_id": 2,
      "table_type": "bar",
      "include_combinations": false,
      "is_reservable": false
    }
  ],
  "turn_controls": [
    { "rule_type": "party_size", "party_size": 8, "min_turns": 2 },
    { "rule_type": "table", "restaurant_table_id": 101, "min_turns": 1 }
  ],
  "flow_controls": {
    "interval_minutes": 15,
    "default_max_covers": 4,
    "release_policy": "hours_before_shift",
    "release_hours_before": 48,
    "intervals": [
      { "starts_at": "17:00", "max_covers": 8 },
      { "starts_at": "19:00", "max_covers": 12 }
    ]
  }
}
```

---

## Update (`PATCH /shifts/{id}`)

### Core fields

All core fields are **optional** (`sometimes`). Only sent fields are updated.

### Nested settings — **replace semantics**

When you include a nested key, the server **deletes all existing rows** in that section and recreates from the array you send:

| Key | Effect |
|-----|--------|
| `turn_times` | Replace all turn times. Send full list. `[]` clears all. |
| `table_availability` | Replace all rules. |
| `turn_controls` | Replace all controls. `[]` clears all. |
| `flow_controls.interval_minutes` etc. | Updates scalar flow fields on the shift row only. |
| `flow_controls.intervals` | Replace all flow intervals. `[]` clears intervals. |

**Important:** Omitting `turn_times` on PATCH leaves turn times unchanged. Sending `turn_times: []` removes every turn time (avoid unless intentional).

Flow scalar fields (`interval_minutes`, `default_max_covers`, `release_policy`, `release_hours_before`) can be PATCHed inside `flow_controls` without sending `intervals`.

### Example — rename + deactivate

```json
{
  "name": "Late Dinner",
  "is_active": false
}
```

### Example — replace turn times only

```json
{
  "turn_times": [
    { "party_size": 2, "duration_minutes": 75 },
    { "party_size": 4, "duration_minutes": 90 }
  ]
}
```

---

## Delete

```http
DELETE /api/v1/merchant/restaurants/3/shifts/12
```

**Response:** `200`

```json
{
  "message": "Shift deleted successfully."
}
```

---

## Validation errors

**422** — Laravel validation format:

```json
{
  "message": "The name field is required. (and 1 more error)",
  "errors": {
    "name": ["The name field is required."],
    "ends_at": ["The ends at field must be a date after starts at."]
  }
}
```

Common rules:

| Field | Rules |
|-------|--------|
| `turn_times.*.duration_minutes` | 15–480 |
| `flow_controls.interval_minutes` | 5–120 |
| `flow_controls.release_hours_before` | 0–168 when using `hours_before_shift` |
| `table_availability.*.dining_area_id` | Must belong to restaurant |
| `turn_controls.*.restaurant_table_id` | Must belong to restaurant when `rule_type` is `table` |
| `restaurant_meal_type_id` | Must belong to restaurant |

Invalid table on turn control may also return **422** with message: `Table ID {id} does not belong to this restaurant.`

---

## Related endpoints (for dropdowns & layout)

All under `/api/v1/merchant/restaurants/{restaurantId}/…` (same auth + billing):

| Purpose | Endpoint |
|---------|----------|
| Meal type labels / link | `GET/POST/PATCH/DELETE …/meal-types` |
| Dining areas | `GET/POST/PATCH/DELETE …/dining-areas` |
| Tables (turn control picker) | `GET/POST/PATCH/DELETE …/tables` |
| Table combinations | `GET/POST …/table-combinations` (if UI needs combo context) |
| Special days (date overrides) | `GET/POST/PATCH/DELETE …/special-days` |
| Onboarding business hours (seeds shifts once) | `PUT …/onboarding/business-hours` |

List responses use the same `{ "data": [...] }` pattern unless paginated otherwise.

---

## UI mapping (suggested tabs)

| UI section | API fields |
|------------|------------|
| **Shift list / calendar** | `GET /shifts` — group by `day_of_week`; sort by `sort_order`, `starts_at`; use `color`, `is_active` |
| **Shift details** | Core fields + link `restaurant_meal_type_id` to meal types |
| **Turn times grid** | `turn_times[]` — rows keyed by `party_size` |
| **Table availability matrix** | `table_availability[]` — rows per area + type; toggles `is_reservable`, `include_combinations` |
| **Turn controls** | `turn_controls[]` — type switch `party_size` vs `table`; `min_turns`; release via `flow_controls.release_policy` + `release_hours_before` |
| **Flow / pacing** | `flow_controls.interval_minutes`, `default_max_covers`, `intervals[]` (`starts_at`, `max_covers`) |

### Weekly grid vs single shift editor

- **Load:** one `GET /shifts` for the restaurant (includes all nested data).
- **Save one shift:** `PATCH /shifts/{id}` with full nested arrays for any section the user edited (because of replace semantics).
- **Add shift:** `POST /shifts`.
- **Delete:** `DELETE /shifts/{id}`.

### Onboarding flow

After merchant saves business hours, backend may auto-create shifts from existing availability schedules **only if** `GET /shifts` would return `[]`. Frontend should still offer the shifts settings page; first visit may show pre-filled data without extra calls.

---

## TypeScript-friendly types (reference)

```ts
type DayOfWeek = 0 | 1 | 2 | 3 | 4 | 5 | 6;

type TableType =
  | "regular"
  | "booth"
  | "high_top"
  | "bar"
  | "communal"
  | "outdoor";

type TurnControlRuleType = "party_size" | "table";

type ReleasePolicy =
  | "dont_release"
  | "at_shift_start"
  | "hours_before_shift";

interface RestaurantShiftTurnTime {
  party_size: number;
  duration_minutes: number;
}

interface RestaurantShiftTableAvailability {
  id?: number;
  dining_area_id: number | null;
  table_type: TableType | null;
  include_combinations: boolean;
  is_reservable: boolean;
}

interface RestaurantShiftTurnControl {
  id?: number;
  rule_type: TurnControlRuleType;
  party_size: number | null;
  restaurant_table_id: number | null;
  min_turns: number;
}

interface RestaurantShiftFlowInterval {
  id?: number;
  starts_at: string;
  max_covers: number;
}

interface RestaurantShiftFlowControls {
  interval_minutes: number;
  default_max_covers: number;
  release_policy: ReleasePolicy;
  release_hours_before: number | null;
  intervals: RestaurantShiftFlowInterval[];
}

interface RestaurantShift {
  id: number;
  restaurant_id: number;
  restaurant_meal_type_id: number | null;
  name: string;
  day_of_week: DayOfWeek;
  starts_at: string;
  ends_at: string;
  color: string | null;
  is_active: boolean;
  sort_order: number;
  turn_times: RestaurantShiftTurnTime[];
  table_availability: RestaurantShiftTableAvailability[];
  turn_controls: RestaurantShiftTurnControl[];
  flow_controls: RestaurantShiftFlowControls;
}
```

---

## Frontend checklist

- [ ] Bearer token on all requests; handle **402** (billing) and **403** (permissions).
- [ ] Use `day_of_week` 0–6 consistently with backend (document in UI if showing Mon–Sun picker).
- [ ] On save, send **complete** arrays for any nested section that changed (`turn_times`, `table_availability`, `turn_controls`, `flow_controls.intervals`).
- [ ] Do not rely on nested `id` fields when building PATCH payloads.
- [ ] Load meal types, dining areas, and tables for selectors before editing turn controls / availability.
- [ ] Empty state: no shifts → prompt to complete business hours or create first shift via `POST`.
- [ ] Optional: link to Scramble **Merchant Shifts** for interactive testing.

---

## Questions / changes

Contact backend if you need:

- Bulk save (multiple shifts in one request)
- Stable IDs on `turn_times` for diff-based PATCH
- Public (guest) shift summary endpoint for marketing pages

OpenAPI/Scramble is the source of truth for exact request schemas once deployed.
