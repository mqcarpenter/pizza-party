<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/seatgeek.php';
require __DIR__ . '/ticketmaster.php';

/**
 * Refreshes the News tab's events sidebar: upcoming concerts in the three
 * tracked regions, matched against artists in the collection and wantlist.
 * Run from cron, e.g. every 6 hours:
 *   php /path/to/pizzaparty/sync-events.php
 *
 * Fetches each region's concert calendar in bulk (a handful of paginated
 * calls) and matches performers client-side, rather than querying a
 * provider once per artist per region -- see seatgeek.php's header for why.
 *
 * Matches against significant_artists() (db.php) -- the same two-plus-
 * records-owned-or-wanted bar sync-news.php uses -- rather than every
 * artist ever logged, for the same reason: a one-off artist isn't someone
 * worth surfacing a concert alert for.
 *
 * Two independently toggleable sources: SeatGeek is the intended one
 * (better boutique-venue coverage), Ticketmaster is a stand-in while a
 * SeatGeek client_id is pending approval. Comment out the 'ticketmaster'
 * entry below once SeatGeek is live and confirmed working -- running both
 * is harmless (pizzaparty_events_cache's unique key includes `source`,
 * so they can never collide), it just means a show listed on both will
 * show up twice in the sidebar until one source is switched off.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

$SOURCES = [
    'seatgeek' => [
        'regions' => SEATGEEK_REGIONS,
        'fetch'   => 'seatgeek_events_in_region',
        'clean'   => 'seatgeek_clean_artist',
    ],
    // Stand-in for SeatGeek while its client_id is pending approval --
    // comment out this entry once SeatGeek is live.
    'ticketmaster' => [
        'regions' => TICKETMASTER_REGIONS,
        'fetch'   => 'ticketmaster_events_in_region',
        'clean'   => 'ticketmaster_clean_artist',
    ],
];

$pdo = db_admin();

$artists = significant_artists($pdo);

// clean-lowercase key -> the original artist string, as stored/displayed
// elsewhere in the app (row_to_item() etc. all use the raw Discogs credit).
// Built once; each source's own clean_artist() should agree closely
// enough (all three are the same Discogs-disambiguation cleanup) that one
// shared lookup works for all of them.
$lookup = [];
foreach ($artists as $a) {
    $key = strtolower(seatgeek_clean_artist((string)$a));
    if ($key !== '') $lookup[$key] = $a;
}
fwrite(STDOUT, 'Matching events against ' . count($lookup) . " artists...\n");

$upsertSt = $pdo->prepare(
    'INSERT INTO pizzaparty_events_cache
        (external_id, source, artist, title, venue_name, venue_city, venue_state, region, starts_at, url, fetched_at)
     VALUES (:id, :source, :artist, :title, :vn, :vc, :vs, :region, :starts, :url, NOW())
     ON DUPLICATE KEY UPDATE
        title = VALUES(title), venue_name = VALUES(venue_name), venue_city = VALUES(venue_city),
        venue_state = VALUES(venue_state), region = VALUES(region), starts_at = VALUES(starts_at),
        url = VALUES(url), fetched_at = VALUES(fetched_at)'
);

$matched = 0;
$errors = 0;
$regionAttempts = 0;
$keptKeys = [];

foreach ($SOURCES as $sourceName => $src) {
    foreach (array_keys($src['regions']) as $regionKey) {
        $regionAttempts++;
        try {
            $events = ($src['fetch'])($regionKey);
        } catch (Throwable $e) {
            $errors++;
            error_log("sync-events: $sourceName/$regionKey: " . $e->getMessage());
            continue;
        }
        fwrite(STDOUT, "  $sourceName/$regionKey: " . count($events) . " events fetched\n");

        foreach ($events as $ev) {
            if ($ev['id'] === '' || $ev['id'] === null) continue;
            foreach ($ev['performers'] as $performerName) {
                $key = strtolower(($src['clean'])($performerName));
                if ($key === '' || !isset($lookup[$key])) continue;
                $artist = $lookup[$key];

                $upsertSt->execute([
                    ':id'     => (string)$ev['id'],
                    ':source' => $sourceName,
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
                $keptKeys[] = $sourceName . '|' . $ev['id'] . '|' . $artist;
            }
        }
    }
}

// Drop events that are no longer upcoming/no longer match (sold out of a
// provider's window, cancelled, already passed, or from a source that's
// since been commented out of $SOURCES above).
$kept = array_flip($keptKeys);
$staleIds = [];
foreach ($pdo->query('SELECT id, source, external_id, artist FROM pizzaparty_events_cache')->fetchAll() as $row) {
    $k = $row['source'] . '|' . $row['external_id'] . '|' . $row['artist'];
    if (!isset($kept[$k])) $staleIds[] = $row['id'];
}
if ($staleIds) {
    $ph = implode(',', array_fill(0, count($staleIds), '?'));
    $pdo->prepare("DELETE FROM pizzaparty_events_cache WHERE id IN ($ph)")->execute($staleIds);
}

fwrite(STDOUT, "Matched $matched artist/event pairs ($errors of $regionAttempts region fetches failed).\n");
if ($errors > 0 && $errors === $regionAttempts) exit(1);
