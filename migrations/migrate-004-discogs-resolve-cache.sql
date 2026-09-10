-- Migration 004 — a small cache for resolving a (artist, title) pair to its
-- Discogs master release's "main release" (Discogs' own pick for the
-- definitive/most-common pressing, used when showing an addable Discogs
-- link for a Last.fm-sourced "similar album" suggestion).
--
--   mysql -u ADMIN -p otbdesig_wp298 < migrations/migrate-004-discogs-resolve-cache.sql

CREATE TABLE IF NOT EXISTS pizzaparty_discogs_cache (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cache_key   VARCHAR(255) NOT NULL,
  payload     LONGTEXT     NOT NULL,
  fetched_at  DATETIME     NOT NULL,
  UNIQUE KEY uniq_cache_key (cache_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
