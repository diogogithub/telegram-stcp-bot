# STCP Chatbot

A self-hosted, multi-platform chatbot for checking STCP stops and lines from Telegram, Discord and Matrix.

The same PHP domain layer powers all three transports. Telegram receives HTTPS webhooks, Discord runs a persistent Gateway worker, and Matrix uses a small Node.js adapter with end-to-end encryption for direct conversations.

## Live instance

- Telegram: [`@stcp_bot`](https://t.me/stcp_bot)
- Discord: [add STCP Bot to a server](https://discord.com/oauth2/authorize?client_id=1528488532232900618&scope=bot&permissions=68608)
- Matrix: [`@stcp:ndef.dev`](https://matrix.to/#/@stcp:ndef.dev)

The Matrix deployment accepts direct conversations only and encrypts them end to end.

## What it does

```text
paragem FCUP1        next expected passages at a stop
linha 204            stops served by a line, in both directions
casa FCUP1           save a home stop
trabalho TRND1       save a work stop
casa                 query the saved home stop
trabalho             query the saved work stop
favoritos            show both saved stops
avisos_off           opt out of administrator announcements
avisos_on            opt back in
privacidade          explain retained data
apagar_dados CONFIRMAR
ajuda
```

A stop code such as `FCUP1` or a line such as `204`, `1M` or `ZC` may also be sent directly.

Replies and command descriptions are currently written in European Portuguese.

## Platform behaviour

- **Telegram:** private chats work without a prefix. In groups, use a slash command or the configured prefix, which defaults to `!stcp`.
- **Discord:** direct messages work without a prefix. In server channels, mention the bot or start the message with `!stcp`.
- **Matrix:** encrypted direct rooms only. The worker rejects rooms containing more than the bot and one other Matrix user.

## Architecture

```text
Telegram webhook ─┐
Discord worker ────┼─> IncomingMessage -> BotService -> BotRouter -> STCP client
Matrix E2EE worker ┘                                  |
                                                       v
                                                SQLite store
                                                       |
                                                admin dashboard
```

Identities and conversations are isolated by platform:

```text
UNIQUE(platform, external_user_id)
UNIQUE(platform, external_chat_id)
```

A Telegram user, Discord user and Matrix user are separate identities even when their numeric or textual IDs happen to match.

See [Architecture](docs/ARCHITECTURE.md) for the complete data and message flow.

## Requirements

Core application:

- PHP 8.4.1 or newer;
- Composer;
- PHP cURL, JSON, Mbstring, PDO SQLite and Sodium extensions;
- a web server with HTTPS for Telegram and the dashboard.

Additional transports:

- Discord requires a long-running PHP CLI process;
- Matrix E2EE requires Node.js 22 or newer and a Matrix account/device access token.

## Installation

```sh
git clone https://github.com/diogogithub/stcp-chatbot.git
cd stcp-chatbot
composer install --no-dev --classmap-authoritative
cp config.example.php config.php
php bin/password.php
```

Edit `config.php`, create the writable storage directories, and point the web-server document root at `public/`.

The complete installation and production-hardening procedure is in [Installation](docs/INSTALLATION.md).

Platform guides:

- [Telegram](docs/TELEGRAM.md)
- [Discord](docs/DISCORD.md)
- [Matrix E2EE](docs/MATRIX.md)
- [Admin dashboard and announcements](docs/ADMIN-DASHBOARD.md)
- [Production deployment](docs/PRODUCTION.md)
- [Migration from the former Telegram-only bot](docs/MIGRATION.md)

## Development

```sh
composer install
composer check

cd matrix
npm ci
npm run check
```

The PHP checks validate Composer metadata, lint PHP files, enforce PSR-12 and run PHPUnit. Matrix checks validate the JavaScript entry points without connecting to a homeserver.

## Data and privacy

The SQLite database stores platform-scoped identifiers, chat identifiers, optional profile metadata, saved home/work stops, interaction counters and announcement-delivery state. It does not intentionally store message contents.

Users can inspect the privacy notice with `privacidade`, opt out of announcements with `avisos_off`, and delete their identity-scoped data with `apagar_dados CONFIRMAR`.

See [Security](SECURITY.md) and [Privacy and retention](docs/PRIVACY.md).

## STCP data source

The bot reads JSON services used by the public STCP website for real-time arrivals and route stops. Those interfaces are not documented here as a stable public API. An STCP website change may therefore require an update to the client.

## Disclaimer

This is an independent, unofficial project. It is not affiliated with, endorsed by, or operated by STCP.

## Licence

MIT. See [LICENSE](LICENSE).
