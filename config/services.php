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

    // Bot báo tiền về cho chủ shop (docs/KE-HOACH.md, mục 5.9). Wyatt tạo bot qua @BotFather và tự dán token.
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        // Tên bot không có @, để dựng link t.me/<bot>?start=<mã>.
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        // Telegram gửi lại chuỗi này trong header X-Telegram-Bot-Api-Secret-Token ở mỗi update.
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
