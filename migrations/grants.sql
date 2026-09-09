-- Additional privileges for pizza-party, piggybacking on markgrace's
-- existing accounts and database.
--
-- Accounts are assumed to ALREADY EXIST (created for markgrace):
--   otbdesig_gracey  -> the web app
--   otbdesig_harper  -> the command-line importer/sync job
--
-- Run as an admin account AFTER migrations/schema.sql:
--
--   mysql -u ADMIN -p markgrace < migrations/grants.sql
--
-- This file only ADDS privileges on the new pizzaparty_* tables. It does not
-- touch markgrace's own `cards`/`devices` grants at all.

GRANT SELECT, INSERT, UPDATE ON markgrace.pizzaparty_collection_items TO 'otbdesig_gracey'@'localhost';
GRANT SELECT, INSERT, UPDATE ON markgrace.pizzaparty_wantlist_items   TO 'otbdesig_gracey'@'localhost';
-- The one-time OAuth connect/callback flow runs over the web (you visit the
-- URL in a browser to authorize), so the app account needs to write here too.
GRANT SELECT, INSERT, UPDATE ON markgrace.pizzaparty_discogs_auth      TO 'otbdesig_gracey'@'localhost';
GRANT SELECT, INSERT         ON markgrace.pizzaparty_devices          TO 'otbdesig_gracey'@'localhost';
GRANT UPDATE (sign_count, last_used_at) ON markgrace.pizzaparty_devices TO 'otbdesig_gracey'@'localhost';
GRANT SELECT                 ON markgrace.pizzaparty_sync_state       TO 'otbdesig_gracey'@'localhost';

-- The sync job (cron, CLI only) needs full read/write on the cache tables
-- and the ability to record its own runs and store/rotate the OAuth token.
GRANT SELECT, INSERT, UPDATE, DELETE ON markgrace.pizzaparty_collection_items TO 'otbdesig_harper'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON markgrace.pizzaparty_wantlist_items   TO 'otbdesig_harper'@'localhost';
GRANT SELECT, INSERT, UPDATE          ON markgrace.pizzaparty_discogs_auth    TO 'otbdesig_harper'@'localhost';
GRANT SELECT, INSERT, UPDATE          ON markgrace.pizzaparty_sync_state      TO 'otbdesig_harper'@'localhost';

FLUSH PRIVILEGES;

-- Verify:
--   SHOW GRANTS FOR 'otbdesig_gracey'@'localhost';
--   SHOW GRANTS FOR 'otbdesig_harper'@'localhost';
