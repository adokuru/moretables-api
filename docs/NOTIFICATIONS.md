# Notifications — full copy & payloads

Use this doc when briefing copywriters or designers. **Placeholders** in `{curly braces}` are filled at runtime.

| Placeholder | Meaning |
|-------------|---------|
| `{restaurantName}` | Restaurant name |
| `{guestName}` | Guest first + last name (or omitted) |
| `{code}` | 6-digit (or configured) OTP |
| `{purpose}` | e.g. `verify your email` or `finish signing in` |
| `{N}` | Minutes until code expires (default 10) |
| `{reference}` | Reservation reference string |
| `{datetime}` | Localized date/time of reservation start |
| `{partySize}` | Number of guests |
| `{preferredTime}` | Waitlist preferred start datetime |
| `{action}` | For logged-in user reservation emails: **`created`**, **`updated`**, or **`cancelled`** (appears verbatim in subject/body) |

**Email shell:** All mails use Laravel’s `MailMessage` markdown theme → **app name** salutation/footer (`Regards, {APP_NAME}`), no CTA buttons unless noted.

**Push:** Expo — **sound** `default`. Payload includes `data` for deep linking.

---

## 1. AuthChallengeCodeNotification

| | |
|--|--|
| **File** | `app/Notifications/AuthChallengeCodeNotification.php` |
| **Channels** | Email only |
| **When** | Guest signup OTP, guest/customer resend OTP, **staff** 2FA step after password (`AuthChallengeService`) |
| **Recipient** | User’s registered email |

### Email

**Subject:** `Your MoreTables verification code`

```
Hello!

Use this code to {purpose}:

{code}

This code expires in {N} minutes.

If you did not request this code, you can ignore this email.

—
Regards,
{APP_NAME}
```

**`{purpose}` values today:**

| Flow | Text |
|------|------|
| Guest signup | `verify your email` |
| Sign-in / staff challenge | `finish signing in` |

### Push

_Not sent._

---

## 2. ReservationLifecycleNotification

| | |
|--|--|
| **File** | `app/Notifications/ReservationLifecycleNotification.php` |
| **Channels** | Email + Expo push |
| **When** | Customer has a **linked user**; reservation **created** (self-booking), **updated**, or **cancelled** |
| **Recipient** | `User` (all registered Expo tokens) |

### Email

**Subject:** `Your reservation was {action}`  
*(e.g. “Your reservation was created”)*

```
Hello!

Your reservation at {restaurantName} was {action}.

Reference: {reference}
Time: {datetime}
Party size: {partySize}

—
Regards,
{APP_NAME}
```

### Push

| Field | Value |
|-------|--------|
| **Title** | `Reservation update` |
| **Body** | `Your reservation at {restaurantName} was {action}.` |

**Data payload (JSON keys):**

```json
{
  "type": "reservation_lifecycle",
  "reservation_id": "<uuid>",
  "restaurant_id": "<uuid>",
  "status": "<reservation status enum>",
  "action": "created|updated|cancelled",
  "reference": "<string>",
  "starts_at": "<ISO8601>"
}
```

---

## 3. GuestReservationLifecycleMailNotification

| | |
|--|--|
| **File** | `app/Notifications/GuestReservationLifecycleMailNotification.php` |
| **Channels** | Email only |
| **When** | Reservation has **no** linked user but **guest email** on file; merchant/customer flows create/update/cancel |
| **Recipient** | On-demand route → guest email |

### Email — subject & intro by action

| Action | Subject | First body line after greeting |
|--------|---------|-------------------------------|
| **created** | `Reservation confirmed at {restaurantName}` | `A reservation has been booked for you at {restaurantName}.` |
| **updated** | `Reservation updated — {restaurantName}` | `Your reservation at {restaurantName} has been updated.` |
| **cancelled** | `Reservation cancelled — {restaurantName}` | `Your reservation at {restaurantName} has been cancelled.` |
| *(other)* | `Reservation update — {restaurantName}` | `There is an update to your reservation at {restaurantName}.` |

**Greeting:** `Hello {guestName},` or `Hello,` if no name.

**Shared lines:**

```
Reference: {reference}
Time: {datetime}
Party size: {partySize}

If you have questions, contact the restaurant directly.

—
Regards,
{APP_NAME}
```

### Push

_Not sent._

---

## 4. WaitlistAvailabilityNotification

| | |
|--|--|
| **File** | `app/Notifications/WaitlistAvailabilityNotification.php` |
| **Channels** | Email + Expo push |
| **When** | Staff notifies waitlist; entry has a **user** account |
| **Recipient** | User |

### Email

**Subject:** `A table is available for your waitlist request`

```
Good news!

A table may be available at {restaurantName}.

Preferred time: {preferredTime}

Please confirm with the restaurant as soon as possible.

—
Regards,
{APP_NAME}
```

### Push

| Field | Value |
|-------|--------|
| **Title** | `Table available` |
| **Body** | `A table may be available at {restaurantName}.` |

**Data payload:**

```json
{
  "type": "waitlist_availability",
  "waitlist_entry_id": "<uuid>",
  "restaurant_id": "<uuid>",
  "status": "<waitlist status>",
  "preferred_starts_at": "<ISO8601>",
  "expires_at": "<ISO8601|null>"
}
```

---

## 5. GuestWaitlistTableAvailableMailNotification

| | |
|--|--|
| **File** | `app/Notifications/GuestWaitlistTableAvailableMailNotification.php` |
| **Channels** | Email only |
| **When** | Staff notifies **guest-only** waitlist (email on file) |
| **Recipient** | Guest email (on-demand) |

### Email

**Subject:** `A table may be available at {restaurantName}`

**Greeting:** `Hello {guestName},` or `Hello,`

```
Good news — a table may be available at {restaurantName} for your waitlist request.

Preferred time: {preferredTime}

Please open the MoreTables app or contact the restaurant as soon as possible to confirm.

This offer is time-limited.

—
Regards,
{APP_NAME}
```

### Push

_Not sent._

---

## 6. WaitlistOfferExpiredNotification

| | |
|--|--|
| **File** | `app/Notifications/WaitlistOfferExpiredNotification.php` |
| **Channels** | Email + Expo push |
| **When** | User tries accept/decline after **`expires_at`** (time expired) |
| **Recipient** | User |

### Email

**Subject:** `Waitlist offer expired`

```
Hello!

The time to respond to your table offer at {restaurantName} has passed.

You can join the waitlist again in the app if tables become available.

—
Regards,
{APP_NAME}
```

### Push

| Field | Value |
|-------|--------|
| **Title** | `Waitlist offer expired` |
| **Body** | `Your table offer at {restaurantName} has expired.` |

**Data payload:**

```json
{
  "type": "waitlist_offer_expired",
  "waitlist_entry_id": "<uuid>",
  "restaurant_id": "<uuid>"
}
```

---

## 7. GuestWaitlistOfferExpiredMailNotification

| | |
|--|--|
| **File** | `app/Notifications/GuestWaitlistOfferExpiredMailNotification.php` |
| **Channels** | Email only |
| **When** | Same expiry path for **guest-email** waitlist (rare until guest accept flow exists) |
| **Recipient** | Guest email |

### Email

**Subject:** `Waitlist offer expired — {restaurantName}`

**Greeting:** `Hello {guestName},` or `Hello,`

```
The time to respond to your table offer at {restaurantName} has passed.

You can ask the restaurant to add you to the waitlist again if you are still interested.

—
Regards,
{APP_NAME}
```

### Push

_Not sent._

---

## 8. WaitlistTableNoLongerAvailableNotification

| | |
|--|--|
| **File** | `app/Notifications/WaitlistTableNoLongerAvailableNotification.php` |
| **Channels** | Email + Expo push |
| **When** | User accepts waitlist but **no table** can be assigned |
| **Recipient** | User |

### Email

**Subject:** `Waitlist table no longer available`

```
Hello!

The table held for your waitlist offer at {restaurantName} is no longer available.

Please join the waitlist again in the app if you would still like a table.

—
Regards,
{APP_NAME}
```

### Push

| Field | Value |
|-------|--------|
| **Title** | `Table no longer available` |
| **Body** | `Your waitlist offer at {restaurantName} could not be completed.` |

**Data payload:**

```json
{
  "type": "waitlist_table_unavailable",
  "waitlist_entry_id": "<uuid>",
  "restaurant_id": "<uuid>"
}
```

---

## 9. GuestWaitlistTableUnavailableMailNotification

| | |
|--|--|
| **File** | `app/Notifications/GuestWaitlistTableUnavailableMailNotification.php` |
| **Channels** | Email only |
| **When** | Same “no table” path for guest-email waitlist |
| **Recipient** | Guest email |

### Email

**Subject:** `Table no longer available — {restaurantName}`

**Greeting:** `Hello {guestName},` or `Hello,`

```
The table that was held for you at {restaurantName} is no longer available.

Please contact the restaurant if you would still like a table.

—
Regards,
{APP_NAME}
```

### Push

_Not sent._

---

## Changing copy

1. **Edit the PHP class** listed in each section (`toMail` / `toExpoPush`).
2. For **rich HTML** or full control, switch to `MailMessage::view(...)` or a **Markdown mail** view under `resources/views/vendor/mail` (publish Laravel mail views if needed).
3. After changes, run **`php artisan test`** (e.g. `CustomerWaitlistResponseTest`, `MerchantOperationsTest`, auth tests) if assertions depend on exact strings.

## Wiring reference

| Concern | Location |
|---------|----------|
| Reservations & waitlist sends | `app/Services/ReservationService.php` |
| OTP / staff challenge | `app/Services/AuthChallengeService.php` |
| Expo transport | `app/Notifications/ExpoPushChannel.php`, `app/Notifications/ExpoPushMessage.php` |
