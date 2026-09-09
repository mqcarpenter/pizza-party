<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/discogs_oauth.php';

/**
 * Pulls the full Discogs wantlist into pizzaparty_wantlist_items. Run from
 * cron:  php /path/to/pizzaparty/sync-wantlist.php
 *
 * This is a baseline refresh — gated writes (wantlist-add/remove/note in
 * api/index.php) already keep the cache current in real time, so this mainly
 * catches drift (e.g. an edit made directly on discogs.com).
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

function record_sync(string $status, ?string $detail = null): void {
    $st = db_admin()->prepare(
        'INSERT INTO pizzaparty_sync_state (last_synced_at, status, detail) VALUES (NOW(), :s, :d)'
    );
    $st->execute([':s' => $status, ':d' => $detail]);
}

try {
    $auth = discogs_auth_row();
    $username = $auth['discogs_username'];

    $pdo  = db_admin();
    $seen = [];
    $page = 1;
    $perPage = 100;

    do {
        [$status, $json] = discogs_signed_request(
            'GET',
            DISCOGS_API_BASE . '/users/' . rawurlencode($username) . '/wants',
            ['page' => $page, 'per_page' => $perPage]
        );
        if ($status !== 200) {
            throw new RuntimeException('Wantlist fetch failed (HTTP ' . $status . '): ' . json_encode($json));
        }

        $wants = $json['wants'] ?? [];
        $st = $pdo->prepare(
            'INSERT INTO pizzaparty_wantlist_items
                (release_id, artist, title, year, format, label, thumb_url, notes, rating, date_added, raw_json)
             VALUES (:release_id, :artist, :title, :year, :format, :label, :thumb_url, :notes, :rating, :date_added, :raw_json)
             ON DUPLICATE KEY UPDATE
                artist = VALUES(artist), title = VALUES(title), year = VALUES(year),
                format = VALUES(format), label = VALUES(label), thumb_url = VALUES(thumb_url),
                notes = VALUES(notes), rating = VALUES(rating), date_added = VALUES(date_added),
                raw_json = VALUES(raw_json)'
        );

        foreach ($wants as $w) {
            $info = $w['basic_information'] ?? [];
            $artists = array_map(fn($a) => $a['name'] ?? '', $info['artists'] ?? []);
            $labels  = array_map(fn($l) => $l['name'] ?? '', $info['labels'] ?? []);
            $formats = array_map(fn($f) => $f['name'] ?? '', $info['formats'] ?? []);

            $seen[] = (int)$w['id'];
            $st->execute([
                ':release_id'  => $w['id'],
                ':artist'      => implode(', ', array_filter($artists)),
                ':title'       => $info['title'] ?? null,
                ':year'        => $info['year'] ?? null,
                ':format'      => implode(', ', array_filter($formats)),
                ':label'       => implode(', ', array_filter($labels)),
                ':thumb_url'   => $info['thumb'] ?? null,
                ':notes'       => $w['notes'] ?? null,
                ':rating'      => $w['rating'] ?? null,
                ':date_added'  => !empty($w['date_added']) ? date('Y-m-d H:i:s', strtotime($w['date_added'])) : null,
                ':raw_json'    => json_encode($w),
            ]);
        }

        $pages = (int)($json['pagination']['pages'] ?? 1);
        $page++;
        if ($page <= $pages) usleep(600000);
    } while ($page <= $pages);

    if ($seen) {
        $placeholders = implode(',', array_fill(0, count($seen), '?'));
        $pdo->prepare("DELETE FROM pizzaparty_wantlist_items WHERE release_id NOT IN ($placeholders)")->execute($seen);
    } else {
        $pdo->exec('DELETE FROM pizzaparty_wantlist_items');
    }

    record_sync('ok', count($seen) . ' items');
    fwrite(STDOUT, 'Synced ' . count($seen) . " wantlist items.\n");
} catch (Throwable $e) {
    record_sync('error', $e->getMessage());
    fwrite(STDERR, 'sync-wantlist failed: ' . $e->getMessage() . "\n");
    exit(1);
}
