# Architecture

## Components

The repository deliberately keeps transport code separate from STCP and persistence logic.

- `src/Core/StcpClient.php` retrieves and normalises STCP data.
- `src/Core/BotRouter.php` interprets commands and creates replies.
- `src/Core/BotService.php` handles event deduplication, identity observation and action recording.
- `src/Infrastructure/Store.php` owns the SQLite schema and persistence operations.
- `src/Channels/Telegram/` converts Telegram webhook updates and sends replies.
- `bin/discord-worker.php` converts Discord Gateway events and sends replies through Discord REST.
- `matrix/worker.js` handles Matrix sync, Olm/Megolm encryption and room policy.
- `bin/matrix-core.php` is the JSON bridge between the Node Matrix worker and the PHP core.
- `public/index.php` implements the administrator dashboard.
- `bin/announcement-worker.php` delivers queued Telegram and Discord announcements. Matrix announcements are delivered by the E2EE worker.

## Message lifecycle

1. A platform adapter creates an `IncomingMessage`.
2. `BotService` claims the platform event ID to prevent duplicate processing.
3. `Store::observe()` creates or updates the platform identity, conversation and membership.
4. `BotRouter` parses the request and calls `StcpClient` or the favourites/privacy operations.
5. The action category is recorded for aggregate analytics.
6. The platform adapter sends one or more `OutgoingMessage` values.
7. When processing fails, the event claim is released so the platform may retry.

## Identity isolation

The database uses `(platform, external_user_id)` and `(platform, external_chat_id)` as natural uniqueness boundaries. No automatic account linking is performed.

This prevents a Telegram numeric ID from colliding with the same number on Discord, and keeps favourites and deletion requests scoped to the identity that issued them.

## Matrix boundary

The Matrix worker is intentionally small. It owns:

- `/sync` state;
- E2EE keys and sessions;
- invite handling;
- the two-member direct-room policy;
- encrypted sends;
- Matrix announcement delivery.

It invokes `bin/matrix-core.php` over standard input/output for application decisions. The Node process does not implement STCP business logic.

## Announcement delivery

The dashboard creates immutable delivery rows when an announcement is queued. Delivery is retried and its final result is recorded per conversation.

- Telegram and Discord are processed by `bin/announcement-worker.php`.
- Matrix is excluded from that worker and polled by `matrix/worker.js`, because encrypted sends require the Matrix crypto device.

## Storage

The application uses one SQLite database with WAL mode and foreign keys. The schema is created automatically by `Store` when the application starts.

Matrix state is separate from SQLite:

```text
storage/matrix/state.json
storage/matrix/crypto/
```

That directory represents the Matrix device and must be backed up together with the access token and application database.
