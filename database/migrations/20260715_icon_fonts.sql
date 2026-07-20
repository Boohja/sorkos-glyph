ALTER TABLE glyph_sprites
  CHANGE cdn_enabled font_cdn_enabled TINYINT(1) NOT NULL DEFAULT 0,
  CHANGE cdn_disabled_at font_cdn_disabled_at DATETIME NULL;

ALTER TABLE glyph_icons
  ADD COLUMN codepoint INT UNSIGNED NULL AFTER symbol_id,
  ADD UNIQUE KEY uq_glyph_icons_sprite_codepoint (sprite_id, codepoint);

CREATE TABLE glyph_sprite_fonts (
  sprite_id BIGINT UNSIGNED NOT NULL,
  source_version INT UNSIGNED NOT NULL,
  builder_version VARCHAR(64) NOT NULL,
  status ENUM('pending', 'ready', 'failed') NOT NULL,
  woff2_hash CHAR(64) NULL,
  woff2_size INT UNSIGNED NULL,
  woff_hash CHAR(64) NULL,
  woff_size INT UNSIGNED NULL,
  error_json JSON NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  generated_at DATETIME NULL,
  PRIMARY KEY (sprite_id),
  KEY idx_glyph_sprite_fonts_woff2_hash (woff2_hash),
  CONSTRAINT fk_glyph_sprite_fonts_sprite
    FOREIGN KEY (sprite_id) REFERENCES glyph_sprites(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- output_mode is retained for a safe migration. Saved projects no longer use it.
