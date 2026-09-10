<?php
declare(strict_types=1);

/**
 * A thin Last.fm client. Unlike Discogs, Last.fm's read methods
 * (album.getInfo, artist.getInfo, artist.getSimilar, artist.getTopAlbums)
 * need only an API key — no OAuth, no per-user auth — so this is much
 * simpler than discogs_oauth.php. Every call is cached in
 * pizzaparty_lastfm_cache so a "Details" expand costs at most one live
 * Last.fm request per (method, args) combination, ever.
 */

const LASTFM_API_BASE = 'https://ws.audioscrobbler.com/2.0/';
const LASTFM_CACHE_TTL_DAYS = 30;

function lastfm_config(): array {
    $cfg = config()['lastfm'] ?? null;
    if (!$cfg || empty($cfg['api_key'])) {
        throw new RuntimeException('Last.fm API key is not set in config.php.');
    }
    return $cfg;
}

/**
 * Discogs disambiguates same-named artists with a trailing "(N)" — e.g.
 * "Genesis (2)" — which Last.fm has never heard of. Strip it, and take only
 * the first credited artist when several are joined with ", " (our own
 * join format from sync-collection.php / sync-wantlist.php), since Last.fm's
 * album/artist lookups expect one name.
 */
function lastfm_clean_artist(string $artist): string {
    $first = explode(', ', $artist, 2)[0];
    return trim(preg_replace('/\s*\(\d+\)\s*$/', '', $first));
}

function lastfm_cache_get(string $key): ?array {
    $st = db()->prepare('SELECT payload FROM pizzaparty_lastfm_cache WHERE cache_key = :k AND fetched_at > :cutoff');
    $st->execute([
        ':k' => $key,
        ':cutoff' => date('Y-m-d H:i:s', time() - LASTFM_CACHE_TTL_DAYS * 86400),
    ]);
    $row = $st->fetch();
    return $row ? json_decode($row['payload'], true) : null;
}

function lastfm_cache_put(string $key, array $payload): void {
    $st = db()->prepare(
        'INSERT INTO pizzaparty_lastfm_cache (cache_key, payload, fetched_at) VALUES (:k, :p, NOW())
         ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = VALUES(fetched_at)'
    );
    $st->execute([':k' => $key, ':p' => json_encode($payload)]);
}

/** A raw, uncached Last.fm call. Returns the decoded JSON body. */
function lastfm_call(string $method, array $params): array {
    $cfg = lastfm_config();
    $query = array_merge($params, [
        'method'  => $method,
        'api_key' => $cfg['api_key'],
        'format'  => 'json',
    ]);
    $ch = curl_init(LASTFM_API_BASE . '?' . http_build_query($query));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['User-Agent: PizzaPartyApp/1.0'],
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Last.fm request failed: ' . $err);
    }
    curl_close($ch);
    $json = json_decode((string)$body, true);
    if (!is_array($json)) throw new RuntimeException('Last.fm returned an unreadable response.');
    if (isset($json['error'])) throw new RuntimeException('Last.fm: ' . ($json['message'] ?? 'unknown error'));
    return $json;
}

/** Cached wrapper around lastfm_call(). */
function lastfm_cached(string $method, array $params): array {
    ksort($params);
    $key = $method . '|' . http_build_query($params);
    $cached = lastfm_cache_get($key);
    if ($cached !== null) return $cached;
    $result = lastfm_call($method, $params);
    lastfm_cache_put($key, $result);
    return $result;
}

/**
 * Everything the "Details" panel needs for one release, in one call:
 * album stats/tags/url, similar artists, and a few albums from each of the
 * top couple of similar artists as an honest stand-in for "similar albums"
 * — Last.fm has no true album-similarity endpoint.
 */
function lastfm_detail(string $artist, string $title): array {
    $cleanArtist = lastfm_clean_artist($artist);

    $album = null;
    try {
        $r = lastfm_cached('album.getinfo', ['artist' => $cleanArtist, 'album' => $title, 'autocorrect' => 1]);
        $a = $r['album'] ?? null;
        if ($a) {
            $tags = array_map(fn($t) => $t['name'] ?? '', $a['tags']['tag'] ?? []);
            $album = [
                'url'       => $a['url'] ?? null,
                'listeners' => isset($a['listeners']) ? (int)$a['listeners'] : null,
                'playcount' => isset($a['playcount']) ? (int)$a['playcount'] : null,
                'tags'      => array_values(array_filter($tags)),
                'summary'   => isset($a['wiki']['summary']) ? trim(strip_tags($a['wiki']['summary'])) : null,
            ];
        }
    } catch (Throwable $e) {
        error_log('lastfm album.getinfo: ' . $e->getMessage());
    }

    $similarArtists = [];
    $similarAlbums = [];
    try {
        $r = lastfm_cached('artist.getsimilar', ['artist' => $cleanArtist, 'autocorrect' => 1, 'limit' => 6]);
        $rows = $r['similarartists']['artist'] ?? [];
        foreach ($rows as $sa) {
            $similarArtists[] = [
                'name'  => $sa['name'] ?? '',
                'url'   => $sa['url'] ?? null,
                'match' => isset($sa['match']) ? round((float)$sa['match'], 2) : null,
            ];
        }
        // A few albums from the top 2 similar artists, as a proxy for
        // "similar albums" — labeled honestly in the UI, not claimed as a
        // precise match.
        foreach (array_slice($similarArtists, 0, 2) as $sa) {
            try {
                $ta = lastfm_cached('artist.gettopalbums', ['artist' => $sa['name'], 'autocorrect' => 1, 'limit' => 3]);
                foreach ($ta['topalbums']['album'] ?? [] as $al) {
                    if (empty($al['name'])) continue;
                    $similarAlbums[] = [
                        'artist' => $al['artist']['name'] ?? $sa['name'],
                        'title'  => $al['name'],
                        'url'    => $al['url'] ?? null,
                    ];
                }
            } catch (Throwable $e) {
                error_log('lastfm artist.gettopalbums: ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('lastfm artist.getsimilar: ' . $e->getMessage());
    }

    return [
        'album'          => $album,
        'similarArtists' => $similarArtists,
        'similarAlbums'  => $similarAlbums,
    ];
}
