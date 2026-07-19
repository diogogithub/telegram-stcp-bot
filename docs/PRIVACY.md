# Privacy and retention

## Stored data

Depending on the platform, the application may store:

- platform and external user identifiers;
- usernames, display names and language codes supplied by the platform;
- conversation/channel/room identifiers and titles;
- first and last seen timestamps;
- interaction counters and action categories;
- saved home and work stop codes;
- announcement preferences and delivery state;
- errors indicating that a conversation is no longer reachable.

Message text is processed to answer the request but is not intentionally stored in the application database.

## User controls

- `privacidade` displays the in-chat privacy explanation.
- `avisos_off` disables administrator announcements for that identity.
- `avisos_on` enables them again.
- `apagar_dados CONFIRMAR` deletes identity-scoped application data.

Deletion is scoped to the platform identity issuing the command. The application does not automatically infer that accounts on different platforms belong to the same person.

## Operational data

Web server, systemd, PHP and homeserver logs may retain additional metadata outside this application. Operators are responsible for configuring those systems consistently with their own privacy policy.
