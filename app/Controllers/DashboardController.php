<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UserRepository;
use App\Repositories\SpriteRepository;
use App\Services\AuthService;
use App\Services\CsrfService;
use App\Services\Database;
use Base;
use Template;

final class DashboardController
{
    public function index(Base $f3): void
    {
        $auth = $this->authService($f3);
        $currentUser = $auth->currentUser();

        if ($currentUser === null) {
            $f3->reroute('/auth/login');
            return;
        }

        $pdo = Database::connection($f3->get('DB_CONFIG'));
        $sprites = (new SpriteRepository($pdo))->listForUser((int)$currentUser['id']);
        $config = $f3->get('CONFIG');
        $f3->set('title', 'Collections');
        $f3->set('appName', $config['app']['name'] ?? 'Glyph');
        $f3->set('currentUser', $currentUser);
        $f3->set('sprites', $sprites);
        $f3->set('hasSprites', count($sprites) > 0);
        $f3->set('csrfToken', (new CsrfService())->token());
        $f3->set('authLoginUrl', '/auth/login');
        $f3->set('content', 'dashboard.html');

        echo Template::instance()->render('layout.html');
    }

    private function authService(Base $f3): AuthService
    {
        $pdo = Database::connection($f3->get('DB_CONFIG'));

        return new AuthService($f3->get('CONFIG'), new UserRepository($pdo));
    }
}
