<?php

declare(strict_types=1);

namespace Diogo\StcpChatbot\Infrastructure;

use Diogo\StcpChatbot\Domain\IncomingMessage;
use PDO;
use PDOException;
use RuntimeException;

final class Store
{
    private PDO $pdo;

    public function __construct(string $databasePath)
    {
        $directory = dirname($databasePath);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create SQLite directory: {$directory}");
        }
        if (!is_writable($directory)) {
            throw new RuntimeException("SQLite directory is not writable: {$directory}");
        }

        try {
            $this->pdo = new PDO('sqlite:' . $databasePath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->ensureSchema();
        } catch (PDOException $exception) {
            throw new RuntimeException('Could not open the application SQLite database.', 0, $exception);
        }
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS meta (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS identities (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    platform TEXT NOT NULL,
    external_user_id TEXT NOT NULL,
    username TEXT,
    display_name TEXT NOT NULL DEFAULT '',
    language_code TEXT,
    first_seen_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    interaction_count INTEGER NOT NULL DEFAULT 0,
    announcements_enabled INTEGER NOT NULL DEFAULT 1 CHECK (announcements_enabled IN (0, 1)),
    reachable INTEGER NOT NULL DEFAULT 1 CHECK (reachable IN (0, 1)),
    service_window_expires_at TEXT,
    last_error TEXT,
    UNIQUE (platform, external_user_id)
);

CREATE TABLE IF NOT EXISTS conversations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    platform TEXT NOT NULL,
    external_chat_id TEXT NOT NULL,
    chat_type TEXT NOT NULL,
    title TEXT,
    username TEXT,
    first_seen_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    can_message INTEGER NOT NULL DEFAULT 1 CHECK (can_message IN (0, 1)),
    last_error TEXT,
    UNIQUE (platform, external_chat_id)
);

CREATE TABLE IF NOT EXISTS identity_conversations (
    identity_id INTEGER NOT NULL,
    conversation_id INTEGER NOT NULL,
    first_seen_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    PRIMARY KEY (identity_id, conversation_id),
    FOREIGN KEY (identity_id) REFERENCES identities(id) ON DELETE CASCADE,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS interaction_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    identity_id INTEGER NOT NULL,
    conversation_id INTEGER NOT NULL,
    action TEXT NOT NULL,
    occurred_at TEXT NOT NULL,
    FOREIGN KEY (identity_id) REFERENCES identities(id) ON DELETE CASCADE,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS processed_events (
    platform TEXT NOT NULL,
    external_event_id TEXT NOT NULL,
    processed_at TEXT NOT NULL,
    PRIMARY KEY (platform, external_event_id)
);

CREATE TABLE IF NOT EXISTS favourites (
    identity_id INTEGER NOT NULL,
    slot TEXT NOT NULL CHECK (slot IN ('home', 'work')),
    stop_code TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (identity_id, slot),
    FOREIGN KEY (identity_id) REFERENCES identities(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS announcements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    message_text TEXT NOT NULL,
    audience TEXT NOT NULL DEFAULT 'private' CHECK (audience IN ('private', 'all_chats')),
    status TEXT NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft', 'queued', 'processing', 'completed', 'cancelled')),
    created_at TEXT NOT NULL,
    queued_at TEXT,
    started_at TEXT,
    finished_at TEXT,
    created_by TEXT NOT NULL,
    recipient_count INTEGER NOT NULL DEFAULT 0,
    delivered_count INTEGER NOT NULL DEFAULT 0,
    failed_count INTEGER NOT NULL DEFAULT 0,
    skipped_count INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS announcement_platforms (
    announcement_id INTEGER NOT NULL,
    platform TEXT NOT NULL,
    PRIMARY KEY (announcement_id, platform),
    FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS announcement_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    announcement_id INTEGER NOT NULL,
    platform TEXT NOT NULL,
    conversation_id INTEGER,
    external_chat_id TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'sent', 'failed', 'skipped')),
    attempts INTEGER NOT NULL DEFAULT 0,
    sent_at TEXT,
    last_error TEXT,
    UNIQUE (announcement_id, platform, external_chat_id),
    FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS admin_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor TEXT NOT NULL,
    action TEXT NOT NULL,
    details TEXT,
    occurred_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS identities_platform_last_seen_idx
    ON identities(platform, last_seen_at);
CREATE INDEX IF NOT EXISTS conversations_platform_last_seen_idx
    ON conversations(platform, last_seen_at);
CREATE INDEX IF NOT EXISTS interactions_occurred_idx
    ON interaction_events(occurred_at);
CREATE INDEX IF NOT EXISTS interactions_action_idx
    ON interaction_events(action, occurred_at);
CREATE INDEX IF NOT EXISTS deliveries_pending_idx
    ON announcement_deliveries(status, platform, id);
SQL);

        $this->setMeta('schema_version', '1');
    }

    public function claimEvent(string $platform, string $eventId): bool
    {
        if ($eventId === '') {
            return true;
        }

        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO processed_events(platform, external_event_id, processed_at)
             VALUES(:platform, :event_id, :processed_at)'
        );
        $statement->execute([
            ':platform' => $platform,
            ':event_id' => $eventId,
            ':processed_at' => self::now(),
        ]);

        return $statement->rowCount() === 1;
    }


    public function releaseEvent(string $platform, string $eventId): void
    {
        if ($eventId === '') {
            return;
        }

        $statement = $this->pdo->prepare(
            'DELETE FROM processed_events WHERE platform = :platform AND external_event_id = :event_id'
        );
        $statement->execute([
            ':platform' => $platform,
            ':event_id' => $eventId,
        ]);
    }

    /** @return array{identity_id:int,conversation_id:int} */
    public function observe(IncomingMessage $message): array
    {
        $now = self::now();
        $this->pdo->beginTransaction();

        try {
            $identity = $this->pdo->prepare(
                'INSERT INTO identities(
                    platform, external_user_id, username, display_name, language_code,
                    first_seen_at, last_seen_at, interaction_count,
                    announcements_enabled, reachable, service_window_expires_at
                 ) VALUES(
                    :platform, :external_user_id, :username, :display_name, :language_code,
                    :now, :now, 1, 1, 1, :service_window
                 )
                 ON CONFLICT(platform, external_user_id) DO UPDATE SET
                    username = excluded.username,
                    display_name = excluded.display_name,
                    language_code = COALESCE(excluded.language_code, identities.language_code),
                    last_seen_at = excluded.last_seen_at,
                    interaction_count = identities.interaction_count + 1,
                    reachable = 1,
                    service_window_expires_at = COALESCE(
                        excluded.service_window_expires_at,
                        identities.service_window_expires_at
                    ),
                    last_error = NULL'
            );
            $identity->execute([
                ':platform' => $message->platform,
                ':external_user_id' => $message->userId,
                ':username' => self::nullable($message->username),
                ':display_name' => $message->displayName,
                ':language_code' => self::nullable($message->languageCode),
                ':now' => $now,
                ':service_window' => self::nullable($message->serviceWindowExpiresAt),
            ]);

            $identityId = $this->identityId($message->platform, $message->userId);

            $conversation = $this->pdo->prepare(
                'INSERT INTO conversations(
                    platform, external_chat_id, chat_type, title, username,
                    first_seen_at, last_seen_at, can_message
                 ) VALUES(
                    :platform, :external_chat_id, :chat_type, :title, :username,
                    :now, :now, :can_message
                 )
                 ON CONFLICT(platform, external_chat_id) DO UPDATE SET
                    chat_type = excluded.chat_type,
                    title = excluded.title,
                    username = excluded.username,
                    last_seen_at = excluded.last_seen_at,
                    can_message = excluded.can_message,
                    last_error = NULL'
            );
            $conversation->execute([
                ':platform' => $message->platform,
                ':external_chat_id' => $message->chatId,
                ':chat_type' => $message->chatType,
                ':title' => self::nullable($message->chatTitle),
                ':username' => self::nullable($message->chatUsername),
                ':now' => $now,
                ':can_message' => $message->canMessage ? 1 : 0,
            ]);

            $conversationId = $this->conversationId($message->platform, $message->chatId);

            $membership = $this->pdo->prepare(
                'INSERT INTO identity_conversations(identity_id, conversation_id, first_seen_at, last_seen_at)
                 VALUES(:identity_id, :conversation_id, :now, :now)
                 ON CONFLICT(identity_id, conversation_id) DO UPDATE SET
                    last_seen_at = excluded.last_seen_at'
            );
            $membership->execute([
                ':identity_id' => $identityId,
                ':conversation_id' => $conversationId,
                ':now' => $now,
            ]);

            $this->pdo->commit();

            return ['identity_id' => $identityId, 'conversation_id' => $conversationId];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function recordAction(int $identityId, int $conversationId, string $action): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO interaction_events(identity_id, conversation_id, action, occurred_at)
             VALUES(:identity_id, :conversation_id, :action, :occurred_at)'
        );
        $statement->execute([
            ':identity_id' => $identityId,
            ':conversation_id' => $conversationId,
            ':action' => $action,
            ':occurred_at' => self::now(),
        ]);
    }

    public function setFavourite(int $identityId, string $slot, string $stopCode): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO favourites(identity_id, slot, stop_code, updated_at)
             VALUES(:identity_id, :slot, :stop_code, :updated_at)
             ON CONFLICT(identity_id, slot) DO UPDATE SET
                stop_code = excluded.stop_code,
                updated_at = excluded.updated_at'
        );
        $statement->execute([
            ':identity_id' => $identityId,
            ':slot' => $slot,
            ':stop_code' => $stopCode,
            ':updated_at' => self::now(),
        ]);
    }

    /** @return array{home:?string,work:?string} */
    public function favourites(int $identityId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT slot, stop_code FROM favourites WHERE identity_id = :identity_id'
        );
        $statement->execute([':identity_id' => $identityId]);

        $result = ['home' => null, 'work' => null];
        foreach ($statement->fetchAll() as $row) {
            $slot = (string) $row['slot'];
            if (array_key_exists($slot, $result)) {
                $result[$slot] = (string) $row['stop_code'];
            }
        }

        return $result;
    }

    public function setAnnouncementsEnabled(int $identityId, bool $enabled): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE identities SET announcements_enabled = :enabled WHERE id = :identity_id'
        );
        $statement->execute([
            ':enabled' => $enabled ? 1 : 0,
            ':identity_id' => $identityId,
        ]);
    }

    public function deleteIdentityData(int $identityId): void
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('DELETE FROM identities WHERE id = :identity_id');
            $statement->execute([':identity_id' => $identityId]);
            $this->pdo->exec(
                "DELETE FROM conversations
                 WHERE chat_type = 'private'
                   AND NOT EXISTS (
                       SELECT 1 FROM identity_conversations ic
                       WHERE ic.conversation_id = conversations.id
                   )"
            );
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string, int|array<int,array<string,mixed>>> */
    public function dashboardStats(): array
    {
        $stats = [];
        $stats['known_users'] = (int) $this->pdo->query('SELECT COUNT(*) FROM identities')->fetchColumn();
        $stats['reachable_users'] = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM identities WHERE reachable = 1')
            ->fetchColumn();
        $stats['announcement_users'] = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM identities WHERE reachable = 1 AND announcements_enabled = 1')
            ->fetchColumn();
        $stats['known_chats'] = (int) $this->pdo->query('SELECT COUNT(*) FROM conversations')->fetchColumn();

        foreach ([1 => 'active_today', 7 => 'active_7_days', 30 => 'active_30_days'] as $days => $key) {
            $statement = $this->pdo->prepare(
                "SELECT COUNT(*) FROM identities WHERE last_seen_at >= datetime('now', :modifier)"
            );
            $statement->execute([':modifier' => "-{$days} days"]);
            $stats[$key] = (int) $statement->fetchColumn();
        }

        $statement = $this->pdo->query(
            "SELECT platform,
                    COUNT(*) AS users,
                    SUM(CASE WHEN reachable = 1 THEN 1 ELSE 0 END) AS reachable,
                    SUM(CASE WHEN last_seen_at >= datetime('now', '-30 days') THEN 1 ELSE 0 END) AS active_30
             FROM identities
             GROUP BY platform
             ORDER BY platform"
        );
        $stats['platforms'] = $statement->fetchAll();

        $statement = $this->pdo->query(
            "SELECT action, COUNT(*) AS uses, COUNT(DISTINCT identity_id) AS users
             FROM interaction_events
             WHERE occurred_at >= datetime('now', '-30 days')
             GROUP BY action
             ORDER BY uses DESC, action
             LIMIT 12"
        );
        $stats['top_actions'] = $statement->fetchAll();

        return $stats;
    }

    /** @return list<array<string,mixed>> */
    public function dailyActivity(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $statement = $this->pdo->prepare(
            "SELECT substr(occurred_at, 1, 10) AS day, platform, COUNT(*) AS interactions
             FROM interaction_events e
             JOIN identities i ON i.id = e.identity_id
             WHERE occurred_at >= datetime('now', :modifier)
             GROUP BY day, platform
             ORDER BY day ASC, platform ASC"
        );
        $statement->execute([':modifier' => "-{$days} days"]);
        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function listIdentities(
        ?string $platform = null,
        string $search = '',
        int $limit = 200
    ): array {
        $conditions = [];
        $parameters = [];

        if ($platform !== null && $platform !== '') {
            $conditions[] = 'i.platform = :platform';
            $parameters[':platform'] = $platform;
        }

        if (trim($search) !== '') {
            $conditions[] = '(i.external_user_id LIKE :search OR i.username LIKE :search OR i.display_name LIKE :search)';
            $parameters[':search'] = '%' . trim($search) . '%';
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        $limit = max(1, min(1000, $limit));

        $statement = $this->pdo->prepare(
            "SELECT i.*,
                    (SELECT stop_code FROM favourites f WHERE f.identity_id = i.id AND f.slot = 'home') AS home_stop,
                    (SELECT stop_code FROM favourites f WHERE f.identity_id = i.id AND f.slot = 'work') AS work_stop
             FROM identities i
             {$where}
             ORDER BY i.last_seen_at DESC
             LIMIT {$limit}"
        );
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    /** @param list<string> $platforms */
    public function createAnnouncement(
        string $title,
        string $message,
        string $audience,
        array $platforms,
        string $actor
    ): int {
        $title = trim($title);
        $message = trim($message);
        if ($title === '' || $message === '') {
            throw new RuntimeException('Title and message are required.');
        }
        if (!in_array($audience, ['private', 'all_chats'], true)) {
            throw new RuntimeException('Invalid announcement audience.');
        }

        $platforms = array_values(array_unique(array_intersect(
            ['telegram', 'discord', 'matrix'],
            $platforms
        )));
        if ($platforms === []) {
            throw new RuntimeException('Select at least one platform.');
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO announcements(title, message_text, audience, status, created_at, created_by)
                 VALUES(:title, :message, :audience, \'draft\', :created_at, :created_by)'
            );
            $statement->execute([
                ':title' => $title,
                ':message' => $message,
                ':audience' => $audience,
                ':created_at' => self::now(),
                ':created_by' => $actor,
            ]);

            $id = (int) $this->pdo->lastInsertId();
            $platformStatement = $this->pdo->prepare(
                'INSERT INTO announcement_platforms(announcement_id, platform)
                 VALUES(:announcement_id, :platform)'
            );
            foreach ($platforms as $platform) {
                $platformStatement->execute([
                    ':announcement_id' => $id,
                    ':platform' => $platform,
                ]);
            }

            $this->audit($actor, 'announcement.create', 'Announcement #' . $id);
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function queueAnnouncement(int $announcementId, string $actor): array
    {
        $announcement = $this->announcement($announcementId);
        if ($announcement === null) {
            throw new RuntimeException('Announcement not found.');
        }
        if ($announcement['status'] !== 'draft') {
            throw new RuntimeException('Only draft announcements can be queued.');
        }

        $platforms = $this->announcementPlatforms($announcementId);
        $audience = (string) $announcement['audience'];
        $privateOnly = $audience === 'private';
        $now = self::now();

        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT OR IGNORE INTO announcement_deliveries(
                    announcement_id, platform, conversation_id, external_chat_id, status, last_error
                 ) VALUES(
                    :announcement_id, :platform, :conversation_id, :external_chat_id, :status, :last_error
                 )'
            );

            $recipientCount = 0;
            $skippedCount = 0;

            foreach ($platforms as $platform) {
                $conditions = [
                    'c.platform = :platform',
                    'c.can_message = 1',
                ];

                if ($privateOnly) {
                    $conditions[] = "c.chat_type = 'private'";
                }

                $statement = $this->pdo->prepare(
                    'SELECT c.id AS conversation_id, c.external_chat_id,
                            COALESCE(MAX(i.announcements_enabled), 1) AS announcements_enabled,
                            COALESCE(MAX(i.reachable), 1) AS reachable,
                            MAX(i.service_window_expires_at) AS service_window_expires_at
                     FROM conversations c
                     LEFT JOIN identity_conversations ic ON ic.conversation_id = c.id
                     LEFT JOIN identities i ON i.id = ic.identity_id AND i.platform = c.platform
                     WHERE ' . implode(' AND ', $conditions) . '
                     GROUP BY c.id, c.external_chat_id
                     ORDER BY c.id'
                );
                $statement->execute([':platform' => $platform]);

                foreach ($statement->fetchAll() as $row) {
                    $eligible = (int) ($row['announcements_enabled'] ?? 1) === 1
                        && (int) ($row['reachable'] ?? 1) === 1;
                    $error = null;

                    if (!$eligible) {
                        $error = 'Recipient disabled announcements or is unreachable.';
                    }

                    $status = $eligible ? 'pending' : 'skipped';
                    $insert->execute([
                        ':announcement_id' => $announcementId,
                        ':platform' => $platform,
                        ':conversation_id' => (int) $row['conversation_id'],
                        ':external_chat_id' => (string) $row['external_chat_id'],
                        ':status' => $status,
                        ':last_error' => $error,
                    ]);

                    if ($insert->rowCount() === 1) {
                        if ($status === 'pending') {
                            $recipientCount++;
                        } else {
                            $skippedCount++;
                        }
                    }
                }
            }

            $update = $this->pdo->prepare(
                'UPDATE announcements
                 SET status = \'queued\', queued_at = :queued_at,
                     recipient_count = :recipient_count, skipped_count = :skipped_count
                 WHERE id = :id'
            );
            $update->execute([
                ':queued_at' => $now,
                ':recipient_count' => $recipientCount,
                ':skipped_count' => $skippedCount,
                ':id' => $announcementId,
            ]);

            $this->audit(
                $actor,
                'announcement.queue',
                "Announcement #{$announcementId}; recipients={$recipientCount}; skipped={$skippedCount}"
            );
            $this->pdo->commit();

            return ['recipients' => $recipientCount, 'skipped' => $skippedCount];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function cancelAnnouncement(int $announcementId, string $actor): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE announcements
             SET status = 'cancelled', finished_at = :now
             WHERE id = :id AND status IN ('draft', 'queued')"
        );
        $statement->execute([':now' => self::now(), ':id' => $announcementId]);

        $deliveries = $this->pdo->prepare(
            "UPDATE announcement_deliveries
             SET status = 'skipped', last_error = 'Cancelled by administrator.'
             WHERE announcement_id = :id AND status = 'pending'"
        );
        $deliveries->execute([':id' => $announcementId]);

        $this->audit($actor, 'announcement.cancel', 'Announcement #' . $announcementId);
    }

    /** @return list<array<string,mixed>> */
    public function listAnnouncements(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $statement = $this->pdo->query(
            "SELECT a.*,
                    GROUP_CONCAT(ap.platform, ', ') AS platforms
             FROM announcements a
             LEFT JOIN announcement_platforms ap ON ap.announcement_id = a.id
             GROUP BY a.id
             ORDER BY a.id DESC
             LIMIT {$limit}"
        );
        return $statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function announcement(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM announcements WHERE id = :id');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return list<string> */
    public function announcementPlatforms(int $id): array
    {
        $statement = $this->pdo->prepare(
            'SELECT platform FROM announcement_platforms WHERE announcement_id = :id ORDER BY platform'
        );
        $statement->execute([':id' => $id]);
        return array_map(
            static fn (array $row): string => (string) $row['platform'],
            $statement->fetchAll()
        );
    }

    /** @return list<array<string,mixed>> */
    public function announcementDeliveries(int $id, int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        $statement = $this->pdo->prepare(
            "SELECT * FROM announcement_deliveries
             WHERE announcement_id = :id
             ORDER BY id
             LIMIT {$limit}"
        );
        $statement->execute([':id' => $id]);
        return $statement->fetchAll();
    }

    /**
     * @param list<string> $excludedPlatforms
     * @return list<array<string,mixed>>
     */
    public function pendingDeliveries(int $limit, array $excludedPlatforms = []): array
    {
        $limit = max(1, min(1000, $limit));
        $conditions = [
            "d.status = 'pending'",
            "a.status IN ('queued', 'processing')",
        ];
        $parameters = [];

        $excludedPlatforms = array_values(array_unique(array_filter(
            $excludedPlatforms,
            static fn (mixed $platform): bool => is_string($platform) && trim($platform) !== ''
        )));
        if ($excludedPlatforms !== []) {
            $placeholders = [];
            foreach ($excludedPlatforms as $index => $platform) {
                $placeholder = ':excluded_platform_' . $index;
                $placeholders[] = $placeholder;
                $parameters[$placeholder] = $platform;
            }
            $conditions[] = 'd.platform NOT IN (' . implode(', ', $placeholders) . ')';
        }

        $statement = $this->pdo->prepare(
            "SELECT d.*, a.message_text, a.status AS announcement_status
             FROM announcement_deliveries d
             JOIN announcements a ON a.id = d.announcement_id
             WHERE " . implode(' AND ', $conditions) . "
             ORDER BY d.id
             LIMIT {$limit}"
        );
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function pendingDeliveriesForPlatform(string $platform, int $limit): array
    {
        $platform = trim($platform);
        if ($platform === '') {
            throw new RuntimeException('Platform is required.');
        }

        $limit = max(1, min(1000, $limit));
        $statement = $this->pdo->prepare(
            "SELECT d.*, a.message_text, a.status AS announcement_status
             FROM announcement_deliveries d
             JOIN announcements a ON a.id = d.announcement_id
             WHERE d.status = 'pending'
               AND d.platform = :platform
               AND a.status IN ('queued', 'processing')
             ORDER BY d.id
             LIMIT {$limit}"
        );
        $statement->execute([':platform' => $platform]);
        return $statement->fetchAll();
    }

    public function markAnnouncementProcessing(int $announcementId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE announcements
             SET status = 'processing', started_at = COALESCE(started_at, :now)
             WHERE id = :id AND status = 'queued'"
        );
        $statement->execute([':now' => self::now(), ':id' => $announcementId]);
    }

    public function markDeliverySent(int $deliveryId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE announcement_deliveries
             SET status = 'sent', attempts = attempts + 1, sent_at = :now, last_error = NULL
             WHERE id = :id"
        );
        $statement->execute([':now' => self::now(), ':id' => $deliveryId]);
    }

    public function markDeliveryFailed(int $deliveryId, string $error, bool $final = false): void
    {
        $status = $final ? 'failed' : 'pending';
        $statement = $this->pdo->prepare(
            'UPDATE announcement_deliveries
             SET status = :status, attempts = attempts + 1, last_error = :error
             WHERE id = :id'
        );
        $statement->execute([
            ':status' => $status,
            ':error' => mb_substr($error, 0, 1000),
            ':id' => $deliveryId,
        ]);
    }

    public function markConversationUnreachable(string $platform, string $externalChatId, string $error): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE conversations SET can_message = 0, last_error = :error
             WHERE platform = :platform AND external_chat_id = :chat_id'
        );
        $statement->execute([
            ':error' => mb_substr($error, 0, 1000),
            ':platform' => $platform,
            ':chat_id' => $externalChatId,
        ]);

        if ($platform === 'telegram') {
            $identity = $this->pdo->prepare(
                'UPDATE identities SET reachable = 0, last_error = :error
                 WHERE platform = :platform AND external_user_id = :user_id'
            );
            $identity->execute([
                ':error' => mb_substr($error, 0, 1000),
                ':platform' => $platform,
                ':user_id' => $externalChatId,
            ]);
        }
    }

    public function finishAnnouncements(): void
    {
        $ids = $this->pdo->query(
            "SELECT id FROM announcements WHERE status IN ('queued', 'processing')"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($ids as $idValue) {
            $id = (int) $idValue;
            $counts = $this->pdo->prepare(
                "SELECT
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                    SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) AS skipped
                 FROM announcement_deliveries
                 WHERE announcement_id = :id"
            );
            $counts->execute([':id' => $id]);
            $row = $counts->fetch() ?: [];

            $pending = (int) ($row['pending'] ?? 0);
            $update = $this->pdo->prepare(
                'UPDATE announcements
                 SET delivered_count = :sent, failed_count = :failed, skipped_count = :skipped,
                     status = :status,
                     finished_at = CASE WHEN :status = \'completed\' THEN :now ELSE finished_at END
                 WHERE id = :id'
            );
            $update->execute([
                ':sent' => (int) ($row['sent'] ?? 0),
                ':failed' => (int) ($row['failed'] ?? 0),
                ':skipped' => (int) ($row['skipped'] ?? 0),
                ':status' => $pending === 0 ? 'completed' : 'processing',
                ':now' => self::now(),
                ':id' => $id,
            ]);
        }
    }

    public function audit(string $actor, string $action, ?string $details = null): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO admin_audit(actor, action, details, occurred_at)
             VALUES(:actor, :action, :details, :occurred_at)'
        );
        $statement->execute([
            ':actor' => $actor,
            ':action' => $action,
            ':details' => self::nullable($details),
            ':occurred_at' => self::now(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function auditLog(int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));
        return $this->pdo->query(
            "SELECT * FROM admin_audit ORDER BY id DESC LIMIT {$limit}"
        )->fetchAll();
    }

    public function getMeta(string $key): ?string
    {
        $statement = $this->pdo->prepare('SELECT value FROM meta WHERE key = :key');
        $statement->execute([':key' => $key]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public function setMeta(string $key, string $value): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO meta(key, value) VALUES(:key, :value)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $statement->execute([':key' => $key, ':value' => $value]);
    }

    public function cleanup(int $eventRetentionDays): array
    {
        $eventRetentionDays = max(1, min(3650, $eventRetentionDays));
        $eventStatement = $this->pdo->prepare(
            "DELETE FROM interaction_events WHERE occurred_at < datetime('now', :modifier)"
        );
        $eventStatement->execute([':modifier' => "-{$eventRetentionDays} days"]);

        $processedStatement = $this->pdo->prepare(
            "DELETE FROM processed_events WHERE processed_at < datetime('now', '-30 days')"
        );
        $processedStatement->execute();

        return [
            'events' => $eventStatement->rowCount(),
            'processed' => $processedStatement->rowCount(),
        ];
    }

    private function identityId(string $platform, string $externalUserId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM identities WHERE platform = :platform AND external_user_id = :external_user_id'
        );
        $statement->execute([
            ':platform' => $platform,
            ':external_user_id' => $externalUserId,
        ]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new RuntimeException('Identity upsert failed.');
        }

        return (int) $id;
    }

    private function conversationId(string $platform, string $externalChatId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM conversations WHERE platform = :platform AND external_chat_id = :external_chat_id'
        );
        $statement->execute([
            ':platform' => $platform,
            ':external_chat_id' => $externalChatId,
        ]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new RuntimeException('Conversation upsert failed.');
        }

        return (int) $id;
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private static function nullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
