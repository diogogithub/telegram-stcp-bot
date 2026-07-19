<?php

declare(strict_types=1);

namespace Diogo\StcpChatbot\Tests\Unit;

use Diogo\StcpChatbot\Core\StcpClient;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StcpClientValidationTest extends TestCase
{
    #[DataProvider('lineProvider')]
    public function testRecognisesPublicLineCodes(string $line): void
    {
        self::assertTrue(StcpClient::looksLikeLine($line));
    }

    /** @return iterable<string, array{string}> */
    public static function lineProvider(): iterable
    {
        yield 'day line' => ['204'];
        yield 'night line' => ['1M'];
        yield 'zone circuit' => ['ZC'];
    }

    public function testNormalisesStopAndLine(): void
    {
        self::assertSame('FCUP1', StcpClient::normaliseStop(' fcup1 '));
        self::assertSame('204', StcpClient::normaliseLine(' 204 '));
    }

    public function testRejectsUnsafeStopCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StcpClient::normaliseStop('../secret');
    }
}
