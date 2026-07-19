# Admin dashboard and announcements

The dashboard is served by `public/index.php` and uses session authentication.

Configure:

```php
'ADMIN_USERNAME' => 'admin',
'ADMIN_PASSWORD_HASH' => '$2y$...',
'SESSION_COOKIE_NAME' => 'stcp_chatbot_admin',
'SESSION_COOKIE_SECURE' => '1',
'SESSION_COOKIE_PATH' => '/',
```

Generate the password hash with:

```sh
php bin/password.php
```

## Dashboard data

The dashboard shows:

- aggregate user and activity totals;
- per-platform reachability and recent activity;
- platform identities and saved favourites;
- command/action counts;
- announcement drafts and delivery status.

It does not display or intentionally retain user message contents.

## Announcements

Announcements are created as drafts, reviewed, and then expanded into immutable per-conversation delivery rows.

Users who send `avisos_off` are excluded. Conversations marked unreachable are also skipped.

Run the PHP delivery worker:

```sh
php bin/announcement-worker.php --loop
```

Telegram and Discord deliveries are handled by this process. Matrix deliveries are handled inside the E2EE Matrix worker.
