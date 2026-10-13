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

    // Fase 6, tarea 1 (D4): llaves de Wompi. En pruebas, las del sandbox (pub_test_…, prv_test_…,
    // test_events_…, test_integrity_…); en producción, las de la cuenta comercial. Se copian del
    // panel de Wompi > Desarrolladores. Nunca se suben al repositorio.
    'wompi' => [
        'ambiente' => env('WOMPI_AMBIENTE', 'sandbox'), // sandbox | produccion
        'llave_publica' => env('WOMPI_LLAVE_PUBLICA'),
        'llave_privada' => env('WOMPI_LLAVE_PRIVADA'),
        'secreto_eventos' => env('WOMPI_SECRETO_EVENTOS'),
        'secreto_integridad' => env('WOMPI_SECRETO_INTEGRIDAD'),
        'url_checkout' => env('WOMPI_URL_CHECKOUT', 'https://checkout.wompi.co/p/'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
