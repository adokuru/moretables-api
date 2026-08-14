# Plan-tier permissions — what subscription tier gates, separately from role permissions

This is a **different axis** from `PERMISSION_MATRIX.md`. That doc covers
role-based access-config permissions (who on a restaurant's staff can do
what). This one covers **subscription-plan-tier** gating — what a
restaurant's plan (Foundation / Core / Premium) unlocks regardless of which
staff role is asking. The two are orthogonal and both apply: a user still
needs the right role permission *and* the restaurant needs the right plan
tier for a plan-gated feature.

Frontend counterpart: `moretable-web-app/docs/plan-permissions.md`.

## The 3 tiers

Defined in `app/BillingPlanSlug.php`, seeded into `billing_plans` via
`database/seeders/BillingPlanSeeder.php` (amounts in `config/billing.php`,
kobo):

| Plan | Slug | Rank | Price |
|---|---|:-:|---|
| Foundation | `foundation` | 0 | ₦85,000/month |
| Core | `core` | 1 | ₦135,000/month |
| Premium | `premium` | 2 | ₦185,000/month |

`BillingPlanSlug::rank()`/`::atLeast()` turn this into an ordinal comparison
— a plan "at least Core" means rank ≥ 1, i.e. Core or Premium.

## `Restaurant::hasPlanAtLeast()` — the enforcement primitive

```php
public function hasPlanAtLeast(BillingPlanSlug $minimum): bool
{
    return $this->activeBillingSubscription?->plan?->slug?->atLeast($minimum) === true;
}
```

`activeBillingSubscription` (already existed) only resolves a subscription
whose status is `active`/`trialing` and whose `current_period_end` hasn't
passed — so a trialing restaurant on a given tier counts as meeting it, and
a lapsed/canceled subscription never does, regardless of what tier it was
on.

This mirrors the codebase's established inline-gate convention for role
permissions (`$user->hasAnyRestaurantPermission([...], $restaurant)`,
checked inline per-controller-method, no Policy classes) rather than
introducing a new authorization layer — confirmed no Merchant controller
uses Laravel Policies before adding this.

## What's actually gated today

Two features so far. `BillingPlan.features` (a JSON column) and
`plans-table.tsx` (the public pricing page) describe further Premium/Core-only
rows — Reservation Widget, Pre-shift Report — that still aren't wired to any
real check. `hasPlanAtLeast()`/the frontend's mirroring `planAccess.ts` are
written as **reusable infrastructure**, not single-purpose to either feature
below.

### Customizable Post-meal Guest Survey (Premium-only)

`MerchantGuestSurveyController.php` — Foundation and Core restaurants get
the fixed `post_dining` question set and cannot customize it; Premium can
customize freely:

| Method | Behavior below Premium |
|---|---|
| `templates()` | Only returns the `post_dining` entry — the `blank` (build-your-own) option is omitted entirely. |
| `store()` | Rejects (`403`, custom message) unless `template_key === 'post_dining'` and the resulting `questions` exactly match the fixed template. |
| `update()` | Rejects (`403`, custom message) if a `questions` field is sent that doesn't match the fixed template. Every other field (`title`, `description`, `logo_url`, `channels`, `send_delay_minutes`) stays editable on any plan — only question *content* is gated. |
| `publish()` | Re-checks the same rule as a defense-in-depth backstop (a draft should already satisfy it via `store`/`update`, but this is the last gate before a survey goes live). |

The comparison lives in a private `isDefaultPostDiningQuestions(array
$questions): bool` helper, shared by all three methods, checking `$questions
== $this->postDiningQuestions()` (loose `==`, so key order within a question
doesn't matter, but the list of questions and their content must match
exactly).

The 403 always carries the same message —
`"Upgrade to Premium to customize your survey questions."` — passed as
`abort_unless(..., 403, '...')`'s third argument, so it lands in the JSON
body's top-level `message` field. On the frontend, a mutation's (POST/PATCH)
403 is *not* redirect-on-forbidden by default (see
`moretable-web-app/src/lib/client.ts`) — it surfaces this exact string via
the global toast, no special-casing needed on that end.

Tests: `tests/Feature/GuestSurveyTest.php` (search "Plan-tier gating"). Test
helper `setRestaurantBillingPlan(Restaurant, BillingPlanSlug)` (in
`tests/Pest.php`, alongside `activateMerchantBilling()` which defaults every
test restaurant to Foundation) moves an already-activated subscription onto
a specific tier.

### Guest Loyalty Program (Core/Premium — the first non-Premium-only gate)

Two independent surfaces, both requiring `hasPlanAtLeast(BillingPlanSlug::Core)`:

1. **The onboarding opt-in flag** (`restaurants.rewards_enabled`, the
   "I agree to participate" checkbox saved via the generic
   `MerchantRestaurantSettingsController::update()`, **not** a dedicated
   onboarding endpoint). When the submitted `rewards_enabled` is truthy but
   the restaurant doesn't qualify, it's silently coerced to `false` before
   saving ("auto-disagree") — no error, no rejection, since this is a
   passive settings field, not an explicit feature action. Other settings
   fields in the same request are unaffected.
2. **`Restaurant::offersMoretablesCredits()`** — the single source of truth
   for whether a restaurant's reservations actually earn reward points, now
   `rewards_enabled && hasPlanAtLeast(Core)` instead of the bare column.
   `ReservationService::maybeAwardReservationPoints()` and
   `MerchantRestaurantRewardStatusController` (the merchant-facing "does
   this restaurant offer credits" check) both read through this helper —
   so a restaurant whose `rewards_enabled` is stuck at a stale `true` from
   before a downgrade (or from the DB column's own `true` default) never
   actually issues points, no backfill migration needed. This is stronger
   than the survey feature's approach (which only blocks *changing* content,
   not *using* already-saved content) — deliberate, since a boolean toggle
   has no "saved custom content" to preserve, unlike survey questions.
3. **`MerchantRewardRuleController::store()`/`::update()`** (the ongoing
   `/admin/marketing` bonus-rule editor — a day/time-specific point bonus on
   top of the base program, a *different* resource from the opt-in flag
   above) — reject with `403 "Upgrade to Core or Premium to set up the
   Guest Loyalty Program."` when the restaurant doesn't qualify. Per
   explicit product decision, the frontend form is **not** disabled/hidden
   pre-submit — staff can fill it out, and the error only appears on
   submit, surfaced via the same global-toast mechanism as the survey
   feature's 403s.
   - **Adjacent fix, found while adding this**: `store()`/`update()` had
     **no authorization check of any kind** before this — any authenticated
     user could create or edit reward rules for *any* restaurant, not just
     ones they belong to. Added `abort_unless($user->hasAnyRestaurantPermission(['restaurants.manage', 'marketing.manage'], $restaurant), 403)`
     to both, matching `destroy()`'s existing permission set in the same
     controller. Not part of the plan-gating ask, but too directly adjacent
     (same two methods, same edit) to leave for a separate pass.

Tests: `tests/Feature/MerchantRewardRuleCrudTest.php`,
`tests/Feature/MerchantRestaurantRewardStatusTest.php`,
`tests/Feature/MerchantRestaurantSettingsRewardsTest.php` (new file), and
`tests/Feature/Feature/Reservations/ReservationBookingFieldsTest.php` (the
point-awarding path). All follow the same `setRestaurantBillingPlan()`
pattern as the survey tests.

## Known gaps (flagged, not built)

- **Foundation's `features.guest_communication` config flag is unenforced.**
  `config/billing.php`/`BillingPlanSeeder` already mark Foundation as
  `guest_communication: false` — read literally, that implies Foundation
  shouldn't have *any* access to `/admin/guest-communication`, not just the
  survey-customization slice gated here. Live behavior today: Foundation
  restaurants can fully use Guest Email and Broadcast Messaging, and can use
  (not customize) the Survey tab. This was scoped out deliberately — the
  ask was specifically "lock survey customization to Premium," not "lock
  all of guest communication to Core+." If the broader lock is wanted later,
  it needs its own explicit decision (which sub-features Foundation keeps,
  if any) before implementing, not an inferred read of the config flag.
- **No retroactive handling of a plan downgrade.** A restaurant that
  customizes its survey on Premium and later downgrades to Foundation/Core
  keeps its already-saved custom `questions` untouched (they simply become
  un-editable — `update()`'s check only blocks *changing* `questions`, it
  doesn't force them back to the fixed template). There's no migration or
  scheduled job that reverts custom content on downgrade.
- **No generic `PlanFeature`/policy abstraction yet.** `hasPlanAtLeast()` is
  a plain boolean helper called inline, same as the role-permission
  convention it mirrors. Two features now call it across 5 different
  controller methods — still judged not worth a shared `FeatureGate`-style
  service, but the next feature added here should revisit that judgment.
- **Reservation Widget and Pre-shift Report are still unenforced.** Both are
  advertised as plan-gated on the public pricing page; neither has any real
  check anywhere in the app yet.

## See also

`moretable-web-app/docs/plan-permissions.md` — the frontend counterpart:
where the current plan is exposed (`AuthProvider.planSlug`), the
`planAccess.ts` helper, how the survey editor renders the locked state, and
how the onboarding stepper removes the Reward Program step entirely below
Core.

`PERMISSION_MATRIX.md` — the orthogonal role-permission axis. A user still
needs `communications.manage`/`restaurants.manage` *and* the right plan
tier to customize a survey — the two checks are independent and both must
pass.
