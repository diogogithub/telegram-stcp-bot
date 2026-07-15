<?php

declare(strict_types=1);

namespace Longman\TelegramBot\Commands\SystemCommands;

use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

final class LeftchatmemberCommand extends SystemCommand
{
    protected $name = 'leftchatmember';
    protected $description = 'Evento interno de grupo';
    protected $usage = '';
    protected $version = '1.0.0';
    protected $show_in_help = false;

    public function execute(): ServerResponse
    {
        return Request::emptyResponse();
    }
}
