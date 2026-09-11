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
 *   - "Article" items come from Bing News' public RSS search -- no API
 *     key, no signup. Google News' RSS was tried first, but its <link>
 *     is an obfuscated news.google.com wrapper that requires running
 *     Google's own JavaScript to resolve to the real article -- a plain
 *     curl (confirmed empirically) just lands on Google's generic
 *     interstitial page, not the publisher's, so no real excerpt or
 *     image was ever reachable that way. Bing's RSS gives a direct
 *     publisher link (or an easily-decoded tracking redirect), a real
 *     excerpt, and a real thumbnail image, all inline -- no follow-up
 *     fetch of the article needed at all. Tradeoff: still an unofficial
 *     feed, so results can include loosely-related mentions, and Bing
 *     could change its shape without notice -- everything here degrades
 *     to an empty list rather than breaking the sync.
 */

const BING_NEWS_RSS_BASE = 'https://www.bing.com/news/search';

/**
 * A handful of recent articles mentioning an artist, each already carrying
 * a real excerpt and thumbnail image straight from Bing's own feed.
 * Best-effort: returns [] on any failure rather than throwing, since one
 * artist's feed going quiet must never abort the whole sync run.
 */
function bingnews_fetch(string $artist, int $limit = 6): array {
    $q = $artist . ' (album OR music OR tour)';
    $url = BING_NEWS_RSS_BASE . '?' . http_build_query(['q' => $q, 'format' => 'RSS']);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        error_log(sprintf('bingnews: GET failed for "%s" (http %d)', $artist, $code));
        return [];
    }

    $prevErrors = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    libxml_use_internal_errors($prevErrors);
    if ($xml === false || !isset($xml->channel->item)) return [];

    // The "News:" namespace URI Bing declares on <rss> embeds the search
    // query itself (xmlns:News="https://www.bing.com/news/search?q=...."),
    // so it's different on every request -- has to be read back from this
    // response rather than hardcoded, or $item->children() silently
    // returns nothing.
    $newsNs = $xml->getNamespaces(true)['News'] ?? null;

    $out = [];
    foreach ($xml->channel->item as $item) {
        $title = trim((string)$item->title);
        $link  = bingnews_resolve_link(trim((string)$item->link));
        $ns    = $newsNs ? $item->children($newsNs) : null;
        $image = trim((string)($ns->Image ?? ''));
        // News:Image is host-relative sometimes ("/th?id=...") and a plain
        // URL other times ("http://www.bing.com/th?id=...") -- normalize
        // to absolute, and ask for a specific size rather than Bing's
        // template placeholders ("w={0}&h={1}").
        if ($image !== '') {
            if ($image[0] === '/') $image = 'https://www.bing.com' . $image;
            $image = preg_replace('/[?&](w|h)=\{\d\}/', '', $image) . '&w=300&h=225&c=7';
        }

        if ($title === '' || $link === '') continue;
        $out[] = [
            'headline'    => $title,
            'excerpt'     => trim((string)$item->description) ?: null,
            'image'       => $image ?: null,
            'url'         => $link,
            'source'      => trim((string)($ns->Source ?? '')) ?: null,
            'publishedAt' => !empty($item->pubDate) ? date('Y-m-d H:i:s', strtotime((string)$item->pubDate)) : null,
        ];
        if (count($out) >= $limit) break;
    }
    return $out;
}

/**
 * Bing wraps some (not all) result links in an apiclick.aspx tracking
 * redirect with the real URL sitting in its own ?url= query param --
 * plain text, no JS needed, unlike Google's wrapper. Anything else is
 * already a direct publisher link.
 */
function bingnews_resolve_link(string $link): string {
    if (strpos($link, '/news/apiclick.aspx') === false) return $link;
    $query = parse_url($link, PHP_URL_QUERY);
    if (!$query) return $link;
    parse_str($query, $params);
    return $params['url'] ?? $link;
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
