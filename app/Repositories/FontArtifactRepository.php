<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class FontArtifactRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function findForSprite(int $spriteId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM glyph_sprite_fonts WHERE sprite_id = :sprite_id');
        $statement->execute([':sprite_id' => $spriteId]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public function findPublicByHash(string $publicHash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT f.*, s.user_id, s.public_hash, s.slug, s.name, s.public_version, s.updated_at AS sprite_updated_at
             FROM glyph_sprite_fonts f
             INNER JOIN glyph_sprites s ON s.id = f.sprite_id
             WHERE s.public_hash = :public_hash
               AND s.deleted_at IS NULL
               AND s.font_cdn_enabled = 1
               AND s.font_cdn_disabled_at IS NULL
               AND f.status = \'ready\''
        );
        $statement->execute([':public_hash' => $publicHash]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    public function clear(int $spriteId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM glyph_sprite_fonts WHERE sprite_id = :sprite_id');
        $statement->execute([':sprite_id' => $spriteId]);
    }

    public function markPending(int $spriteId, int $sourceVersion, string $builderVersion): void
    {
        $this->upsert($spriteId, $sourceVersion, $builderVersion, 'pending', null, null, null, null, null);
    }

    /** @param array<string, mixed> $error */
    public function markFailed(int $spriteId, int $sourceVersion, string $builderVersion, array $error): void
    {
        $this->upsert(
            $spriteId,
            $sourceVersion,
            $builderVersion,
            'failed',
            null,
            null,
            null,
            null,
            json_encode($error, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    public function markReady(
        int $spriteId,
        int $sourceVersion,
        string $builderVersion,
        string $woff2Hash,
        int $woff2Size,
        string $woffHash,
        int $woffSize
    ): void {
        $this->upsert(
            $spriteId,
            $sourceVersion,
            $builderVersion,
            'ready',
            $woff2Hash,
            $woff2Size,
            $woffHash,
            $woffSize,
            null
        );
    }

    private function upsert(
        int $spriteId,
        int $sourceVersion,
        string $builderVersion,
        string $status,
        ?string $woff2Hash,
        ?int $woff2Size,
        ?string $woffHash,
        ?int $woffSize,
        ?string $errorJson
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO glyph_sprite_fonts
                (sprite_id, source_version, builder_version, status, woff2_hash, woff2_size, woff_hash, woff_size, error_json, attempted_at, generated_at)
             VALUES
                (:sprite_id, :source_version, :builder_version, :status, :woff2_hash, :woff2_size, :woff_hash, :woff_size, :error_json, CURRENT_TIMESTAMP,
                 CASE WHEN :generated_status = \'ready\' THEN CURRENT_TIMESTAMP ELSE NULL END)
             ON DUPLICATE KEY UPDATE
                source_version = VALUES(source_version), builder_version = VALUES(builder_version), status = VALUES(status),
                woff2_hash = VALUES(woff2_hash), woff2_size = VALUES(woff2_size), woff_hash = VALUES(woff_hash),
                woff_size = VALUES(woff_size), error_json = VALUES(error_json), attempted_at = CURRENT_TIMESTAMP,
                generated_at = CASE WHEN VALUES(status) = \'ready\' THEN CURRENT_TIMESTAMP ELSE generated_at END'
        );
        $statement->execute([
            ':sprite_id' => $spriteId,
            ':source_version' => $sourceVersion,
            ':builder_version' => $builderVersion,
            ':status' => $status,
            ':woff2_hash' => $woff2Hash,
            ':woff2_size' => $woff2Size,
            ':woff_hash' => $woffHash,
            ':woff_size' => $woffSize,
            ':error_json' => $errorJson,
            ':generated_status' => $status,
        ]);
    }
}
