# Canvas Notifications

Canvas includes a notification system that allows the server to
communicate events to the Canvas UI. Notifications appear as toast
popups and in an Activity Center dropdown.

The feature is gated behind the `canvas_dev_mode` module. When
disabled, no bell icon, Activity Center, or toasts are rendered.

## Notification types

| Type         | Purpose                                | Example           |
|--------------|----------------------------------------|-------------------|
| `processing` | An operation is in progress            | Publishing page   |
| `success`    | An operation completed successfully    | Page published    |
| `info`       | Informational, requires acknowledgment | Update available  |
| `warning`    | Requires user attention                | Conflict detected |
| `error`      | Requires user resolution               | Publish failed    |

## Creating notifications

Inject `CanvasNotificationHandler` and call `create()`:

```php
use Drupal\canvas\CanvasNotificationHandler;

final class MyService {

  public function __construct(
    private readonly CanvasNotificationHandler $notificationHandler,
  ) {}

  public function doSomething(): void {
    $this->notificationHandler->create([
      'type' => 'info',
      'title' => 'Update available',
      'message' => 'A new version is available for your site.',
    ]);
  }

}
```

### Notification fields

| Field       | Type              | Required | Description                   |
|-------------|-------------------|----------|-------------------------------|
| `type`      | `string`          | Yes      | One of the five types above.  |
| `title`     | `string`          | Yes      | Short summary shown in bold.  |
| `message`   | `string`          | Yes      | Body text.                    |
| `key`       | `string` or null  | No       | Groups related notifications. |
| `actions`   | `array` or null   | No       | Action links (see below).     |
| `timestamp` | `int` (ms)        | No       | Defaults to current time.     |

The `id` field is always auto-generated (UUID). Callers must not
provide it.

An `\InvalidArgumentException` is thrown if required fields are missing
or if `id` is provided.

### Action links

Actions render as clickable links in both toasts and the Notification
Center. Each action is an associative array with `label` and `href`:

```php
$handler->create([
  'type' => 'error',
  'title' => 'Publish failed',
  'message' => 'Unable to connect to server.',
  'actions' => [
    ['label' => 'View logs', 'href' => '/admin/reports/dblog'],
    ['label' => 'Retry', 'href' => '/canvas/my-module/publish'],
  ],
]);
```

### Tracking operations with keys

The `key` field connects related notifications across an operation's
lifecycle. When a new `processing`, `error`, or `warning` notification
is created with a `key`, all existing `processing`/`error`/`warning`
notifications sharing that key are automatically deleted.

Use a namespaced key format like `{module}_{operation}` (e.g.
`my_module_publish`, `my_module_import`) to avoid collisions between
modules.

This means you can model a complete operation lifecycle without manual
cleanup:

```php
// Start the operation - shows a spinner in the UI.
$handler->create([
  'type' => 'processing',
  'key' => 'my_module_publish',
  'title' => 'Publishing page',
  'message' => 'Publishing page to production...',
]);

// On success - the processing notification is deleted,
// and the success notification takes its place.
$handler->create([
  'type' => 'success',
  'key' => 'my_module_publish',
  'title' => 'Page published',
  'message' => 'Page successfully published to production.',
]);

// Or, on failure - the processing notification is deleted,
// and the error notification takes its place.
$handler->create([
  'type' => 'error',
  'key' => 'my_module_publish',
  'title' => 'Publish failed',
  'message' => 'Unable to connect to server.',
  'actions' => [
    ['label' => 'View logs', 'href' => '/admin/reports/dblog'],
  ],
]);

// On retry - the error notification is deleted,
// and a new processing notification takes its place.
$handler->create([
  'type' => 'processing',
  'key' => 'my_module_publish',
  'title' => 'Publishing page',
  'message' => 'Retrying publish to production...',
]);
```

`success` and `info` types do not trigger key-based deletion, so they
accumulate as a history of completed operations.

### Stale processing timeout

If a `processing` notification is not replaced within 30 minutes, cron
automatically deletes it and creates an `error` notification with the
same key and the title "Operation timed out". This prevents stuck
spinners when an operation fails silently.

## How notifications appear in the UI

### Activity Center

A bell icon in the Topbar opens a dropdown panel listing all recent
notifications. The bell displays a badge counting unread `info`,
`warning`, and `error` notifications (`success` and `processing` do
not count toward the badge).

Notifications are sorted by priority:

1. `processing` (always first)
2. Unread `error` (newest first)
3. Unread `warning` (newest first)
4. Everything else chronologically (newest first)

When a user opens the Activity Center, unread `success` and `info`
notifications are automatically marked as read. Users can also click
individual read/unread indicators on `info`, `warning`, and `error`
notifications, or use "Mark all as read".

### Toast popups

When new notifications arrive (after the page was loaded), a toast
appears in the top-right corner for each one. Toasts auto-dismiss
after 15 seconds.

- **Clicking the dismiss (X) button** marks the notification as read.
- **Clicking an action link** marks as read, dismisses the toast, and
  opens the action URL in a new tab.
- **Auto-dismiss (15s timeout)** removes the toast without marking it
  as read.

### Polling behavior

The frontend polls `GET /canvas/api/v0/notifications` at adaptive
intervals:

| Tab state  | Processing present | Interval          |
|------------|--------------------|--------------------|
| Focused    | Yes                | 2 seconds          |
| Focused    | No                 | 10 seconds         |
| Unfocused  | Either             | 5 minutes          |

When processing notifications are present, the UI polls more
frequently so the user sees state transitions promptly.

## Data retention

- **Processing timeout:** 30 minutes (then replaced with an error).
- **Notification retention:** 30 days, then purged by cron.
- **Read entries:** 30 days, purged alongside their notifications.

## REST API

Both endpoints require Canvas authentication and are documented in
`openapi.yml`.

### `GET /canvas/api/v0/notifications`

Returns the most recent 25 non-processing notifications plus all
active processing notifications, with per-user `hasRead` state.

```json
{
  "data": {
    "notifications": [
      {
        "id": "550e8400-e29b-41d4-a716-446655440000",
        "type": "processing",
        "key": "my_module_publish",
        "title": "Publishing page",
        "message": "Publishing page to production...",
        "timestamp": 1234567890000,
        "hasRead": false,
        "actions": null
      }
    ]
  }
}
```

### `POST /canvas/api/v0/notifications/read`

Marks notifications as read for the authenticated user. Returns
`204 No Content` on success.

```json
{
  "ids": ["550e8400-e29b-41d4-a716-446655440001"]
}
```

## Architecture overview

```
┌────────────────────────────────────┐
│  PHP: CanvasNotificationHandler    │
│  Tables: canvas_notification,      │
│          canvas_notification_read  │
└──────────────┬─────────────────────┘
               │ REST API
               │ GET  /canvas/api/v0/notifications
               │ POST /canvas/api/v0/notifications/read
               ▼
┌──────────────────────────────┐
│  React: RTK Query polling    │
│  (notificationsApi)          │
│  ┌──────────┐  ┌────────────┐│
│  │Activity  │  │ Toast      ││
│  │Center    │  │ Manager    ││
│  └──────────┘  └────────────┘│
└──────────────────────────────┘
```
