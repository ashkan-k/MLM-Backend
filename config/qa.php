<?php

return [
    /*
    | Mega MLM org generator (QA only). Never point at production.
    */
    'total_users' => (int) env('QA_TOTAL_USERS', 10000),
    'total_roots' => (int) env('QA_TOTAL_ROOTS', 20),
    'max_tree_depth' => (int) env('QA_MAX_TREE_DEPTH', 20),
    'max_children_per_node' => (int) env('QA_MAX_CHILDREN', 500),
    'transaction_count' => (int) env('QA_TRANSACTION_COUNT', 5000),
    'order_count' => (int) env('QA_ORDER_COUNT', 2000),
    'random_seed' => (int) env('QA_RANDOM_SEED', 12345),
    'sqlite_path' => env('QA_SQLITE_PATH', 'database/qa_mega_org.sqlite'),
    'password' => env('QA_SEED_PASSWORD', 'Password123!'),
    'populations' => [
        'highly_active' => 0.10,
        'moderate' => 0.20,
        'low' => 0.30,
        'inactive' => 0.20,
        'new' => 0.10,
        'managers_special' => 0.10,
    ],
];
