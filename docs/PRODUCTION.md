# Production deployment

## Filesystem isolation

Only `public/` belongs under a web document root. Keep these locations inaccessible over HTTP:

```text
config.php
storage/
vendor/
matrix/
src/
bin/
```

Use a dedicated Unix account and grant it write access only to `storage/`.

## TLS

Telegram webhooks and the admin session should use HTTPS. Keep `SESSION_COOKIE_SECURE=1` in production.

## Services

The example systemd units apply:

- automatic restart;
- a private temporary directory;
- a protected home and system filesystem;
- a narrow writable path;
- `NoNewPrivileges`.

Review every unit after changing the installation directory or service account.

## Backups

Back up `config.php` and `storage/app.sqlite`. With Matrix enabled, also back up the complete `storage/matrix/` directory. Stop the Matrix worker or use a filesystem-consistent snapshot while backing up its crypto database.

## Retention

`EVENT_RETENTION_DAYS` controls cleanup of deduplication and interaction-event data. Enable `stcp-chatbot-cleanup.timer` or run:

```sh
php bin/cleanup.php
```

## Updates

```sh
git pull --ff-only
composer install --no-dev --classmap-authoritative
cd matrix && npm ci --omit=dev && cd ..
php bin/self-test.php
systemctl restart stcp-chatbot-announcements.service
systemctl try-restart stcp-chatbot-discord.service
systemctl try-restart stcp-chatbot-matrix.service
```

Never replace `config.php`, the production SQLite database or Matrix crypto state during an application update.
