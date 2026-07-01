<?php

declare(strict_types=1);

namespace App\Services;

final class CsrfService
{
    public function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string)$_SESSION['csrf_token'];
    }

    public function verify(?string $token): bool
    {
        $expected = (string)($_SESSION['csrf_token'] ?? '');

        return $token !== null && $expected !== '' && hash_equals($expected, $token);
    }
}
