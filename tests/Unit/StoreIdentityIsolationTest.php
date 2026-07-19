<?php

declare(strict_types=1);

namespace Diogo\StcpChatbot\Tests\Unit;

use Diogo\StcpChatbot\Domain\IncomingMessage;
use Diogo\StcpChatbot\Infrastructure\Store;
use PHPUnit\Framework\TestCase;

final class StoreIdentityIsolationTest extends TestCase
{
    private string $database;

    protected function setUp(): void
    {
        $this->database = sys_get_temp_dir() . '/stcp-chatbot-test-' . bin2hex(random_bytes(8)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->database);
        @unlink($this->database . '-shm');
        @unlink($this->database . '-wal');
    }

    public function testSameExternalIdRemainsIsolatedByPlatform(): void
    {
        $store = new Store($this->database);

        $telegram = $store->observe(new IncomingMessage(
            platform: 'telegram',
            eventId: 't-1',
            userId: '123',
            chatId: '123',
            chatType: 'private',
            text: 'favoritos',
            displayName: 'Telegram user'
        ));
        $discord = $store->observe(new IncomingMessage(
            platform: 'discord',
            eventId: 'd-1',
            userId: '123',
            chatId: '123',
            chatType: 'private',
            text: 'favoritos',
            displayName: 'Discord user'
        ));

        $store->setFavourite($telegram['identity_id'], 'home', 'FCUP1');
        $store->setFavourite($discord['identity_id'], 'home', 'TRND1');

        self::assertNotSame($telegram['identity_id'], $discord['identity_id']);
        self::assertSame('FCUP1', $store->favourites($telegram['identity_id'])['home']);
        self::assertSame('TRND1', $store->favourites($discord['identity_id'])['home']);
        self::assertCount(2, $store->listIdentities(null, '123', 10));
    }

    public function testEventClaimsAreScopedByPlatform(): void
    {
        $store = new Store($this->database);

        self::assertTrue($store->claimEvent('telegram', 'same-id'));
        self::assertFalse($store->claimEvent('telegram', 'same-id'));
        self::assertTrue($store->claimEvent('discord', 'same-id'));
    }
}
