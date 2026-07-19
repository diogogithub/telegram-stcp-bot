<?php

declare(strict_types=1);

namespace Diogo\StcpChatbot;

use Diogo\StcpChatbot\Config\Config;
use Diogo\StcpChatbot\Core\BotRouter;
use Diogo\StcpChatbot\Core\BotService;
use Diogo\StcpChatbot\Core\StcpClient;
use Diogo\StcpChatbot\Infrastructure\Store;

final readonly class App
{
    public Store $store;
    public BotService $bot;

    public function __construct(public Config $config)
    {
        $this->store = new Store($config->databasePath());
        $stcp = new StcpClient(
            $config->string('STCP_BASE_URL', 'https://stcp.pt'),
            $config->string('APP_CACHE_DIR', dirname(__DIR__) . '/storage/cache'),
            $config->int('STCP_HTTP_TIMEOUT', 15),
            $config->int('STCP_ROUTE_CACHE_TTL', 21600),
        );
        $router = new BotRouter($stcp, $this->store, $config->string('BOT_GROUP_PREFIX', '!stcp'));
        $this->bot = new BotService($this->store, $router);
    }
}
