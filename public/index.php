<?php

declare(strict_types=1);

$requestPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
if (preg_match('#^/cdn/sprites/[A-Za-z0-9]{32,128}\.svg$#', $requestPath) === 1) {
    header('Access-Control-Allow-Origin: *');
    header('Cross-Origin-Resource-Policy: cross-origin');
    header('Timing-Allow-Origin: *');
}

require dirname(__DIR__) . '/lib/f3/base.php';
require dirname(__DIR__) . '/app/bootstrap.php';

\Base::instance()->run();
