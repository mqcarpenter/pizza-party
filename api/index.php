<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';
require __DIR__ . '/../discogs_oauth.php';
require __DIR__ . '/../lastfm.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * Without this, any uncaught PDO/HTTP error dies as a blank 500 with no
 * body, which tells the browser (and you) nothing. Always answer with JSON.
 * Set 'debug' => true in config.php to include the real message.
 */
function fail(string $msg, ?Throwable $e = null): void {
    if ($e) error_log('pizzaparty: ' . $e->getMessage());
    http_response_code(500);
    $out = ['error' => $msg];
    if ($e && !empty(config()['debug'])) {
        $out['detail'] = $e->getMessage();
        $out['where']  = basename($e->getFile()) . ':' . $e->getLine();
    }
    echo json_encode($out);
    exit;
}
set_exception_handler(function (Throwable $e) { fail('Server error.', $e); });
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) http_response_code(500);
        error_log('pizzaparty fatal: ' . $e['message']);
        $out = ['error' => 'Server error.'];
        $dbg = false;
        try { $dbg = !empty(config()['debug']); } catch (Throwable $ignored) {}
        if ($dbg) {
            $out['detail'] = $e['message'];
            $out['where']  = $e['file'] . ':' . $e['line'];
        } else {
            $out['detail'] = 'See server error log.';
        }
        echo json_encode($out);
    }
});

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

/** Parsed request body. Memoised — several handlers read it more than once. */
function body(): array {
    static $parsed = null;
    if ($parsed !== null) return $parsed;
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return $parsed = [];
    $d = json_decode($raw, true);
    return $parsed = (is_array($d) ? $d : []);
}
function out(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_SLASHES);
    exit;
}

// ---- auth (passphrase gate on reading, separate from the passkey below) ----
if ($action === 'login') {
    $pass = config()['passphrase'] ?? null;
    if ($pass === null || $pass === '') out(['ok' => true, 'locked' => false]);
    $given = (string)(body()['passphrase'] ?? '');
    if (hash_equals((string)$pass, $given)) {
        if (session_status() === PHP_SESSION_NONE) session_start();
        session_regenerate_id(true);
        $_SESSION['pp_ok'] = true;
        out(['ok' => true]);
    }
    usleep(400000);
    out(['ok' => false, 'error' => 'Wrong passphrase.'], 401);
}

if ($action === 'session') {
    $pass = config()['passphrase'] ?? null;
    out([
        'protected'        => !($pass === null || $pass === ''),
        'locked'           => locked(),
        'devices'          => devices_registered(),
        'writeWindow'      => write_window(),
        'discogsConnected' => discogs_connected(),
        'sync'             => sync_state(),
    ]);
}

if ($action === 'logout') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION = [];
    session_destroy();
    out(['ok' => true]);
}

// ---- discogs connect (one-time OAuth1 setup, run once from a browser) ----
if ($action === 'discogs-connect') {
    require_unlocked();
    try {
        $url = discogs_start_connect();
    } catch (Throwable $e) {
        fail('Could not start the Discogs connection.', $e);
    }
    header('Location: ' . $url);
    exit;
}

if ($action === 'discogs-callback') {
    require_unlocked();
    $token    = (string)($_GET['oauth_token'] ?? '');
    $verifier = (string)($_GET['oauth_verifier'] ?? '');
    if ($token === '' || $verifier === '') out(['error' => 'Missing oauth_token/oauth_verifier.'], 400);
    try {
        discogs_finish_connect($token, $verifier);
    } catch (Throwable $e) {
        fail('Could not complete the Discogs connection.', $e);
    }
    // A friendly landing page beats raw JSON for a flow the user just did by hand.
    header('Content-Type: text/html; charset=utf-8');
    echo '<p>Discogs connected. <a href="../">Return to Pizza Party</a>.</p>';
    exit;
}

// ---- passkeys --------------------------------------------------------
//
// Reading the collection/wantlist never needs a passkey. Adding, removing,
// or editing a wantlist item does, as soon as at least one device is
// registered.

function may_register(): bool {
    if (devices_registered() === 0) return true;
    if (write_window() > 0) return true;
    $key = config()['enroll_key'] ?? null;
    if ($key === null || $key === '') return false;
    $given = (string)(body()['enroll_key'] ?? '');
    return $given !== '' && hash_equals((string)$key, $given);
}

if ($action === 'passkey-register-options') {
    require_unlocked();
    if (!may_register()) {
        usleep(400000);
        out(['error' => 'Registration is closed. Use the enrolment key from config.php.'], 403);
    }
    $existing = db()->query('SELECT credential_id FROM pizzaparty_devices')->fetchAll();
    out([
        'challenge' => new_challenge('register'),
        'rp'        => ['id' => rp_id(), 'name' => 'Pizza Party'],
        'user'      => [
            'id'          => b64url_encode('pizzaparty'),
            'name'        => 'collection',
            'displayName' => 'Pizza Party Collection',
        ],
        'pubKeyCredParams' => [
            ['type' => 'public-key', 'alg' => -7],     // ES256 — Apple, Android
            ['type' => 'public-key', 'alg' => -257],   // RS256 — Windows Hello
        ],
        'excludeCredentials' => array_map(static function (array $r): array {
            return ['type' => 'public-key', 'id' => b64url_encode($r['credential_id'])];
        }, $existing),
        'authenticatorSelection' => [
            'authenticatorAttachment' => 'platform',
            'residentKey'             => 'preferred',
            'userVerification'        => 'required',
        ],
        'timeout'     => 60000,
        'attestation' => 'none',
    ]);
}

if ($method === 'POST' && $action === 'passkey-register') {
    require_unlocked();
    if (!may_register()) {
        usleep(400000);
        out(['error' => 'Registration is closed.'], 403);
    }
    $d = body();
    try {
        $reg = webauthn_verify_registration(
            b64url_decode((string)($d['clientDataJSON'] ?? '')),
            b64url_decode((string)($d['attestationObject'] ?? '')),
            take_challenge('register'),
            rp_id(),
            allowed_origins()
        );
    } catch (Throwable $e) {
        error_log('pizzaparty passkey register: ' . $e->getMessage());
        out(['error' => $e->getMessage()], 400);
    }

    $label = trim((string)($d['label'] ?? ''));
    if ($label === '') $label = 'iPhone';

    $st = db()->prepare(
        'INSERT INTO pizzaparty_devices (credential_id, public_key, sign_count, label, created_at)
         VALUES (:c, :p, :s, :l, NOW())'
    );
    try {
        $st->execute([
            ':c' => $reg['credential_id'],
            ':p' => $reg['public_key'],
            ':s' => $reg['sign_count'],
            ':l' => mb_substr($label, 0, 64),
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') out(['error' => 'That device is already registered.'], 409);
        throw $e;
    }

    open_write_window();
    out(['ok' => true, 'label' => $label, 'writeWindow' => write_window()]);
}

if ($action === 'passkey-auth-options') {
    require_unlocked();
    if (devices_registered() === 0) out(['error' => 'No device registered yet.'], 409);
    $rows = db()->query('SELECT credential_id FROM pizzaparty_devices')->fetchAll();
    out([
        'challenge' => new_challenge('auth'),
        'rpId'      => rp_id(),
        'allowCredentials' => array_map(static function (array $r): array {
            return ['type' => 'public-key', 'id' => b64url_encode($r['credential_id'])];
        }, $rows),
        'userVerification' => 'required',
        'timeout'          => 60000,
    ]);
}

if ($method === 'POST' && $action === 'passkey-auth') {
    require_unlocked();
    $d     = body();
    $rawId = b64url_decode((string)($d['id'] ?? ''));
    if ($rawId === '') out(['error' => 'Missing credential id.'], 400);

    $st = db()->prepare('SELECT id, public_key, sign_count FROM pizzaparty_devices WHERE credential_id = ?');
    $st->execute([$rawId]);
    $dev = $st->fetch();
    if (!$dev) {
        usleep(400000);
        out(['error' => 'That device is not registered.'], 403);
    }

    try {
        $count = webauthn_verify_assertion(
            b64url_decode((string)($d['clientDataJSON'] ?? '')),
            b64url_decode((string)($d['authenticatorData'] ?? '')),
            b64url_decode((string)($d['signature'] ?? '')),
            take_challenge('auth'),
            (string)$dev['public_key'],
            (int)$dev['sign_count'],
            rp_id(),
            allowed_origins()
        );
    } catch (Throwable $e) {
        error_log('pizzaparty passkey auth: ' . $e->getMessage());
        usleep(400000);
        out(['error' => $e->getMessage()], 403);
    }

    $up = db()->prepare('UPDATE pizzaparty_devices SET sign_count = :s, last_used_at = NOW() WHERE id = :id');
    $up->execute([':s' => $count, ':id' => $dev['id']]);

    open_write_window();
    out(['ok' => true, 'writeWindow' => write_window()]);
}

if ($method === 'POST' && $action === 'passkey-lock') {
    session_boot();
    unset($_SESSION['pp_write_until']);
    out(['ok' => true, 'writeWindow' => 0]);
}

// ---- reads: collection / wantlist (from the local cache) -------------

function row_to_item(array $r, bool $isWant = false): array {
    $out = [
        'releaseId' => (int)$r['release_id'],
        'artist'    => $r['artist'],
        'title'     => $r['title'],
        'year'      => $r['year'] !== null ? (int)$r['year'] : null,
        'format'    => $r['format'],
        'label'     => $r['label'],
        'genres'    => $r['genres'],
        'styles'    => $r['styles'],
        'thumb'     => $r['thumb_url'],
        'notes'     => $r['notes'],
        'rating'    => isset($r['rating']) && $r['rating'] !== null ? (int)$r['rating'] : null,
        'dateAdded' => $r['date_added'],
    ];
    if (!$isWant) $out['instanceId'] = (int)$r['instance_id'];
    return $out;
}

if ($method === 'GET' && $action === 'collection') {
    require_unlocked();
    $rows = db()->query(
        'SELECT release_id, instance_id, artist, title, year, format, label, genres, styles, thumb_url, notes, rating, date_added
           FROM pizzaparty_collection_items ORDER BY artist ASC, year ASC'
    )->fetchAll();
    out(['items' => array_map(fn($r) => row_to_item($r, false), $rows)]);
}

if ($method === 'GET' && $action === 'wantlist') {
    require_unlocked();
    $rows = db()->query(
        'SELECT release_id, artist, title, year, format, label, genres, styles, thumb_url, notes, rating, date_added
           FROM pizzaparty_wantlist_items ORDER BY artist ASC, year ASC'
    )->fetchAll();
    out(['items' => array_map(fn($r) => row_to_item($r, true), $rows)]);
}

// ---- live Discogs search (read-only, ungated) --------------------------

if ($method === 'GET' && $action === 'search') {
    require_unlocked();
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') out(['error' => 'Missing q.'], 400);

    // This is a vinyl collection tool -- Discogs' own 'format' filter keeps
    // CD/cassette/etc. pressings out of the results entirely rather than
    // filtering them client-side after the fact.
    [$status, $json] = discogs_signed_request('GET', DISCOGS_API_BASE . '/database/search', [
        'q' => $q, 'type' => 'release', 'format' => 'Vinyl', 'per_page' => 25, 'page' => 1,
    ]);
    if ($status !== 200) out(['error' => 'Discogs search failed.', 'detail' => $json], 502);

    $items = array_map(function ($r) {
        // Discogs search results title releases as "Artist - Title" — split
        // it so the card can show them the same way as everything else.
        $title = (string)($r['title'] ?? '');
        $artist = null;
        if (strpos($title, ' - ') !== false) {
            [$artist, $title] = explode(' - ', $title, 2);
        }
        return [
            'releaseId' => $r['id'] ?? null,
            'artist'    => $artist,
            'title'     => $title,
            'year'      => $r['year'] ?? null,
            'format'    => implode(', ', $r['format'] ?? []),
            'label'     => implode(', ', $r['label'] ?? []),
            'genres'    => implode(', ', $r['genre'] ?? []),
            'styles'    => implode(', ', $r['style'] ?? []),
            'thumb'     => $r['thumb'] ?: null,
        ];
    }, $json['results'] ?? []);
    out(['items' => $items]);
}

// ---- gated writes: wantlist add / remove / note -----------------------

if ($method === 'POST' && $action === 'wantlist-add') {
    require_unlocked();
    require_write_access();
    $d = body();
    $releaseId = (int)($d['releaseId'] ?? 0);
    if ($releaseId <= 0) out(['error' => 'Missing releaseId.'], 400);

    $auth = discogs_auth_row();
    $params = [];
    if (!empty($d['notes'])) $params['notes'] = (string)$d['notes'];
    if (!empty($d['rating'])) $params['rating'] = (int)$d['rating'];

    [$status, $json] = discogs_signed_request(
        'PUT',
        DISCOGS_API_BASE . '/users/' . rawurlencode($auth['discogs_username']) . '/wants/' . $releaseId,
        $params
    );
    if ($status !== 201 && $status !== 200) {
        out(['error' => 'Discogs rejected the add.', 'detail' => $json], 502);
    }

    $info = $json['basic_information'] ?? [];
    $artists = array_map(fn($a) => $a['name'] ?? '', $info['artists'] ?? []);
    $labels  = array_map(fn($l) => $l['name'] ?? '', $info['labels'] ?? []);
    $formats = array_map(fn($f) => $f['name'] ?? '', $info['formats'] ?? []);

    $st = db()->prepare(
        'INSERT INTO pizzaparty_wantlist_items
            (release_id, artist, title, year, format, label, genres, styles, thumb_url, notes, rating, date_added, raw_json)
         VALUES (:release_id, :artist, :title, :year, :format, :label, :genres, :styles, :thumb_url, :notes, :rating, NOW(), :raw_json)
         ON DUPLICATE KEY UPDATE
            notes = VALUES(notes), rating = VALUES(rating), raw_json = VALUES(raw_json)'
    );
    $st->execute([
        ':release_id' => $releaseId,
        ':artist'     => implode(', ', array_filter($artists)),
        ':title'      => $info['title'] ?? null,
        ':year'       => $info['year'] ?? null,
        ':format'     => implode(', ', array_filter($formats)),
        ':label'      => implode(', ', array_filter($labels)),
        ':genres'     => implode(', ', $info['genres'] ?? []),
        ':styles'     => implode(', ', $info['styles'] ?? []),
        ':thumb_url'  => $info['thumb'] ?? null,
        ':notes'      => $json['notes'] ?? ($params['notes'] ?? null),
        ':rating'     => $json['rating'] ?? ($params['rating'] ?? null),
        ':raw_json'   => json_encode($json),
    ]);
    out(['ok' => true, 'releaseId' => $releaseId]);
}

if ($method === 'POST' && $action === 'wantlist-remove') {
    require_unlocked();
    require_write_access();
    $d = body();
    $releaseId = (int)($d['releaseId'] ?? 0);
    if ($releaseId <= 0) out(['error' => 'Missing releaseId.'], 400);

    $auth = discogs_auth_row();
    [$status, $json] = discogs_signed_request(
        'DELETE',
        DISCOGS_API_BASE . '/users/' . rawurlencode($auth['discogs_username']) . '/wants/' . $releaseId
    );
    if ($status !== 204 && $status !== 200) {
        out(['error' => 'Discogs rejected the removal.', 'detail' => $json], 502);
    }

    db()->prepare('DELETE FROM pizzaparty_wantlist_items WHERE release_id = ?')->execute([$releaseId]);
    out(['ok' => true, 'releaseId' => $releaseId]);
}

if ($method === 'POST' && $action === 'wantlist-to-collection') {
    require_unlocked();
    require_write_access();
    $d = body();
    $releaseId = (int)($d['releaseId'] ?? 0);
    if ($releaseId <= 0) out(['error' => 'Missing releaseId.'], 400);

    $wst = db()->prepare(
        'SELECT artist, title, year, format, label, genres, styles, thumb_url
           FROM pizzaparty_wantlist_items WHERE release_id = ?'
    );
    $wst->execute([$releaseId]);
    $cached = $wst->fetch();
    if (!$cached) out(['error' => 'That release is not on your wantlist.'], 404);

    $auth = discogs_auth_row();

    // Discogs has no "move" endpoint — add to a real collection folder (1 =
    // the default "Uncategorized" folder; 0 is the virtual "All" folder and
    // can't be added to directly), then remove the wantlist entry.
    [$addStatus, $addJson] = discogs_signed_request(
        'POST',
        DISCOGS_API_BASE . '/users/' . rawurlencode($auth['discogs_username']) . '/collection/folders/1/releases/' . $releaseId
    );
    if ($addStatus !== 201 && $addStatus !== 200) {
        out(['error' => 'Discogs rejected adding it to your collection.', 'detail' => $addJson], 502);
    }
    $instanceId = $addJson['instance_id'] ?? null;
    if (!$instanceId) out(['error' => 'Discogs did not return an instance id.'], 502);

    [$rmStatus, $rmJson] = discogs_signed_request(
        'DELETE',
        DISCOGS_API_BASE . '/users/' . rawurlencode($auth['discogs_username']) . '/wants/' . $releaseId
    );
    if ($rmStatus !== 204 && $rmStatus !== 200) {
        // The collection add already succeeded — leaving the wantlist entry
        // behind is a harmless duplicate the next sync will reconcile,
        // better than losing track of the successful add.
        error_log('wantlist-to-collection: collection add ok but wantlist removal failed: ' . json_encode($rmJson));
    }

    $cst = db()->prepare(
        'INSERT INTO pizzaparty_collection_items
            (release_id, instance_id, folder_id, artist, title, year, format, label, genres, styles, thumb_url, date_added, raw_json)
         VALUES (:release_id, :instance_id, 1, :artist, :title, :year, :format, :label, :genres, :styles, :thumb_url, NOW(), :raw_json)
         ON DUPLICATE KEY UPDATE
            release_id = VALUES(release_id), folder_id = VALUES(folder_id), raw_json = VALUES(raw_json)'
    );
    $cst->execute([
        ':release_id'  => $releaseId,
        ':instance_id' => $instanceId,
        ':artist'      => $cached['artist'],
        ':title'       => $cached['title'],
        ':year'        => $cached['year'],
        ':format'      => $cached['format'],
        ':label'       => $cached['label'],
        ':genres'      => $cached['genres'],
        ':styles'      => $cached['styles'],
        ':thumb_url'   => $cached['thumb_url'],
        ':raw_json'    => json_encode($addJson),
    ]);
    db()->prepare('DELETE FROM pizzaparty_wantlist_items WHERE release_id = ?')->execute([$releaseId]);

    $item = row_to_item(array_merge($cached, [
        'release_id'  => $releaseId,
        'instance_id' => $instanceId,
        'notes'       => null,
        'rating'      => null,
        'date_added'  => date('Y-m-d H:i:s'),
    ]), false);
    out(['ok' => true, 'releaseId' => $releaseId, 'item' => $item]);
}

if ($method === 'POST' && $action === 'wantlist-note') {
    require_unlocked();
    require_write_access();
    $d = body();
    $releaseId = (int)($d['releaseId'] ?? 0);
    if ($releaseId <= 0) out(['error' => 'Missing releaseId.'], 400);

    $auth = discogs_auth_row();
    $params = [];
    if (isset($d['notes'])) $params['notes'] = (string)$d['notes'];
    if (isset($d['rating'])) $params['rating'] = (int)$d['rating'];

    [$status, $json] = discogs_signed_request(
        'POST',
        DISCOGS_API_BASE . '/users/' . rawurlencode($auth['discogs_username']) . '/wants/' . $releaseId,
        $params
    );
    if ($status !== 200) {
        out(['error' => 'Discogs rejected the update.', 'detail' => $json], 502);
    }

    $st = db()->prepare('UPDATE pizzaparty_wantlist_items SET notes = :n, rating = :r WHERE release_id = :id');
    $st->execute([
        ':n'  => $params['notes'] ?? null,
        ':r'  => $params['rating'] ?? null,
        ':id' => $releaseId,
    ]);
    out(['ok' => true, 'releaseId' => $releaseId]);
}

// ---- collection rating (Discogs' own per-instance 0-5 rating) --------

if ($method === 'POST' && $action === 'collection-rate') {
    require_unlocked();
    require_write_access();
    $d = body();
    $instanceId = (int)($d['instanceId'] ?? 0);
    $rating = isset($d['rating']) ? (int)$d['rating'] : 0;
    if ($instanceId <= 0) out(['error' => 'Missing instanceId.'], 400);
    if ($rating < 0 || $rating > 5) out(['error' => 'Rating must be 0-5.'], 400);

    $rowSt = db()->prepare('SELECT release_id, folder_id FROM pizzaparty_collection_items WHERE instance_id = ?');
    $rowSt->execute([$instanceId]);
    $rec = $rowSt->fetch();
    if (!$rec) out(['error' => 'Unknown collection item.'], 404);

    $auth = discogs_auth_row();
    [$status, $json] = discogs_signed_request(
        'POST',
        DISCOGS_API_BASE . '/users/' . rawurlencode($auth['discogs_username']) .
            '/collection/folders/' . $rec['folder_id'] . '/releases/' . $rec['release_id'] . '/instances/' . $instanceId,
        ['rating' => $rating]
    );
    if ($status !== 204 && $status !== 200) {
        out(['error' => 'Discogs rejected the rating update.', 'detail' => $json], 502);
    }

    db()->prepare('UPDATE pizzaparty_collection_items SET rating = :r WHERE instance_id = :id')
        ->execute([':r' => $rating ?: null, ':id' => $instanceId]);
    out(['ok' => true, 'instanceId' => $instanceId, 'rating' => $rating ?: null]);
}

// ---- Last.fm enrichment (read-only, ungated — links/stats/similar) ----

if ($method === 'GET' && $action === 'lastfm-detail') {
    require_unlocked();
    $artist = trim((string)($_GET['artist'] ?? ''));
    $title  = trim((string)($_GET['title'] ?? ''));
    if ($artist === '' || $title === '') out(['error' => 'Missing artist/title.'], 400);
    try {
        out(lastfm_detail($artist, $title));
    } catch (Throwable $e) {
        fail('Could not load Last.fm data.', $e);
    }
}

// ---- News tab: articles/new-releases + nearby events (read-only) -----
// Both are pure reads off cache tables kept fresh by sync-news.php /
// sync-events.php (cron) -- the web app never calls MusicBrainz, Google
// News, or SeatGeek directly.

if ($method === 'GET' && $action === 'news') {
    require_unlocked();
    $rows = db()->query(
        'SELECT artist, kind, headline, excerpt, image_url, url, source, published_at
           FROM pizzaparty_news_items
          ORDER BY (published_at IS NULL), published_at DESC
          LIMIT 300'
    )->fetchAll();
    out(['items' => array_map(fn($r) => [
        'artist'      => $r['artist'],
        'kind'        => $r['kind'],
        'headline'    => $r['headline'],
        'excerpt'     => $r['excerpt'],
        'image'       => $r['image_url'],
        'url'         => $r['url'],
        'source'      => $r['source'],
        'publishedAt' => $r['published_at'],
    ], $rows)]);
}

if ($method === 'GET' && $action === 'events') {
    require_unlocked();
    $rows = db()->query(
        'SELECT external_id, source, artist, title, venue_name, venue_city, venue_state, region, starts_at, url
           FROM pizzaparty_events_cache
          WHERE starts_at IS NULL OR starts_at >= NOW()
          ORDER BY (starts_at IS NULL), starts_at ASC
          LIMIT 200'
    )->fetchAll();
    out(['items' => array_map(fn($r) => [
        'id'        => $r['external_id'],
        'source'    => $r['source'],
        'artist'    => $r['artist'],
        'title'     => $r['title'],
        'venueName' => $r['venue_name'],
        'venueCity' => $r['venue_city'],
        'venueState'=> $r['venue_state'],
        'region'    => $r['region'],
        'startsAt'  => $r['starts_at'],
        'url'       => $r['url'],
    ], $rows)]);
}

out(['error' => 'Unknown endpoint.'], 404);
