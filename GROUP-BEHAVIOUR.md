# Group behaviour

STCP Telegram Bot is primarily designed for private conversations, but it may be added to Telegram groups.

This update makes group service events silent:

- adding or removing the bot does not trigger a private error message;
- group creation and membership service messages receive no reply;
- `/start` is ignored outside private conversations;
- ordinary group messages are not interpreted as STCP stop or line codes;
- explicit public commands that already support groups continue to work normally.

The handlers contain no analytics, user database, broadcast or deployment-specific code.
