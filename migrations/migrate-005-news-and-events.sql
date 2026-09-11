-- Migration 005 — artist news and nearby events, backing the News tab.
--
--   mysql -u ADMIN -p otbdesig_wp298 < migrations/migrate-005-news-and-events.sql

-- Resolving an artist name to its MusicBrainz id is its own rate-limited
-- search call (MusicBrainz allows 1 req/sec); once resolved it practically
-- never changes, so it's cached indefinitely rather than re-resolved on
-- every sync run.
CREATE TABLE IF NOT EXISTS pizzaparty_artist_mbid (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  artist_key   VARCHAR(191) NOT NULL,
  mbid         CHAR(36)     DEFAULT NULL,   -- NULL = searched, no confident match
  resolved_at  DATETIME     NOT NULL,
  UNIQUE KEY uniq_artist_key (artist_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every release-group MusicBrainz has ever reported for an artist we track,
-- so sync-news.php can tell a genuinely NEW release apart from one it has
-- already reported on. Without this, every sync would re-announce an
-- artist's entire back catalog as "news" every time it ran.
CREATE TABLE IF NOT EXISTS pizzaparty_release_seen (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  artist_key     VARCHAR(191) NOT NULL,   -- lower(cleaned artist name)
  mbid           CHAR(36)     NOT NULL,   -- MusicBrainz release-group id
  title          VARCHAR(255) DEFAULT NULL,
  first_release  DATE         DEFAULT NULL,
  seen_at        DATETIME     NOT NULL,
  UNIQUE KEY uniq_artist_release (artist_key, mbid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- News feed items: one row per (artist, article) or (artist, new release).
-- Rebuilt by sync-news.php on every run rather than upserted indefinitely,
-- so an article that scrolls out of Google News' result window naturally
-- disappears instead of accumulating forever.
CREATE TABLE IF NOT EXISTS pizzaparty_news_items (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  artist         VARCHAR(255) NOT NULL,
  kind           ENUM('article','release') NOT NULL DEFAULT 'article',
  headline       VARCHAR(500) NOT NULL,
  url            VARCHAR(768) NOT NULL,
  source         VARCHAR(191) DEFAULT NULL,
  published_at   DATETIME     DEFAULT NULL,
  fetched_at     DATETIME     NOT NULL,
  UNIQUE KEY uniq_item (artist, url(255)),
  KEY idx_published (published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upcoming SeatGeek events matched to an artist in the collection/wantlist,
-- within one of the three tracked regions. Rebuilt by sync-events.php each
-- run (a matched show simply won't reappear next sync once it's sold out
-- of SeatGeek's own upcoming-events window or has passed).
CREATE TABLE IF NOT EXISTS pizzaparty_events_cache (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  seatgeek_id    BIGINT UNSIGNED NOT NULL,
  artist         VARCHAR(255) NOT NULL,
  title          VARCHAR(500) NOT NULL,
  venue_name     VARCHAR(255) DEFAULT NULL,
  venue_city     VARCHAR(191) DEFAULT NULL,
  venue_state    VARCHAR(64)  DEFAULT NULL,
  region         VARCHAR(32)  NOT NULL,   -- 'nyc' | 'philadelphia' | 'dc100'
  starts_at      DATETIME     DEFAULT NULL,
  url            VARCHAR(768) DEFAULT NULL,
  fetched_at     DATETIME     NOT NULL,
  UNIQUE KEY uniq_event_artist (seatgeek_id, artist),
  KEY idx_starts (starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
