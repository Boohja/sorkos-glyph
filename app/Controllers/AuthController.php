<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\CsrfService;
use App\Services\Database;
use Base;
use Template;

final class AuthController
{
    public function login(Base $f3): void
    {
        $auth = $this->authService($f3);

        $f3->reroute($auth->loginUrl('en'));
    }

    public function callback(Base $f3): void
    {
        if ($f3->get('GET.error') === 'access_denied') {
            unset($_SESSION['sorkos_oauth_state']);
            $f3->reroute('/');
            return;
        }

        try {
            $this->authService($f3)->completeCallback([
                'code' => $f3->get('GET.code'),
                'state' => $f3->get('GET.state'),
                'error' => $f3->get('GET.error'),
            ]);

            $f3->reroute('/sprites');
        } catch (\Throwable $exception) {
            $this->renderMessage($f3, 'Sign-in failed', $exception->getMessage(), 400);
        }
    }

    public function logout(Base $f3): void
    {
        $csrf = new CsrfService();

        if (!$csrf->verify($f3->get('POST.csrf_token'))) {
            $this->renderMessage($f3, 'Logout failed', 'The logout request could not be verified.', 400);
            return;
        }

        $this->authService($f3)->logoutLocal();
        $f3->reroute('/');
    }

    private function authService(Base $f3): AuthService
    {
        $pdo = Database::connection($f3->get('DB_CONFIG'));

        return new AuthService($f3->get('CONFIG'), new UserRepository($pdo));
    }

    private function renderMessage(Base $f3, string $title, string $body, int $status): void
    {
        $config = $f3->get('CONFIG');
        $f3->status($status);
        $f3->set('title', $title);
        $f3->set('appName', $config['app']['name'] ?? 'Glyph');
        $f3->set('currentUser', null);
        $f3->set('csrfToken', (new CsrfService())->token());
        $f3->set('authLoginUrl', '/auth/login');
        $f3->set('messageTitle', $title);
        $f3->set('messageBody', $body);
        $f3->set('content', 'message.html');

        echo Template::instance()->render('layout.html');
    }
}
