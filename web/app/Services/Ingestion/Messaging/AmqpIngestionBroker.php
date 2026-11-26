<?php

namespace App\Services\Ingestion\Messaging;

use App\Data\Ingestion\CommandMessage;
use Closure;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * RabbitMQ-клиент ingest: публикация команд и чтение результатов.
 * Топология идентична collector/internal/amqp/topology.go.
 */
class AmqpIngestionBroker implements CommandPublisher
{
    private ?AMQPStreamConnection $connection = null;

    private ?AMQPChannel $channel = null;

    public function publish(CommandMessage $command): void
    {
        $channel = $this->channel();
        $channel->confirm_select();

        $channel->basic_publish(
            new AMQPMessage(
                json_encode($command->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ['content_type' => 'application/json', 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT],
            ),
            config('rabbitmq.ingest.exchange'),
            config('rabbitmq.ingest.commands.routing_key'),
        );
        $channel->wait_for_pending_acks(10);
    }

    /**
     * Читает ingest.results. `$handler(string $body, bool $redelivered)` возвращает без исключения — ack;
     * `InvalidResultMessage` — reject в DLQ; любое другое исключение — requeue, при повторной доставке в DLQ.
     *
     * @param  Closure(string, bool): void  $handler
     * @param  Closure(): bool  $shouldStop
     */
    public function consumeResults(Closure $handler, Closure $shouldStop, int $maxMessages = 0, int $idleTimeout = 5): int
    {
        $channel = $this->channel();
        $channel->basic_qos(0, (int) config('rabbitmq.ingest.prefetch'), false);

        $processed = 0;
        $channel->basic_consume(
            config('rabbitmq.ingest.results.queue'),
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $message) use ($handler, &$processed): void {
                try {
                    $handler($message->getBody(), (bool) $message->isRedelivered());
                    $message->ack();
                } catch (\App\Services\Ingestion\Exceptions\InvalidResultMessage $e) {
                    $message->reject(false);
                } catch (\Throwable $e) {
                    report($e);
                    $message->reject(! $message->isRedelivered());
                }
                $processed++;
            },
        );

        while ($channel->is_consuming() && ! $shouldStop()) {
            if ($maxMessages > 0 && $processed >= $maxMessages) {
                break;
            }

            try {
                $channel->wait(null, false, $idleTimeout);
            } catch (AMQPTimeoutException) {
                // Нет сообщений за $idleTimeout: даём шанс проверить shouldStop().
            }
        }

        return $processed;
    }

    public function declareTopology(): void
    {
        $channel = $this->channel();
        $config = config('rabbitmq.ingest');

        $channel->exchange_declare($config['exchange'], 'direct', false, true, false);

        foreach (['commands', 'results'] as $kind) {
            $queue = $config[$kind];

            $channel->queue_declare($queue['dlq'], false, true, false, false);
            $channel->queue_bind($queue['dlq'], $config['exchange'], $queue['dlq_routing_key']);

            $channel->queue_declare($queue['queue'], false, true, false, false, false, new AMQPTable([
                'x-dead-letter-exchange' => $config['exchange'],
                'x-dead-letter-routing-key' => $queue['dlq_routing_key'],
            ]));
            $channel->queue_bind($queue['queue'], $config['exchange'], $queue['routing_key']);
        }
    }

    public function close(): void
    {
        $this->channel?->close();
        $this->connection?->close();
        $this->channel = null;
        $this->connection = null;
    }

    private function channel(): AMQPChannel
    {
        if ($this->channel === null) {
            $this->connection = new AMQPStreamConnection(
                config('rabbitmq.host'),
                config('rabbitmq.port'),
                config('rabbitmq.user'),
                config('rabbitmq.password'),
                config('rabbitmq.vhost'),
            );
            $this->channel = $this->connection->channel();
            $this->declareTopology();
        }

        return $this->channel;
    }
}
