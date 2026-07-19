# Migration from the Telegram-only bot

The multi-platform application can import identities, conversations, interaction history, processed-update identifiers and announcement preferences from the SQLite database used by the former Telegram-only deployment.

## Procedure

1. Back up both the old and new deployments.
2. Configure the new application and allow it to create an empty `storage/app.sqlite`.
3. Stop the old Telegram worker/webhook from writing to its database.
4. Run:

```sh
php bin/migrate-legacy-telegram.php /path/to/old/bot.sqlite
```

5. Inspect the JSON summary and run:

```sh
php bin/self-test.php
php bin/verify-deployment.php
```

6. Point the Telegram webhook at the new public endpoint.

The migration is guarded by the `legacy_telegram_migrated_at` metadata value and refuses to run twice against the same destination database.

The old Telegram Git history can be preserved when updating the repository: rename the repository and replace its working tree in a new commit rather than recreating it.
