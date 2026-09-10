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

## First-time setup

1. Register a Discogs application at
   https://www.discogs.com/settings/developers to get a Consumer Key/Secret.
2. Create `config.php` (gitignored) and fill in:
   - The `db`/`db_admin` blocks — same host/user/pass as markgrace's
     `config.php` (the shared database is `otbdesig_wp298`).
   - The `discogs` block — consumer key/secret, and `callback_url` pointing
     at `https://licoricepizzareviews.com/pizzaparty/api/index.php?action=discogs-callback`.
3. Run the schema and grants, as a MySQL admin, against markgrace's database
   (`otbdesig_wp298`):
   ```
   mysql -u ADMIN -p otbdesig_wp298 < migrations/schema.sql
   mysql -u ADMIN -p otbdesig_wp298 < migrations/grants.sql
   ```
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
7. Open the site on your iPhone, tap the lock button, and register the
   device — from then on, wantlist edits need Face ID from that phone.
8. "Add to Home Screen" in Safari to install it as a standalone app.

## Icons

`icons/*.png` are placeholder glyphs generated locally — swap them for real
branded artwork before shipping (same sizes: 192, 512, 512 maskable, 180
apple-touch-icon).

## Not yet built (phase 2)

Adding a wantlist item currently requires typing in a known Discogs release
ID (see the "Add" box on the Wantlist tab). A proper search-and-pick flow
against Discogs' `/database/search` endpoint is planned but not yet built.
