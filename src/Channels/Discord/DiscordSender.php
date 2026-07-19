<?php

declare(strict_types=1);

namespace Diogo\StcpChatbot\Channels\Discord;

use Diogo\StcpChatbot\Channels\SenderInterface;
use Diogo\StcpChatbot\Channels\TextChunker;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

final readonly class DiscordSender implements SenderInterface
{
    private Client $http;

    public function __construct(string $botToken)
    {
        if (trim($botToken) === '') {
            throw new RuntimeException('Missing Discord bot token.');
        }

        $this->http = new Client([
            'base_uri' => 'https://discord.com/api/v10/',
            'timeout' => 20,
            'http_errors' => false,
            'headers' => [
                'Authorization' => 'Bot ' . $botToken,
                'Content-Type' => 'application/json',
                'User-Agent' => 'DiscordBot (https://github.com/diogogithub/stcp-chatbot, 1.0)',
            ],
        ]);
    }

    public function platform(): string
    {
        return 'discord';
    }

    public function send(string $chatId, string $text): void
    {
        foreach (TextChunker::split($text, 1900) as $chunk) {
            try {
                $response = $this->http->post(
                    'channels/' . rawurlencode($chatId) . '/messages',
                    ['json' => [
                        'content' => $chunk,
                        'allowed_mentions' => ['parse' => []],
                    ]]
                );
            } catch (GuzzleException $exception) {
                throw new RuntimeException('Discord API request failed: ' . $exception->getMessage(), 0, $exception);
            }

            $status = $response->getStatusCode();
            if ($status >= 300) {
                $body = trim((string) $response->getBody());
                throw new RuntimeException(sprintf(
                    'Discord send failed with HTTP %d%s',
                    $status,
                    $body === '' ? '' : ': ' . mb_substr($body, 0, 500)
                ));
            }
        }
    }
}
