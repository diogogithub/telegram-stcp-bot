# Matrix E2EE

The Matrix adapter supports encrypted direct rooms. It deliberately rejects rooms with more than two active identities: the bot and one user.

## Requirements

- Node.js 22 or newer;
- a dedicated Matrix account;
- an access token tied to a stable device ID;
- persistent writable storage for sync and crypto state.

Install dependencies:

```sh
cd matrix
npm ci --omit=dev
npm run check
cd ..
```

Configure:

```php
'MATRIX_ENABLED' => '1',
'MATRIX_HOMESERVER' => 'https://matrix.example.org',
'MATRIX_ACCESS_TOKEN' => 'replace-me',
'MATRIX_USER_ID' => '@stcp:example.org',
'MATRIX_DEVICE_ID' => 'STCPBOT',
'MATRIX_STORAGE_DIR' => __DIR__ . '/storage/matrix',
'MATRIX_NODE_BINARY' => '/usr/bin/node',
'MATRIX_PHP_BINARY' => '/usr/bin/php',
```

Create storage with restrictive permissions:

```sh
install -d -o stcp-chatbot -g stcp-chatbot -m 0700 storage/matrix storage/matrix/crypto
```

## Start and verify

```sh
php bin/verify-matrix.php
node matrix/worker.js
```

For production use `deploy/systemd/stcp-chatbot-matrix.service`.

## Synapse account provisioner

Operators of a Synapse homeserver may use `bin/provision-matrix-account.sh`. It requires a Synapse admin token and creates or resets a dedicated non-admin account, logs in with a fixed device ID and updates the private application configuration without printing the resulting access token.

Set, for example:

```sh
export SYNAPSE_API_BASE=http://127.0.0.1:8008
export SYNAPSE_SERVER_NAME=example.org
export SYNAPSE_ADMIN_TOKEN='syt_replace_me'
export MATRIX_PUBLIC_HOMESERVER=https://matrix.example.org
export MATRIX_LOCALPART=stcp
export MATRIX_SERVICE_USER=stcp-chatbot
```

Then run as root:

```sh
bin/provision-matrix-account.sh
```

`--reset-existing` logs out existing devices and archives the local crypto state. Use it only for the dedicated bot account.

## Cross-signing

A working E2EE device may still be reported as “not trusted by its owner” until it is signed by the account's cross-signing identity.

Synapse operators may run:

```sh
bin/bootstrap-matrix-cross-signing.sh
```

The helper temporarily changes the dedicated account password without logging out the bot device, uploads cross-signing material through the Rust crypto SDK, signs the existing device and discards the temporary password.

Back up the Matrix state before running it.

## Backups

Back up these items together:

```text
config.php
storage/app.sqlite
storage/matrix/state.json
storage/matrix/crypto/
```

The crypto directory is not a cache. Removing it creates a new Matrix device identity and can prevent decryption of earlier sessions.
