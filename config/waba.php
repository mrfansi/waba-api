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
    ],

    'rate_limit' => [
        'channel_api_per_minute' => 600,
    ],
];
