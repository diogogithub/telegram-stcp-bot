<?php

declare(strict_types=1);

namespace Diogo\StcpChatbot\Domain;

final readonly class OutgoingMessage
{
    public function __construct(public string $text)
    {
    }
}
