'use strict';

const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const {
    MatrixClient,
    SimpleFsStorageProvider,
    RustSdkCryptoStorageProvider,
} = require('matrix-bot-sdk');
const { StoreType } = require('@matrix-org/matrix-sdk-crypto-nodejs');

const appRoot = path.resolve(__dirname, '..');
const configScript = path.join(appRoot, 'bin', 'matrix-config-json.php');
const coreScript = path.join(appRoot, 'bin', 'matrix-core.php');

function log(message, extra = null) {
    const prefix = `[${new Date().toISOString()}]`;
    if (extra === null) {
        console.log(prefix, message);
    } else {
        console.log(prefix, message, extra);
    }
}

function logError(message, error) {
    const detail = error instanceof Error ? (error.stack || error.message) : String(error);
    console.error(`[${new Date().toISOString()}] ${message}: ${detail}`);
}

function runProcess(command, args, input, timeoutMs = 30000) {
    return new Promise((resolve, reject) => {
        const child = spawn(command, args, {
            cwd: appRoot,
            stdio: ['pipe', 'pipe', 'pipe'],
            env: { ...process.env, LC_ALL: 'C.UTF-8' },
        });
        let stdout = '';
        let stderr = '';
        let settled = false;
        const maxOutput = 2 * 1024 * 1024;

        const timer = setTimeout(() => {
            child.kill('SIGKILL');
            if (!settled) {
                settled = true;
                reject(new Error(`Process timed out: ${command} ${args.join(' ')}`));
            }
        }, timeoutMs);

        child.stdout.on('data', (chunk) => {
            stdout += chunk.toString('utf8');
            if (stdout.length > maxOutput) child.kill('SIGKILL');
        });
        child.stderr.on('data', (chunk) => {
            stderr += chunk.toString('utf8');
            if (stderr.length > maxOutput) child.kill('SIGKILL');
        });
        child.on('error', (error) => {
            clearTimeout(timer);
            if (!settled) {
                settled = true;
                reject(error);
            }
        });
        child.on('close', (code, signal) => {
            clearTimeout(timer);
            if (settled) return;
            settled = true;
            if (code !== 0) {
                reject(new Error(
                    `Process failed (${code ?? signal}): ${stderr.trim() || stdout.trim() || 'no output'}`
                ));
                return;
            }
            resolve(stdout);
        });

        child.stdin.end(input === undefined ? '' : JSON.stringify(input));
    });
}

async function runPhpJson(phpBinary, command, payload, timeoutMs = 30000) {
    const stdout = await runProcess(phpBinary, [coreScript, command], payload, timeoutMs);
    let decoded;
    try {
        decoded = JSON.parse(stdout);
    } catch (error) {
        throw new Error(`Invalid JSON from matrix-core.php: ${stdout.slice(0, 1000)}`);
    }
    if (!decoded || decoded.ok !== true) {
        throw new Error(`matrix-core.php returned a failure for ${command}`);
    }
    return decoded;
}

async function loadConfig() {
    const output = await runProcess(process.env.STCP_CHATBOT_PHP || '/usr/bin/php', [configScript], undefined, 15000);
    const config = JSON.parse(output);
    const required = ['homeserver', 'access_token', 'user_id', 'device_id', 'storage_dir', 'php_binary'];
    for (const key of required) {
        if (typeof config[key] !== 'string' || config[key].trim() === '') {
            throw new Error(`Missing Matrix configuration value: ${key}`);
        }
    }
    return config;
}

function splitText(text, limit = 12000) {
    const value = String(text).trim();
    if (value.length <= limit) return value === '' ? [] : [value];
    const chunks = [];
    let remaining = value;
    while (remaining.length > limit) {
        let cut = remaining.lastIndexOf('\n\n', limit);
        if (cut < Math.floor(limit * 0.5)) cut = remaining.lastIndexOf('\n', limit);
        if (cut < Math.floor(limit * 0.5)) cut = remaining.lastIndexOf(' ', limit);
        if (cut < 1) cut = limit;
        chunks.push(remaining.slice(0, cut).trimEnd());
        remaining = remaining.slice(cut).trimStart();
    }
    if (remaining !== '') chunks.push(remaining);
    return chunks;
}

class StcpMatrixClient extends MatrixClient {
    constructor(baseUrl, accessToken, storage, cryptoStore, handlers) {
        super(baseUrl, accessToken, storage, cryptoStore);
        this.handlers = handlers;
        this.persistTokenAfterSync = true;
    }

    startSyncInternal() {
        return this.startSync(this.handleSynchronousEvent.bind(this));
    }

    async handleSynchronousEvent(type, first, second) {
        if (type === 'room.join' && this.crypto) {
            await this.crypto.onRoomJoin(first);
        }
        if (type === 'room.event' && this.crypto) {
            await this.crypto.onRoomEvent(first, second);
        }

        if (type === 'room.invite') await this.handlers.onInvite(first, second);
        if (type === 'room.join') await this.handlers.onJoin(first, second);
        if (type === 'room.leave') await this.handlers.onLeave(first, second);
        if (type === 'room.message') await this.handlers.onMessage(first, second);
        if (type === 'room.event') await this.handlers.onRoomEvent(first, second);
    }
}

async function main() {
    const config = await loadConfig();
    const storageDir = path.resolve(config.storage_dir);
    const cryptoDir = path.join(storageDir, 'crypto');
    const stateFile = path.join(storageDir, 'state.json');
    fs.mkdirSync(cryptoDir, { recursive: true, mode: 0o700 });

    const storage = new SimpleFsStorageProvider(stateFile);
    const firstSync = !storage.getSyncToken();
    const startedAt = Date.now();
    const initialGrace = Math.max(0, Number(config.initial_history_grace_ms) || 5000);
    const roomValidity = new Map();
    const rejectedRooms = new Set();
    let client;
    let selfUserId;

    async function activeRoomMembers(roomId) {
        const state = await client.getRoomState(roomId);
        const active = new Set();
        for (const event of state) {
            if (!event || event.type !== 'm.room.member' || typeof event.state_key !== 'string') continue;
            const membership = event.content && event.content.membership;
            if (membership === 'join' || membership === 'invite') active.add(event.state_key);
        }
        return active;
    }

    async function isDirectRoom(roomId, refresh = false) {
        if (!refresh && roomValidity.has(roomId)) return roomValidity.get(roomId);
        const members = await activeRoomMembers(roomId);
        const valid = members.size === 2 && members.has(selfUserId);
        roomValidity.set(roomId, valid);
        return valid;
    }

    async function rejectGroupRoom(roomId) {
        if (rejectedRooms.has(roomId)) return;
        rejectedRooms.add(roomId);
        try {
            await client.sendNotice(
                roomId,
                `Este bot aceita apenas conversas diretas. Para consultar a STCP, inicia uma conversa privada com ${selfUserId}.`
            );
        } catch (error) {
            logError(`Could not send the group rejection message to ${roomId}`, error);
        }
        await client.leaveRoom(roomId, 'Direct conversations only');
        roomValidity.delete(roomId);
        log(`Left non-direct Matrix room ${roomId}`);
    }

    async function validateJoinedRoom(roomId) {
        if (!(await isDirectRoom(roomId, true))) {
            await rejectGroupRoom(roomId);
            return false;
        }
        return true;
    }

    async function getDisplayName(userId) {
        try {
            const profile = await client.getUserProfile(userId);
            if (profile && typeof profile.displayname === 'string' && profile.displayname.trim() !== '') {
                return profile.displayname.trim();
            }
        } catch (error) {
            logError(`Could not load Matrix profile for ${userId}`, error);
        }
        return userId;
    }

    async function releaseEvent(eventId) {
        try {
            await runPhpJson(config.php_binary, 'release-event', { event_id: eventId });
        } catch (error) {
            logError(`Could not release Matrix event ${eventId}`, error);
        }
    }

    const handlers = {
        async onInvite(roomId) {
            log(`Joining invited Matrix room ${roomId}`);
            await client.joinRoom(roomId);
        },

        async onJoin(roomId) {
            if (await validateJoinedRoom(roomId)) {
                await client.sendNotice(
                    roomId,
                    'Olá! Este é o bot independente e não oficial da STCP. Envia um código de paragem, por exemplo FCUP1, ou escreve ajuda.'
                );
            }
        },

        async onLeave(roomId) {
            roomValidity.delete(roomId);
            rejectedRooms.delete(roomId);
        },

        async onRoomEvent(roomId, event) {
            if (event && event.type === 'm.room.member') {
                roomValidity.delete(roomId);
                await validateJoinedRoom(roomId);
            }
        },

        async onMessage(roomId, event) {
            if (!event || event.sender === selfUserId) return;
            if (firstSync && Number(event.origin_server_ts || 0) < startedAt - initialGrace) return;
            const content = event.content;
            if (!content || content.msgtype !== 'm.text' || typeof content.body !== 'string') return;
            if (content['m.relates_to'] && content['m.relates_to'].rel_type === 'm.replace') return;
            if (!(await validateJoinedRoom(roomId))) return;

            const eventId = typeof event.event_id === 'string' ? event.event_id : '';
            const sender = typeof event.sender === 'string' ? event.sender : '';
            if (eventId === '' || sender === '') return;

            const payload = {
                event_id: eventId,
                room_id: roomId,
                user_id: sender,
                display_name: await getDisplayName(sender),
                text: content.body,
            };

            let handled;
            try {
                handled = await runPhpJson(config.php_binary, 'handle', payload, 45000);
                for (const outgoing of handled.messages || []) {
                    if (!outgoing || typeof outgoing.text !== 'string') continue;
                    for (const chunk of splitText(outgoing.text)) {
                        await client.sendText(roomId, chunk);
                    }
                }
            } catch (error) {
                await releaseEvent(eventId);
                throw error;
            }
        },
    };

    const cryptoStore = new RustSdkCryptoStorageProvider(
        cryptoDir,
        StoreType.Sqlite
    );
    client = new StcpMatrixClient(
        config.homeserver,
        config.access_token,
        storage,
        cryptoStore,
        handlers
    );
    client.syncingPresence = 'offline';
    client.syncingTimeout = Math.max(0, Number(config.sync_timeout_ms) || 30000);
    selfUserId = await client.getUserId();
    if (selfUserId !== config.user_id) {
        throw new Error(`Matrix token belongs to ${selfUserId}, expected ${config.user_id}`);
    }

    async function pollAnnouncements() {
        const batch = await runPhpJson(config.php_binary, 'pending-announcements', { limit: 50 });
        for (const delivery of batch.deliveries || []) {
            const deliveryId = Number(delivery.id);
            const roomId = String(delivery.external_chat_id || '');
            const attempts = Number(delivery.attempts || 0);
            try {
                if (!(await validateJoinedRoom(roomId))) {
                    throw new Error('Matrix conversation is no longer a direct room.');
                }
                for (const chunk of splitText(String(delivery.message_text || ''))) {
                    await client.sendNotice(roomId, chunk);
                }
                await runPhpJson(config.php_binary, 'delivery-sent', { delivery_id: deliveryId });
            } catch (error) {
                const final = attempts >= 2;
                await runPhpJson(config.php_binary, 'delivery-failed', {
                    delivery_id: deliveryId,
                    room_id: roomId,
                    error: error instanceof Error ? error.message : String(error),
                    final,
                });
                logError(`Matrix announcement delivery ${deliveryId} failed`, error);
            }
        }
    }

    let announcementPolling = false;
    const announcementInterval = Math.max(1000, Number(config.announcement_poll_ms) || 5000);
    const timer = setInterval(async () => {
        if (announcementPolling) return;
        announcementPolling = true;
        try {
            await pollAnnouncements();
        } catch (error) {
            logError('Matrix announcement poll failed', error);
        } finally {
            announcementPolling = false;
        }
    }, announcementInterval);
    timer.unref();

    const stop = () => {
        clearInterval(timer);
        try { client.stop(); } catch (_) {}
        setTimeout(() => process.exit(0), 250).unref();
    };
    process.on('SIGTERM', stop);
    process.on('SIGINT', stop);
    process.on('unhandledRejection', (error) => {
        logError('Unhandled Matrix worker rejection', error);
        process.exitCode = 1;
    });
    process.on('uncaughtException', (error) => {
        logError('Uncaught Matrix worker exception', error);
        process.exit(1);
    });

    log(`Starting Matrix E2EE worker as ${selfUserId}; device ${config.device_id}`);
    await client.start({
        room: {
            timeline: { limit: 20 },
            state: { lazy_load_members: false },
        },
        presence: { types: [] },
    });
}

main().catch((error) => {
    logError('Matrix worker failed to start', error);
    process.exit(1);
});
