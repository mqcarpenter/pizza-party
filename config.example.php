<?php
// Copy to config.php and fill in. config.php is gitignored — never commit credentials.
//
// This app PIGGYBACKS on the same MySQL database and accounts as the
// markgrace card tracker, rather than provisioning new ones. Use the exact
// same host/name/user/pass values already sitting in markgrace's config.php
// on the server (otbdesig_gracey / otbdesig_harper), and see
// migrations/grants.sql for the additional per-table grants those accounts
// need for pizza-party's tables.
return [
    // The WEB APP account — same one markgrace uses (otbdesig_gracey).
    // Extended (see grants.sql) with SELECT/INSERT/UPDATE on wantlist_items,
    // collection_items and devices, but no DELETE and nothing on markgrace's
    // own tables beyond what it already had.
    'db' => [
        'host'    => 'localhost',
        'name'    => 'markgrace',
        'user'    => 'otbdesig_gracey',
        'pass'    => 'CHANGE_ME_APP',
        'charset' => 'utf8mb4',
    ],

    // The SYNC account — same one markgrace's seed.php uses (otbdesig_harper),
    // used only by sync-collection.php / sync-wantlist.php on the command
    // line (cron). Extended with full INSERT/UPDATE/DELETE on the cache
    // tables. Web requests never use it.
    // Delete this block and the sync scripts fall back to the 'db' account
    // above.
    'db_admin' => [
        'host'    => 'localhost',
        'name'    => 'markgrace',
        'user'    => 'otbdesig_harper',
        'pass'    => 'CHANGE_ME_SYNC',
        'charset' => 'utf8mb4',
    ],

    // Set true to return the real error text from api/ instead of a bare
    // "Server error." Useful while installing; turn it off afterwards.
    'debug' => false,

    // Anyone with the URL can VIEW the page unless you set a passphrase.
    // Leave null for an open page; set a string to require sign-in to read.
    // This is separate from the passkey below, which governs writing.
    'passphrase' => null,

    // ---- Discogs API ------------------------------------------------
    //
    // Register an application at https://www.discogs.com/settings/developers
    // to get a Consumer Key/Secret. This app uses 3-legged OAuth 1.0a: you
    // authorize once (visit api/index.php?action=discogs-connect), and the
    // resulting access token/secret are stored in the discogs_auth table —
    // NOT here — so they survive independently of this file.
    'discogs' => [
        'consumer_key'    => 'CHANGE_ME',
        'consumer_secret' => 'CHANGE_ME',
        // Sent as the User-Agent header on every Discogs API call, as their
        // API terms require. Put something identifying, not a browser UA.
        'user_agent'      => 'PizzaPartyApp/1.0 +https://licoricepizzareviews.com/pizzaparty',
        // Discogs must redirect back here after you authorize the app.
        'callback_url'    => 'https://licoricepizzareviews.com/pizzaparty/api/index.php?action=discogs-callback',
    ],

    // ---- Passkeys: who may CHANGE the wantlist ----------------------
    //
    // Viewing stays open. Adding/removing/editing a wantlist item requires a
    // signature from a registered device — on an iPhone, a Face ID prompt.
    // Until you register the first device the page behaves exactly as any
    // normal read/write app; the moment you register one, writes are gated.
    //
    // Register from the iPhone itself: open the page and tap "Unprotected".

    // A one-time key for enrolling ADDITIONAL devices later, or for
    // re-enrolling after you erase the phone. The first device needs no key.
    // Generate one with:  php -r 'echo bin2hex(random_bytes(16)), "\n";'
    // Leave null and no further device can ever enrol over the web — the
    // safest setting, since recovery is then a DELETE in phpMyAdmin.
    'enroll_key' => null,

    // How long one Face ID prompt keeps writes unlocked, in seconds.
    'passkey_ttl' => 900,

    // Normally detected from the request. Set these only if the app is
    // reached at more than one hostname, or sits behind a proxy that
    // rewrites Host — a passkey is bound to exactly one domain.
    // 'rp_id'   => 'licoricepizzareviews.com',
    // 'origins' => ['https://licoricepizzareviews.com'],
];
