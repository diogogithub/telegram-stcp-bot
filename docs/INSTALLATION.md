# Installation

These instructions describe a conventional Linux deployment using `/opt/stcp-chatbot` and a dedicated `stcp-chatbot` service account. Adapt paths and users to the host.

## 1. Install system dependencies

Example for Debian-family systems:

```sh
apt install php-cli php-fpm php-curl php-mbstring php-sqlite3 php-sodium composer nginx sqlite3
```

Node.js 22 or newer is additionally required for Matrix E2EE.

## 2. Create the service account and clone

```sh
useradd --system --home /opt/stcp-chatbot --shell /usr/sbin/nologin stcp-chatbot

git clone https://github.com/diogogithub/stcp-chatbot.git /opt/stcp-chatbot
cd /opt/stcp-chatbot
composer install --no-dev --classmap-authoritative
```

For Matrix:

```sh
cd /opt/stcp-chatbot/matrix
npm ci --omit=dev
cd ..
```

## 3. Create configuration

```sh
cp config.example.php config.php
php bin/password.php
```

`bin/password.php` prints a password hash. Put that hash in `ADMIN_PASSWORD_HASH`; do not place the plaintext password in the file.

At minimum configure:

```php
'APP_BASE_URL' => 'https://bot.example.org',
'ADMIN_USERNAME' => 'admin',
'ADMIN_PASSWORD_HASH' => '$2y$...',
```

Then configure one or more platform sections.

## 4. Create writable directories

```sh
install -d -o stcp-chatbot -g stcp-chatbot -m 0750 storage storage/cache storage/logs
install -d -o stcp-chatbot -g stcp-chatbot -m 0700 storage/matrix storage/matrix/crypto
chown root:stcp-chatbot config.php
chmod 0640 config.php
```

The PHP-FPM pool serving the dashboard/webhook must be able to read `config.php` and write `storage/`.

## 5. Initialise and verify

```sh
sudo -u stcp-chatbot php bin/self-test.php
sudo -u stcp-chatbot php public/health.php
```

The first application boot creates the SQLite schema automatically.

## 6. Configure the web server

Point the document root at `/opt/stcp-chatbot/public`. Never expose the repository root, `config.php`, `storage/`, `vendor/` or Matrix crypto data.

An Nginx example is provided at `deploy/nginx/stcp-chatbot.conf.example`.

## 7. Configure transports

Follow the relevant guides:

- [Telegram](TELEGRAM.md)
- [Discord](DISCORD.md)
- [Matrix E2EE](MATRIX.md)

## 8. Install background services

Example systemd units are in `deploy/systemd/`. They assume `/opt/stcp-chatbot` and the `stcp-chatbot` service user; edit them before installation if necessary.

```sh
cp deploy/systemd/stcp-chatbot-*.service /etc/systemd/system/
cp deploy/systemd/stcp-chatbot-cleanup.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now stcp-chatbot-announcements.service
systemctl enable --now stcp-chatbot-cleanup.timer
```

Enable Discord and Matrix services only after their credentials pass the respective verification scripts.
