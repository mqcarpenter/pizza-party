-- Migration 003
--   1. pizzaparty_lastfm_cache — caches Last.fm API responses (album info,
--      similar artists, top albums) so the app's own account never needs
--      more than one live Last.fm call per cold "Details" expand, and
--      repeat views/expands hit MySQL instead of the Last.fm API.
--   2. pizzaparty_collection_items.rating — Discogs' own per-instance
--      collection rating (0-5), so a personal star rating on something you
--      already own round-trips to Discogs like everything else here.
--
--   mysql -u ADMIN -p otbdesig_wp298 < migrations/migrate-003-lastfm-and-collection-rating.sql
--
-- Safe to re-run.

CREATE TABLE IF NOT EXISTS pizzaparty_lastfm_cache (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cache_key   VARCHAR(255) NOT NULL,
  payload     LONGTEXT     NOT NULL,
  fetched_at  DATETIME     NOT NULL,
  UNIQUE KEY uniq_cache_key (cache_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'pizzaparty_collection_items'
      AND COLUMN_NAME  = 'rating') > 0,
  'SELECT "pizzaparty_collection_items.rating already present" AS note',
  'ALTER TABLE pizzaparty_collection_items ADD COLUMN rating TINYINT UNSIGNED DEFAULT NULL AFTER notes');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
