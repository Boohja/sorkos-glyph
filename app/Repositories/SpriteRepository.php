<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SpriteRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT s.*,
                    COUNT(i.id) AS icon_count
             FROM glyph_sprites s
             LEFT JOIN glyph_icons i ON i.sprite_id = s.id
             WHERE s.user_id = :user_id AND s.deleted_at IS NULL
             GROUP BY s.id
             ORDER BY s.updated_at DESC, s.id DESC'
        );
        $statement->execute([':user_id' => $userId]);

        return $statement->fetchAll();
    }

    /**
     * @return array<string, mixed>
     */
    public function create(int $userId, string $name, string $slug, string $description = '', string $outputMode = 'pretty'): array
    {
        $slug = $this->uniqueSlug($userId, $slug);
        $statement = $this->pdo->prepare(
            'INSERT INTO glyph_sprites (user_id, name, slug, description, output_mode)
             VALUES (:user_id, :name, :slug, :description, :output_mode)'
        );
        $statement->execute([
            ':user_id' => $userId,
            ':name' => $name,
            ':slug' => $slug,
            ':description' => $description !== '' ? $description : null,
            ':output_mode' => $outputMode === 'minified' ? 'minified' : 'pretty',
        ]);

        return $this->findForUser((int)$this->pdo->lastInsertId(), $userId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUser(int $spriteId, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM glyph_sprites WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL'
        );
        $statement->execute([':id' => $spriteId, ':user_id' => $userId]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    public function update(int $spriteId, int $userId, string $name, string $slug, ?string $description, string $outputMode): void
    {
        $slug = $this->uniqueSlug($userId, $slug, $spriteId);
        $statement = $this->pdo->prepare(
            'UPDATE glyph_sprites
             SET name = :name, slug = :slug, description = :description, output_mode = :output_mode
             WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL'
        );
        $statement->execute([
            ':id' => $spriteId,
            ':user_id' => $userId,
            ':name' => $name,
            ':slug' => $slug,
            ':description' => $description !== '' ? $description : null,
            ':output_mode' => $outputMode === 'minified' ? 'minified' : 'pretty',
        ]);
    }

    public function touch(int $spriteId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE glyph_sprites SET updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([':id' => $spriteId, ':user_id' => $userId]);
    }

    public function softDelete(int $spriteId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE glyph_sprites SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([':id' => $spriteId, ':user_id' => $userId]);
    }

    private function uniqueSlug(int $userId, string $baseSlug, ?int $ignoreSpriteId = null): string
    {
        $baseSlug = substr(trim($baseSlug, '-'), 0, 130);
        if ($baseSlug === '') {
            $baseSlug = 'sprite';
        }

        $candidate = $baseSlug;
        $counter = 2;

        while ($this->slugExists($userId, $candidate, $ignoreSpriteId)) {
            $candidate = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $candidate;
    }

    private function slugExists(int $userId, string $slug, ?int $ignoreSpriteId): bool
    {
        $sql = 'SELECT COUNT(*) FROM glyph_sprites WHERE user_id = :user_id AND slug = :slug AND deleted_at IS NULL';
        $params = [':user_id' => $userId, ':slug' => $slug];

        if ($ignoreSpriteId !== null) {
            $sql .= ' AND id <> :ignore_id';
            $params[':ignore_id'] = $ignoreSpriteId;
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return (int)$statement->fetchColumn() > 0;
    }
}
