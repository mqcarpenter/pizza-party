<?php
declare(strict_types=1);

/**
 * Hand-rolled Discogs OAuth 1.0a (3-legged) client — no Composer dependency,
 * same philosophy as webauthn.php. Discogs access tokens for a personal-use
 * app don't expire, so this runs once via the browser (discogs-connect /
 * discogs-callback) and the resulting token/secret are stored in
 * pizzaparty_discogs_auth for every later signed request, made from
 * sync-collection.php, sync-wantlist.php, and the gated wantlist-* actions.
 */

const DISCOGS_API_BASE      = 'https://api.discogs.com';
const DISCOGS_REQUEST_TOKEN = 'https://api.discogs.com/oauth/request_token';
const DISCOGS_AUTHORIZE     = 'https://www.discogs.com/oauth/authorize';
const DISCOGS_ACCESS_TOKEN  = 'https://api.discogs.com/oauth/access_token';

function discogs_config(): array {
    $cfg = config()['discogs'] ?? null;
    if (!$cfg || empty($cfg['consumer_key']) || empty($cfg['consumer_secret'])) {
        throw new RuntimeException('Discogs consumer key/secret are not set in config.php.');
    }
    return $cfg;
}

function oauth_nonce(): string {
    return bin2hex(random_bytes(16));
}

/** Percent-encoding per RFC 3986, which is stricter than PHP's rawurlencode about a few characters — none of which appear here, but this is the spec-correct name for what rawurlencode already does. */
function oauth_encode(string $s): string {
    return rawurlencode($s);
}

function oauth_signature(string $method, string $url, array $params, string $consumerSecret, string $tokenSecret = ''): string {
    ksort($params);
    $pairs = [];
    foreach ($params as $k => $v) {
        $pairs[] = oauth_encode((string)$k) . '=' . oauth_encode((string)$v);
    }
    $baseString = strtoupper($method) . '&' . oauth_encode($url) . '&' . oauth_encode(implode('&', $pairs));
    $signingKey = oauth_encode($consumerSecret) . '&' . oauth_encode($tokenSecret);
    return base64_encode(hash_hmac('sha1', $baseString, $signingKey, true));
}

/**
 * Signs and sends a Discogs API request. $token/$tokenSecret are the stored
 * access token (or, mid-handshake, the temporary request token).
 *
 * $params means different things depending on $method: for GET it's the
 * query string (pagination, sort — participates in the OAuth1 signature
 * alongside the oauth_ params, per spec). For anything else it's sent as a
 * JSON request body — Discogs' newer write endpoints (e.g. the collection
 * instance-rating update) strictly require a JSON body and reject query
 * params with a "Field required" validation error. A JSON body is not
 * form-urlencoded, so per the OAuth1 spec it is deliberately excluded from
 * the signature base string — only the oauth_ params sign a JSON-body call.
 *
 * Returns [httpStatus, decodedJsonOrNull, rawBody].
 */
function discogs_request(
    string $method,
    string $url,
    array $params = [],
    ?string $token = null,
    ?string $tokenSecret = null,
    array $extraOauthParams = []
): array {
    $cfg = discogs_config();
    $isGet = strtoupper($method) === 'GET';

    $oauthParams = array_merge([
        'oauth_consumer_key'     => $cfg['consumer_key'],
        'oauth_nonce'            => oauth_nonce(),
        'oauth_signature_method' => 'HMAC-SHA1',
        'oauth_timestamp'        => (string)time(),
        'oauth_version'          => '1.0',
    ], $extraOauthParams);
    if ($token !== null) $oauthParams['oauth_token'] = $token;

    $signParams = $isGet ? array_merge($oauthParams, $params) : $oauthParams;
    $oauthParams['oauth_signature'] = oauth_signature($method, $url, $signParams, $cfg['consumer_secret'], (string)$tokenSecret);

    $authHeaderParts = [];
    foreach ($oauthParams as $k => $v) {
        $authHeaderParts[] = oauth_encode($k) . '="' . oauth_encode($v) . '"';
    }
    $authHeader = 'OAuth ' . implode(', ', $authHeaderParts);

    $fullUrl = $url;
    $headers = ['Authorization: ' . $authHeader, 'User-Agent: ' . $cfg['user_agent']];
    $curlOpts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ];

    if ($isGet) {
        if ($params) $fullUrl .= '?' . http_build_query($params);
    } elseif ($params) {
        $curlOpts[CURLOPT_POSTFIELDS] = json_encode($params);
        $headers[] = 'Content-Type: application/json';
    }
    $curlOpts[CURLOPT_HTTPHEADER] = $headers;

    $ch = curl_init($fullUrl);
    curl_setopt_array($ch, $curlOpts);
    $body   = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Discogs request failed: ' . $err);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode((string)$body, true);
    return [$status, is_array($json) ? $json : null, (string)$body];
}

/** Step 1: get a temporary request token, store its secret in the session for the callback. */
function discogs_start_connect(): string {
    $cfg = discogs_config();
    [$status, , $body] = discogs_request('GET', DISCOGS_REQUEST_TOKEN, [], null, null, [
        'oauth_callback' => $cfg['callback_url'],
    ]);
    if ($status !== 200) {
        throw new RuntimeException('Discogs request_token failed (HTTP ' . $status . '): ' . $body);
    }
    parse_str($body, $out);
    if (empty($out['oauth_token']) || empty($out['oauth_token_secret'])) {
        throw new RuntimeException('Discogs request_token response was missing fields.');
    }
    session_boot();
    $_SESSION['pp_discogs_req_secret'] = $out['oauth_token_secret'];
    return DISCOGS_AUTHORIZE . '?oauth_token=' . oauth_encode($out['oauth_token']);
}

/** Step 2: exchange the verifier Discogs redirected back with for a permanent access token, and store it. */
function discogs_finish_connect(string $oauthToken, string $verifier): void {
    session_boot();
    $reqSecret = $_SESSION['pp_discogs_req_secret'] ?? null;
    unset($_SESSION['pp_discogs_req_secret']);
    if (!$reqSecret) {
        throw new RuntimeException('No Discogs connect flow in progress — start again.');
    }

    [$status, , $body] = discogs_request('POST', DISCOGS_ACCESS_TOKEN, [], $oauthToken, (string)$reqSecret, [
        'oauth_verifier' => $verifier,
    ]);
    if ($status !== 200) {
        throw new RuntimeException('Discogs access_token exchange failed (HTTP ' . $status . '): ' . $body);
    }
    parse_str($body, $out);
    if (empty($out['oauth_token']) || empty($out['oauth_token_secret'])) {
        throw new RuntimeException('Discogs access_token response was missing fields.');
    }

    // Confirm the identity so the collection/wantlist sync knows which
    // username's data to pull.
    [$idStatus, $idJson] = discogs_signed_request('GET', DISCOGS_API_BASE . '/oauth/identity', [], $out['oauth_token'], $out['oauth_token_secret']);
    if ($idStatus !== 200 || empty($idJson['username'])) {
        throw new RuntimeException('Could not confirm the Discogs account identity after connecting.');
    }

    // A fixed id keeps this a genuine upsert — no DELETE privilege required,
    // which the web app's account (least-privilege) deliberately doesn't have.
    $st = db()->prepare(
        'INSERT INTO pizzaparty_discogs_auth (id, discogs_username, access_token, access_token_secret, created_at)
         VALUES (1, :u, :t, :s, NOW())
         ON DUPLICATE KEY UPDATE
            discogs_username = VALUES(discogs_username),
            access_token = VALUES(access_token),
            access_token_secret = VALUES(access_token_secret),
            updated_at = NOW()'
    );
    $st->execute([
        ':u' => $idJson['username'],
        ':t' => $out['oauth_token'],
        ':s' => $out['oauth_token_secret'],
    ]);
}

/** Reads the stored access token, throwing if the connect flow hasn't run yet. */
function discogs_auth_row(): array {
    $row = db()->query('SELECT discogs_username, access_token, access_token_secret FROM pizzaparty_discogs_auth LIMIT 1')->fetch();
    if (!$row) {
        throw new RuntimeException('Discogs is not connected yet — visit ?action=discogs-connect.');
    }
    return $row;
}

/** A signed request using the stored access token. Returns [status, decodedJson]. */
function discogs_signed_request(string $method, string $url, array $queryParams = [], ?string $token = null, ?string $tokenSecret = null): array {
    if ($token === null) {
        $auth = discogs_auth_row();
        $token = $auth['access_token'];
        $tokenSecret = $auth['access_token_secret'];
    }
    [$status, $json, $body] = discogs_request($method, $url, $queryParams, $token, $tokenSecret);
    return [$status, $json ?? ['_raw' => $body]];
}

// ---- resolving a bare (artist, title) to a real Discogs release --------
//
// Used to give a Last.fm-sourced "similar album" suggestion an addable
// Discogs release id, rather than only a dead-end link to Last.fm.

const DISCOGS_RESOLVE_CACHE_TTL_DAYS = 30;

function discogs_resolve_cache_get(string $key): ?array {
    $st = db()->prepare('SELECT payload FROM pizzaparty_discogs_cache WHERE cache_key = :k AND fetched_at > :cutoff');
    $st->execute([':k' => $key, ':cutoff' => date('Y-m-d H:i:s', time() - DISCOGS_RESOLVE_CACHE_TTL_DAYS * 86400)]);
    $row = $st->fetch();
    return $row ? json_decode($row['payload'], true) : null;
}

function discogs_resolve_cache_put(string $key, ?array $payload): void {
    $st = db()->prepare(
        'INSERT INTO pizzaparty_discogs_cache (cache_key, payload, fetched_at) VALUES (:k, :p, NOW())
         ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = VALUES(fetched_at)'
    );
    // json_encode(null) is the literal string "null", which json_decode()
    // reads back as null — a negative (no match found) is cached too, so a
    // known-obscure title doesn't retry Discogs on every expand.
    $st->execute([':k' => $key, ':p' => json_encode($payload)]);
}

/**
 * Finds the release Discogs itself considers the definitive pressing of an
 * (artist, title) — a master release's `main_release` — rather than every
 * individual country/format variant a plain release search would return.
 * Returns null if nothing matched. Cached, since this is called once per
 * "similar album" suggestion and that data almost never changes.
 */
function discogs_resolve_release(string $artist, string $title): ?array {
    // v3: resolves to an actual Vinyl version instead of the master's
    // main_release (which could be any format). Bumped again so rows
    // cached under the old shape/semantics refetch instead of serving a
    // possibly-non-vinyl release for up to DISCOGS_RESOLVE_CACHE_TTL_DAYS.
    $key = 'resolve|v3|' . strtolower($artist . '|' . $title);
    // A cache miss and a cached "no match" both decode to null here, so an
    // unresolvable title gets retried against Discogs once per TTL window
    // rather than being remembered as permanently unresolvable — an
    // acceptable cost for the simplicity.
    $cached = discogs_resolve_cache_get($key);
    if ($cached !== null) return $cached ?: null;

    try {
        [$status, $json] = discogs_signed_request('GET', DISCOGS_API_BASE . '/database/search', [
            'q' => $artist . ' ' . $title, 'type' => 'master', 'per_page' => 1,
        ]);
        $result = $status === 200 ? ($json['results'][0] ?? null) : null;
        if (!$result) {
            discogs_resolve_cache_put($key, null);
            return null;
        }

        // This is a vinyl collection tool, and a master's main_release is
        // whatever format Discogs happens to default to for that album --
        // often a CD or a digital release, not a pressing anyone here could
        // add to a wantlist. Ask the master for its actual vinyl versions
        // instead of trusting main_release at all.
        $masterId = $result['id'] ?? null;
        $releaseId = null;
        if ($masterId) {
            [$vStatus, $vJson] = discogs_signed_request(
                'GET', DISCOGS_API_BASE . '/masters/' . $masterId . '/versions',
                ['format' => 'Vinyl', 'per_page' => 1, 'sort' => 'released', 'sort_order' => 'asc']
            );
            if ($vStatus === 200) $releaseId = $vJson['versions'][0]['id'] ?? null;
        }
        if (!$releaseId) {
            // No vinyl pressing of this album exists on Discogs at all --
            // nothing here is addable, so there's nothing to resolve to.
            discogs_resolve_cache_put($key, null);
            return null;
        }

        $resolved = [
            'releaseId' => (int)$releaseId,
            'thumb'     => $result['thumb'] ?? null,
            'year'      => $result['year'] ?? null,
        ];

        // The search/master result's year is the master's, not necessarily
        // this specific pressing's -- and carries no country or price at
        // all. Worth a second call: this is the exact release we're about
        // to let someone add to their wantlist, so "which pressing is
        // this?" (year, country, going rate) is the whole point of
        // resolving one in the first place, not a nice-to-have.
        try {
            [$rStatus, $rJson] = discogs_signed_request('GET', DISCOGS_API_BASE . '/releases/' . $releaseId);
            if ($rStatus === 200) {
                if (!empty($rJson['year'])) $resolved['year'] = $rJson['year'];
                if (!empty($rJson['country'])) $resolved['country'] = $rJson['country'];
            }
        } catch (Throwable $e) {
            error_log('discogs_resolve_release (release details): ' . $e->getMessage());
        }

        // Discogs has no single "median sale price" field; the closest real
        // signal is its own price-suggestion tool, which returns a
        // suggested price per condition grade. A median ACROSS those grades
        // isn't a price anyone would actually pay, but it's a fair, honest
        // single number to show at a glance -- computed from real values
        // Discogs returned, not invented.
        try {
            [$pStatus, $pJson] = discogs_signed_request('GET',
                DISCOGS_API_BASE . '/marketplace/price_suggestions/' . $releaseId);
            if ($pStatus === 200 && is_array($pJson)) {
                $values = [];
                $currency = null;
                foreach ($pJson as $grade) {
                    if (!isset($grade['value'])) continue;
                    $values[] = (float) $grade['value'];
                    $currency = $currency ?? ($grade['currency'] ?? null);
                }
                if ($values) {
                    sort($values);
                    $mid = (int) floor((count($values) - 1) / 2);
                    $median = count($values) % 2
                        ? $values[$mid]
                        : ($values[$mid] + $values[$mid + 1]) / 2;
                    $resolved['medianPrice'] = round($median, 2);
                    $resolved['priceCurrency'] = $currency ?? 'USD';
                }
            }
        } catch (Throwable $e) {
            error_log('discogs_resolve_release (price suggestions): ' . $e->getMessage());
        }

        discogs_resolve_cache_put($key, $resolved);
        return $resolved;
    } catch (Throwable $e) {
        error_log('discogs_resolve_release: ' . $e->getMessage());
        return null;
    }
}
