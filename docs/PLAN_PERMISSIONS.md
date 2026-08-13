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

## What's actually gated today: the Customizable Post-meal Guest Survey

The **only** plan-gated feature enforced so far. `BillingPlan.features`
(a JSON column) and `plans-table.tsx` (the public pricing page) both
describe several other Premium/Core-only rows — Loyalty Program,
Reservation Widget, Pre-shift Report — but none of those are wired to any
real check yet. `hasPlanAtLeast()`/the frontend's mirroring `planAccess.ts`
are written as **reusable infrastructure** for when those get built, not
single-purpose to surveys.

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
  convention it mirrors. If a 3rd or 4th plan-gated feature shows up, it's
  worth revisiting whether a shared `FeatureGate`-style service is warranted
  — not built preemptively here since there's only one real caller so far.

## See also

`moretable-web-app/docs/plan-permissions.md` — the frontend counterpart:
where the current plan is exposed (`AuthProvider.planSlug`), the
`planAccess.ts` helper, and how the survey editor renders the locked state.

`PERMISSION_MATRIX.md` — the orthogonal role-permission axis. A user still
needs `communications.manage`/`restaurants.manage` *and* the right plan
tier to customize a survey — the two checks are independent and both must
pass.
