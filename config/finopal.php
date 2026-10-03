<?php

return [
    'currency' => 'IRT',
    // Economic outflow of per-tx rates: 15 + 2 + 6 + 4.5 + 4 = 31.5 (referrer from total profit).
    // Monthly reward deltas (5 + 2 + 1.5 = 8.5) sit in the separate reward pool toward 40% cap.
    'base_organization_percent' => '31.500',
    'reward_pool_percent' => '8.500',
    'max_organization_percent' => '40.000',
    'full_sale_points' => 100,
    'roles' => [
        'senior_manager' => ['name' => 'مدیر ارشد', 'level' => 1, 'organizational' => true],
        'development_manager' => ['name' => 'مدیر توسعه', 'level' => 2, 'organizational' => true],
        'sales_manager' => ['name' => 'مدیر فروش', 'level' => 3, 'organizational' => true],
        'representative_referrer' => ['name' => 'نماینده معرف', 'level' => 4, 'organizational' => true],
        'representative' => ['name' => 'نماینده', 'level' => 5, 'organizational' => true],
        'superuser' => ['name' => 'مدیر سامانه', 'level' => 0, 'organizational' => false],
    ],
    'ws_server' => env('FINOPAL_WS_URL', 'http://127.0.0.1:6001'),
    'ws_secret' => env('FINOPAL_WS_SECRET', 'finopal-ws-secret'),
    'webhook_secret' => env('FINOPAL_WEBHOOK_SECRET', ''),
    /** Default password for users created via org-structure webhook. */
    'sync_default_password' => env('FINOPAL_SYNC_DEFAULT_PASSWORD', 'Password123!'),

    /*
    |--------------------------------------------------------------------------
    | Product catalog (commission source types)
    |--------------------------------------------------------------------------
    | Webhook field: product_type
    | - gateway_profit: resolve by merchant_id → approved gateway sale
    | - other types: resolve owners by national_id / user_id and walk org tree
    */
    'products' => [
        'gateway_profit' => [
            'label' => 'سود درگاه پرداخت',
            'requires_merchant' => true,
            'requires_owner' => false,
            'sale_points' => 0,
            'default_code' => 'GATEWAY',
        ],
        'ticketing' => [
            'label' => 'سیستم تیکتینگ فینوپال',
            'requires_merchant' => false,
            'requires_owner' => true,
            'sale_points' => 0,
            'default_code' => 'TICKETING',
        ],
        '_default' => [
            'label' => 'محصول سفارشی',
            'requires_merchant' => false,
            'requires_owner' => true,
            'sale_points' => 0,
        ],
    ],
];
