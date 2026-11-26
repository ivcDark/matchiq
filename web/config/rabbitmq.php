<?php

/*
 * RabbitMQ для ingest (Laravel <-> Go collector). Обычные очереди Laravel остаются на redis.
 * Топология должна совпадать с collector/internal/amqp/topology.go.
 */
return [
    'host' => env('RABBITMQ_HOST', 'rabbitmq'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER', 'matchiq'),
    'password' => env('RABBITMQ_PASSWORD', 'matchiq'),
    'vhost' => env('RABBITMQ_VHOST', '/'),

    'ingest' => [
        'schema_version' => 1,
        'exchange' => 'matchiq.ingest',

        'commands' => [
            'queue' => 'ingest.commands',
            'routing_key' => 'command',
            'dlq' => 'ingest.commands.dlq',
            'dlq_routing_key' => 'command.dlq',
        ],
        'results' => [
            'queue' => 'ingest.results',
            'routing_key' => 'result',
            'dlq' => 'ingest.results.dlq',
            'dlq_routing_key' => 'result.dlq',
        ],

        'prefetch' => (int) env('INGEST_CONSUME_PREFETCH', 1),
        'stuck_after_minutes' => (int) env('INGEST_STUCK_AFTER_MINUTES', 60),
        'max_run_errors' => 1000,
    ],
];
