<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'Pediacare',
        'timezone' => 'Asia/Manila',
    ],
    'first_admin' => [
        'name' => '',
        'email' => '',
        'password' => '', // Set only in config.local.php; remove after seeding.
    ],
    'backup' => [
        'directory' => '', // Existing private directory OUTSIDE this project; restrict Windows ACLs.
        'key' => '', // Base64 of 32 random bytes; set only in config.local.php and protect recovery copies.
        'mysqldump' => 'C:/xampp/mysql/bin/mysqldump.exe',
        'mysql' => 'C:/xampp/mysql/bin/mysql.exe',
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'pediacare',
        'test_name' => 'pediacare_test',
        'username' => 'pediacare_app',
        'password' => '', // Set this in config.local.php only.
        'charset' => 'utf8mb4',
    ],
];
