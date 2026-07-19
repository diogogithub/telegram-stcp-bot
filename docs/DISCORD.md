# Discord

## Create the application

Create an application and bot in the Discord Developer Portal.

Enable the **Message Content Intent**, because the bot must read command text in server channels.

Configure either manually or with the interactive helper:

```sh
php bin/configure-discord.php
```

The helper validates the token with Discord, creates a timestamped backup of `config.php`, stores the application ID and prints an invite URL.

Manual configuration:

```php
'DISCORD_ENABLED' => '1',
'DISCORD_BOT_TOKEN' => 'replace-me',
'DISCORD_APPLICATION_ID' => 'replace-me',
'DISCORD_BOT_USERNAME' => 'STCP Bot',
```

## Verify and run

```sh
php bin/verify-discord.php
php bin/test-discord-core.php
php bin/discord-worker.php
```

For production, use `deploy/systemd/stcp-chatbot-discord.service`.

## Interaction policy

- Direct messages do not require a prefix.
- Server channels require a bot mention or `BOT_GROUP_PREFIX`.
- The default invite requests View Channels, Send Messages and Read Message History permissions.
