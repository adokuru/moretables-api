# Admin Surveys Frontend Handoff

This document explains the new admin survey API so the frontend can implement survey management in the admin dashboard.

## Overview

Admins can now:

- create surveys
- edit draft surveys
- publish draft surveys
- send a published survey immediately
- schedule a published survey for later
- delete an unused draft survey
- list and view surveys

The API supports two survey scopes:

- `platform`: a general survey created by admin for the platform
- `restaurant`: a survey linked to a specific restaurant

## Audience Behavior

Sending a survey targets different audiences based on `scope`:

- `platform`: all active users
- `restaurant`: only active users who have at least one non-cancelled / non-no-show reservation at that restaurant

Both scopes still respect delivery preferences and channels (`email`, `push`, `whatsapp`).

## Survey email link / 404 note

Survey invitation emails link to the **customer frontend**, not the API:

`{FRONTEND_URL}/surveys/{token}`

Example:

`https://www.moretables.com/surveys/b3e9c6dd...`

That page must exist on the customer app. The page should call:

- `GET /api/v1/guest-surveys/{token}` to load the survey
- `POST /api/v1/guest-surveys/{token}/responses` to submit answers

If `FRONTEND_URL` is missing in `.env`, the API falls back to `APP_URL` (often `http://localhost:8000`). That produces links like:

`http://localhost:8000/surveys/{token}`

Those return 404 because that route does not exist on the API. Fix by setting:

```env
FRONTEND_URL=http://localhost:3000
```

(or whatever port your customer frontend runs on), then resend / regenerate the invitation.

## Base Route

All admin routes are under:

`/api/v1/admin`

These endpoints require the normal admin auth flow and admin access middleware.

## Survey Object

Typical survey response shape from `GET /api/v1/admin/surveys/{survey}`:

```json
{
  "id": 12,
  "scope": "platform",
  "version": 1,
  "publication_sequence": 4,
  "title": "Customer Satisfaction Survey",
  "description": "Tell us how we are doing.",
  "logo_url": null,
  "status": "published",
  "questions": [
    {
      "id": "rating",
      "type": "rating",
      "prompt": "Overall rating?",
      "required": true,
      "options": []
    }
  ],
  "settings": {
    "send_delay_minutes": 0,
    "channels": ["push", "email"]
  },
  "restaurant": null,
  "dispatches": [
    {
      "id": 7,
      "status": "dispatched",
      "recipients_count": 42,
      "scheduled_at": null,
      "dispatched_at": "2026-07-29T10:05:00+00:00",
      "created_at": "2026-07-29T10:00:00+00:00"
    }
  ],
  "published_at": "2026-07-29T09:50:00+00:00",
  "created_at": "2026-07-29T09:40:00+00:00",
  "updated_at": "2026-07-29T09:50:00+00:00"
}
```

### Fields the frontend should care about

- `id`: survey id
- `scope`: `platform` or `restaurant`
- `title`
- `description`
- `status`: `draft`, `published`, or `archived`
- `questions`
- `settings.channels`: any of `email`, `push`, `whatsapp`
- `restaurant`: present for restaurant-scoped surveys, otherwise `null`
- `dispatches`: send history (only on show when loaded)
- `published_at`

### Dispatch statuses

| Status | Meaning |
|---|---|
| `pending` | Queued / waiting for worker (or scheduled for later) |
| `processing` | Worker is currently sending |
| `dispatched` | Finished — use `recipients_count` for how many users were notified |

Poll `GET /api/v1/admin/surveys/{survey}` after send to refresh dispatch status.
## Supported Question Types

Each question must have:

- `id`
- `type`
- `prompt`
- `required`
- `options`

Supported `type` values:

- `rating`
- `yes_no`
- `nps`
- `single_choice`
- `long_text`

Notes:

- `single_choice` requires at least 2 unique non-empty options
- other question types should still send `options: []`

## Endpoints

### 1. List surveys

`GET /api/v1/admin/surveys`

Optional query params:

- `scope=platform|restaurant`
- `status=draft|published|archived`

Example response:

```json
{
  "data": [
    {
      "id": 12,
      "scope": "platform",
      "title": "Customer Satisfaction Survey",
      "status": "draft",
      "questions": [],
      "settings": {
        "send_delay_minutes": 0,
        "channels": ["push", "email"]
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 1
  }
}
```

### 2. View one survey

`GET /api/v1/admin/surveys/{survey}`

Response:

```json
{
  "survey": {
    "id": 12,
    "scope": "platform",
    "title": "Customer Satisfaction Survey",
    "status": "draft",
    "questions": []
  }
}
```

### 3. Create a survey

`POST /api/v1/admin/surveys`

#### Platform survey payload

```json
{
  "scope": "platform",
  "title": "Customer Satisfaction Survey",
  "description": "Tell us how we are doing.",
  "channels": ["email", "push"],
  "questions": [
    {
      "id": "rating",
      "type": "rating",
      "prompt": "Overall rating?",
      "required": true,
      "options": []
    }
  ]
}
```

#### Restaurant survey payload

```json
{
  "scope": "restaurant",
  "restaurant_id": 55,
  "title": "Post Dining Survey",
  "description": "Help us improve this restaurant experience.",
  "channels": ["email"],
  "questions": [
    {
      "id": "food",
      "type": "rating",
      "prompt": "Rate the food.",
      "required": true,
      "options": []
    }
  ]
}
```

Success response:

```json
{
  "message": "Survey created successfully.",
  "survey": {
    "id": 12,
    "scope": "platform",
    "status": "draft"
  }
}
```

### 4. Update a draft survey

`PATCH /api/v1/admin/surveys/{survey}`

Only draft surveys can be updated.

Example payload:

```json
{
  "title": "Updated Survey Title",
  "description": "Updated description",
  "channels": ["email", "push"],
  "questions": [
    {
      "id": "rating",
      "type": "rating",
      "prompt": "How would you rate us?",
      "required": true,
      "options": []
    }
  ]
}
```

Success response:

```json
{
  "message": "Survey updated successfully.",
  "survey": {
    "id": 12,
    "status": "draft"
  }
}
```

### 5. Publish a draft survey

`POST /api/v1/admin/surveys/{survey}/publish`

Rules:

- only draft surveys can be published
- survey must have at least one question

Success response:

```json
{
  "message": "Survey published successfully.",
  "survey": {
    "id": 12,
    "status": "published",
    "published_at": "2026-07-29T09:50:00+00:00"
  }
}
```

### 6. Send now or schedule send

`POST /api/v1/admin/surveys/{survey}/send`

Only published surveys can be sent.

#### Send immediately

Request body:

```json
{}
```

Response:

```json
{
  "message": "Survey dispatch queued.",
  "dispatch": {
    "id": 7,
    "status": "pending",
    "scheduled_at": null
  }
}
```

#### Schedule for later

Request body:

```json
{
  "scheduled_at": "2026-07-30T09:00:00Z"
}
```

Response:

```json
{
  "message": "Survey scheduled for dispatch.",
  "dispatch": {
    "id": 8,
    "status": "pending",
    "scheduled_at": "2026-07-30T09:00:00+00:00"
  }
}
```

### 7. Delete a draft survey

`DELETE /api/v1/admin/surveys/{survey}`

Rules:

- only unreferenced draft surveys can be deleted
- published surveys cannot be deleted
- drafts with invitations already created cannot be deleted

Response:

```json
{
  "message": "Survey deleted successfully."
}
```

## Validation Rules

### Create / Update

- `scope` is required on create and must be `platform` or `restaurant`
- `restaurant_id` is required when `scope=restaurant`
- `title` max length is 160
- `description` max length is 1000
- `logo_url` must be a valid URL
- `questions` max length is 50
- each question id must be unique
- `channels` must contain at least 1 item
- allowed channels: `email`, `push`, `whatsapp`
- `single_choice` questions need at least 2 unique non-empty options

### Send

- `scheduled_at` is optional
- if sent, it must be a valid future date/time

## Recommended Admin Dashboard UX

### Survey list page

Recommended columns:

- title
- scope
- restaurant name
- status
- channels
- published at
- created at

Recommended filters:

- scope
- status

Recommended actions:

- view
- edit
- publish
- send now
- schedule send
- delete

### Survey form page

Recommended fields:

- scope selector: `platform` / `restaurant`
- restaurant selector, shown only when scope is `restaurant`
- title
- description
- logo URL
- channels multi-select
- question builder

### Question builder behavior

For each question, support:

- prompt
- type
- required toggle
- options editor for `single_choice`

Recommended frontend defaults:

- `channels`: `["push", "email"]`
- `questions`: empty array

### Detail page / actions

If status is `draft`:

- allow edit
- allow publish
- allow delete

If status is `published`:

- make survey read-only
- allow send now
- allow schedule send

If status is `archived`:

- read-only

## Error Handling

Common backend validation failures the frontend should handle:

### Updating a published survey

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "survey": [
      "Published surveys are immutable. Create a new draft instead."
    ]
  }
}
```

### Publishing with no questions

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "questions": [
      "Add at least one question before publishing."
    ]
  }
}
```

### Sending an unpublished survey

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "survey": [
      "Only published surveys can be sent."
    ]
  }
}
```

### Restaurant scope without restaurant_id

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "restaurant_id": [
      "A restaurant must be provided when scope is restaurant."
    ]
  }
}
```

## Suggested Frontend Flow

Recommended implementation order:

1. Build survey list page with filters.
2. Build create/edit form for draft surveys.
3. Build publish action.
4. Build send now modal.
5. Build schedule send modal.
6. After send, poll `GET /api/v1/admin/surveys/{id}` and show `dispatches` status / `recipients_count`.
7. Add status-driven action guards in the UI.

## Customer survey page requirement

The customer frontend must implement `/surveys/{token}`:

1. Read the token from the URL.
2. Call `GET /api/v1/guest-surveys/{token}`.
3. Render questions from the returned `survey` object.
4. Submit answers with `POST /api/v1/guest-surveys/{token}/responses`.

Without that page, email links will 404 even when the API is working.

## Current Gaps To Be Aware Of

- There is still no dedicated standalone `/admin/surveys/{id}/dispatches` endpoint; dispatch history is returned on survey show.
- List endpoint does not include `dispatches` (only show does).
- Platform emails use the survey title; restaurant emails use restaurant-visit copy.
