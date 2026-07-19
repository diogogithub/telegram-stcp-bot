<?php

declare(strict_types=1);

use Diogo\StcpChatbot\Config\Config;

$vendor = __DIR__ . '/vendor/autoload.php';
if (is_file($vendor)) {
    require $vendor;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Diogo\\StcpChatbot\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$configurationFile = __DIR__ . '/config.php';
if (!is_file($configurationFile)) {
    throw new RuntimeException('Missing production configuration file: ' . $configurationFile);
}

$values = require $configurationFile;
if (!is_array($values)) {
    throw new RuntimeException('The production configuration file must return an array.');
}

foreach ($values as $key => $value) {
    if (!is_string($key) || !is_scalar($value)) {
        continue;
    }
    $_ENV[$key] = (string) $value;
    $_SERVER[$key] = (string) $value;
}

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Europe/Lisbon');

set_exception_handler(static function (Throwable $exception): void {
    $line = sprintf(
        "[%s] %s: %s in %s:%d\n%s\n",
        gmdate('c'),
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString()
    );

    $log = $_ENV['APP_LOG'] ?? null;
    if (is_string($log) && $log !== '') {
        $directory = dirname($log);
        if (is_dir($directory) || @mkdir($directory, 0770, true)) {
            @file_put_contents($log, $line, FILE_APPEND | LOCK_EX);
        }
    }

    error_log($line);

    if (PHP_SAPI === 'cli') {
        exit(1);
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Internal server error.';
});

return Config::fromEnvironment();
