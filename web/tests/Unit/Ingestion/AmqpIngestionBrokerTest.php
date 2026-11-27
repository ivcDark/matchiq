<?php

namespace Tests\Unit\Ingestion;

use App\Data\Ingestion\CommandMessage;
use App\Enums\IngestionKind;
use App\Services\Ingestion\Messaging\AmqpIngestionBroker;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use Tests\TestCase;

class AmqpIngestionBrokerTest extends TestCase
{
    public function test_confirm_mode_is_enabled_once_for_consecutive_publishes(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('set_nack_handler')->once();
        $channel->shouldReceive('confirm_select')->once();
        $channel->shouldReceive('basic_publish')->times(3);
        $channel->shouldReceive('wait_for_pending_acks')->times(3)->with(10);

        $broker = $this->brokerWith($channel);
        foreach ([1, 2, 3] as $runId) {
            $broker->publish($this->command($runId));
        }
    }

    public function test_failed_publish_drops_channel_and_rethrows(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('set_nack_handler', 'confirm_select', 'basic_publish');
        $channel->shouldReceive('wait_for_pending_acks')->andThrow(new AMQPRuntimeException('boom'));
        $channel->shouldReceive('close')->once();

        $broker = $this->brokerWith($channel);

        try {
            $broker->publish($this->command(1));
            $this->fail('Ожидалось исключение публикации.');
        } catch (AMQPRuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertNull((fn () => $this->channel)->call($broker));
        $this->assertFalse((fn () => $this->confirming)->call($broker));
    }

    private function brokerWith(AMQPChannel $channel): AmqpIngestionBroker
    {
        $broker = new AmqpIngestionBroker;
        (fn () => $this->channel = $channel)->call($broker);

        return $broker;
    }

    private function command(int $runId): CommandMessage
    {
        return new CommandMessage($runId, IngestionKind::SyncFixtures, 'sstats', ['competition' => ['id' => 1]]);
    }
}
