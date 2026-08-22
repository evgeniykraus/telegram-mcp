<?php

use danog\MadelineProto\Logger;

return [

    'sessions' => [

        'single' => [
            'session_file' => env('MP_SESSION_FILE', 'session.madeline'),
        ],

        'multiple' => [
            'table' => 'telegram_sessions',
        ],

    ],

    'settings' => [

        'logger' => [
            // Logger type: FILE_LOGGER (2), STDOUT_LOGGER (1), NO_LOGGER (0)
            'logger' => class_exists(Logger::class) ? Logger::FILE_LOGGER : 2,

            // Logger output path (for FILE_LOGGER)
            'logger_param' => env('MP_LOGGER_PATH', storage_path('logs/madeline_proto_'.date('dmY').'.log')),

            // Logger level: ULTRA_VERBOSE (5), VERBOSE (4), NOTICE (3), WARNING (2), ERROR (1), FATAL_ERROR (0)
            'logger_level' => env('MP_LOGGER_LEVEL', class_exists(Logger::class) ? Logger::NOTICE : 3),
        ],

        'connection' => [
            'use_do_h' => false,
            'proxy' => env('MP_PROXY_HOST') ? [
                'type' => env('MP_PROXY_TYPE', 'socks5'),
                'address' => env('MP_PROXY_HOST', ''),
                'port' => (int) env('MP_PROXY_PORT', 0),
                'username' => env('MP_PROXY_USERNAME') ?: null,
                'password' => env('MP_PROXY_PASSWORD') ?: null,
            ] : null,
        ],

        'app_info' => [
            'api_id' => env('MP_TELEGRAM_API_ID', ''),
            'api_hash' => env('MP_TELEGRAM_API_HASH', ''),
        ],
    ],
];
