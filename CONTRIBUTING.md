# Contributing

Bug reports and focused pull requests are welcome.

Before submitting code:

```sh
composer install
composer check
cd matrix
npm ci
npm run check
```

Keep platform-specific event parsing and sending inside adapters. Shared command behaviour belongs in the PHP core, and persistence changes belong in `Store` with tests.

Do not commit credentials, production data, generated dependencies or Matrix crypto state.

The STCP website interfaces used by the client are not documented as stable APIs. When changing parsers, include anonymised fixtures or tests that demonstrate the expected response shape.
