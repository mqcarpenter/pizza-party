-- Additional privileges for pizza-party, piggybacking on markgrace's
-- existing accounts and database (otbdesig_wp298).
--
-- Accounts are assumed to ALREADY EXIST (created for markgrace):
--   otbdesig_gracey  -> the web app
--   otbdesig_harper  -> the command-line importer/sync job
--
-- Run as an admin account AFTER migrations/schema.sql:
--
--   mysql -u ADMIN -p otbdesig_wp298 < migrations/grants.sql
--
-- This file only ADDS privileges on the new pizzaparty_* tables. It does not
-- touch markgrace's own `cards`/`devices` grants at all.

GRANT SELECT, INSERT, UPDATE ON otbdesig_wp298.pizzaparty_collection_items TO 'otbdesig_gracey'@'localhost';
GRANT SELECT, INSERT, UPDATE ON otbdesig_wp298.pizzaparty_wantlist_items   TO 'otbdesig_gracey'@'localhost';
-- The one-time OAuth connect/callback flow runs over the web (you visit the
-- URL in a browser to authorize), so the app account needs to write here too.
GRANT SELECT, INSERT, UPDATE ON otbdesig_wp298.pizzaparty_discogs_auth      TO 'otbdesig_gracey'@'localhost';
GRANT SELECT, INSERT         ON otbdesig_wp298.pizzaparty_devices          TO 'otbdesig_gracey'@'localhost';
GRANT UPDATE (sign_count, last_used_at) ON otbdesig_wp298.pizzaparty_devices TO 'otbdesig_gracey'@'localhost';
GRANT SELECT                 ON otbdesig_wp298.pizzaparty_sync_state       TO 'otbdesig_gracey'@'localhost';
-- Last.fm/Discogs-resolve caches are upserts (ON DUPLICATE KEY UPDATE), so
-- no DELETE needed.
GRANT SELECT, INSERT, UPDATE ON otbdesig_wp298.pizzaparty_lastfm_cache      TO 'otbdesig_gracey'@'localhost';
GRANT SELECT, INSERT, UPDATE ON otbdesig_wp298.pizzaparty_discogs_cache     TO 'otbdesig_gracey'@'localhost';
-- News/events are read-only from the web app -- only sync-news.php and
-- sync-events.php (cron, otbdesig_harper) ever write these.
GRANT SELECT ON otbdesig_wp298.pizzaparty_news_items    TO 'otbdesig_gracey'@'localhost';
GRANT SELECT ON otbdesig_wp298.pizzaparty_events_cache  TO 'otbdesig_gracey'@'localhost';

-- The sync job (cron, CLI only) needs full read/write on the cache tables
-- and the ability to record its own runs and store/rotate the OAuth token.
GRANT SELECT, INSERT, UPDATE, DELETE ON otbdesig_wp298.pizzaparty_collection_items TO 'otbdesig_harper'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON otbdesig_wp298.pizzaparty_wantlist_items   TO 'otbdesig_harper'@'localhost';
GRANT SELECT, INSERT, UPDATE          ON otbdesig_wp298.pizzaparty_discogs_auth    TO 'otbdesig_harper'@'localhost';
GRANT SELECT, INSERT, UPDATE          ON otbdesig_wp298.pizzaparty_sync_state      TO 'otbdesig_harper'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON otbdesig_wp298.pizzaparty_release_seen  TO 'otbdesig_harper'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON otbdesig_wp298.pizzaparty_news_items    TO 'otbdesig_harper'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON otbdesig_wp298.pizzaparty_events_cache  TO 'otbdesig_harper'@'localhost';

FLUSH PRIVILEGES;

-- Verify:
--   SHOW GRANTS FOR 'otbdesig_gracey'@'localhost';
--   SHOW GRANTS FOR 'otbdesig_harper'@'localhost';
