<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Mode pemeliharaan
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../vendor/autoload.php';

/* [MODUL 6] FRONT CONTROLLER Laravel: semua request masuk lewat file ini, lalu diteruskan ke bootstrap/app.php. */
(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());
