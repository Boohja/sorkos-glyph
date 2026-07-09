<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $path = __DIR__ . DIRECTORY_SEPARATOR . $relative . '.php';

    if (is_file($path)) {
        require $path;
    }
});

$f3 = \Base::instance();
$root = dirname(__DIR__);
$appConfigPath = $root . '/config/app.php';
$dbConfigPath = $root . '/config/db.php';

$appConfig = is_file($appConfigPath)
    ? require $appConfigPath
    : require $root . '/config/app.example.php';

$dbConfig = is_file($dbConfigPath)
    ? require $dbConfigPath
    : require $root . '/config/db.example.php';

$f3->set('ROOT', $root);
$f3->set('UI', $root . '/app/Views/');
$f3->set('TEMP', $root . '/tmp/');
$f3->set('CACHE', $root . '/storage/cache/');
$f3->set('LOGS', $root . '/storage/logs/');
$f3->set('CONFIG', $appConfig);
$f3->set('DB_CONFIG', $dbConfig);
$f3->set('SETUP_WARNING', !is_file($appConfigPath) || !is_file($dbConfigPath));
$f3->set('DEBUG', !empty($appConfig['app']['debug']) ? 3 : 0);

$requestPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$isPublicCdnSpriteRequest = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && preg_match('#^/cdn/sprites/[A-Za-z0-9]{32,128}\.svg$#', $requestPath) === 1;

$security = $appConfig['security'] ?? [];
session_name((string)($security['session_name'] ?? 'glyph_session'));
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => (bool)($security['session_secure'] ?? true),
    'httponly' => (bool)($security['session_httponly'] ?? true),
    'samesite' => (string)($security['session_samesite'] ?? 'Lax'),
]);

if (!$isPublicCdnSpriteRequest && session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$f3->route('GET /', 'App\Controllers\HomeController->index');
$f3->route('GET /health', 'App\Controllers\HealthController->show');
$f3->route('GET /auth/login', 'App\Controllers\AuthController->login');
$f3->route('GET /auth/callback', 'App\Controllers\AuthController->callback');
$f3->route('POST /auth/logout', 'App\Controllers\AuthController->logout');
$f3->route('GET /dashboard', static function (Base $f3): void {
    $f3->reroute('/sprites');
});
$f3->route('GET /sprites', 'App\Controllers\DashboardController->index');
$f3->route('POST /sprites', 'App\Controllers\SpriteController->create');
$f3->route('GET /sprites/@hash', 'App\Controllers\SpriteController->edit');
$f3->route('POST /sprites/@hash', 'App\Controllers\SpriteController->update');
$f3->route('POST /sprites/@hash/delete', 'App\Controllers\SpriteController->delete');
$f3->route('POST /api/sanitize', 'App\Controllers\ApiController->sanitize');
$f3->route('POST /api/build-sprite', 'App\Controllers\ApiController->buildSprite');
$f3->route('POST /api/sprites/@hash/icons', 'App\Controllers\ApiController->addIcons');
$f3->route('POST /api/sprites/@hash/icons/update', 'App\Controllers\ApiController->updateIcons');
$f3->route('POST /api/icons/@id', 'App\Controllers\ApiController->updateIcon');
$f3->route('POST /api/icons/@id/delete', 'App\Controllers\ApiController->deleteIcon');
$f3->route('GET /api/sprites/@hash.svg', 'App\Controllers\ApiController->downloadSavedSprite');
$f3->route('GET /cdn/sprites/@hash.svg', 'App\Controllers\ApiController->cdnSprite');
