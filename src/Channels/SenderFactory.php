<?php

declare(strict_types=1);

namespace Diogo\StcpChatbot\Channels;

use Diogo\StcpChatbot\Channels\Discord\DiscordSender;
use Diogo\StcpChatbot\Channels\Telegram\TelegramSender;
use Diogo\StcpChatbot\Config\Config;
use RuntimeException;

final readonly class SenderFactory
{
    public function __construct(private Config $config)
    {
    }

    public function make(string $platform): SenderInterface
    {
        return match ($platform) {
            'telegram' => new TelegramSender(),
            'discord' => new DiscordSender($this->config->string('DISCORD_BOT_TOKEN')),
            'matrix' => throw new RuntimeException(
                'Matrix delivery is handled by the dedicated E2EE worker.'
            ),
            default => throw new RuntimeException('Unsupported platform: ' . $platform),
        };
    }
}
