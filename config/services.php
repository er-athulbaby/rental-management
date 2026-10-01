<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Forge heartbeat ping URLs, one per scheduled job (spec §12).
    'forge' => [
        'heartbeats' => [
            'backup_clean' => env('HEARTBEAT_BACKUP_CLEAN'),
            'backup_run' => env('HEARTBEAT_BACKUP_RUN'),
            'backup_monitor' => env('HEARTBEAT_BACKUP_MONITOR'),
            'owner_contracts' => env('HEARTBEAT_OWNER_CONTRACTS'),
            'number_sequences' => env('HEARTBEAT_NUMBER_SEQUENCES'),
            'invoices_issue' => env('HEARTBEAT_INVOICES_ISSUE'),
            'agreements_expire' => env('HEARTBEAT_AGREEMENTS_EXPIRE'),
            'integrity_check' => env('HEARTBEAT_INTEGRITY_CHECK'),
        ],
    ],

];
