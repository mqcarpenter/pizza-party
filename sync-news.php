<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/musicbrainz.php';
require __DIR__ . '/news.php';

/**
 * Refreshes the News tab's feed for every ARTIST WORTH TRACKING (see
 * significant_artists() in db.php -- two-plus records between collection
 * and wantlist combined): new-release detection (MusicBrainz) and recent
 * coverage (Bing News RSS). Run from cron, e.g. every 6 hours:
 *   php /path/to/pizzaparty/sync-news.php
 *
 * Narrowed on purpose: a full collection's worth of one-off artists (owned
 * exactly once, no particular follow-up interest) was drowning the feed in
 * volume without adding anything anyone would call "my music news".
 *
 * MusicBrainz enforces 1 req/sec; a large collection's worth of artists
 * means this can take a while (each artist costs one release-group fetch,
 * plus one more the first time its MBID is resolved). That's fine for a
 * background cron job -- just don't schedule it more often than it takes
 * to finish a full pass.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

$pdo = db_admin();

$artists = significant_artists($pdo);

fwrite(STDOUT, 'Refreshing news for ' . count($artists) . " artists...\n");

$mbidSt = $pdo->prepare('SELECT mbid FROM pizzaparty_artist_mbid WHERE artist_key = :k');
$mbidInsertSt = $pdo->prepare(
    'INSERT INTO pizzaparty_artist_mbid (artist_key, mbid, resolved_at) VALUES (:k, :m, NOW())
     ON DUPLICATE KEY UPDATE mbid = VALUES(mbid), resolved_at = VALUES(resolved_at)'
);
$insertNewsSt = $pdo->prepare(
    'INSERT INTO pizzaparty_news_items (artist, kind, headline, excerpt, image_url, url, source, published_at, fetched_at)
     VALUES (:artist, :kind, :headline, :excerpt, :image_url, :url, :source, :published_at, NOW())
     ON DUPLICATE KEY UPDATE
        headline = VALUES(headline), excerpt = VALUES(excerpt), image_url = VALUES(image_url),
        source = VALUES(source), published_at = VALUES(published_at), fetched_at = VALUES(fetched_at)'
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

        // ---- Bing News: a handful of recent articles, excerpt+image included ----
        $articleNews = bingnews_fetch((string)$rawArtist);

        foreach (array_merge($releaseNews, $articleNews) as $item) {
            $url = $item['url'];
            $insertNewsSt->execute([
                ':artist'       => $rawArtist,
                ':kind'         => $item['kind'] ?? 'article',
                ':headline'     => mb_substr($item['headline'], 0, 500),
                ':excerpt'      => !empty($item['excerpt']) ? mb_substr($item['excerpt'], 0, 2000) : null,
                ':image_url'    => !empty($item['image']) ? mb_substr($item['image'], 0, 768) : null,
                ':url'          => mb_substr($url, 0, 768),
                ':source'       => $item['source'] ?? null,
                ':published_at' => $item['publishedAt'] ?? null,
            ]);
            $keptKeys[] = $rawArtist . '|' . mb_substr($url, 0, 255);
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
