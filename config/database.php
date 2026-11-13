<?php

/* [MODUL 6] Pengaturan koneksi. Nilainya diambil dari file .env (setara .env.example di Modul 4 dan 5). */
return [
    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'disty_helpdesk'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            // Waktu disimpan dan dibaca sebagai UTC (sama dengan SET time_zone di database/disty_helpdesk.sql),
            // supaya data dari SQL dan data dari aplikasi tidak bergeser jam walau server MySQL memakai WIB.
            'timezone' => '+00:00',
            'strict' => true,
            'engine' => 'InnoDB',
            // [MODUL 7] Database MySQL terkelola di cloud (DigitalOcean, AWS RDS, dan sejenisnya) biasanya mewajibkan SSL.
            // Isi MYSQL_ATTR_SSL_CA dengan jalur berkas sertifikat CA penyedia. Dikosongkan = koneksi seperti biasa.
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_SSL_CA : \PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
    ],
];
