<?php
// Typecho router for `php -S 127.0.0.1:8080 router.php` (quick test mode).
// For production use Caddy + php-cgi (see README).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;

// serve static files directly
if ($path !== '/' && is_file($file)) {
    return false;
}

// rewrite support: non-.php requests go to index.php with PATH_INFO
if ($path !== '/') {
    $_SERVER['PATH_INFO']    = $path;
    $_SERVER['SCRIPT_NAME']  = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
}

require __DIR__ . '/index.php';
