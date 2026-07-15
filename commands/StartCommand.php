<?php

declare(strict_types=1);

namespace Longman\TelegramBot\Commands\SystemCommands;

use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

final class StartCommand extends SystemCommand
{
    protected $name = 'start';
    protected $description = 'Apresentação do bot';
    protected $usage = '/start';
    protected $version = '1.1.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();

        if ($message === null || !$message->getChat()->isPrivateChat()) {
            return Request::emptyResponse();
        }

        return $this->replyToChat(
            "Olá! Posso consultar informação da STCP.\n\n"
            . "Envie um código de paragem, como FCUP1, para ver as próximas passagens. "
            . "Envie uma linha, como 404, 1M ou ZC, para ver as paragens nos dois sentidos.\n\n"
            . 'Use /help para consultar os comandos disponíveis.'
        );
    }
}
