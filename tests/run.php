#!/usr/bin/env php
<?php

declare(strict_types=1);

use Diogo\StcpTelegramBot\StcpClient;
use Diogo\StcpTelegramBot\TelegramReply;

require dirname(__DIR__) . '/src/StcpClient.php';
require dirname(__DIR__) . '/src/TelegramReply.php';

$client = new StcpClient();
$reflection = new ReflectionClass($client);
$replyReflection = new ReflectionClass(TelegramReply::class);

$invoke = static function (string $method, mixed ...$arguments) use ($client, $reflection): mixed {
    $reflectedMethod = $reflection->getMethod($method);

    return $reflectedMethod->invoke($client, ...$arguments);
};

$assertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            sprintf(
                "FAIL: %s\nExpected: %s\nActual:   %s\n",
                $message,
                var_export($expected, true),
                var_export($actual, true),
            )
        );
        exit(1);
    }
};

$assertContains = static function (string $needle, string $haystack, string $message): void {
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, sprintf("FAIL: %s\nMissing: %s\n", $message, $needle));
        exit(1);
    }
};

$assertNotContains = static function (string $needle, string $haystack, string $message): void {
    if (str_contains($haystack, $needle)) {
        fwrite(STDERR, sprintf("FAIL: %s\nUnexpected: %s\n", $message, $needle));
        exit(1);
    }
};

$html = <<<'HTML'
<a href="/pt/linha?line=205"><strong>205</strong> Campanhã – Castelo do Queijo</a>
<a href="/pt/linha?line=107">ZC Zona Centro</a>
<a href="/pt/linha?line=1M">1M Aliados – Matosinhos</a>
HTML;

$assertSame(
    ['205' => '205', 'ZC' => '107', '1M' => '1M'],
    $invoke('parseRouteIds', $html),
    'route IDs are extracted from the current line-list markup',
);

$assertSame(
    [['line' => '205']],
    $invoke('extractList', ['data' => ['results' => [['line' => '205']]]], ['arrivals', 'data', 'results']),
    'nested response lists are extracted',
);

$assertSame('a chegar', $invoke('formatArrival', 0), 'zero minutes is formatted');
$assertSame('1 minuto', $invoke('formatArrival', '1'), 'one minute is formatted');
$assertSame('12 minutos', $invoke('formatArrival', 12.2), 'multiple minutes are formatted');
$assertSame('FCUP1', $invoke('normaliseStopCode', ' fcup1 '), 'stop codes are normalised');
$assertSame('ZC', $invoke('normaliseLineCode', 'zc'), 'line codes are normalised');
$assertSame(
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36',
    $reflection->getConstant('USER_AGENT'),
    'the configured browser user agent is retained',
);

$splitText = $replyReflection->getMethod('splitText');
$longMessage = str_repeat('Paragem • linha 205' . PHP_EOL, 300);
$chunks = $splitText->invoke(null, $longMessage);
$assertSame(true, count($chunks) > 1, 'long Telegram messages are split');
foreach ($chunks as $chunk) {
    $assertSame(true, strlen($chunk) <= 3800, 'Telegram message chunks stay below the safe limit');
    $assertSame(1, preg_match('//u', $chunk), 'Telegram message chunks remain valid UTF-8');
}

$startSource = file_get_contents(dirname(__DIR__) . '/commands/StartCommand.php');
$genericSource = file_get_contents(dirname(__DIR__) . '/commands/GenericmessageCommand.php');
$assertNotContains('$private_only = true', $startSource, '/start does not trigger Longman private-chat DMs');
$assertNotContains('$private_only = true', $genericSource, 'generic messages do not trigger Longman private-chat DMs');
$assertContains('isPrivateChat()', $startSource, '/start explicitly ignores group calls');
$assertContains('isPrivateChat()', $genericSource, 'ordinary group messages are explicitly ignored');

foreach ([
    'MychatmemberCommand.php',
    'NewchatmembersCommand.php',
    'LeftchatmemberCommand.php',
    'GroupchatcreatedCommand.php',
    'SupergroupchatcreatedCommand.php',
] as $handler) {
    $source = file_get_contents(dirname(__DIR__) . '/commands/' . $handler);
    $assertContains('Request::emptyResponse()', $source, "{$handler} is silent");
    $assertContains('$show_in_help = false', $source, "{$handler} is hidden from help");
}

fwrite(STDOUT, "All STCP client and group-behaviour tests passed.\n");
