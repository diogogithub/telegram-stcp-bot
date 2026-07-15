<?php

declare(strict_types=1);

namespace Longman\TelegramBot\Commands\SystemCommands;

use Diogo\StcpTelegramBot\StcpClient;
use Diogo\StcpTelegramBot\TelegramReply;
use InvalidArgumentException;
use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use RuntimeException;

final class GenericmessageCommand extends SystemCommand
{
    protected $name = 'genericmessage';
    protected $description = 'Interpreta códigos de linha e paragem';
    protected $version = '1.1.0';

    public function execute(): ServerResponse
    {
        $message = $this->getMessage();

        // Do not treat ordinary group messages or service messages as STCP
        // lookups, and never send a private error merely because the bot was
        // added to a group.
        if ($message === null || !$message->getChat()->isPrivateChat()) {
            return Request::emptyResponse();
        }

        $input = trim((string) $message->getText(true));

        if ($input === '') {
            return TelegramReply::send($message, 'Envie um código de paragem ou de linha.');
        }

        try {
            $client = new StcpClient();
            $reply = preg_match('/^(?:\\d{2,3}|(?:[1-9]|1[0-3])M|ZC)$/i', $input) === 1
                ? $client->lineInBothDirections($input)
                : $client->arrivalsAtStop($input);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $reply = $exception->getMessage();
        }

        return TelegramReply::send($message, $reply);
    }
}
