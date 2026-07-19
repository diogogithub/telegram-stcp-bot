<?php

declare(strict_types=1);

namespace Diogo\StcpChatbot\Domain;

final readonly class IncomingMessage
{
    public function __construct(
        public string $platform,
        public string $eventId,
        public string $userId,
        public string $chatId,
        public string $chatType,
        public string $text,
        public ?string $username = null,
        public string $displayName = '',
        public ?string $languageCode = null,
        public ?string $chatTitle = null,
        public ?string $chatUsername = null,
        public bool $canMessage = true,
        public ?string $serviceWindowExpiresAt = null,
    ) {
    }

    public function isPrivate(): bool
    {
        return $this->chatType === 'private';
    }
}
