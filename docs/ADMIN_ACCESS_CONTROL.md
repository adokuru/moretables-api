# Dashboard vs. Admin access control

How MoreTables decides whether a logged-in staff member can see the merchant
`/admin` back-office section (Restaurant Settings, Billing, Accounts,
Integrations, Marketing, Guest Communication, Reporting exports, ...), as
opposed to being scoped to the day-to-day `/dashboard` front-of-house view.

## The problem this solves

Every staff member is invited through the **access config** system
(`RestaurantAccessConfig`), never through a hand-picked classic `Role`. A
restaurant can define arbitrary named permission bundles (5 defaults —
Principal Admin, Operations, Analytics & Reporting, Marketing & Growth, Guest
Relations — plus any custom ones an owner creates) and assign one to each
staff member. There was no existing signal anywhere in the API response the
frontend could use to answer "should this user see `/admin` at all?"

**A tempting but wrong shortcut**: `UserRole.role_id` is *not* useful for
this. `ScopedRoleAssignmentService::syncRestaurantAccessConfig()` stamps
every access-config-based assignment with the same hardcoded legacy
`role_id` (`principal_admin`) as a backward-compatibility stand-in, *"since
access config handles actual permissions"* (see that method's own comment).
That means `$user->roles->pluck('name')` reads `principal_admin` for **every
single invited staff member**, regardless of which access config they were
actually given. Any frontend check built on a role name (`user.roles`) is
checking something that's true for everyone — this was tried once and had
to be corrected (see the "Broken role check" section below).

The real, per-restaurant signal is the resolved **permission set** —
`User::hasRestaurantPermission()` already correctly checks both paths (the
access config's `permissions` JSON array, or the classic `Role::permissions`
pivot for non-access-config assignments like `OrganizationOwner`). This
feature surfaces that resolved set to the frontend instead of the role name.

## Backend

### `Permission` model (`app/Models/Permission.php`)

Two static lists, kept deliberately separate:

- `restaurantAccessConfigPermissions()` — every permission a restaurant owner
  can assign via the Access Config editor's checkboxes. Mirrors the
  frontend's `PERMISSION_LABELS`
  (`moretable-web-app/src/lib/api/accounts/accounts.types.ts`) exactly —
  **keep both in sync** if either changes. Deliberately excludes
  `staff.manage` — that one isn't a user-togglable checkbox, only the two
  default configs (Principal Admin, Operations) carry it, baked directly
  into `RestaurantAccessConfig::defaults()`.
- `adminSectionPermissions()` — the subset that unlocks `/admin`. **As of
  the per-page `/admin` gating work (see `docs/PERMISSION_MATRIX.md`), this
  is now every permission that gates at least one real `/admin` page**:
  `restaurants.view`, `restaurants.manage`, `tables.manage`, `staff.manage`,
  `audit_logs.view`, `reporting.export`, `billing.manage`,
  `integrations.manage`, `marketing.manage`, `communications.manage`,
  `messaging.manage`, `policies.manage` — i.e. everything except the 3
  purely dashboard-scoped permissions (`reservations.manage`,
  `reservations.view`, `waitlist.manage`), since nothing in `/admin` is
  reservation/waitlist-scoped. **`staff.manage` was previously excluded**
  (it only unlocked the dashboard's own User Management "Add"/"Edit"
  actions) but is now included by explicit product decision, since
  `/admin/accounts` is itself a real `/admin` page a `staff.manage`-only
  user should be able to reach directly.

### `User::hasAnyRestaurantPermission()` (`app/Models/User.php`)

```php
public function hasAnyRestaurantPermission(array $permissionNames, Restaurant $restaurant): bool
```

Loops `hasRestaurantPermission()` over a list, true on first match. Used to
compute `can_access_admin` below.

### `GET /merchant/restaurants` (`MerchantRestaurantController::index`)

Each restaurant in the response now carries:

```jsonc
{
  "permissions": ["reservations.manage", "tables.manage", "staff.manage", "..."],
  "can_access_admin": true,
  "role_name": "Marketing & Growth"
}
```

- `permissions` — every permission from `Permission::restaurantAccessConfigPermissions()`
  plus the baseline day-to-day set (`restaurants.view`, `reservations.view`,
  `reservations.manage`, `waitlist.manage`) plus `staff.manage`, filtered down
  to what this user actually has for this restaurant.
- `can_access_admin` — `hasAnyRestaurantPermission(Permission::adminSectionPermissions(), $restaurant)`.
  Computed once, server-side, so the frontend never needs to duplicate the
  admin-permission list itself.
- `role_name` — `User::restaurantRoleLabel($restaurant)`: the assigned access
  config's own `name` (e.g. "Marketing & Growth") for access-config-based
  staff, or the classic `Role->name` title-cased (e.g. "Organization Owner")
  otherwise. Added because `AuthUser.roles`/`role_assignments` (from
  `/merchant/auth/profile`, `UserResource`) read the legacy `role_id`
  stand-in described above — `principal_admin` for every access-config-based
  assignment — so neither was usable for a "what's my role" display. Scope
  priority (restaurant-specific → org-wide → global) mirrors `hasPermission()`'s
  own scope filter. Frontend: `UserDropDown.tsx`'s nav-avatar dropdown, which
  previously hardcoded the literal string `"Super Admin"` for every user.

No new middleware was added on the `/admin/*`-facing merchant routes
themselves. That was a deliberate choice, not an oversight — every one of
those endpoints (`MerchantRestaurantSettingsController`,
`MerchantBillingController`, `MerchantRestaurantStaffController`, etc.)
**already** enforces its own specific `hasRestaurantPermission()` check, and
confirmed correct by existing tests. Retrofitting a blanket route-group
middleware across dozens of already-individually-secured routes would have
meant restructuring `routes/v1/merchant.php`'s single flat route group into
separate dashboard/admin groups — high risk, no security benefit, since the
real boundary (can this specific action be performed) was never the gap.
The actual gap was purely that nothing told the *frontend* whether a user
should even attempt to navigate into `/admin` — that's what `can_access_admin`
closes.

## Frontend

### `AuthProvider` (`src/providers/AuthProvider.tsx`)

`restaurantPermissions: string[]`, `canAccessAdmin: boolean`, and
`restaurantRoleName: string | null` are now part of the auth context,
populated from `restaurants[0].permissions` / `.can_access_admin` /
`.role_name` in `fetchSessionData()` (the same function that already
resolves `restaurantId`/`restaurantName`). Reset on `logout()`, threaded
through `setRestaurant()`'s existing optional-param pattern.

### Gating

- **`Sidebar.tsx`** (dashboard's left nav) — the "Admin" link is filtered out
  of `OTHERS` entirely when `!canAccessAdmin`.
- **`admin/layout.tsx`** — a `lacksAdminAccess` redirect to `/dashboard`,
  checked *before* the existing `needsOnboarding`/`needsBillingRenewal`
  gates (onboarding/billing are themselves admin-only concerns; a
  dashboard-only user has no reason to land on them either). Unlike those
  two gates, no path is exempt — covers direct URL navigation into any
  `/admin/*` route, not just the nav link.
- **`UserDropDown.tsx`** (the avatar dropdown in `Nav.tsx`) — the
  dashboard-section menu shows "Manage Reservations"/"View
  Analytics"/"Settings" when `canAccessAdmin`, else just "Settings". This
  supersedes an earlier, broken version of this same check that read
  `user.roles.includes("principal_admin")` — see "Broken role check" above
  for why that was wrong.
- **`user-management-section.tsx`** (`dashboard/settings` → User
  Management) — "Add new user" and each row's edit action require
  `restaurantPermissions.includes("staff.manage")`. Everyone else still
  sees the read-only staff list.
- **`useVerify2FA.ts`** — the post-login redirect now checks
  `restaurant.can_access_admin` before routing into `/admin/onboarding` or
  `/admin/availability-planning`; a dashboard-only user is routed straight
  to `/dashboard` instead of bouncing through an `/admin` page it would
  immediately get redirected back out of.

## What this does *not* do (deliberately, not an oversight)

- **No backfill for already-invited staff.** `RestaurantAccessConfig::defaults()`
  gaining `staff.manage` on the Operations config only affects restaurants
  created from now on — an already-existing restaurant's Operations config
  keeps whatever permissions it already has (an owner can add `staff.manage`
  themselves via the existing Access Config editor).

## See also

`docs/ERROR_MESSAGES_AND_SESSION_TERMINATION.md` — what happens once access
is denied or withdrawn: every `abort()` with no message now gets a real
fallback instead of an empty string, and suspending a staff member now
revokes their already-issued token instead of leaving it valid for up to 30
days.

Frontend counterpart: `moretable-web-app/docs/access-control.md` — how
`canAccessAdmin`/`restaurantPermissions` from this doc actually get consumed
(`AuthProvider`, `Sidebar.tsx`, `admin/layout.tsx`, `UserDropDown.tsx`,
`user-management-section.tsx`, `useVerify2FA.ts`).

`docs/PERMISSION_MATRIX.md` — the full picture, one row per assignable
permission: what it unlocks in `/dashboard` vs `/admin`, read vs write, and
(important) which ones aren't actually enforced by any endpoint yet.
