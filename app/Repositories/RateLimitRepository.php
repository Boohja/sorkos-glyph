<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class RateLimitRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function hit(string $key, int $windowSeconds): int
    {
        $now = time();
        $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
        $expiresAt = gmdate('Y-m-d H:i:s', $windowStart + ($windowSeconds * 2));

        $statement = $this->pdo->prepare(
            'INSERT INTO glyph_rate_limits (rate_key, window_start, hits, expires_at)
             VALUES (:rate_key, :window_start, 1, :expires_at)
             ON DUPLICATE KEY UPDATE hits = hits + 1, expires_at = :updated_expires_at'
        );
        $statement->execute([
            ':rate_key' => $key,
            ':window_start' => $windowStart,
            ':expires_at' => $expiresAt,
            ':updated_expires_at' => $expiresAt,
        ]);

        $statement = $this->pdo->prepare(
            'SELECT hits FROM glyph_rate_limits WHERE rate_key = :rate_key AND window_start = :window_start'
        );
        $statement->execute([
            ':rate_key' => $key,
            ':window_start' => $windowStart,
        ]);

        return (int)$statement->fetchColumn();
    }

    public function retryAfterSeconds(int $windowSeconds): int
    {
        $elapsed = time() % $windowSeconds;

        return max(1, $windowSeconds - $elapsed);
    }

    public function cleanupExpired(): void
    {
        $this->pdo->exec('DELETE FROM glyph_rate_limits WHERE expires_at < UTC_TIMESTAMP()');
    }
}
