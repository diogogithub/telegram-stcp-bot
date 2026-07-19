<?php

declare(strict_types=1);

namespace Diogo\StcpChatbot\Core;

use Diogo\StcpChatbot\Domain\IncomingMessage;
use Diogo\StcpChatbot\Domain\OutgoingMessage;
use Diogo\StcpChatbot\Infrastructure\Store;

final readonly class BotService
{
    public function __construct(private Store $store, private BotRouter $router)
    {
    }

    /** @return list<OutgoingMessage> */
    public function handle(IncomingMessage $message): array
    {
        if (!$this->store->claimEvent($message->platform, $message->eventId)) {
            return [];
        }

        try {
            $ids = $this->store->observe($message);
            $result = $this->router->route($message, $ids['identity_id']);
            $this->store->recordAction($ids['identity_id'], $ids['conversation_id'], $result->action);

            if ($result->deleteIdentity) {
                $this->store->deleteIdentityData($ids['identity_id']);
            }

            return $result->messages;
        } catch (\Throwable $exception) {
            $this->store->releaseEvent($message->platform, $message->eventId);
            throw $exception;
        }
    }
}
