# Telegram

## Create the bot

Create a bot with BotFather and record its token and username.

Configure:

```php
'TELEGRAM_ENABLED' => '1',
'TELEGRAM_BOT_TOKEN' => '123456789:replace-me',
'TELEGRAM_BOT_USERNAME' => 'replace_me_bot',
'TELEGRAM_WEBHOOK_SECRET' => 'a-long-random-secret',
'TELEGRAM_WEBHOOK_URL' => 'https://bot.example.org/webhooks/telegram.php',
```

Generate a webhook secret, for example:

```sh
openssl rand -hex 32
```

## Register the webhook

```sh
php bin/set-telegram-webhook.php
php bin/verify-deployment.php
```

The webhook entry point verifies `X-Telegram-Bot-Api-Secret-Token` when `TELEGRAM_WEBHOOK_SECRET` is configured.

## Group use

In private chats, plain stop and line codes are accepted. In groups, messages are ignored unless they are slash commands or begin with `BOT_GROUP_PREFIX`, which defaults to `!stcp`.

## Migration from the former bot

See [Migration](MIGRATION.md) before replacing a Telegram-only deployment that already contains user preferences or interaction history.

## Remove the webhook

```sh
php bin/delete-telegram-webhook.php
```

Add `--drop-pending` only when queued Telegram updates should also be discarded.
