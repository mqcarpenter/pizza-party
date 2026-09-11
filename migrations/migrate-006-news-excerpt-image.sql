-- Migration 006 — excerpt + feature image for News tab articles, read off
-- each article's own Open Graph tags (Google News' RSS carries neither).
--
--   mysql -u ADMIN -p otbdesig_wp298 < migrations/migrate-006-news-excerpt-image.sql

ALTER TABLE pizzaparty_news_items
  ADD COLUMN excerpt   TEXT         DEFAULT NULL AFTER headline,
  ADD COLUMN image_url VARCHAR(768) DEFAULT NULL AFTER excerpt;
