<?php

declare(strict_types=1);

namespace Diogo\StcpChatbot\Channels;

interface SenderInterface
{
    public function platform(): string;

    public function send(string $chatId, string $text): void;
}
