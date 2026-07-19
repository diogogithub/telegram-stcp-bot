'use strict';

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const cryptoModulePath = process.env.MATRIX_CROSS_SIGNING_CRYPTO_MODULE;
if (typeof cryptoModulePath !== 'string' || cryptoModulePath === '') {
    throw new Error('MATRIX_CROSS_SIGNING_CRYPTO_MODULE is not configured.');
}

const {
    OlmMachine,
    UserId,
    DeviceId,
    StoreType,
    RequestType,
} = require(cryptoModulePath);
const cryptoPackage = require(path.join(cryptoModulePath, 'package.json'));

const appRoot = path.resolve(__dirname, '..');
const configScript = path.join(appRoot, 'bin', 'matrix-config-json.php');

function fail(message) {
    throw new Error(message);
}

function loadConfig() {
    const result = spawnSync(process.env.STCP_CHATBOT_PHP || '/usr/bin/php', [configScript], {
        cwd: appRoot,
        encoding: 'utf8',
        env: { ...process.env, LC_ALL: 'C.UTF-8' },
    });
    if (result.status !== 0) {
        fail(`Could not read Matrix configuration: ${(result.stderr || result.stdout || '').trim()}`);
    }
    const config = JSON.parse(result.stdout);
    for (const key of ['homeserver', 'access_token', 'user_id', 'device_id', 'storage_dir']) {
        if (typeof config[key] !== 'string' || config[key].trim() === '') {
            fail(`Missing Matrix configuration value: ${key}`);
        }
    }
    return config;
}

function readInput() {
    const raw = fs.readFileSync(0, 'utf8');
    const input = JSON.parse(raw || '{}');
    if (typeof input.password !== 'string' || input.password === '') {
        fail('A temporary Matrix password must be supplied on stdin.');
    }
    return input;
}

async function requestJson(config, method, endpoint, body = undefined, allow401 = false) {
    const response = await fetch(`${config.homeserver}${endpoint}`, {
        method,
        headers: {
            Authorization: `Bearer ${config.access_token}`,
            ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    const text = await response.text();
    let decoded = {};
    if (text !== '') {
        try {
            decoded = JSON.parse(text);
        } catch (_) {
            decoded = { raw: text };
        }
    }

    if (!response.ok && !(allow401 && response.status === 401)) {
        const detail = decoded.error || decoded.errcode || decoded.raw || `HTTP ${response.status}`;
        fail(`${method} ${endpoint} failed: ${detail}`);
    }

    return { response, decoded, text };
}

async function uploadSigningKeysWithUia(config, userId, password, body) {
    let attempt = await requestJson(
        config,
        'POST',
        '/_matrix/client/v3/keys/device_signing/upload',
        body,
        true,
    );

    if (attempt.response.ok) return attempt.decoded;

    const challenge = attempt.decoded;
    const flows = Array.isArray(challenge.flows) ? challenge.flows : [];
    const passwordSupported = flows.some((flow) =>
        Array.isArray(flow.stages) && flow.stages.includes('m.login.password')
    );
    if (!passwordSupported || typeof challenge.session !== 'string') {
        fail('Synapse requested UI authentication, but no m.login.password flow was offered.');
    }

    const authenticatedBody = {
        ...body,
        auth: {
            type: 'm.login.password',
            identifier: {
                type: 'm.id.user',
                user: userId,
            },
            password,
            session: challenge.session,
        },
    };

    attempt = await requestJson(
        config,
        'POST',
        '/_matrix/client/v3/keys/device_signing/upload',
        authenticatedBody,
    );
    return attempt.decoded;
}

function requestBody(request) {
    if (!request || typeof request.body !== 'string') fail('Crypto request has no JSON body.');
    return JSON.parse(request.body);
}

async function sendMachineRequest(config, machine, endpoint, request, transform = (body) => body) {
    const body = transform(requestBody(request));
    const result = await requestJson(config, 'POST', endpoint, body);
    await machine.markRequestAsSent(request.id, request.type, JSON.stringify(result.decoded));
}

async function queryOwnKeys(config) {
    const result = await requestJson(config, 'POST', '/_matrix/client/v3/keys/query', {
        device_keys: {
            [config.user_id]: [],
        },
    });
    return result.decoded;
}

function serverHasCrossSigning(keys, userId) {
    return Boolean(keys && keys.master_keys && keys.master_keys[userId]);
}

function deviceHasSelfSignature(keys, userId, deviceId) {
    const selfSigning = keys?.self_signing_keys?.[userId];
    const device = keys?.device_keys?.[userId]?.[deviceId];
    if (!selfSigning || !device) return false;
    const keyIds = Object.keys(selfSigning.keys || {});
    const signatures = device.signatures?.[userId] || {};
    return keyIds.some((keyId) => typeof signatures[keyId] === 'string' && signatures[keyId] !== '');
}

async function main() {
    const config = loadConfig();
    const input = readInput();
    const cryptoDir = path.join(path.resolve(config.storage_dir), 'crypto');
    const force = input.force === true;

    const machine = await OlmMachine.initialize(
        new UserId(config.user_id),
        new DeviceId(config.device_id),
        cryptoDir,
        '',
        StoreType.Sqlite,
    );

    try {
        const beforeStatus = await machine.crossSigningStatus();
        const beforeKeys = await queryOwnKeys(config);
        const serverHasKeys = serverHasCrossSigning(beforeKeys, config.user_id);
        const deviceAlreadySigned = deviceHasSelfSignature(
            beforeKeys,
            config.user_id,
            config.device_id,
        );

        if (beforeStatus.hasMaster && beforeStatus.hasSelfSigning && beforeStatus.hasUserSigning && deviceAlreadySigned) {
            process.stdout.write(JSON.stringify({
                ok: true,
                changed: false,
                crypto_helper_version: cryptoPackage.version,
                user_id: config.user_id,
                device_id: config.device_id,
                cross_signing: {
                    master: true,
                    self_signing: true,
                    user_signing: true,
                    device_signed: true,
                },
            }, null, 2) + '\n');
            return;
        }

        if (serverHasKeys && !(beforeStatus.hasMaster && beforeStatus.hasSelfSigning && beforeStatus.hasUserSigning) && !force) {
            fail('Cross-signing keys already exist on the server but are not available in this crypto store. Refusing to reset them without force=true.');
        }

        const bootstrap = await machine.bootstrapCrossSigning(!serverHasKeys || force);
        if (!bootstrap || typeof bootstrap.uploadSigningKeysReq !== 'string') {
            fail(`Crypto helper ${cryptoPackage.version} did not return cross-signing upload requests.`);
        }

        if (bootstrap.uploadKeysReq) {
            await sendMachineRequest(
                config,
                machine,
                '/_matrix/client/v3/keys/upload',
                bootstrap.uploadKeysReq,
            );
        }

        await uploadSigningKeysWithUia(
            config,
            config.user_id,
            input.password,
            JSON.parse(bootstrap.uploadSigningKeysReq),
        );

        if (bootstrap.uploadSignaturesReq) {
            await sendMachineRequest(
                config,
                machine,
                '/_matrix/client/v3/keys/signatures/upload',
                bootstrap.uploadSignaturesReq,
                (body) => body.signed_keys || body,
            );
        }

        const afterStatus = await machine.crossSigningStatus();
        const afterKeys = await queryOwnKeys(config);
        const deviceSigned = deviceHasSelfSignature(
            afterKeys,
            config.user_id,
            config.device_id,
        );

        if (!(afterStatus.hasMaster && afterStatus.hasSelfSigning && afterStatus.hasUserSigning && deviceSigned)) {
            fail('Cross-signing upload completed, but the current device is still not signed by the account self-signing key.');
        }

        process.stdout.write(JSON.stringify({
            ok: true,
            changed: true,
            crypto_helper_version: cryptoPackage.version,
            user_id: config.user_id,
            device_id: config.device_id,
            cross_signing: {
                master: afterStatus.hasMaster,
                self_signing: afterStatus.hasSelfSigning,
                user_signing: afterStatus.hasUserSigning,
                device_signed: deviceSigned,
            },
        }, null, 2) + '\n');
    } finally {
        machine.close();
    }
}

main().catch((error) => {
    console.error(error instanceof Error ? (error.stack || error.message) : String(error));
    process.exit(1);
});
