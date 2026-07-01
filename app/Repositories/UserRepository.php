<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class UserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public function upsertFromSorkosProfile(array $profile): array
    {
        $sorkosUserId = (string)($profile['id'] ?? '');

        if ($sorkosUserId === '') {
            throw new \RuntimeException('Sorkos Auth did not return a user id.');
        }

        $displayName = $profile['display_name'] ?? null;
        $email = $profile['email'] ?? null;

        $statement = $this->pdo->prepare(
            'INSERT INTO glyph_users (sorkos_user_id, display_name, email, last_login_at)
             VALUES (:sorkos_user_id, :display_name, :email, NOW())
             ON DUPLICATE KEY UPDATE
               display_name = VALUES(display_name),
               email = VALUES(email),
               last_login_at = NOW()'
        );

        $statement->execute([
            ':sorkos_user_id' => $sorkosUserId,
            ':display_name' => $displayName !== null ? (string)$displayName : null,
            ':email' => $email !== null ? (string)$email : null,
        ]);

        return $this->findBySorkosUserId($sorkosUserId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM glyph_users WHERE id = :id');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>
     */
    private function findBySorkosUserId(string $sorkosUserId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM glyph_users WHERE sorkos_user_id = :sorkos_user_id');
        $statement->execute([':sorkos_user_id' => $sorkosUserId]);
        $row = $statement->fetch();

        if (!$row) {
            throw new \RuntimeException('Unable to load local Glyph user.');
        }

        return $row;
    }
}
