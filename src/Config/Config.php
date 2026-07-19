<?php

declare(strict_types=1);

namespace Diogo\StcpChatbot\Config;

use RuntimeException;

final readonly class Config
{
    /** @param array<string, string> $values */
    private function __construct(private array $values)
    {
    }

    public static function fromEnvironment(): self
    {
        $values = [];
        foreach (array_merge($_SERVER, $_ENV) as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $values[$key] = (string) $value;
            }
        }

        return new self($values);
    }

    public function string(string $key, ?string $default = null): string
    {
        $value = trim($this->values[$key] ?? '');
        if ($value !== '') {
            return $value;
        }

        if ($default !== null) {
            return $default;
        }

        throw new RuntimeException("Missing configuration value: {$key}");
    }

    public function optional(string $key): ?string
    {
        $value = trim($this->values[$key] ?? '');
        return $value === '' ? null : $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $raw = $this->values[$key] ?? null;
        if ($raw === null || trim($raw) === '') {
            return $default;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public function int(string $key, int $default): int
    {
        $raw = trim($this->values[$key] ?? '');
        if ($raw === '' || filter_var($raw, FILTER_VALIDATE_INT) === false) {
            return $default;
        }

        return (int) $raw;
    }

    public function baseUrl(): string
    {
        return rtrim($this->string('APP_BASE_URL', ''), '/');
    }

    public function databasePath(): string
    {
        return $this->string('APP_DATABASE', dirname(__DIR__, 2) . '/storage/app.sqlite');
    }

    public function enabled(string $platform): bool
    {
        return $this->bool(strtoupper($platform) . '_ENABLED', false);
    }
}
