<?php
declare(strict_types=1);

/**
 * musicbrainz.php
 * Detects a genuinely NEW release for an artist in the collection/wantlist,
 * for the News tab. Read-only, keyless — MusicBrainz's API needs nothing
 * but a descriptive User-Agent (their API etiquette, not a hard
 * requirement, but they do rate-limit/block generic ones).
 *
 * MusicBrainz enforces 1 request/second per IP, strictly. Every call here
 * goes through musicbrainz_request(), which sleeps to hold that pace, so a
 * caller (sync-news.php) can just call these in a loop without its own
 * rate-limiting logic.
 */

const MUSICBRAINZ_API_BASE = 'https://musicbrainz.org/ws/2';
const MUSICBRAINZ_USER_AGENT = 'PizzaPartyApp/1.0 ( https://licoricepizzareviews.com/pizzaparty )';

/** Holds the 1 req/sec pace across every call in this process. */
function musicbrainz_throttle(): void {
    static $last = 0.0;
    $now = microtime(true);
    $wait = 1.05 - ($now - $last);
    if ($last > 0 && $wait > 0) usleep((int)round($wait * 1_000_000));
    $last = microtime(true);
}

function musicbrainz_request(string $path, array $params): ?array {
    musicbrainz_throttle();
    $params['fmt'] = 'json';
    $url = MUSICBRAINZ_API_BASE . $path . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['User-Agent: ' . MUSICBRAINZ_USER_AGENT, 'Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        error_log(sprintf('musicbrainz: GET %s failed (http %d)', $path, $code));
        return null;
    }
    $json = json_decode((string)$body, true);
    return is_array($json) ? $json : null;
}

/**
 * Same disambiguation cleanup as lastfm_clean_artist() (Discogs' trailing
 * "(N)" and multi-artist joins) -- MusicBrainz has never heard of either.
 */
function musicbrainz_clean_artist(string $artist): string {
    $first = explode(', ', $artist, 2)[0];
    return trim(preg_replace('/\s*\(\d+\)\s*$/', '', $first));
}

/**
 * Best-match artist MBID for a name, or null if nothing scored highly
 * enough to trust. Cached by the caller (pizzaparty_artist_mbid) since
 * this practically never changes once resolved.
 */
function musicbrainz_find_artist(string $artistName): ?string {
    $clean = musicbrainz_clean_artist($artistName);
    if ($clean === '') return null;
    $d = musicbrainz_request('/artist', ['query' => 'artist:"' . $clean . '"', 'limit' => 5]);
    $candidates = $d['artists'] ?? [];
    if (!$candidates) return null;
    // MusicBrainz scores 0-100 on how well a candidate matches the query.
    // Below ~90 is often a same-named-different-artist false positive --
    // better to report nothing than the wrong artist's discography.
    $best = $candidates[0];
    if ((int)($best['score'] ?? 0) < 90) return null;
    return (string)($best['id'] ?? '') ?: null;
}

/**
 * An artist's studio albums/EPs, oldest first. Live albums, compilations,
 * and broadcasts are excluded -- they'd otherwise dominate "new release"
 * detection for any heavily-reissued/bootlegged catalog without actually
 * being new music.
 */
function musicbrainz_release_groups(string $mbid): array {
    $out = [];
    $offset = 0;
    do {
        $d = musicbrainz_request('/release-group', [
            'artist' => $mbid,
            'type'   => 'album|ep',
            'limit'  => 100,
            'offset' => $offset,
        ]);
        $groups = $d['release-groups'] ?? [];
        foreach ($groups as $g) {
            // Secondary types (live/compilation/soundtrack/remix/...) ride
            // alongside the primary type -- exclude anything carrying one.
            if (!empty($g['secondary-types'])) continue;
            $out[] = [
                'mbid'         => (string)($g['id'] ?? ''),
                'title'        => (string)($g['title'] ?? ''),
                'firstRelease' => $g['first-release-date'] ?: null,
            ];
        }
        $total = (int)($d['release-group-count'] ?? count($groups));
        $offset += count($groups);
    } while ($groups && $offset < $total);
    return $out;
}
