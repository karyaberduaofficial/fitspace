<?php

return [
    'demo_mode' => filter_var(env('FITSPACE_DEMO_MODE', false), FILTER_VALIDATE_BOOLEAN),
    'reschedule_cutoff_hours' => (int) env('FITSPACE_RESCHEDULE_CUTOFF_HOURS', 8),
    'max_reschedule_count' => (int) env('FITSPACE_MAX_RESCHEDULE_COUNT', 1),
    'qr_signing_secret' => env('QR_SIGNING_SECRET', ''),
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI', ''),
    ],
    'payment' => [
        'default_gateway' => env('PAYMENT_DEFAULT_GATEWAY', 'midtrans'),
        'midtrans_server_key' => env('MIDTRANS_SERVER_KEY', ''),
        'midtrans_client_key' => env('MIDTRANS_CLIENT_KEY', ''),
        'midtrans_is_production' => filter_var(env('MIDTRANS_IS_PRODUCTION', false), FILTER_VALIDATE_BOOLEAN),
        'xendit_secret_key' => env('XENDIT_SECRET_KEY', ''),
        'xendit_webhook_token' => env('XENDIT_WEBHOOK_TOKEN', ''),
    ],
    'score_labels' => [
        1 => 'Belum Menguasai',
        2 => 'Mulai Berkembang',
        3 => 'Berkembang Sesuai Target',
        4 => 'Sangat Baik',
    ],
];
