<?php

declare(strict_types=1);

namespace Diogo\StcpChatbot\Core;

use Diogo\StcpChatbot\Domain\IncomingMessage;
use Diogo\StcpChatbot\Domain\OutgoingMessage;
use Diogo\StcpChatbot\Domain\RouteResult;
use Diogo\StcpChatbot\Infrastructure\Store;
use Throwable;

final readonly class BotRouter
{
    public function __construct(
        private StcpClient $stcp,
        private Store $store,
        private string $commandPrefix = '!stcp',
    ) {
    }

    public function route(IncomingMessage $message, int $identityId): RouteResult
    {
        $text = trim($message->text);
        $text = preg_replace('/^\s*' . preg_quote($this->commandPrefix, '/') . '\s*/iu', '', $text) ?? $text;
        $text = preg_replace('/^\/([a-z_]+)(?:@[a-z0-9_]+)?/iu', '$1', $text) ?? $text;
        $text = trim($text);

        if ($text === '') {
            return $this->help('help');
        }

        [$command, $argument] = $this->split($text);
        $command = $this->normaliseCommand($command);

        try {
            return match ($command) {
                'start', 'help', 'ajuda', 'comandos' => $this->help('help'),
                'stop', 'paragem', 'parar' => $this->arrivals($argument),
                'line', 'linha' => $this->line($argument),
                'home', 'casa' => $this->favourite($identityId, 'home', $argument),
                'work', 'trabalho' => $this->favourite($identityId, 'work', $argument),
                'favourites', 'favorites', 'favoritos', 'favoritas' => $this->favourites($identityId),
                'notifications_on', 'avisos_on', 'anuncios_on' => $this->announcements($identityId, true),
                'notifications_off', 'avisos_off', 'anuncios_off' => $this->announcements($identityId, false),
                'privacy', 'privacidade' => $this->privacy(),
                'delete_my_data', 'apagar_dados' => $this->deleteData($argument),
                default => $this->guess($text),
            };
        } catch (Throwable $exception) {
            return new RouteResult(
                'error',
                [new OutgoingMessage('Não consegui concluir o pedido: ' . $exception->getMessage())]
            );
        }
    }

    private function help(string $action): RouteResult
    {
        return new RouteResult($action, [new OutgoingMessage(
            "Olá! Consulto passagens e linhas da STCP.\n\n"
            . "paragem FCUP1 — próximas passagens\n"
            . "linha 204 — paragens da linha\n"
            . "casa FCUP1 — guardar paragem de casa\n"
            . "trabalho TRND1 — guardar paragem de trabalho\n"
            . "casa / trabalho — consultar a favorita\n"
            . "favoritos — ver as duas favoritas\n"
            . "avisos_off — deixar de receber anúncios\n"
            . "privacidade — informação sobre dados\n\n"
            . "Também podes enviar diretamente um código de paragem ou uma linha."
        )]);
    }

    private function arrivals(string $stop): RouteResult
    {
        if (trim($stop) === '') {
            return new RouteResult('stop_missing', [new OutgoingMessage(
                'Indica o código da paragem, por exemplo: paragem FCUP1.'
            )]);
        }

        $stop = StcpClient::normaliseStop($stop);
        return new RouteResult('stop', [new OutgoingMessage(
            "Paragem {$stop}\n\n" . $this->stcp->arrivals($stop)
        )]);
    }

    private function line(string $line): RouteResult
    {
        if (trim($line) === '') {
            return new RouteResult('line_missing', [new OutgoingMessage(
                'Indica a linha, por exemplo: linha 204.'
            )]);
        }

        $line = StcpClient::normaliseLine($line);
        $messages = array_map(
            static fn (string $text): OutgoingMessage => new OutgoingMessage($text),
            $this->stcp->line($line)
        );
        return new RouteResult('line', $messages);
    }

    private function favourite(int $identityId, string $slot, string $argument): RouteResult
    {
        $label = $slot === 'home' ? 'casa' : 'trabalho';
        if (trim($argument) !== '') {
            $stop = StcpClient::normaliseStop($argument);
            $this->store->setFavourite($identityId, $slot, $stop);
            return new RouteResult('favourite_set_' . $slot, [new OutgoingMessage(
                "Guardei {$stop} como a tua paragem de {$label}."
            )]);
        }

        $stop = $this->store->favourites($identityId)[$slot];
        if ($stop === null) {
            return new RouteResult('favourite_missing_' . $slot, [new OutgoingMessage(
                "Ainda não tens uma paragem de {$label}. Usa: {$label} FCUP1."
            )]);
        }

        return new RouteResult('favourite_' . $slot, [new OutgoingMessage(
            "Paragem de {$label}: {$stop}\n\n" . $this->stcp->arrivals($stop)
        )]);
    }

    private function favourites(int $identityId): RouteResult
    {
        $favourites = $this->store->favourites($identityId);
        return new RouteResult('favourites', [new OutgoingMessage(sprintf(
            "Favoritos\nCasa: %s\nTrabalho: %s",
            $favourites['home'] ?? 'não definida',
            $favourites['work'] ?? 'não definida'
        ))]);
    }

    private function announcements(int $identityId, bool $enabled): RouteResult
    {
        $this->store->setAnnouncementsEnabled($identityId, $enabled);
        return new RouteResult(
            $enabled ? 'announcements_on' : 'announcements_off',
            [new OutgoingMessage($enabled
                ? 'Os anúncios ocasionais do serviço ficaram ativos.'
                : 'Deixei de te incluir nos anúncios. O bot continua a responder normalmente.')]
        );
    }

    private function privacy(): RouteResult
    {
        return new RouteResult('privacy', [new OutgoingMessage(
            "Guardo o identificador técnico da conta e conversa, nome público, idioma, datas de utilização, "
            . "contagens de comandos e as paragens favoritas que escolhas. Isto permite responder, medir o uso "
            . "e enviar avisos opcionais. Não guardo o conteúdo livre das mensagens.\n\n"
            . "Usa apagar_dados CONFIRMAR para eliminar os teus dados associados ao bot."
        )]);
    }

    private function deleteData(string $argument): RouteResult
    {
        if (mb_strtoupper(trim($argument)) !== 'CONFIRMAR') {
            return new RouteResult('delete_data_prompt', [new OutgoingMessage(
                'Para eliminar definitivamente os teus dados e favoritos, envia: apagar_dados CONFIRMAR'
            )]);
        }

        return new RouteResult('delete_data', [new OutgoingMessage(
            'Os teus dados associados ao bot foram eliminados.'
        )], true);
    }

    private function guess(string $text): RouteResult
    {
        $value = trim($text);
        if (StcpClient::looksLikeLine($value)) {
            return $this->line($value);
        }

        if (preg_match('/^[A-Z0-9.]{1,12}$/i', $value) === 1) {
            return $this->arrivals($value);
        }

        return new RouteResult('unknown', [new OutgoingMessage(
            'Não reconheci o pedido. Envia ajuda para veres os comandos disponíveis.'
        )]);
    }

    /** @return array{string,string} */
    private function split(string $text): array
    {
        $parts = preg_split('/\s+/u', trim($text), 2);
        return [(string) ($parts[0] ?? ''), (string) ($parts[1] ?? '')];
    }

    private function normaliseCommand(string $command): string
    {
        return mb_strtolower(strtr($command, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c',
        ]));
    }
}
