<?php

return [
    'currency' => 'IRT',
    // Economic outflow of per-tx role rates: 15 (rep pool, may include referrer cut) + 6 + 4.5 + 4 = 29.5
    // Referrer 2% is taken from the referred rep's slice (not additive).
    'base_organization_percent' => '29.500',
    'reward_pool_percent' => '10.500',
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
];
