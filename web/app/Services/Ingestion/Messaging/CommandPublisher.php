<?php

namespace App\Services\Ingestion\Messaging;

use App\Data\Ingestion\CommandMessage;

interface CommandPublisher
{
    /** @throws \Throwable если брокер недоступен или не подтвердил публикацию */
    public function publish(CommandMessage $command): void;
}
