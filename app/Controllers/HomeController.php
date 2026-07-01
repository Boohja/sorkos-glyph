<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\CsrfService;
use App\Services\Database;
use Base;
use Template;

final class HomeController
{
    public function index(Base $f3): void
    {
        $config = $f3->get('CONFIG');

        $f3->set('title', 'Glyph');
        $f3->set('appName', $config['app']['name'] ?? 'Glyph');
        $f3->set('baseUrl', $config['app']['base_url'] ?? '');
        $f3->set('authLoginUrl', '/auth/login');
        $f3->set('currentUser', $this->currentUser($f3));
        $f3->set('csrfToken', (new CsrfService())->token());
        $f3->set('content', 'home.html');

        echo Template::instance()->render('layout.html');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentUser(Base $f3): ?array
    {
        try {
            $pdo = Database::connection($f3->get('DB_CONFIG'));
            $auth = new AuthService($f3->get('CONFIG'), new UserRepository($pdo));

            return $auth->currentUser();
        } catch (\Throwable) {
            return null;
        }
    }
}
