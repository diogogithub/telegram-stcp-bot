# Security policy

Please report security-sensitive issues privately to `mail@diogo.site` rather than opening a public issue.

Do not include live bot tokens, access tokens, passwords, private keys, production databases or Matrix crypto stores in reports.

## Secrets

Never commit:

- `config.php`;
- Telegram or Discord bot tokens;
- Matrix access tokens;
- Synapse administrator tokens;
- the production SQLite database;
- `storage/matrix/`;
- logs or configuration backups.

If a platform token is exposed, revoke and replace it through that platform immediately. Removing it from a later Git commit is not sufficient.

## Supported version

Security fixes are applied to the latest revision of the default branch. This is a small independent project and does not currently maintain parallel release branches.
