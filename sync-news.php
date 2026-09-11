<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/musicbrainz.php';
require __DIR__ . '/news.php';

/**
 * Refreshes the News tab's feed for every artist in the collection and
 * wantlist: new-release detection (MusicBrainz) and recent coverage
 * (Google News RSS). Run from cron, e.g. every 6 hours:
 *   php /path/to/pizzaparty/sync-news.php
 *
 * MusicBrainz enforces 1 req/sec; a large collection's worth of artists
 * means this can take a while (each artist costs one release-group fetch,
 * plus one more the first time its MBID is resolved). That's fine for a
 * background cron job -- just don't schedule it more often than it takes
 * to finish a full pass.
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

fwrite(STDOUT, 'Refreshing news for ' . count($artists) . " artists...\n");

$mbidSt = $pdo->prepare('SELECT mbid FROM pizzaparty_artist_mbid WHERE artist_key = :k');
$mbidInsertSt = $pdo->prepare(
    'INSERT INTO pizzaparty_artist_mbid (artist_key, mbid, resolved_at) VALUES (:k, :m, NOW())
     ON DUPLICATE KEY UPDATE mbid = VALUES(mbid), resolved_at = VALUES(resolved_at)'
);
$insertNewsSt = $pdo->prepare(
    'INSERT INTO pizzaparty_news_items (artist, kind, headline, url, source, published_at, fetched_at)
     VALUES (:artist, :kind, :headline, :url, :source, :published_at, NOW())
     ON DUPLICATE KEY UPDATE
        headline = VALUES(headline), source = VALUES(source),
        published_at = VALUES(published_at), fetched_at = VALUES(fetched_at)'
);

$errors = 0;
$newItems = 0;
$keptKeys = [];

foreach ($artists as $rawArtist) {
    $artistKey = strtolower(musicbrainz_clean_artist((string)$rawArtist));
    if ($artistKey === '') continue;

    try {
        // ---- MusicBrainz: resolve (cached) + diff release-groups ----
        $mbidSt->execute([':k' => $artistKey]);
        $mbidRow = $mbidSt->fetch();
        if ($mbidRow === false) {
            $mbid = musicbrainz_find_artist((string)$rawArtist);
            $mbidInsertSt->execute([':k' => $artistKey, ':m' => $mbid]);
        } else {
            $mbid = $mbidRow['mbid'];
        }

        $releaseNews = $mbid ? musicbrainz_new_releases($pdo, $artistKey, $mbid) : [];

        // ---- Google News: a handful of recent articles ----
        $articleNews = googlenews_fetch((string)$rawArtist);

        foreach (array_merge($releaseNews, $articleNews) as $item) {
            $insertNewsSt->execute([
                ':artist'       => $rawArtist,
                ':kind'         => $item['kind'] ?? 'article',
                ':headline'     => mb_substr($item['headline'], 0, 500),
                ':url'          => mb_substr($item['url'], 0, 768),
                ':source'       => $item['source'] ?? null,
                ':published_at' => $item['publishedAt'] ?? null,
            ]);
            $keptKeys[] = $rawArtist . '|' . mb_substr($item['url'], 0, 255);
            $newItems++;
        }
    } catch (Throwable $e) {
        $errors++;
        error_log('sync-news: ' . $rawArtist . ': ' . $e->getMessage());
    }
}

// Drop stale items: anything not refreshed just now, for ANY artist --
// deliberately not scoped to the current $artists list, so a row for an
// artist since removed from both collection and wantlist (and therefore
// never re-queried, never re-added to $keptKeys) still gets swept here
// rather than lingering forever.
$kept = array_flip($keptKeys);
$staleIds = [];
foreach ($pdo->query('SELECT id, artist, url FROM pizzaparty_news_items')->fetchAll() as $row) {
    $k = $row['artist'] . '|' . mb_substr($row['url'], 0, 255);
    if (!isset($kept[$k])) $staleIds[] = $row['id'];
}
if ($staleIds) {
    $ph = implode(',', array_fill(0, count($staleIds), '?'));
    $pdo->prepare("DELETE FROM pizzaparty_news_items WHERE id IN ($ph)")->execute($staleIds);
}

fwrite(STDOUT, "Wrote $newItems news items ($errors artist errors).\n");
if ($errors > 0 && $newItems === 0) exit(1);
