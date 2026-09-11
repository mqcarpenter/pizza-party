<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/seatgeek.php';

/**
 * Refreshes the News tab's events sidebar: upcoming SeatGeek concerts in
 * the three tracked regions, matched against artists in the collection and
 * wantlist. Run from cron, e.g. every 6 hours:
 *   php /path/to/pizzaparty/sync-events.php
 *
 * Fetches each region's concert calendar in bulk (a handful of paginated
 * calls) and matches performers client-side, rather than querying SeatGeek
 * once per artist per region -- see seatgeek.php's header for why.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

$pdo = db_admin();

$artists = $pdo->query(
    'SELECT DISTINCT artist FROM (
        SELECT artist FROM pizzaparty_collection_items
        UNION
        SELECT artist FROM pizzaparty_wantlist_items
     ) a WHERE artist IS NOT NULL AND artist != \'\''
)->fetchAll(PDO::FETCH_COLUMN);

// clean-lowercase key -> the original artist string, as stored/displayed
// elsewhere in the app (row_to_item() etc. all use the raw Discogs credit).
$lookup = [];
foreach ($artists as $a) {
    $key = strtolower(seatgeek_clean_artist((string)$a));
    if ($key !== '') $lookup[$key] = $a;
}
fwrite(STDOUT, 'Matching events against ' . count($lookup) . " artists...\n");

$upsertSt = $pdo->prepare(
    'INSERT INTO pizzaparty_events_cache
        (seatgeek_id, artist, title, venue_name, venue_city, venue_state, region, starts_at, url, fetched_at)
     VALUES (:id, :artist, :title, :vn, :vc, :vs, :region, :starts, :url, NOW())
     ON DUPLICATE KEY UPDATE
        title = VALUES(title), venue_name = VALUES(venue_name), venue_city = VALUES(venue_city),
        venue_state = VALUES(venue_state), region = VALUES(region), starts_at = VALUES(starts_at),
        url = VALUES(url), fetched_at = VALUES(fetched_at)'
);

$matched = 0;
$errors = 0;
$keptKeys = [];

foreach (array_keys(SEATGEEK_REGIONS) as $regionKey) {
    try {
        $events = seatgeek_events_in_region($regionKey);
    } catch (Throwable $e) {
        $errors++;
        error_log('sync-events: region ' . $regionKey . ': ' . $e->getMessage());
        continue;
    }
    fwrite(STDOUT, "  $regionKey: " . count($events) . " events fetched\n");

    foreach ($events as $ev) {
        if ($ev['id'] <= 0) continue;
        foreach ($ev['performers'] as $performerName) {
            $key = strtolower(seatgeek_clean_artist($performerName));
            if ($key === '' || !isset($lookup[$key])) continue;
            $artist = $lookup[$key];

            $upsertSt->execute([
                ':id'     => $ev['id'],
                ':artist' => $artist,
                ':title'  => mb_substr($ev['title'], 0, 500),
                ':vn'     => $ev['venueName'],
                ':vc'     => $ev['venueCity'],
                ':vs'     => $ev['venueState'],
                ':region' => $regionKey,
                ':starts' => $ev['startsAt'],
                ':url'    => $ev['url'] !== '' ? mb_substr($ev['url'], 0, 768) : null,
            ]);
            $matched++;
            $keptKeys[] = $ev['id'] . '|' . $artist;
        }
    }
}

// Drop events that are no longer upcoming/no longer match (sold out of
// SeatGeek's window, cancelled, or already passed).
$kept = array_flip($keptKeys);
$staleIds = [];
foreach ($pdo->query('SELECT id, seatgeek_id, artist FROM pizzaparty_events_cache')->fetchAll() as $row) {
    $k = $row['seatgeek_id'] . '|' . $row['artist'];
    if (!isset($kept[$k])) $staleIds[] = $row['id'];
}
if ($staleIds) {
    $ph = implode(',', array_fill(0, count($staleIds), '?'));
    $pdo->prepare("DELETE FROM pizzaparty_events_cache WHERE id IN ($ph)")->execute($staleIds);
}

fwrite(STDOUT, "Matched $matched artist/event pairs ($errors region errors).\n");
if ($errors === count(SEATGEEK_REGIONS)) exit(1);
