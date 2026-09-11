# Pizza Party

A mobile-friendly (installable PWA) viewer for your Discogs record collection
and wantlist, deployed at `licoricepizzareviews.com/pizzaparty`. Reading is
always open; adding, removing, or editing a wantlist item requires Face ID
from a registered iPhone, using the same dependency-free WebAuthn approach as
the `markgrace` card tracker.

## How it works

- **Discogs → MySQL**: `sync-collection.php` and `sync-wantlist.php` run from
  cron, pulling your collection and wantlist via the Discogs API into local
  cache tables (`pizzaparty_collection_items`, `pizzaparty_wantlist_items`).
  The app always reads from MySQL, never live from Discogs, so it's instant
  and never trips the API's rate limit.
- **Writes** (`wantlist-add` / `wantlist-remove` / `wantlist-note`) call the
  Discogs API directly, then update the local cache immediately — no need to
  wait for the next cron run to see your own change.
- **Face ID lock**: exactly like markgrace — reading is public, but the
  moment one device is registered, writes require a signature from it. See
  `webauthn.php` / `db.php` / `api/index.php`.
- **Shared infrastructure**: this app lives in the SAME MySQL database and
  accounts as markgrace (`otbdesig_gracey` / `otbdesig_harper`), with its own
  `pizzaparty_*`-prefixed tables so nothing collides. Its own `devices` table
  (`pizzaparty_devices`) is separate from markgrace's, because a WebAuthn
  passkey is bound to one origin — markgrace and pizza-party are different
  domains, so a passkey registered for one cannot authenticate the other.
  You'll register your iPhone here separately (one extra Face ID tap).
- **Last.fm enrichment** (`lastfm.php`): tapping "Details" on any card
  lazy-loads listener/playcount stats, tags, similar artists, and similar
  albums (via a shared-tag lookup against the release's own top tags —
  Last.fm has no true album-similarity endpoint, but this ranks by genuine
  style rather than "other albums by an artist who sounds similar"), cached
  in `pizzaparty_lastfm_cache` for 30 days. Read-only — no OAuth, just an
  API key.
- **Discogs resolve** (`discogs_oauth.php`'s `discogs_resolve_release()`):
  each similar-album suggestion is tied back to a real, *vinyl* Discogs
  release. This is a vinyl collection tool, so a master's `main_release`
  (Discogs' own default pick, often a CD or digital release) isn't good
  enough — this asks the master for its actual Vinyl versions and resolves
  to the earliest one, along with that pressing's own year, country, and a
  median across Discogs' price-suggestion tool's per-condition values.
  Returns nothing addable if no vinyl pressing of the album exists at all.
  Cached in `pizzaparty_discogs_cache` for 30 days. The live "Search
  Discogs to add" box and the `search` action are filtered to
  `format=Vinyl` the same way.
- **Ratings**: a 5-star control on each card writes Discogs' own rating
  field — the wantlist's native 0-5 rating for Wantlist items, and the
  collection's native per-instance 0-5 rating for Collection items — so it
  round-trips to Discogs like every other write here (Face ID gated).
- **News tab** (the app's landing tab): artist news for everyone in your
  Collection and Wantlist, with an "upcoming shows" sidebar.
  - `musicbrainz.php` + `sync-news.php` detect a genuinely NEW release by
    diffing each artist's MusicBrainz release-groups against
    `pizzaparty_release_seen` — an artist's first sync seeds this table
    without announcing their whole back catalog as news. Keyless; rate-
    limited to MusicBrainz's own 1 req/sec.
  - `news.php`'s `googlenews_fetch()` pulls a handful of recent articles
    per artist from Google News' public RSS search — keyless, but
    unofficial, so results can be loosely related and the feed's shape
    could change without notice.
  - `seatgeek.php` + `sync-events.php` fetch upcoming concerts in three
    regions (New York, Philadelphia, and 100 miles around DC) in bulk and
    match performers against the artist set client-side, rather than
    querying per artist per region. Needs a free SeatGeek `client_id`
    (self-serve, no OAuth) — chosen over Ticketmaster because it aggregates
    independent venues' own box offices too, not just Ticketmaster/Live
    Nation rooms.
  - `ticketmaster.php` is a stand-in for SeatGeek's events while a
    SeatGeek `client_id` is pending approval — same regions, same
    fetch-in-bulk-then-match strategy, different provider. Listed in
    `sync-events.php`'s `$SOURCES` alongside SeatGeek; comment out that
    entry once SeatGeek is live (running both is harmless, just means a
    show listed on both providers shows up twice in the sidebar).
  - Both events sources, and MusicBrainz/Google News for the main feed,
    only ever consider **significant artists** — `significant_artists()`
    in `db.php`, two-plus records between collection and wantlist
    combined — not every artist ever logged. A large collection has
    plenty of one-off artists that would otherwise dilute the feed
    without adding anything anyone would call "my music news."
  - Every source writes to cache tables (`pizzaparty_news_items`,
    `pizzaparty_events_cache`) that `api/index.php`'s `news`/`events`
    actions only ever read — the web app never calls MusicBrainz, Google
    News, SeatGeek, or Ticketmaster directly.

## First-time setup

1. Register a Discogs application at
   https://www.discogs.com/settings/developers to get a Consumer Key/Secret.
2. Create `config.php` (gitignored) and fill in:
   - The `db`/`db_admin` blocks — same host/user/pass as markgrace's
     `config.php` (the shared database is `otbdesig_wp298`).
   - The `discogs` block — consumer key/secret, and `callback_url` pointing
     at `https://licoricepizzareviews.com/pizzaparty/api/index.php?action=discogs-callback`.
   - A `'lastfm' => ['api_key' => '...']` block — get a key at
     https://www.last.fm/api/account/create (no OAuth needed, just the key).
   - A `'seatgeek' => ['client_id' => '...']` block, for the News tab's
     events sidebar — free self-serve signup at
     https://seatgeek.com/account/develop, no OAuth needed.
   - A `'ticketmaster' => ['api_key' => '...']` block — SeatGeek's
     approval can take a while, so `sync-events.php` runs Ticketmaster
     alongside it in the meantime. Free self-serve key at
     https://developer-acct.ticketmaster.com/. Remove this block (and
     comment out its entry in `sync-events.php`'s `$SOURCES`) once
     SeatGeek is approved and confirmed working, if you'd rather not run
     both.
3. Run the schema and grants, as a MySQL admin, against markgrace's database
   (`otbdesig_wp298`). On a fresh install `schema.sql` already includes
   everything; on an existing install, also run the numbered migrations for
   whatever's new since your last deploy:
   ```
   mysql -u ADMIN -p otbdesig_wp298 < migrations/schema.sql
   mysql -u ADMIN -p otbdesig_wp298 < migrations/grants.sql
   mysql -u ADMIN -p otbdesig_wp298 < migrations/migrate-002-wantlist-genres.sql
   mysql -u ADMIN -p otbdesig_wp298 < migrations/migrate-003-lastfm-and-collection-rating.sql
   mysql -u ADMIN -p otbdesig_wp298 < migrations/migrate-004-discogs-resolve-cache.sql
   mysql -u ADMIN -p otbdesig_wp298 < migrations/migrate-005-news-and-events.sql
   mysql -u ADMIN -p otbdesig_wp298 < migrations/migrate-006-news-excerpt-image.sql
   mysql -u ADMIN -p otbdesig_wp298 < migrations/migrate-007-events-multi-source.sql
   ```
   Raw `GRANT` statements don't work on cPanel accounts without GRANT
   privilege (common on shared hosting) — if `grants.sql` errors with
   "GRANT command denied", set the equivalent privileges through cPanel's
   **MySQL® Databases** UI instead (whole-database SELECT/INSERT/UPDATE for
   `otbdesig_gracey`, plus DELETE for the wantlist-remove/collection-move
   actions; SELECT/INSERT/UPDATE/DELETE for `otbdesig_harper`).
4. Deploy this directory to `/pizzaparty` under the licoricepizzareviews.com
   docroot. Being a real subdirectory, WordPress's rewrite rules
   (`RewriteCond %{REQUEST_FILENAME} !-d`) leave it alone.
5. Visit `https://licoricepizzareviews.com/pizzaparty/api/index.php?action=discogs-connect`
   once, in a browser, to authorize the app against your Discogs account.
   This stores an access token in `pizzaparty_discogs_auth` — it does not
   expire, so this is a one-time step.
6. Run the sync scripts once by hand to populate the cache, then add them to
   cron (e.g. every 30 minutes):
   ```
   php /path/to/pizzaparty/sync-collection.php
   php /path/to/pizzaparty/sync-wantlist.php
   ```
   Also add the News tab's two sync scripts, on a longer interval (e.g.
   every 6 hours) — MusicBrainz's 1 req/sec limit means a full pass over a
   large collection can take a while, and neither news nor "what's playing
   nearby" needs to be fresher than that:
   ```
   php /path/to/pizzaparty/sync-news.php
   php /path/to/pizzaparty/sync-events.php
   ```
7. Open the site on your iPhone, tap the lock button, and register the
   device — from then on, wantlist edits need Face ID from that phone.
8. "Add to Home Screen" in Safari to install it as a standalone app.

## Branding

Colors and the app icon/header mark are derived from licoricepizzareviews.com's
own logo (`assets/logo.png`, `icons/*.png`) — an amber/gold vinyl-record mark.
`--accent` in `assets/app.css` is a darkened version of the logo's brightest
orange so white button text clears WCAG AA contrast; `--accent-bright` carries
the true logo color for decorative surfaces (gradients, chart fills) where no
text sits directly on it.

## Genre data

Genres/styles shown in the app come straight from Discogs' own
`basic_information.genres`/`styles` fields on each release, synced verbatim
(`sync-collection.php`, `sync-wantlist.php`) — the same values Discogs shows
on the release's own page. Discogs' genre taxonomy is broad and
crowd-sourced, so mis-tagging on individual releases does happen. Tap a bar
in the Stats tab to jump to the Collection tab pre-filtered to that genre, to
see exactly which releases are contributing to a count that looks off.
