-- Migration 002 — add genres/styles to the wantlist cache, so genre
-- filtering works the same way on both the Collection and Wantlist tabs.
--
--   mysql -u ADMIN -p otbdesig_wp298 < migrations/migrate-002-wantlist-genres.sql
--
-- Safe to re-run: both statements are guarded, so a second run is a no-op.

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'pizzaparty_wantlist_items'
      AND COLUMN_NAME  = 'genres') > 0,
  'SELECT "pizzaparty_wantlist_items.genres already present" AS note',
  'ALTER TABLE pizzaparty_wantlist_items ADD COLUMN genres VARCHAR(255) DEFAULT NULL AFTER label');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'pizzaparty_wantlist_items'
      AND COLUMN_NAME  = 'styles') > 0,
  'SELECT "pizzaparty_wantlist_items.styles already present" AS note',
  'ALTER TABLE pizzaparty_wantlist_items ADD COLUMN styles VARCHAR(255) DEFAULT NULL AFTER genres');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
