<?php

declare(strict_types=1);

namespace Diogo\StcpChatbot\Channels\Telegram;

use Diogo\StcpChatbot\Channels\SenderInterface;
use Diogo\StcpChatbot\Channels\TextChunker;
use Longman\TelegramBot\Request;
use RuntimeException;

final class TelegramSender implements SenderInterface
{
    public function platform(): string
    {
        return 'telegram';
    }

    public function send(string $chatId, string $text): void
    {
        foreach (TextChunker::split($text, 3900) as $chunk) {
            $response = Request::sendMessage([
                'chat_id' => $chatId,
                'text' => $chunk,
                'disable_web_page_preview' => true,
            ]);
            if (!$response->isOk()) {
                throw new RuntimeException($response->getDescription() ?: 'Telegram send failed.');
            }
        }
    }
}
