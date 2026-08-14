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

Five features so far. `BillingPlan.features` (a JSON column) and
`plans-table.tsx` (the public pricing page) still describe a further
Premium-only row — Pre-shift Report — not wired to any real check yet.
`hasPlanAtLeast()`/the frontend's mirroring `planAccess.ts` are written as
**reusable infrastructure**, not single-purpose to any one feature below.

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

### Automated Email Campaigns (Premium-only)

Covers **both** guest-communication tabs — the pricing page's "Automated
Email Campaigns" row doesn't map to a single dedicated feature in the code
(there's no scheduled/recurring campaign builder anywhere in the app, just
two manual "compose and send now" toggles), so both are gated identically:

- **Guest Email tab** (`reservation_messaging_enabled`, "Send curated
  messages to guests," `MerchantGuestCommunicationController::updateReservationMessaging()`)
- **Broadcast Messaging tab** (`automated_messaging_enabled`, "Send curated
  broadcast messages," `::updateAutomatedMessaging()`)

Three enforcement points, each requiring `hasPlanAtLeast(BillingPlanSlug::Premium)`:

1. **`updateAutomatedMessaging()`/`updateReservationMessaging()`** — a
   private `abortUnlessMayEnable()` helper rejects (`403`, `"Upgrade to
   Premium to send automated email campaigns to guests."`) only when the
   request tries to set `enabled=true`; setting `false` is always allowed
   (turning a feature off never needs a plan check).
2. **`show()`** — reports both `automated_messaging.enabled` and
   `reservation_messaging.enabled` as `false` for a sub-Premium restaurant
   regardless of the stored column value, **without persisting anything** —
   same "report the effective state, don't silently rewrite data" approach
   as the survey feature's read paths, and avoids ever needing a downgrade
   migration (same reasoning as the Loyalty Program's `offersMoretablesCredits()`).
3. **`MerchantRestaurantBroadcastController::store()`** (the actual
   send-a-message action, shared by both tabs, keyed by `audience`) — also
   requires Premium, regardless of `audience`. This one isn't just
   defense-in-depth: the Guest Email tab's compose/send UI is **not** gated
   behind its own toggle on the frontend (unlike Broadcast Messaging's,
   which only renders its compose box while `enabled`), so this is the
   actual, and only, backend enforcement point for that tab's send action.

Per explicit product decision (same as the Loyalty Program's reward-rule
editor): the frontend makes **no changes at all** here — no disabled
toggles, no hidden compose box beyond what `automated_messaging.enabled`'s
now-always-`false` value already naturally causes on the Broadcast
Messaging tab. Staff can flip the toggle or attempt to send on either tab;
the 403 surfaces via the existing global toast. Confirmed none of
`useUpdateAutomatedMessaging`/`useUpdateReservationMessaging`/`useSendBroadcast`
(`moretable-web-app/src/lib/api/guest-communication/hooks/useGuestCommunication.ts`)
define a custom `onError` that would need touching.

Tests: `tests/Feature/Feature/Merchant/MerchantOperationsTest.php` (the
guest-communication toggle tests) and
`tests/Feature/Feature/Merchant/MerchantBroadcastTest.php` (the send
action). Both files' existing tests predate this gate and were updated to
default to Premium via `setRestaurantBillingPlan()`, same pattern as the
other two features.

### Reservation Widget (Core/Premium — first "redirect away" UX treatment)

`MerchantRestaurantWidgetSettingsController::update()` rejects (`403`,
`"Upgrade to Core or Premium to configure your reservation widget."`) below
`hasPlanAtLeast(BillingPlanSlug::Core)`. `show()` is **left ungated** — it's
read-only and harmless to leave available (matches the frontend, which
still fires the settings fetch during its brief pre-redirect render, see
below).

**The one endpoint that must never be touched by this gate**:
`PublicRestaurantController::show` (`GET /v1/restaurants/{restaurant:slug}`,
unauthenticated, serves `widget_settings` via `RestaurantDetailResource`) is
what the actual embedded widget — already live on some Foundation
restaurant's own website, hosted on a separate customer-facing site outside
either of these two repos — reads to render itself. Gating this would break
every already-embedded widget the moment a restaurant's plan status changed
underneath it, not just prevent new configuration. Confirmed no plan check
was added there; a Foundation restaurant's already-configured, already-live
widget keeps working exactly as before, it just can't be reconfigured.

Per explicit product decision, this feature uses a **third UX treatment**,
distinct from the other three features in this doc — see the frontend doc
for why (a full settings page, not a single toggle or a single-submit
form): the frontend **redirects away entirely** rather than showing a
locked form or letting the request fail on submit.

Tests: `tests/Feature/MerchantRestaurantWidgetSettingsTest.php`. Existing
tests predate this gate and were updated to default to Premium via
`setRestaurantBillingPlan()`.

### Reservation Holds (Core/Premium — a sub-tab within a shared page, not the whole page)

`admin/restaurant-settings?tab=policies` has 2 sub-tabs: "Booking Policies"
(language + free-text dining policy, unrelated, stays available on any
plan) and "Cancellation and No-show Policies" — which, despite the generic
name, is **100% the Reservation Holds feature**: every policy created there
is a credit-card-hold rule (`RestaurantCancellationPolicy.management_method`
has only one enum case, `card_hold`). Don't confuse this with the
unrelated, unused `RestaurantPolicy.deposit_required` column (booking
policy's model) — it's dead, never read or written by any real endpoint.

Three enforcement points, all requiring `hasPlanAtLeast(BillingPlanSlug::Core)`:

1. **`MerchantRestaurantCancellationPolicyController::store()`/`update()`**
   — reject (`403`, `"Upgrade to Core or Premium to set up reservation
   holds."`) via a shared private `abortUnlessPlanQualifies()` helper.
   `destroy()` is deliberately **not** gated — removing an existing policy
   stays allowed on any plan, same "turning something off/away never needs
   a plan check" reasoning as the Loyalty Program and Email Campaigns
   features. `index`/`show` also stay ungated (read-only).
2. **`RestaurantCancellationPolicyService::matchingPolicy()`** — the
   runtime lookup that decides whether an incoming booking needs a card
   hold at all — now returns `null` immediately for a sub-Core restaurant,
   **regardless of what policies already exist in the database**. This is
   the actual enforcement that matters operationally: a restaurant that
   downgrades keeps its already-created hold policies in storage (same
   "no backfill migration" choice as `offersMoretablesCredits()`), but they
   silently stop applying to new bookings the moment the plan drops below
   Core — no card hold gets required, no guest gets charged based on a
   policy the restaurant can no longer configure or see take effect.

Frontend UX, per explicit product decision (see the frontend doc): this is
the **second** feature to use the "redirect + toast" treatment introduced
by the Reservation Widget, but scoped to just the one sub-tab —
"Booking Policies" stays completely normal.

Tests: `tests/Feature/MerchantRestaurantCancellationPolicyCrudTest.php`
(CRUD + plan gating) and
`tests/Feature/Feature/Reservations/ReservationCardHoldTest.php` (the
`matchingPolicy()` runtime check — its `cardHoldRestaurant()` helper
predates this gate and was updated to default to Premium).

## Known gaps (flagged, not built)

- **Foundation's `features.guest_communication` config flag is still only
  partially reflected.** `config/billing.php`/`BillingPlanSeeder` mark
  Foundation as `guest_communication: false`, read literally implying no
  `/admin/guest-communication` access at all. Live behavior now: Foundation
  and Core can no longer send anything (Automated Email Campaigns is
  gated above) and can't customize surveys (Premium-only), but they can
  still *view* the guest-communication settings page, list/view surveys,
  and use the fixed-template survey — narrower than the config flag's
  literal claim, but nobody has asked for the page to be fully inaccessible
  below Premium, only for the specific actions gated so far. Revisit if
  that's ever explicitly requested.
- **No retroactive handling of a plan downgrade.** A restaurant that
  customizes its survey on Premium and later downgrades to Foundation/Core
  keeps its already-saved custom `questions` untouched (they simply become
  un-editable — `update()`'s check only blocks *changing* `questions`, it
  doesn't force them back to the fixed template). There's no migration or
  scheduled job that reverts custom content on downgrade.
- **No generic `PlanFeature`/policy abstraction yet.** `hasPlanAtLeast()` is
  a plain boolean helper called inline, same as the role-permission
  convention it mirrors. Five features now call it across 12 different
  controller/service methods — still judged not worth a shared
  `FeatureGate`-style service, but the next feature added here should
  revisit that judgment.
- **Waitlist Management and Pre-shift Report are still unenforced.** Both
  Core/Premium-only (Waitlist) or Premium-only (Pre-shift Report) per the
  public pricing page; no real check anywhere in the app yet. Waitlist
  Management is next up — deliberately not started yet, pending separate
  research the user is doing first.
- **`PERMISSION_MATRIX.md`'s note on `integrations.manage` is stale**,
  found while researching the Reservation Widget gate, unrelated to plan
  tiers: it claims "No backend controller exists yet for Integrations,"
  which was true when written but no longer is
  (`MerchantRestaurantWidgetSettingsController` exists and enforces
  `restaurants.view`/`restaurants.manage` server-side) — and separately,
  `integrations.manage` itself is never actually checked by any controller
  (confirmed via full grep), only referenced in seeders/the access-config
  model. Not touched here — flagging since it was noticed in passing, not
  part of this plan-gating work.

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
