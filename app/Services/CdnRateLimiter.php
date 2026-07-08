<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RateLimitRepository;

final class CdnRateLimiter
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private RateLimitRepository $repository,
        private array $config = []
    ) {
    }

    /**
     * @return array{allowed: bool, retry_after: int}
     */
    public function allow(string $ipAddress, string $spriteHash): array
    {
        if (random_int(1, 100) === 1) {
            $this->repository->cleanupExpired();
        }

        $rules = [
            [
                'key' => 'cdn:ip-sprite:' . hash('sha256', $ipAddress . ':' . $spriteHash),
                'window' => (int)($this->config['per_ip_sprite_window_seconds'] ?? 60),
                'limit' => (int)($this->config['per_ip_sprite_limit'] ?? 120),
            ],
            [
                'key' => 'cdn:ip:' . hash('sha256', $ipAddress),
                'window' => (int)($this->config['per_ip_window_seconds'] ?? 600),
                'limit' => (int)($this->config['per_ip_limit'] ?? 600),
            ],
            [
                'key' => 'cdn:sprite-day:' . $spriteHash . ':' . gmdate('Y-m-d'),
                'window' => (int)($this->config['per_sprite_window_seconds'] ?? 86400),
                'limit' => (int)($this->config['per_sprite_limit'] ?? 5000),
            ],
        ];

        $allowed = true;
        $retryAfter = 1;
        foreach ($rules as $rule) {
            $hits = $this->repository->hit($rule['key'], $rule['window']);
            if ($hits > $rule['limit']) {
                $allowed = false;
                $retryAfter = max($retryAfter, $this->repository->retryAfterSeconds($rule['window']));
            }
        }

        return [
            'allowed' => $allowed,
            'retry_after' => $retryAfter,
        ];
    }
}
