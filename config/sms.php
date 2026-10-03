<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default driver preference (affects provider order)
    |--------------------------------------------------------------------------
    | log  = فقط ثبت محلی (لوکال/تست)
    | http = پنل HTTP واقعی (کاوه‌نگار و مشابه)
    | null = غیرفعال
    */
    'driver' => env('SMS_DRIVER', 'log'),

    'from' => env('SMS_FROM', ''),

    'timeout' => (int) env('SMS_TIMEOUT', 15),
    'connect_timeout' => (int) env('SMS_CONNECT_TIMEOUT', 8),

    'log_path' => storage_path('logs/sms.log'),
    'log_max_bytes' => (int) env('SMS_LOG_MAX_BYTES', 2 * 1024 * 1024),
    'log_max_entries_dashboard' => (int) env('SMS_LOG_MAX_ENTRIES', 200),

    'frontend_login_url' => env(
        'FRONTEND_LOGIN_URL',
        rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/').'/login'
    ),

    'welcome_template' => env(
        'SMS_WELCOME_TEMPLATE',
        "به سازمان فروش فاینوپال خوش آمدید.\nلینک ورود: {login_url}\nنام کاربری: {username}\nرمز عبور: {password}\nنقش: {role}"
    ),

    /*
    | ترتیب امتحان سامانه‌ها برای متن آزاد / خوش‌آمد.
    | اگر خالی باشد از SMS_DRIVER ساخته می‌شود.
    */
    'text_providers_order' => env('SMS_TEXT_PROVIDERS_ORDER', ''),

    'providers' => [
        'http' => [
            'label' => env('SMS_HTTP_LABEL', 'پنل HTTP / کاوه‌نگار'),
            'enabled' => env('SMS_HTTP_ENABLED', true),
            'url' => env('SMS_HTTP_URL', ''),
            'token' => env('SMS_HTTP_TOKEN', ''),
            'receptor_param' => env('SMS_HTTP_RECEPTOR_PARAM', 'receptor'),
            'message_param' => env('SMS_HTTP_MESSAGE_PARAM', 'message'),
            'sender_param' => env('SMS_HTTP_SENDER_PARAM', 'sender'),
        ],
        'log' => [
            'label' => 'لاگ محلی (دیباگ)',
            'enabled' => true,
        ],
    ],
];
