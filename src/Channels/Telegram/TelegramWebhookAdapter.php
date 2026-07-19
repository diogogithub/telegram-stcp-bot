<?php

declare(strict_types=1);

namespace Diogo\StcpChatbot\Channels\Telegram;

use Diogo\StcpChatbot\Domain\IncomingMessage;

final class TelegramWebhookAdapter
{
    /** @param array<string,mixed> $update */
    public static function incoming(array $update, string $groupPrefix): ?IncomingMessage
    {
        $message = $update['message'] ?? $update['edited_message'] ?? null;
        if (!is_array($message)) {
            return null;
        }

        $from = $message['from'] ?? null;
        $chat = $message['chat'] ?? null;
        $text = $message['text'] ?? null;
        if (!is_array($from) || !is_array($chat) || !is_string($text) || ($from['is_bot'] ?? false)) {
            return null;
        }

        $chatType = (string) ($chat['type'] ?? 'private');
        if ($chatType !== 'private') {
            $trimmed = trim($text);
            $isCommand = str_starts_with($trimmed, '/');
            $hasPrefix = preg_match('/^' . preg_quote($groupPrefix, '/') . '(?:\s|$)/iu', $trimmed) === 1;
            if (!$isCommand && !$hasPrefix) {
                return null;
            }
        }

        $displayName = trim(implode(' ', array_filter([
            is_string($from['first_name'] ?? null) ? $from['first_name'] : '',
            is_string($from['last_name'] ?? null) ? $from['last_name'] : '',
        ])));

        return new IncomingMessage(
            platform: 'telegram',
            eventId: (string) ($update['update_id'] ?? $message['message_id'] ?? ''),
            userId: (string) ($from['id'] ?? ''),
            chatId: (string) ($chat['id'] ?? ''),
            chatType: $chatType === 'private' ? 'private' : 'group',
            text: $text,
            username: is_string($from['username'] ?? null) ? $from['username'] : null,
            displayName: $displayName,
            languageCode: is_string($from['language_code'] ?? null) ? $from['language_code'] : null,
            chatTitle: is_string($chat['title'] ?? null) ? $chat['title'] : null,
            chatUsername: is_string($chat['username'] ?? null) ? $chat['username'] : null,
        );
    }
}
