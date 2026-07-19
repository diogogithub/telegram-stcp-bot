<?php

declare(strict_types=1);

namespace Diogo\StcpChatbot\Domain;

final readonly class RouteResult
{
    /** @param list<OutgoingMessage> $messages */
    public function __construct(
        public string $action,
        public array $messages,
        public bool $deleteIdentity = false,
    ) {
    }
}
