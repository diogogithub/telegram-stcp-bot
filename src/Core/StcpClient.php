<?php

declare(strict_types=1);

namespace Diogo\StcpChatbot\Core;

use GuzzleHttp\Client;
use InvalidArgumentException;
use RuntimeException;

final class StcpClient
{
    private Client $http;

    /** @var array<string, string>|null */
    private ?array $routeIds = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $cacheDirectory,
        int $timeout = 15,
        private readonly int $routeCacheTtl = 21600,
    ) {
        $this->http = new Client([
            'base_uri' => rtrim($baseUrl, '/') . '/',
            'timeout' => $timeout,
            'connect_timeout' => min(8, $timeout),
            'headers' => [
                'Accept-Language' => 'pt-PT,pt;q=0.9',
                'User-Agent' => 'stcp-chatbot/1.0 (+https://github.com/diogogithub/stcp-chatbot)',
            ],
        ]);
    }

    public function arrivals(string $stop): string
    {
        $stop = self::normaliseStop($stop);
        $data = $this->json('api/stops/' . rawurlencode($stop) . '/realtime');
        $items = $this->list($data, ['arrivals', 'realtime', 'data', 'results']);
        $output = [];

        foreach ($items as $arrival) {
            if (!is_array($arrival)) {
                continue;
            }

            $line = $this->stringValue(
                $arrival,
                ['route_short_name', 'route_long_name', 'route', 'line', 'line_code', 'linha']
            );
            $eta = $this->value(
                $arrival,
                ['arrival_minutes', 'minutes', 'eta', 'arrival_time', 'estimated_arrival', 'tempo']
            );

            if ($line === null || $eta === null) {
                continue;
            }

            $destination = $this->stringValue(
                $arrival,
                ['trip_headsign', 'headsign', 'destination', 'destination_name', 'destino']
            );

            if (is_numeric($eta)) {
                $minutes = (int) $eta;
                $when = match (true) {
                    $minutes <= 0 => 'a chegar',
                    $minutes === 1 => '1 minuto',
                    default => "{$minutes} minutos",
                };
            } else {
                $when = trim((string) $eta);
            }

            $title = 'Linha ' . $line;
            if ($destination !== null && strcasecmp($destination, $line) !== 0) {
                $title .= ' → ' . $destination;
            }

            $output[] = $title . "\nChegada: " . $when;
        }

        return $output !== []
            ? implode("\n\n", $output)
            : 'Sem passagens previstas em tempo real para esta paragem.';
    }

    /** @return list<string> */
    public function line(string $line): array
    {
        $line = self::normaliseLine($line);
        $routeId = $this->routeId($line);
        $output = [];

        foreach ([0, 1] as $direction) {
            $data = $this->json(
                'api/route/' . rawurlencode($routeId) . '/stops/direction?direction_id=' . $direction
            );
            $stops = $this->list($data, ['stops', 'data', 'results']);
            $names = [];
            $rows = [];

            foreach ($stops as $stop) {
                if (!is_array($stop)) {
                    continue;
                }

                $name = $this->stringValue($stop, ['stop_name', 'name', 'nome']);
                if ($name === null) {
                    continue;
                }

                $names[] = $name;
                $code = $this->stringValue($stop, ['stop_code', 'stop_id', 'code', 'codigo']);
                $rows[] = '• ' . $name . ($code !== null ? ' [' . $code . ']' : '');
            }

            if ($names !== []) {
                $output[] = sprintf(
                    'Linha %s: %s – %s',
                    $line,
                    $names[0],
                    $names[array_key_last($names)]
                ) . "\n" . implode("\n", $rows);
            }
        }

        if ($output === []) {
            throw new RuntimeException('A linha não foi encontrada ou não tem paragens disponíveis.');
        }

        return $output;
    }

    public static function looksLikeLine(string $value): bool
    {
        return preg_match('/^(?:\d{2,3}|(?:[1-9]|1[0-3])M|ZC)$/i', trim($value)) === 1;
    }

    public static function normaliseStop(string $stop): string
    {
        $stop = strtoupper(trim($stop));
        if (preg_match('/^[A-Z0-9.]{1,12}$/', $stop) !== 1) {
            throw new InvalidArgumentException(
                'Use um código de paragem válido, por exemplo FCUP1.'
            );
        }

        return $stop;
    }

    public static function normaliseLine(string $line): string
    {
        $line = strtoupper(trim($line));
        if (preg_match('/^[A-Z0-9]{1,4}$/', $line) !== 1) {
            throw new InvalidArgumentException(
                'Use uma linha válida, por exemplo 404, 1M ou ZC.'
            );
        }

        return $line;
    }

    private function routeId(string $line): string
    {
        if ($this->routeIds === null) {
            $this->routeIds = $this->loadRouteIds();
        }

        return $this->routeIds[$line] ?? ($line === 'ZC' ? '107' : $line);
    }

    /** @return array<string, string> */
    private function loadRouteIds(): array
    {
        $cacheFile = rtrim($this->cacheDirectory, '/') . '/stcp-route-ids.json';
        if (
            is_file($cacheFile)
            && (time() - (int) filemtime($cacheFile)) < $this->routeCacheTtl
        ) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return array_filter(
                    $cached,
                    static fn (mixed $value, mixed $key): bool =>
                        is_string($key) && is_string($value),
                    ARRAY_FILTER_USE_BOTH
                );
            }
        }

        $routeIds = [];
        try {
            $html = $this->request('pt/linhas', 'text/html');
            $pattern = '~<a\b[^>]*href\s*=\s*(["\'])([^"\']*?/linha\?[^"\']*\bline=[^"\']+)\1[^>]*>(.*?)</a>~is';

            if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $label = strtoupper(trim((string) preg_replace(
                        '/\s+/u',
                        ' ',
                        html_entity_decode(strip_tags($match[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    )));

                    if (preg_match('/^([0-9]{3}|(?:[1-9]|1[0-3])M|ZC)(?:\s|$)/u', $label, $lineMatch) !== 1) {
                        continue;
                    }

                    parse_str(
                        (string) parse_url(
                            html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                            PHP_URL_QUERY
                        ),
                        $query
                    );

                    $routeId = strtoupper(trim((string) ($query['line'] ?? '')));
                    if ($routeId !== '' && preg_match('/^[A-Z0-9]{1,10}$/', $routeId) === 1) {
                        $routeIds[$lineMatch[1]] = $routeId;
                    }
                }
            }

            if (!is_dir($this->cacheDirectory)) {
                @mkdir($this->cacheDirectory, 0770, true);
            }
            @file_put_contents(
                $cacheFile,
                json_encode($routeIds, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                LOCK_EX
            );
        } catch (\Throwable $exception) {
            error_log('[stcp] ' . $exception->getMessage());
        }

        return $routeIds;
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        $decoded = json_decode($this->request($path, 'application/json'), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('A resposta da STCP não pôde ser interpretada.');
        }

        return $decoded;
    }

    private function request(string $path, string $accept): string
    {
        try {
            $response = $this->http->get(ltrim($path, '/'), [
                'headers' => ['Accept' => $accept],
            ]);
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'Não foi possível obter informação atualizada da STCP.',
                0,
                $exception
            );
        }

        $body = (string) $response->getBody();
        if ($body === '') {
            throw new RuntimeException('A STCP devolveu uma resposta vazia.');
        }

        return $body;
    }

    /** @param array<string, mixed> $data
     *  @param list<string> $keys
     *  @return list<mixed>
     */
    private function list(array $data, array $keys): array
    {
        if (array_is_list($data)) {
            return $data;
        }

        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (!is_array($value)) {
                continue;
            }

            if (array_is_list($value)) {
                return $value;
            }

            foreach ($keys as $nestedKey) {
                if (
                    isset($value[$nestedKey])
                    && is_array($value[$nestedKey])
                    && array_is_list($value[$nestedKey])
                ) {
                    return $value[$nestedKey];
                }
            }
        }

        return [];
    }

    /** @param array<string, mixed> $data
     *  @param list<string> $keys
     */
    private function value(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                return $data[$key];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data
     *  @param list<string> $keys
     */
    private function stringValue(array $data, array $keys): ?string
    {
        $value = $this->value($data, $keys);
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
