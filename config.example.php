<?php

declare(strict_types=1);

return [
    // Application and storage
    'APP_ENV' => 'production',
    'APP_BASE_URL' => 'https://bot.example.org',
    'APP_TIMEZONE' => 'Europe/Lisbon',
    'APP_DATABASE' => __DIR__ . '/storage/app.sqlite',
    'APP_CACHE_DIR' => __DIR__ . '/storage/cache',
    'APP_LOG' => __DIR__ . '/storage/logs/app.log',

    // Administrator dashboard
    'ADMIN_USERNAME' => 'admin',
    'ADMIN_PASSWORD_HASH' => '',
    'SESSION_COOKIE_NAME' => 'stcp_chatbot_admin',
    'SESSION_COOKIE_SECURE' => '1',
    'SESSION_COOKIE_PATH' => '/',

    // Shared bot behaviour
    'BOT_GROUP_PREFIX' => '!stcp',
    'STCP_BASE_URL' => 'https://stcp.pt',
    'STCP_HTTP_TIMEOUT' => '15',
    'STCP_ROUTE_CACHE_TTL' => '21600',

    // Telegram webhook transport
    'TELEGRAM_ENABLED' => '0',
    'TELEGRAM_BOT_TOKEN' => '',
    'TELEGRAM_BOT_USERNAME' => '',
    'TELEGRAM_WEBHOOK_SECRET' => '',
    'TELEGRAM_WEBHOOK_URL' => 'https://bot.example.org/webhooks/telegram.php',

    // Discord Gateway transport
    'DISCORD_ENABLED' => '0',
    'DISCORD_BOT_TOKEN' => '',
    'DISCORD_APPLICATION_ID' => '',
    'DISCORD_BOT_USERNAME' => '',

    // Matrix E2EE transport
    'MATRIX_ENABLED' => '0',
    'MATRIX_HOMESERVER' => 'https://matrix.example.org',
    'MATRIX_ACCESS_TOKEN' => '',
    'MATRIX_USER_ID' => '',
    'MATRIX_DEVICE_ID' => 'STCPBOT',
    'MATRIX_STORAGE_DIR' => __DIR__ . '/storage/matrix',
    'MATRIX_NODE_BINARY' => '/usr/bin/node',
    'MATRIX_PHP_BINARY' => '/usr/bin/php',
    'MATRIX_SYNC_TIMEOUT_MS' => '30000',
    'MATRIX_INITIAL_HISTORY_GRACE_MS' => '5000',
    'MATRIX_ANNOUNCEMENT_POLL_MS' => '5000',

    // Maintenance
    'ANNOUNCEMENT_BATCH_SIZE' => '100',
    'EVENT_RETENTION_DAYS' => '365',
];
