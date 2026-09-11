<?php
declare(strict_types=1);

require_once __DIR__ . '/musicbrainz.php';

/**
 * news.php
 * Builds the News tab's feed: two independent signals per artist in the
 * collection/wantlist, merged into one list by sync-news.php.
 *
 *   - "New release" items come from musicbrainz.php diffing an artist's
 *     release-groups against what pizzaparty_release_seen already knew
 *     about, so it fires exactly once per genuinely new album/EP.
 *   - "Article" items come from Google News' public RSS search -- no API
 *     key, no signup, matching how little friction the rest of this app's
 *     enrichment (Last.fm) needed. Tradeoff: it's an unofficial feed, so
 *     results can include tour-date blurbs or loosely-related mentions,
 *     and Google could change its shape without notice -- everything here
 *     degrades to an empty list rather than breaking the sync.
 */

const GOOGLE_NEWS_RSS_BASE = 'https://news.google.com/rss/search';

/**
 * A handful of recent articles mentioning an artist. Best-effort: returns
 * [] on any failure rather than throwing, since one artist's feed going
 * quiet must never abort the whole sync run.
 */
function googlenews_fetch(string $artist, int $limit = 6): array {
    $q = $artist . ' (album OR music OR tour)';
    $url = GOOGLE_NEWS_RSS_BASE . '?' . http_build_query([
        'q' => $q, 'hl' => 'en-US', 'gl' => 'US', 'ceid' => 'US:en',
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_FOLLOWLOCATION => true,
        // A generic/library User-Agent gets 403'd by some Google front ends
        // (the same lesson learned the hard way with ESPN's endpoints on
        // this same host) -- present as an ordinary browser.
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        error_log(sprintf('googlenews: GET failed for "%s" (http %d)', $artist, $code));
        return [];
    }

    $prevErrors = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    libxml_use_internal_errors($prevErrors);
    if ($xml === false || !isset($xml->channel->item)) return [];

    $out = [];
    foreach ($xml->channel->item as $item) {
        $title  = trim((string)$item->title);
        $link   = trim((string)$item->link);
        $source = trim((string)($item->source ?? ''));
        // Google News titles its items "Headline - Source"; the source is
        // also its own separate element, so drop the redundant suffix when
        // it matches rather than showing the publication name twice.
        if ($source !== '' && str_ends_with($title, ' - ' . $source)) {
            $title = substr($title, 0, -strlen(' - ' . $source));
        }
        if ($title === '' || $link === '') continue;
        $out[] = [
            'headline'    => $title,
            'url'         => $link,
            'source'      => $source ?: null,
            'publishedAt' => !empty($item->pubDate) ? date('Y-m-d H:i:s', strtotime((string)$item->pubDate)) : null,
        ];
        if (count($out) >= $limit) break;
    }
    return $out;
}

/**
 * An excerpt and feature image for an article, read off the page's own
 * Open Graph tags -- Google News' RSS carries neither. Also resolves
 * Google's redirect wrapper (news.google.com/rss/articles/...) to the
 * real publisher URL, which is worth doing anyway since that's a far
 * more useful link to hand someone than a Google redirect.
 *
 * Best-effort like everything else here: a paywalled or bot-hostile site
 * just yields no excerpt/image, never a broken item. Capped at ~300KB
 * read (og: tags are almost always in the first few KB of <head>) so one
 * unusually large page can't stall the sync.
 */
function article_og_meta(string $url): array {
    $bytesRead = 0;
    $buffer = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$buffer, &$bytesRead) {
            $buffer .= $chunk;
            $bytesRead += strlen($chunk);
            return $bytesRead > 300_000 ? 0 : strlen($chunk);   // returning 0 aborts the transfer
        },
    ]);
    curl_exec($ch);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
    curl_close($ch);

    $meta = ['url' => $finalUrl, 'excerpt' => null, 'image' => null];
    if ($buffer === '') return $meta;

    // Attribute order on og: tags isn't guaranteed (property before content,
    // or the reverse), so each pattern is tried both ways.
    $find = function (string $prop) use ($buffer): ?string {
        $patterns = [
            '/<meta[^>]+property=["\']' . preg_quote($prop, '/') . '["\'][^>]+content=["\']([^"\']*)["\']/i',
            '/<meta[^>]+content=["\']([^"\']*)["\'][^>]+property=["\']' . preg_quote($prop, '/') . '["\']/i',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $buffer, $m)) return html_entity_decode($m[1], ENT_QUOTES);
        }
        return null;
    };
    $meta['excerpt'] = $find('og:description');
    $meta['image']   = $find('og:image');
    return $meta;
}

/**
 * New release-groups for an artist since the last time this ran, as News
 * items ready to insert. Resolves+caches the artist's MBID on first call
 * (pizzaparty_artist_mbid), then diffs release-groups against
 * pizzaparty_release_seen -- so the very first sync for a given artist
 * seeds the "seen" table without reporting their entire back catalog as
 * news (see sync-news.php).
 */
function musicbrainz_new_releases(PDO $pdo, string $artistKey, string $mbid): array {
    $groups = musicbrainz_release_groups($mbid);
    if (!$groups) return [];

    $seenSt = $pdo->prepare('SELECT mbid FROM pizzaparty_release_seen WHERE artist_key = :k');
    $seenSt->execute([':k' => $artistKey]);
    $seen = array_flip(array_column($seenSt->fetchAll(), 'mbid'));

    $isFirstRun = count($seen) === 0;
    $news = [];
    $insertSt = $pdo->prepare(
        'INSERT IGNORE INTO pizzaparty_release_seen (artist_key, mbid, title, first_release, seen_at)
         VALUES (:k, :m, :t, :f, NOW())'
    );
    foreach ($groups as $g) {
        if ($g['mbid'] === '') continue;
        $insertSt->execute([
            ':k' => $artistKey, ':m' => $g['mbid'], ':t' => $g['title'],
            ':f' => $g['firstRelease'] ? substr($g['firstRelease'], 0, 10) : null,
        ]);
        // Seeding a never-before-tracked artist's whole discography would
        // otherwise read as "12 new albums!" the first time it's synced.
        if ($isFirstRun || isset($seen[$g['mbid']])) continue;
        $news[] = [
            'kind'        => 'release',
            'headline'    => $g['title'],
            'url'         => 'https://musicbrainz.org/release-group/' . $g['mbid'],
            'source'      => 'MusicBrainz',
            'publishedAt' => $g['firstRelease'] ? substr($g['firstRelease'], 0, 10) . ' 00:00:00' : null,
        ];
    }
    return $news;
}
