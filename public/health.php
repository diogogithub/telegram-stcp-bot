<?php

declare(strict_types=1);

use Diogo\StcpChatbot\App;

$config = require dirname(__DIR__) . '/bootstrap.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    $app = new App($config);
    $app->store->getMeta('schema_version');
    echo '{"ok":true}';
} catch (Throwable) {
    http_response_code(503);
    echo '{"ok":false}';
}
