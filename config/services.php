<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    'shopify' => [
        // In Fase 1 punta allo store di sviluppo Shopify (gratuito); il passaggio
        // allo store reale in Fase 2 e' un cambio esplicito di queste variabili
        // d'ambiente, mai automatico.
        'store_domain' => env('SHOPIFY_STORE_DOMAIN'),
        'access_token' => env('SHOPIFY_ADMIN_API_ACCESS_TOKEN'),
        'api_version' => env('SHOPIFY_API_VERSION', '2025-01'),
    ],

    'backup' => [
        // rclone deve gia' avere il remote configurato sulla macchina (rclone
        // config), con le credenziali OAuth di Google Drive: qui passa solo il
        // nome del remote + percorso, mai un segreto.
        'rclone_binary' => env('BACKUP_RCLONE_BINARY', 'rclone'),
        'rclone_remote' => env('BACKUP_RCLONE_REMOTE'),
        'mysqldump_binary' => env('BACKUP_MYSQLDUMP_BINARY', 'mysqldump'),
        'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
    ],

];
