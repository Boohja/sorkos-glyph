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
                    (
                        SELECT COUNT(*)
                        FROM glyph_icons ci
                        WHERE ci.sprite_id = s.id
                    ) AS icon_count,
                    (
                        SELECT pi.view_box
                        FROM glyph_icons pi
                        WHERE pi.sprite_id = s.id
                        ORDER BY pi.sort_order ASC, pi.id ASC
                        LIMIT 1
                    ) AS preview_view_box,
                    (
                        SELECT pi.symbol_markup
                        FROM glyph_icons pi
                        WHERE pi.sprite_id = s.id
                        ORDER BY pi.sort_order ASC, pi.id ASC
                        LIMIT 1
                    ) AS preview_symbol_markup
             FROM glyph_sprites s
             WHERE s.user_id = :user_id AND s.deleted_at IS NULL
             ORDER BY s.updated_at DESC, s.id DESC'
        );
        $statement->execute([':user_id' => $userId]);

        return $statement->fetchAll();
    }

    /**
     * @return array<string, mixed>
     */
    public function create(int $userId, string $name, string $slug, string $description = ''): array
    {
        $slug = $this->uniqueSlug($userId, $slug);
        $publicHash = $this->uniquePublicHash();
        $statement = $this->pdo->prepare(
            'INSERT INTO glyph_sprites (user_id, public_hash, name, slug, description, font_cdn_enabled)
             VALUES (:user_id, :public_hash, :name, :slug, :description, 0)'
        );
        $statement->execute([
            ':user_id' => $userId,
            ':public_hash' => $publicHash,
            ':name' => $name,
            ':slug' => $slug,
            ':description' => $description !== '' ? $description : null,
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

    /**
     * @return array<string, mixed>|null
     */
    public function findForUserByPublicHash(string $publicHash, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM glyph_sprites WHERE public_hash = :public_hash AND user_id = :user_id AND deleted_at IS NULL'
        );
        $statement->execute([':public_hash' => $publicHash, ':user_id' => $userId]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    public function update(int $spriteId, int $userId, string $name, string $slug, ?string $description): void
    {
        $slug = $this->uniqueSlug($userId, $slug, $spriteId);
        $statement = $this->pdo->prepare(
            'UPDATE glyph_sprites
             SET name = :name,
                 slug = :slug,
                 description = :description,
                 public_version = public_version + 1
             WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL'
        );
        $statement->execute([
            ':id' => $spriteId,
            ':user_id' => $userId,
            ':name' => $name,
            ':slug' => $slug,
            ':description' => $description !== '' ? $description : null,
        ]);
    }

    public function setFontCdnEnabled(int $spriteId, int $userId, bool $enabled): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE glyph_sprites
             SET font_cdn_enabled = :enabled,
                 font_cdn_disabled_at = CASE WHEN :enabled_at = 1 THEN NULL ELSE CURRENT_TIMESTAMP END,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL'
        );
        $statement->execute([
            ':id' => $spriteId,
            ':user_id' => $userId,
            ':enabled' => $enabled ? 1 : 0,
            ':enabled_at' => $enabled ? 1 : 0,
        ]);

        return $statement->rowCount() > 0;
    }

    public function touch(int $spriteId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE glyph_sprites
             SET public_version = public_version + 1, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id = :user_id'
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

    private function uniquePublicHash(): string
    {
        do {
            $publicHash = bin2hex(random_bytes(16));
        } while ($this->publicHashExists($publicHash));

        return $publicHash;
    }

    private function publicHashExists(string $publicHash): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM glyph_sprites WHERE public_hash = :public_hash');
        $statement->execute([':public_hash' => $publicHash]);

        return (int)$statement->fetchColumn() > 0;
    }
}
