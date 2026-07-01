<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class IconRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForSprite(int $spriteId, int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT i.*
             FROM glyph_icons i
             INNER JOIN glyph_sprites s ON s.id = i.sprite_id
             WHERE i.sprite_id = :sprite_id AND s.user_id = :user_id AND s.deleted_at IS NULL
             ORDER BY i.sort_order ASC, i.id ASC'
        );
        $statement->execute([':sprite_id' => $spriteId, ':user_id' => $userId]);

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $icon
     */
    public function add(int $spriteId, int $userId, array $icon): void
    {
        $sortOrder = $this->nextSortOrder($spriteId);
        $symbolId = $this->uniqueSymbolId($spriteId, (string)$icon['symbol_id']);
        $statement = $this->pdo->prepare(
            'INSERT INTO glyph_icons
               (sprite_id, symbol_id, original_filename, title, view_box, symbol_markup, warnings_json, sort_order)
             VALUES
               (:sprite_id, :symbol_id, :original_filename, :title, :view_box, :symbol_markup, :warnings_json, :sort_order)'
        );
        $statement->execute([
            ':sprite_id' => $spriteId,
            ':symbol_id' => $symbolId,
            ':original_filename' => $icon['filename'] ?? null,
            ':title' => $icon['title'] ?? null,
            ':view_box' => (string)$icon['viewBox'],
            ':symbol_markup' => (string)$icon['symbol_markup'],
            ':warnings_json' => json_encode([
                'warnings' => $icon['warnings'] ?? [],
                'notes' => $icon['notes'] ?? [],
            ], JSON_UNESCAPED_SLASHES),
            ':sort_order' => $sortOrder,
        ]);
    }

    public function update(int $iconId, int $userId, string $symbolId, ?string $title, int $sortOrder): bool
    {
        $icon = $this->findForUser($iconId, $userId);
        if ($icon === null) {
            return false;
        }

        $symbolId = $this->uniqueSymbolId((int)$icon['sprite_id'], $symbolId, $iconId);
        $statement = $this->pdo->prepare(
            'UPDATE glyph_icons
             SET symbol_id = :symbol_id, title = :title, sort_order = :sort_order
             WHERE id = :id'
        );
        $statement->execute([
            ':id' => $iconId,
            ':symbol_id' => $symbolId,
            ':title' => $title !== '' ? $title : null,
            ':sort_order' => $sortOrder,
        ]);

        return true;
    }

    public function delete(int $iconId, int $userId): bool
    {
        $icon = $this->findForUser($iconId, $userId);
        if ($icon === null) {
            return false;
        }

        $statement = $this->pdo->prepare('DELETE FROM glyph_icons WHERE id = :id');
        $statement->execute([':id' => $iconId]);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForUser(int $iconId, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT i.*
             FROM glyph_icons i
             INNER JOIN glyph_sprites s ON s.id = i.sprite_id
             WHERE i.id = :id AND s.user_id = :user_id AND s.deleted_at IS NULL'
        );
        $statement->execute([':id' => $iconId, ':user_id' => $userId]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    private function nextSortOrder(int $spriteId): int
    {
        $statement = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM glyph_icons WHERE sprite_id = :sprite_id');
        $statement->execute([':sprite_id' => $spriteId]);

        return (int)$statement->fetchColumn();
    }

    private function uniqueSymbolId(int $spriteId, string $baseSymbolId, ?int $ignoreIconId = null): string
    {
        $baseSymbolId = strtolower($baseSymbolId);
        $baseSymbolId = preg_replace('/[\s_]+/', '-', $baseSymbolId) ?? '';
        $baseSymbolId = preg_replace('/[^a-z0-9_-]+/', '', $baseSymbolId) ?? '';
        $baseSymbolId = trim($baseSymbolId, '-');

        if ($baseSymbolId === '' || !preg_match('/^[a-z]/', $baseSymbolId)) {
            $baseSymbolId = 'icon-' . $baseSymbolId;
        }

        $baseSymbolId = substr($baseSymbolId, 0, 110);
        $candidate = $baseSymbolId;
        $counter = 2;

        while ($this->symbolIdExists($spriteId, $candidate, $ignoreIconId)) {
            $candidate = $baseSymbolId . '-' . $counter;
            $counter++;
        }

        return $candidate;
    }

    private function symbolIdExists(int $spriteId, string $symbolId, ?int $ignoreIconId): bool
    {
        $sql = 'SELECT COUNT(*) FROM glyph_icons WHERE sprite_id = :sprite_id AND symbol_id = :symbol_id';
        $params = [':sprite_id' => $spriteId, ':symbol_id' => $symbolId];

        if ($ignoreIconId !== null) {
            $sql .= ' AND id <> :ignore_id';
            $params[':ignore_id'] = $ignoreIconId;
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return (int)$statement->fetchColumn() > 0;
    }
}
