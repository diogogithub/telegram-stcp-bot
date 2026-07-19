'use strict';

const path = require('path');
const fs = require('fs');

try {
    const sdk = require('matrix-bot-sdk');
    if (typeof sdk.MatrixClient !== 'function') throw new Error('MatrixClient export is missing.');
    if (typeof sdk.RustSdkCryptoStorageProvider !== 'function') {
        throw new Error('RustSdkCryptoStorageProvider export is missing.');
    }

    const cryptoDir = path.join(
        __dirname,
        'node_modules',
        '@matrix-org',
        'matrix-sdk-crypto-nodejs'
    );
    const candidates = fs.existsSync(cryptoDir)
        ? fs.readdirSync(cryptoDir).filter((name) => /^matrix-sdk-crypto\..+\.node$/.test(name))
        : [];

    // Requiring the native package proves the selected binding can actually be loaded.
    const native = require('@matrix-org/matrix-sdk-crypto-nodejs');
    if (native.StoreType === undefined) throw new Error('Matrix crypto StoreType export is missing.');

    console.log(JSON.stringify({
        ok: true,
        node: process.version,
        matrix_bot_sdk: require('matrix-bot-sdk/package.json').version,
        matrix_crypto_sdk: require('@matrix-org/matrix-sdk-crypto-nodejs/package.json').version,
        crypto_binding: candidates.length > 0,
        crypto_bindings: candidates,
    }));
} catch (error) {
    console.error(error && error.stack ? error.stack : String(error));
    process.exit(1);
}
