<?php
declare(strict_types=1);

/**
 * ticketmaster.php
 * Stand-in for seatgeek.php while a SeatGeek client_id is pending approval
 * -- same job (upcoming shows in the three tracked regions, matched
 * against the collection/wantlist), same "fetch region in bulk, match
 * performers client-side" strategy, different provider. Free self-serve
 * key at https://developer-acct.ticketmaster.com/, no OAuth.
 *
 * Intentionally kept as a fully separate, independently toggleable source
 * rather than merged into seatgeek.php: once SeatGeek is approved, this
 * can just be commented out of sync-events.php's source list (see there)
 * without touching seatgeek.php at all. Running both at once is also
 * fine -- pizzaparty_events_cache's unique key is (source, external_id,
 * artist), so they can never collide, though the same real show turning
 * up via both providers will show as two sidebar entries until one
 * source is switched off.
 */

const TICKETMASTER_API_BASE = 'https://app.ticketmaster.com/discovery/v2';

/** Keep in sync with SEATGEEK_REGIONS in seatgeek.php if these ever change. */
const TICKETMASTER_REGIONS = [
    'nyc'          => ['label' => 'New York',     'lat' => 40.7128, 'lon' => -74.0060, 'radius' => 25],
    'philadelphia' => ['label' => 'Philadelphia',  'lat' => 39.9526, 'lon' => -75.1652, 'radius' => 25],
    'dc100'        => ['label' => 'Washington DC', 'lat' => 38.9072, 'lon' => -77.0369, 'radius' => 100],
];

function ticketmaster_config(): array {
    $cfg = config()['ticketmaster'] ?? null;
    if (!$cfg || empty($cfg['api_key']) || $cfg['api_key'] === 'CHANGE_ME') {
        throw new RuntimeException('Ticketmaster api_key is not set in config.php.');
    }
    return $cfg;
}

function ticketmaster_request(string $path, array $params): ?array {
    $params['apikey'] = ticketmaster_config()['api_key'];
    $url = TICKETMASTER_API_BASE . $path . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        error_log(sprintf('ticketmaster: GET %s failed (http %d)', $path, $code));
        return null;
    }
    $json = json_decode((string)$body, true);
    return is_array($json) ? $json : null;
}

/** Same shape as seatgeek_events_in_region(), so sync-events.php can treat both identically. */
function ticketmaster_events_in_region(string $regionKey, int $maxPages = 8): array {
    $region = TICKETMASTER_REGIONS[$regionKey] ?? null;
    if (!$region) return [];

    $out = [];
    for ($page = 0; $page < $maxPages; $page++) {
        $d = ticketmaster_request('/events.json', [
            'latlong'         => $region['lat'] . ',' . $region['lon'],
            'radius'          => $region['radius'],
            'unit'            => 'miles',
            'classificationName' => 'music',
            'startDateTime'   => gmdate('Y-m-d\TH:i:s\Z'),
            'sort'            => 'date,asc',
            'size'            => 200,
            'page'            => $page,
        ]);
        $events = $d['_embedded']['events'] ?? [];
        if (!$events) break;
        foreach ($events as $ev) {
            $venue = $ev['_embedded']['venues'][0] ?? [];
            $attractions = $ev['_embedded']['attractions'] ?? [];
            $startsAt = $ev['dates']['start']['dateTime'] ?? null;   // UTC 'Z' ISO8601
            $out[] = [
                'id'        => (string)($ev['id'] ?? ''),
                'title'     => (string)($ev['name'] ?? ''),
                'url'       => (string)($ev['url'] ?? ''),
                'startsAt'  => $startsAt ? gmdate('Y-m-d H:i:s', strtotime($startsAt)) : null,
                'venueName' => $venue['name'] ?? null,
                'venueCity' => $venue['city']['name'] ?? null,
                'venueState'=> $venue['state']['stateCode'] ?? null,
                'performers'=> array_map(fn($a) => (string)($a['name'] ?? ''), $attractions),
            ];
        }
        $totalPages = (int)($d['page']['totalPages'] ?? 1);
        if ($page + 1 >= $totalPages) break;
    }
    return $out;
}

/** Same disambiguation cleanup used throughout (Discogs "(N)" suffixes, multi-artist joins). */
function ticketmaster_clean_artist(string $artist): string {
    $first = explode(', ', $artist, 2)[0];
    return trim(preg_replace('/\s*\(\d+\)\s*$/', '', $first));
}
