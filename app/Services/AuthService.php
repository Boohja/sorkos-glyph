<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserRepository;

final class AuthService
{
    public function __construct(
        private array $appConfig,
        private UserRepository $users
    ) {
    }

    public function loginUrl(string $lang = 'en'): string
    {
        $state = bin2hex(random_bytes(32));
        $_SESSION['sorkos_oauth_state'] = $state;

        $authConfig = $this->authConfig();
        $params = [
            'client_id' => $authConfig['client_id'],
            'redirect_uri' => $this->callbackUri(),
            'response_type' => 'code',
            'state' => $state,
            'lang' => $lang,
        ];

        $scopes = $authConfig['scopes'] ?? [];
        if (is_array($scopes) && $scopes !== []) {
            $params['scope'] = implode(' ', array_map('strval', $scopes));
        }

        return rtrim((string)$authConfig['base_url'], '/') . '/authorize?' . http_build_query($params);
    }

    /**
     * @param array<string, string|null> $query
     * @return array<string, mixed>
     */
    public function completeCallback(array $query): array
    {
        $returnedState = (string)($query['state'] ?? '');
        $expectedState = (string)($_SESSION['sorkos_oauth_state'] ?? '');
        unset($_SESSION['sorkos_oauth_state']);

        if ($returnedState === '' || $expectedState === '' || !hash_equals($expectedState, $returnedState)) {
            throw new \RuntimeException('Invalid Sorkos Auth state.');
        }

        if (($query['error'] ?? '') === 'access_denied') {
            throw new \RuntimeException('Sign-in was cancelled.');
        }

        $code = (string)($query['code'] ?? '');
        if ($code === '') {
            throw new \RuntimeException('Missing Sorkos Auth authorization code.');
        }

        $tokenResult = $this->exchangeCode($code);
        $profile = $tokenResult['user'] ?? null;

        if (!is_array($profile)) {
            throw new \RuntimeException('Sorkos Auth returned an invalid user profile.');
        }

        $user = $this->users->upsertFromSorkosProfile($profile);
        session_regenerate_id(true);
        $_SESSION['glyph_user_id'] = (int)$user['id'];

        return $user;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentUser(): ?array
    {
        $userId = (int)($_SESSION['glyph_user_id'] ?? 0);

        if ($userId <= 0) {
            return null;
        }

        return $this->users->findById($userId);
    }

    public function logoutLocal(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeCode(string $code): array
    {
        $authConfig = $this->authConfig();
        $url = rtrim((string)$authConfig['base_url'], '/') . '/token';
        $payload = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => (string)$authConfig['client_id'],
            'client_secret' => (string)$authConfig['client_secret'],
            'redirect_uri' => $this->callbackUri(),
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                'content' => $payload,
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);

        $response = file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';

        if ($response === false) {
            throw new \RuntimeException('Could not contact Sorkos Auth token endpoint.');
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Sorkos Auth returned invalid JSON.');
        }

        if (!str_contains($statusLine, ' 200 ')) {
            $description = (string)($decoded['error_description'] ?? $decoded['error'] ?? 'Token exchange failed.');
            throw new \RuntimeException($description);
        }

        return $decoded;
    }

    private function callbackUri(): string
    {
        return rtrim((string)$this->appConfig['app']['base_url'], '/') . '/auth/callback';
    }

    /**
     * @return array<string, mixed>
     */
    private function authConfig(): array
    {
        return $this->appConfig['auth'] ?? [];
    }
}
