<?php

declare(strict_types=1);

namespace Diogo\StcpChatbot\Tests\Unit;

use Diogo\StcpChatbot\Channels\TextChunker;
use PHPUnit\Framework\TestCase;

final class TextChunkerTest extends TestCase
{
    public function testEmptyTextReturnsNoChunks(): void
    {
        self::assertSame([], TextChunker::split("  \n", 10));
    }

    public function testLongTextIsSplitWithinLimit(): void
    {
        $chunks = TextChunker::split('alpha beta gamma delta epsilon', 12);

        self::assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(12, mb_strlen($chunk));
        }
        self::assertSame('alpha beta gamma delta epsilon', implode(' ', $chunks));
    }
}
