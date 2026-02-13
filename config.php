<?php
return [
    'db' => [
        'host' => '127.0.0.1',
        'dbname' => 'gradeapp',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'paths' => [
        'secure_imports' => 'C:\\secure_imports\\gradeapp_csv',
        'base_url' => 'http://localhost/gradeapp'
    ],
    'security' => [
        'token_salt' => 'change_this_to_a_random_string'
    ]
];
