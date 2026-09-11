<?php
declare(strict_types=1);

/**
 * seatgeek.php
 * Upcoming shows near you for artists in the collection/wantlist, for the
 * News tab's events sidebar. Read-only, needs only a free client_id (self-
 * serve at https://seatgeek.com/account/develop) -- no OAuth.
 *
 * Chosen over Ticketmaster: SeatGeek aggregates listings across many
 * independent venues' own box offices as well as the big Ticketmaster/Live
 * Nation rooms, so it catches boutique venues (e.g. DC's 9:30 Club) a
 * Ticketmaster-only search would miss.
 *
 * Strategy: rather than one query per tracked artist per region (which
 * could be hundreds of artists x 3 regions = hundreds of rate-limited
 * calls every sync), fetch each region's upcoming concerts in bulk --
 * a few dozen paginated calls total -- and match performers against the
 * collection/wantlist artist set client-side. See sync-events.php.
 */

const SEATGEEK_API_BASE = 'https://api.seatgeek.com/2';

/**
 * The three tracked regions. NYC and Philadelphia use a metro-area radius;
 * DC's is explicitly 100 miles per how this was asked for. Adjust the
 * 'range' values here if the metro radius ever needs to change.
 */
const SEATGEEK_REGIONS = [
    'nyc'          => ['label' => 'New York',     'lat' => 40.7128, 'lon' => -74.0060, 'range' => '25mi'],
    'philadelphia' => ['label' => 'Philadelphia',  'lat' => 39.9526, 'lon' => -75.1652, 'range' => '25mi'],
    'dc100'        => ['label' => 'Washington DC', 'lat' => 38.9072, 'lon' => -77.0369, 'range' => '100mi'],
];

function seatgeek_config(): array {
    $cfg = config()['seatgeek'] ?? null;
    if (!$cfg || empty($cfg['client_id']) || $cfg['client_id'] === 'CHANGE_ME') {
        throw new RuntimeException('SeatGeek client_id is not set in config.php.');
    }
    return $cfg;
}

function seatgeek_request(string $path, array $params): ?array {
    $params['client_id'] = seatgeek_config()['client_id'];
    $url = SEATGEEK_API_BASE . $path . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        error_log(sprintf('seatgeek: GET %s failed (http %d)', $path, $code));
        return null;
    }
    $json = json_decode((string)$body, true);
    return is_array($json) ? $json : null;
}

/**
 * Every upcoming concert SeatGeek lists within a region, oldest first.
 * Capped at $maxPages (100 events/page) as a safety valve -- a huge metro
 * area's concert calendar is long, but the News tab only needs "coming up
 * soon", not the entire future.
 */
function seatgeek_events_in_region(string $regionKey, int $maxPages = 8): array {
    $region = SEATGEEK_REGIONS[$regionKey] ?? null;
    if (!$region) return [];

    $out = [];
    for ($page = 1; $page <= $maxPages; $page++) {
        $d = seatgeek_request('/events', [
            'lat'                  => $region['lat'],
            'lon'                  => $region['lon'],
            'range'                => $region['range'],
            'taxonomies.name'      => 'concert',
            'datetime_local.gte'   => date('Y-m-d'),
            'sort'                 => 'datetime_local.asc',
            'per_page'             => 100,
            'page'                 => $page,
        ]);
        $events = $d['events'] ?? [];
        if (!$events) break;
        foreach ($events as $ev) {
            $out[] = [
                'id'        => (int)($ev['id'] ?? 0),
                'title'     => (string)($ev['title'] ?? ''),
                'url'       => (string)($ev['url'] ?? ''),
                'startsAt'  => !empty($ev['datetime_local']) ? str_replace('T', ' ', (string)$ev['datetime_local']) : null,
                'venueName' => $ev['venue']['name'] ?? null,
                'venueCity' => $ev['venue']['city'] ?? null,
                'venueState'=> $ev['venue']['state'] ?? null,
                'performers'=> array_map(fn($p) => (string)($p['name'] ?? ''), $ev['performers'] ?? []),
            ];
        }
        $total = (int)($d['meta']['total'] ?? 0);
        if ($page * 100 >= $total) break;
    }
    return $out;
}

/** Same disambiguation cleanup used throughout (Discogs "(N)" suffixes, multi-artist joins). */
function seatgeek_clean_artist(string $artist): string {
    $first = explode(', ', $artist, 2)[0];
    return trim(preg_replace('/\s*\(\d+\)\s*$/', '', $first));
}
