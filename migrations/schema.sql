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
