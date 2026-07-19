<?php

declare(strict_types=1);

namespace Diogo\StcpChatbot\Tests\Unit;

use Diogo\StcpChatbot\Channels\Telegram\TelegramWebhookAdapter;
use PHPUnit\Framework\TestCase;

final class TelegramWebhookAdapterTest extends TestCase
{
    public function testPrivateTextBecomesIncomingMessage(): void
    {
        $incoming = TelegramWebhookAdapter::incoming([
            'update_id' => 7,
            'message' => [
                'message_id' => 8,
                'text' => 'FCUP1',
                'from' => [
                    'id' => 10,
                    'is_bot' => false,
                    'first_name' => 'Diogo',
                    'username' => 'diogo',
                    'language_code' => 'pt',
                ],
                'chat' => ['id' => 10, 'type' => 'private'],
            ],
        ], '!stcp');

        self::assertNotNull($incoming);
        self::assertSame('telegram', $incoming->platform);
        self::assertSame('FCUP1', $incoming->text);
        self::assertSame('private', $incoming->chatType);
    }

    public function testUnaddressedGroupTextIsIgnored(): void
    {
        $incoming = TelegramWebhookAdapter::incoming([
            'update_id' => 9,
            'message' => [
                'message_id' => 10,
                'text' => 'FCUP1',
                'from' => ['id' => 11, 'is_bot' => false, 'first_name' => 'User'],
                'chat' => ['id' => -12, 'type' => 'group', 'title' => 'Test'],
            ],
        ], '!stcp');

        self::assertNull($incoming);
    }

    public function testPrefixedGroupTextIsAccepted(): void
    {
        $incoming = TelegramWebhookAdapter::incoming([
            'update_id' => 11,
            'message' => [
                'message_id' => 12,
                'text' => '!stcp FCUP1',
                'from' => ['id' => 13, 'is_bot' => false, 'first_name' => 'User'],
                'chat' => ['id' => -14, 'type' => 'supergroup', 'title' => 'Test'],
            ],
        ], '!stcp');

        self::assertNotNull($incoming);
        self::assertSame('group', $incoming->chatType);
    }
}
