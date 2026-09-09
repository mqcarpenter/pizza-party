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
 * Returns [httpStatus, decodedJsonOrNull, rawBody].
 */
function discogs_request(
    string $method,
    string $url,
    array $queryParams = [],
    ?string $token = null,
    ?string $tokenSecret = null,
    array $extraOauthParams = []
): array {
    $cfg = discogs_config();

    $oauthParams = array_merge([
        'oauth_consumer_key'     => $cfg['consumer_key'],
        'oauth_nonce'            => oauth_nonce(),
        'oauth_signature_method' => 'HMAC-SHA1',
        'oauth_timestamp'        => (string)time(),
        'oauth_version'          => '1.0',
    ], $extraOauthParams);
    if ($token !== null) $oauthParams['oauth_token'] = $token;

    $allParams = array_merge($oauthParams, $queryParams);
    $oauthParams['oauth_signature'] = oauth_signature($method, $url, $allParams, $cfg['consumer_secret'], (string)$tokenSecret);

    $authHeaderParts = [];
    foreach ($oauthParams as $k => $v) {
        $authHeaderParts[] = oauth_encode($k) . '="' . oauth_encode($v) . '"';
    }
    $authHeader = 'OAuth ' . implode(', ', $authHeaderParts);

    $fullUrl = $url;
    if ($queryParams) $fullUrl .= '?' . http_build_query($queryParams);

    $ch = curl_init($fullUrl);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . $authHeader,
            'User-Agent: ' . $cfg['user_agent'],
        ],
    ]);
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

    $pdo = db();
    $pdo->exec('DELETE FROM pizzaparty_discogs_auth'); // single-row table
    $st = $pdo->prepare(
        'INSERT INTO pizzaparty_discogs_auth (discogs_username, access_token, access_token_secret, created_at)
         VALUES (:u, :t, :s, NOW())'
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
