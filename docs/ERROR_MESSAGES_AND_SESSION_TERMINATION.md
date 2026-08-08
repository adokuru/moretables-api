# Error message fallback + ending a suspended user's session

Two related fixes, both about what happens when access is denied or
withdrawn: the user sees a real message instead of a blank one, and a
suspended user's *existing* session actually ends instead of continuing to
work for up to 30 days.

## 1. Every bare `abort($code)` was sending an empty error message

**The bug**: `abort($code)` / `abort_if($cond, $code)` / `abort_unless($cond, $code)`
called with no message string — which is the overwhelming majority of
authorization checks in this app (every `hasRestaurantPermission()` gate,
`EnsureMerchantAccess`, etc.) — throws `HttpException` with an **empty**
message. Laravel's default JSON rendering sends that straight through as
`{"message": ""}`. The frontend's toast (`error.response?.data?.message ??
error.message ?? "An unexpected error occurred."`, `moretable-web-app/src/lib/client.ts`)
never falls back for this case — `??` only catches `null`/`undefined`, not
`""` — so the user saw a genuinely blank toast.

**Fix** (`bootstrap/app.php`): one central `$exceptions->render()` callback
for `Symfony\Component\HttpKernel\Exception\HttpExceptionInterface`. Only
acts when `$exception->getMessage() === ''` — any call site that already
passes its own message (e.g. `abort_if($cond, 403, 'You cannot change your
own account status.')`) is completely untouched; the callback returns `null`
(passthrough) whenever a message is already present. Status codes covered:
401, 403, 404, 405, 419, 429, with a generic fallback for anything else.

**Don't**: add a message to every individual `abort_unless()` call site to
"properly" fix this — that's dozens of call sites for no benefit over the
one central fix. Do add a specific message at the call site when the generic
fallback genuinely isn't good enough for that one case (rare — see #2 below
for an example of when it's worth it).

**Test**: `tests/Feature/HttpErrorMessageFallbackTest.php`.

## 2. A suspended staff member kept their existing session for up to 30 days

**The bug**: `PATCH .../staff/{user} {status: suspended}`
(`RestaurantStaffManagementService::update`) has always correctly blocked
*future* logins (`AuthController::staffLogin` checks `$user->isActive()`),
but nothing re-checked account status on an **already-issued** Sanctum
token. A suspended user kept full access until their token naturally expired
(`config('sanctum.expiration')`, 30 days) — no middleware or guard anywhere
re-validates `status` per-request.

**Fix** (`RestaurantStaffManagementService::update`): when `status` is set
to anything other than `Active`, immediately call `$staffMember->tokens()->delete()`.
Their very next request 401s — the frontend's response interceptor already
treats a 401 as "clear the stored token, redirect to `/auth/login`" (see
`moretable-web-app/src/lib/client.ts`), so no frontend change was needed.

**Not done, and deliberately so**: no new middleware re-checking `isActive()`
on every authenticated request. Token revocation is the direct fix and costs
nothing per-request; a status-check middleware would be defense-in-depth at
the cost of an extra query on every single authenticated call, for a case
(a token issued in the ~seconds between suspension and the next revocation
sweep) that doesn't exist, since revocation happens synchronously in the
same request that sets the status.

**Test**: `it revokes an already-issued token when a staff member is
suspended, immediately ending their session`
(`tests/Feature/Feature/Merchant/MerchantRestaurantStaffManagementTest.php`).
Deliberately uses real bearer tokens end-to-end rather than
`Sanctum::actingAs()`, with `Auth::forgetGuards()` between calls that switch
identity — see the comment in that test for why: Laravel caches the resolved
user on the guard instance for the lifetime of the test's container, so a
second real-token request within the same test method would otherwise still
authenticate as whoever resolved first. A real request only ever resolves
one user, so this is purely a test-harness quirk, not app behavior — but it
produces a *very* convincing false failure if you don't know to look for it.

## 3. Suspended-login rejection message

`AuthController::staffLogin` now distinguishes `Suspended` specifically:

> "Your account has been disabled. Please reach out to your restaurant's
> management."

Any other non-active status (there currently isn't one reachable via staff
accounts — `RestaurantStaffManagementService` always creates them `Active`,
and `UpdateRestaurantStaffRequest` only ever allows `Active`/`Suspended`)
falls back to the older generic message, "This staff account is not
currently active." — kept as a defensive fallback, not because it's
currently reachable.
