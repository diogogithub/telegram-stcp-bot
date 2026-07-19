<?php

declare(strict_types=1);

namespace Diogo\StcpChatbot\Channels;

final class TextChunker
{
    /** @return list<string> */
    public static function split(string $text, int $limit): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $chunks = [];
        while (mb_strlen($text) > $limit) {
            $candidate = mb_substr($text, 0, $limit);
            $break = max(
                (int) mb_strrpos($candidate, "\n\n"),
                (int) mb_strrpos($candidate, "\n"),
                (int) mb_strrpos($candidate, ' ')
            );
            if ($break < (int) floor($limit * 0.5)) {
                $break = $limit;
            }
            $chunks[] = trim(mb_substr($text, 0, $break));
            $text = trim(mb_substr($text, $break));
        }
        if ($text !== '') {
            $chunks[] = $text;
        }
        return $chunks;
    }
}
