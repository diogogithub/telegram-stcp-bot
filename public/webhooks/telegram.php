<?php

declare(strict_types=1);

use Diogo\StcpChatbot\App;
use Diogo\StcpChatbot\Channels\Telegram\TelegramSender;
use Diogo\StcpChatbot\Channels\Telegram\TelegramWebhookAdapter;
use Longman\TelegramBot\Telegram;

$config = require dirname(__DIR__, 2) . '/bootstrap.php';

if (!$config->enabled('telegram')) {
    http_response_code(404);
    exit;
}

$expected = $config->string('TELEGRAM_WEBHOOK_SECRET');
$provided = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!is_string($provided) || !hash_equals($expected, $provided)) {
    http_response_code(403);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($payload)) {
    http_response_code(400);
    exit;
}

new Telegram($config->string('TELEGRAM_BOT_TOKEN'), $config->string('TELEGRAM_BOT_USERNAME'));
$app = new App($config);
$incoming = TelegramWebhookAdapter::incoming($payload, $config->string('BOT_GROUP_PREFIX', '!stcp'));
if ($incoming !== null) {
    try {
        $sender = new TelegramSender();
        foreach ($app->bot->handle($incoming) as $message) {
            $sender->send($incoming->chatId, $message->text);
        }
    } catch (Throwable $exception) {
        $app->store->releaseEvent($incoming->platform, $incoming->eventId);
        throw $exception;
    }
}

http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';
