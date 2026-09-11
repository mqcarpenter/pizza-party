-- Pizza Party — MySQL schema
--
-- Lives in the SAME database as the markgrace app (shared host/accounts,
-- per infra choice). Every table is prefixed `pizzaparty_` so it can never
-- collide with markgrace's own `cards`/`devices` tables, and so this app's
-- registered devices are never mistaken for markgrace's — a passkey is
-- bound to one origin and the two apps are on different domains.
--
-- Run once:  mysql -u ADMIN -p markgrace < migrations/schema.sql

-- One row, filled in by the OAuth1 connect flow (api/index.php?action=discogs-connect).
CREATE TABLE IF NOT EXISTS pizzaparty_discogs_auth (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  discogs_username    VARCHAR(191) NOT NULL,
  access_token        VARCHAR(255) NOT NULL,
  access_token_secret VARCHAR(255) NOT NULL,
  created_at          DATETIME     NOT NULL,
  updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Local cache of the Discogs collection (folder 0 = "All"), refreshed by
-- sync-collection.php. The app reads/searches this, never Discogs directly.
CREATE TABLE IF NOT EXISTS pizzaparty_collection_items (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  release_id   INT UNSIGNED NOT NULL,
  instance_id  INT UNSIGNED NOT NULL,
  folder_id    INT UNSIGNED NOT NULL DEFAULT 0,
  artist       VARCHAR(255) DEFAULT NULL,
  title        VARCHAR(255) DEFAULT NULL,
  year         SMALLINT     DEFAULT NULL,
  format       VARCHAR(255) DEFAULT NULL,
  label        VARCHAR(255) DEFAULT NULL,
  genres       VARCHAR(255) DEFAULT NULL,
  styles       VARCHAR(255) DEFAULT NULL,
  thumb_url    VARCHAR(500) DEFAULT NULL,
  notes        TEXT         DEFAULT NULL,
  rating       TINYINT UNSIGNED DEFAULT NULL,
  date_added   DATETIME     DEFAULT NULL,
  raw_json     LONGTEXT     DEFAULT NULL,
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_instance (instance_id),
  KEY idx_release (release_id),
  KEY idx_artist  (artist),
  KEY idx_title   (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Local cache of the Discogs wantlist, refreshed by sync-wantlist.php and
-- kept current immediately on every gated write (add/remove/note).
CREATE TABLE IF NOT EXISTS pizzaparty_wantlist_items (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  release_id   INT UNSIGNED NOT NULL,
  artist       VARCHAR(255) DEFAULT NULL,
  title        VARCHAR(255) DEFAULT NULL,
  year         SMALLINT     DEFAULT NULL,
  format       VARCHAR(255) DEFAULT NULL,
  label        VARCHAR(255) DEFAULT NULL,
  genres       VARCHAR(255) DEFAULT NULL,
  styles       VARCHAR(255) DEFAULT NULL,
  thumb_url    VARCHAR(500) DEFAULT NULL,
  notes        TEXT         DEFAULT NULL,
  rating       TINYINT UNSIGNED DEFAULT NULL,
  date_added   DATETIME     DEFAULT NULL,
  raw_json     LONGTEXT     DEFAULT NULL,
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_release (release_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registered WebAuthn passkeys for THIS app's origin only. See db.php's
-- devices_registered() comment for why this isn't shared with markgrace's
-- own `devices` table.
CREATE TABLE IF NOT EXISTS pizzaparty_devices (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  credential_id VARBINARY(255) NOT NULL,
  public_key    TEXT           NOT NULL,
  sign_count    INT UNSIGNED   NOT NULL DEFAULT 0,
  label         VARCHAR(64)    DEFAULT NULL,
  created_at    DATETIME       NOT NULL,
  last_used_at  DATETIME       DEFAULT NULL,
  UNIQUE KEY uniq_credential (credential_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single-row-per-run log of the last sync, surfaced in the UI as
-- "synced N minutes ago".
CREATE TABLE IF NOT EXISTS pizzaparty_sync_state (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  last_synced_at  DATETIME     NOT NULL,
  status          VARCHAR(32)  NOT NULL,
  detail          VARCHAR(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Caches Last.fm API responses (album info, similar artists, top albums) so
-- a "Details" expand only ever hits Last.fm's API once per (method, args)
-- combination — every repeat view/expand reads this table instead.
CREATE TABLE IF NOT EXISTS pizzaparty_lastfm_cache (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cache_key   VARCHAR(255) NOT NULL,
  payload     LONGTEXT     NOT NULL,
  fetched_at  DATETIME     NOT NULL,
  UNIQUE KEY uniq_cache_key (cache_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Caches resolving a (artist, title) pair to its Discogs master release's
-- "main release" — used to give a Last.fm-sourced "similar album" an
-- addable Discogs link/release id.
CREATE TABLE IF NOT EXISTS pizzaparty_discogs_cache (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cache_key   VARCHAR(255) NOT NULL,
  payload     LONGTEXT     NOT NULL,
  fetched_at  DATETIME     NOT NULL,
  UNIQUE KEY uniq_cache_key (cache_key)
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
