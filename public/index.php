<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Ensure storage directories exist
$storage = __DIR__.'/../storage';
foreach (['framework/views', 'framework/cache', 'framework/cache/data', 'framework/sessions', 'logs'] as $sub) {
    $dir = $storage.'/'.$sub;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// Delete cached config so view path override in config/view.php works
$cached = $storage.'/../bootstrap/cache/config.php';
if (file_exists($cached)) {
    @unlink($cached);
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $storage.'/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
