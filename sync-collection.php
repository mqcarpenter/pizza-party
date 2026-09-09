<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/discogs_oauth.php';

/**
 * Pulls the full Discogs collection (folder 0 = "All") into
 * pizzaparty_collection_items. Run from cron:
 *   php /path/to/pizzaparty/sync-collection.php
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
            DISCOGS_API_BASE . '/users/' . rawurlencode($username) . '/collection/folders/0/releases',
            ['page' => $page, 'per_page' => $perPage, 'sort' => 'artist', 'sort_order' => 'asc']
        );
        if ($status !== 200) {
            throw new RuntimeException('Collection fetch failed (HTTP ' . $status . '): ' . json_encode($json));
        }

        $releases = $json['releases'] ?? [];
        $st = $pdo->prepare(
            'INSERT INTO pizzaparty_collection_items
                (release_id, instance_id, folder_id, artist, title, year, format, label, genres, styles, thumb_url, notes, date_added, raw_json)
             VALUES (:release_id, :instance_id, :folder_id, :artist, :title, :year, :format, :label, :genres, :styles, :thumb_url, :notes, :date_added, :raw_json)
             ON DUPLICATE KEY UPDATE
                release_id = VALUES(release_id), folder_id = VALUES(folder_id),
                artist = VALUES(artist), title = VALUES(title), year = VALUES(year),
                format = VALUES(format), label = VALUES(label), genres = VALUES(genres),
                styles = VALUES(styles), thumb_url = VALUES(thumb_url), notes = VALUES(notes),
                date_added = VALUES(date_added), raw_json = VALUES(raw_json)'
        );

        foreach ($releases as $r) {
            $info = $r['basic_information'] ?? [];
            $artists = array_map(fn($a) => $a['name'] ?? '', $info['artists'] ?? []);
            $labels  = array_map(fn($l) => $l['name'] ?? '', $info['labels'] ?? []);
            $formats = array_map(fn($f) => $f['name'] ?? '', $info['formats'] ?? []);
            $notesArr = array_map(fn($n) => ($n['field_id'] ?? '') . ': ' . ($n['value'] ?? ''), $r['notes'] ?? []);

            $seen[] = (int)$r['instance_id'];
            $st->execute([
                ':release_id'  => $info['id'] ?? $r['id'] ?? 0,
                ':instance_id' => $r['instance_id'],
                ':folder_id'   => $r['folder_id'] ?? 0,
                ':artist'      => implode(', ', array_filter($artists)),
                ':title'       => $info['title'] ?? null,
                ':year'        => $info['year'] ?? null,
                ':format'      => implode(', ', array_filter($formats)),
                ':label'       => implode(', ', array_filter($labels)),
                ':genres'      => implode(', ', $info['genres'] ?? []),
                ':styles'      => implode(', ', $info['styles'] ?? []),
                ':thumb_url'   => $info['thumb'] ?? null,
                ':notes'       => implode('; ', array_filter($notesArr)) ?: null,
                ':date_added'  => !empty($r['date_added']) ? date('Y-m-d H:i:s', strtotime($r['date_added'])) : null,
                ':raw_json'    => json_encode($r),
            ]);
        }

        $pages = (int)($json['pagination']['pages'] ?? 1);
        $page++;
        // Discogs allows ~60 req/min authenticated; one request per loop keeps
        // this comfortably under that even for a large collection.
        if ($page <= $pages) usleep(600000);
    } while ($page <= $pages);

    // Anything no longer in the collection (sold/removed on Discogs) drops out
    // of the cache too.
    if ($seen) {
        $placeholders = implode(',', array_fill(0, count($seen), '?'));
        $pdo->prepare("DELETE FROM pizzaparty_collection_items WHERE instance_id NOT IN ($placeholders)")->execute($seen);
    } else {
        $pdo->exec('DELETE FROM pizzaparty_collection_items');
    }

    record_sync('ok', count($seen) . ' items');
    fwrite(STDOUT, 'Synced ' . count($seen) . " collection items.\n");
} catch (Throwable $e) {
    record_sync('error', $e->getMessage());
    fwrite(STDERR, 'sync-collection failed: ' . $e->getMessage() . "\n");
    exit(1);
}
