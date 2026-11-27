<?php

declare(strict_types=1);

namespace App\Foundation\Events;

use App\Foundation\Container;

class Dispatcher
{
    private Container $container;

    /**
     * @var array<string, array<int, string|callable>>
     */
    private array $listeners = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function listen(string $event, $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function dispatch(string $event, array $payload = []): Event
    {
        $eventObject = new Event($event, $payload);

        foreach ($this->listeners[$event] ?? [] as $listener) {
            $result = $this->resolve($listener, $eventObject);

            if ($result === false) {
                $eventObject->halt();
            }

            if ($eventObject->isHalted()) {
                break;
            }
        }

        $this->enqueueWebhooks($event, $payload);

        return $eventObject;
    }

    /**
     * For every registered webhook matching this event, enqueue an outbound
     * delivery job. Decoupled and failure-isolated: enqueue errors never break
     * the originating request.
     */
    private function enqueueWebhooks(string $event, array $payload): void
    {
        try {
            $db = $this->container->get('db');
        } catch (\Throwable $e) {
            return;
        }

        try {
            $webhooks = $db->select('SELECT * FROM `webhooks` WHERE `event` = ? AND `status` = ?', [$event, 'active']);
        } catch (\Throwable $e) {
            return;
        }

        foreach ($webhooks as $webhook) {
            try {
                $db->insert('jobs', [
                    'type' => 'webhook.deliver',
                    'status' => 'pending',
                    'payload' => json_encode([
                        'webhook_id' => (int) $webhook['id'],
                        'event' => $event,
                        'url' => $webhook['url'],
                        'payload' => $payload,
                    ], JSON_UNESCAPED_SLASHES),
                    'result' => null,
                    'attempts' => 0,
                    'max_attempts' => 5,
                    'available_at' => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $e) {
                // best-effort
            }
        }
    }

    private function resolve($listener, Event $event)
    {
        if (is_callable($listener)) {
            return $listener($event);
        }

        if (is_string($listener) && str_contains($listener, '@')) {
            return $this->container->call($listener, ['event' => $event]);
        }

        return null;
    }
}
