<?php

use App\Waba\Drivers\QiscusDriver;

return [
    'default' => env('WABA_DEFAULT_CHANNEL'),

    'providers' => [
        'qiscus' => [
            'class' => QiscusDriver::class,
            'base_url' => env('QISCUS_BASE_URL', 'https://multichannel.qiscus.com'),
            'timeout' => 15,
            'retries' => 2,
        ],
    ],

    'api_key' => [
        'prefix' => 'wba',
        'header' => 'Authorization',
        'last_used_throttle_seconds' => 60,
    ],

    'media' => [
        'disk' => env('WABA_MEDIA_DISK', 'local'),
        'path' => 'waba/media',
        'retention_days' => 30,
    ],

    'inbound' => [
        'store_raw' => true,
        'fanout' => [
            'webhook' => true,
            'broadcasting' => false,
            'polling' => true,
        ],
    ],

    'outbound' => [
        'default_mode' => 'queue',
        'queue_connection' => env('WABA_QUEUE', 'default'),
        'queue_name' => 'waba-outbound',
        'sync_timeout_seconds' => env('WABA_SYNC_TIMEOUT', 15),
        'retry' => [
            'attempts' => env('WABA_RETRY_ATTEMPTS', 3),
            'backoff_seconds' => [30, 120, 600],
        ],
        'idempotency' => [
            'ttl_hours' => 24,
            'cache_store' => env('WABA_IDEMPOTENCY_STORE'),
        ],
        'retention' => [
            'request_payload_days' => 30,
        ],
    ],

    'rate_limit' => [
        'channel_api_per_minute' => 600,
    ],
];
