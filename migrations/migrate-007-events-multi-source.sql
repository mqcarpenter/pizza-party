-- Migration 007 — let pizzaparty_events_cache hold events from more than
-- one provider. Added so Ticketmaster can stand in for SeatGeek's events
-- (see ticketmaster.php) while a SeatGeek client_id is pending approval;
-- seatgeek_id (BIGINT, SeatGeek-only) becomes external_id (VARCHAR,
-- provider-agnostic -- Ticketmaster's own event ids are alphanumeric, e.g.
-- "vvG1zZ96Jud6", not numeric) plus a new source column.
--
--   mysql -u ADMIN -p otbdesig_wp298 < migrations/migrate-007-events-multi-source.sql

ALTER TABLE pizzaparty_events_cache
  CHANGE COLUMN seatgeek_id external_id VARCHAR(64) NOT NULL,
  ADD COLUMN source VARCHAR(16) NOT NULL DEFAULT 'seatgeek' AFTER external_id,
  DROP INDEX uniq_event_artist,
  ADD UNIQUE KEY uniq_event_artist (source, external_id, artist);
