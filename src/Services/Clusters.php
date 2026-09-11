<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Database;

/**
 * Cross-source clustering. Six outlets covering the same story become one card that says
 * "6 outlets covering this" with ME's own one-line framing of who led and who followed.
 * The visible text is ours; each outlet keeps its headline behind the card.
 *
 * Similarity is token overlap on significant words (Jaccard) plus shared proper nouns,
 * within a 48-hour window. Deterministic, dependency-free, fast enough to run on every
 * wire refresh.
 */
final class Clusters
{
    private const WINDOW_HOURS = 48;
    private const STOP = ['about', 'after', 'again', 'against', 'ahead', 'almost', 'along', 'also', 'amid', 'among', 'another', 'around', 'back', 'because', 'been', 'before', 'being', 'between', 'both', 'call', 'calls', 'could', 'day', 'days', 'does', 'down', 'during', 'each', 'even', 'ever', 'every', 'first', 'following', 'from', 'further', 'gets', 'have', 'here', 'high', 'home', 'hour', 'hours', 'how', 'into', 'ireland', 'irish', 'just', 'last', 'latest', 'like', 'live', 'look', 'made', 'make', 'makes', 'many', 'more', 'most', 'much', 'must', 'near', 'need', 'new', 'news', 'next', 'over', 'part', 'people', 'plan', 'plans', 'says', 'said', 'set', 'should', 'since', 'some', 'still', 'such', 'take', 'than', 'that', 'their', 'them', 'then', 'there', 'these', 'they', 'this', 'those', 'through', 'time', 'today', 'told', 'tonight', 'under', 'until', 'update', 'very', 'warns', 'week', 'weeks', 'were', 'what', 'when', 'where', 'which', 'while', 'will', 'with', 'would', 'year', 'years', 'your', 'reveals', 'revealed', 'named', 'named', 'watch', 'video', 'pictures', 'photos', 'live', 'explained', 'everything', 'know'];

    /** Significant tokens of a headline, lower-cased; proper nouns are tracked separately. */
    public static function tokens(string $title): array
    {
        $clean = preg_replace('/[^\p{L}\p{N}\s\'’-]+/u', ' ', $title) ?? '';
        $words = preg_split('/\s+/u', trim($clean)) ?: [];
        $all = [];
        $proper = [];
        foreach ($words as $i => $w) {
            $w = trim($w, "'’-");
            if ($w === '') {
                continue;
            }
            $lower = mb_strtolower($w);
            if (mb_strlen($lower) < 4 || in_array($lower, self::STOP, true)) {
                continue;
            }
            $lower = preg_replace('/(ies)$/u', 'y', $lower) ?? $lower;
            $lower = preg_replace('/(s|es|ed|ing)$/u', '', $lower) ?? $lower;
            if (mb_strlen($lower) < 3) {
                continue;
            }
            $all[$lower] = true;
            if ($i > 0 && preg_match('/^\p{Lu}/u', $w)) {
                $proper[$lower] = true;
            }
        }
        return ['all' => $all, 'proper' => $proper];
    }

    /** True when two headlines are almost certainly the same story. */
    public static function similar(array $a, array $b): bool
    {
        $inter = count(array_intersect_key($a['all'], $b['all']));
        if ($inter < 2) {
            return false;
        }
        $union = count($a['all'] + $b['all']);
        $jaccard = $union ? $inter / $union : 0;
        $properShared = count(array_intersect_key($a['proper'], $b['proper']));
        return $jaccard >= 0.5 || ($jaccard >= 0.34 && $properShared >= 1) || ($inter >= 4 && $properShared >= 2);
    }

    /**
     * Assign a wire story to an existing cluster (or leave it alone). Called after insert.
     * Returns the cluster id or null.
     */
    public static function assign(string $storyId): ?string
    {
        $s = Database::one("SELECT id,title,published_at,created_at,source_name,image_url FROM stories WHERE id=? AND kind='wire'", [$storyId]);
        if (!$s) {
            return null;
        }
        $t = self::tokens($s['title']);
        if (count($t['all']) < 2) {
            return null;
        }
        $when = strtotime($s['published_at'] ?: $s['created_at']) ?: time();
        $from = gmdate('Y-m-d\TH:i:s', $when - self::WINDOW_HOURS * 3600) . '+00:00';
        $to = gmdate('Y-m-d\TH:i:s', $when + self::WINDOW_HOURS * 3600) . '+00:00';
        $candidates = Database::all("SELECT id,title,cluster_id,source_name,published_at,created_at FROM stories WHERE kind='wire' AND status='published' AND id<>? AND COALESCE(published_at,created_at) BETWEEN ? AND ? ORDER BY COALESCE(published_at,created_at) DESC LIMIT 600", [$s['id'], $from, $to]);
        static $cache = [];
        foreach ($candidates as $c) {
            if ($c['source_name'] === $s['source_name']) {
                continue; // an outlet's own follow-up is a different story to the reader
            }
            $cache[$c['id']] ??= self::tokens($c['title']);
            if (self::similar($t, $cache[$c['id']])) {
                $clusterId = $c['cluster_id'] ?: self::create($c['id']);
                Database::query('UPDATE stories SET cluster_id=? WHERE id=?', [$clusterId, $s['id']]);
                self::refresh($clusterId);
                return $clusterId;
            }
        }
        return null;
    }

    private static function create(string $leadId): string
    {
        $id = uuid();
        Database::insert('story_clusters', ['id' => $id, 'created_at' => now(), 'updated_at' => now(), 'lead_id' => $leadId, 'count' => 1]);
        Database::query('UPDATE stories SET cluster_id=? WHERE id=?', [$id, $leadId]);
        return $id;
    }

    /** Recompute lead, count, outlets and framing for a cluster. */
    public static function refresh(string $clusterId): void
    {
        $members = Database::all("SELECT id,title,source_name,source_url,image_url,COALESCE(published_at,created_at) AS t FROM stories WHERE cluster_id=? AND status='published' ORDER BY t ASC", [$clusterId]);
        if (!$members) {
            Database::query('DELETE FROM story_clusters WHERE id=?', [$clusterId]);
            return;
        }
        // Lead: the outlet that got there first, unless it has no image and the next one does.
        $lead = $members[0];
        if (!$lead['image_url'] && isset($members[1]) && $members[1]['image_url'] && (strtotime($members[1]['t']) - strtotime($lead['t'])) < 3 * 3600) {
            $lead = $members[1];
        }
        $outlets = [];
        foreach ($members as $m) {
            $outlets[$m['source_name']] ??= ['name' => $m['source_name'], 'first' => $m['t'], 'url' => $m['source_url'], 'title' => $m['title']];
        }
        $names = array_keys($outlets);
        $n = count($members);
        $first = $members[0];
        $framing = count($names) === 1
            ? $n . ' reports from ' . $names[0]
            : $names[0] . ' had it first at ' . date_irish($first['t'], 'H:i') . '; ' . self::joinNames(array_slice($names, 1, 3)) . (count($names) > 4 ? ' and ' . (count($names) - 4) . ' more' : '') . ' followed' . ($n > count($names) ? ' with ' . $n . ' reports between them' : '') . '.';
        Database::query('UPDATE story_clusters SET lead_id=?,count=?,outlets_json=?,framing=?,updated_at=? WHERE id=?', [$lead['id'], $n, json_encode(array_values($outlets), JSON_UNESCAPED_UNICODE), $framing, now(), $clusterId]);
        Database::query('UPDATE stories SET cluster_count=? WHERE cluster_id=?', [$n, $clusterId]);
    }

    private static function joinNames(array $names): string
    {
        if (count($names) <= 1) {
            return (string)($names[0] ?? '');
        }
        $last = array_pop($names);
        return implode(', ', $names) . ' and ' . $last;
    }

    /**
     * One-off backfill after an upgrade: cluster existing wire stories and compute their
     * suggested section. Runs at most once (flag in settings), bounded, on the next page view.
     */
    public static function backfillIfPending(): void
    {
        if (Database::setting('wire_backfill_pending') !== '1') {
            return;
        }
        Database::setSetting('wire_backfill_pending', '0');
        try {
            set_time_limit(120);
            self::sweep(600);
            NewsWire::resuggest(800);
        } catch (\Throwable $e) {
            error_log('Wire backfill: ' . $e->getMessage());
        }
    }

    /** Cluster any published wire story not yet examined (pending flag set by migrations). */
    public static function sweep(int $limit = 400): int
    {
        $rows = Database::all("SELECT id FROM stories WHERE kind='wire' AND status='published' AND cluster_id IS NULL AND cluster_checked=0 ORDER BY COALESCE(published_at,created_at) DESC LIMIT ?", [$limit]);
        $n = 0;
        foreach ($rows as $r) {
            if (self::assign($r['id'])) {
                $n++;
            }
            Database::query('UPDATE stories SET cluster_checked=1 WHERE id=?', [$r['id']]);
        }
        return $n;
    }

    /** Members of a story's cluster, oldest first, for the "Also covered by" list and the timeline. */
    public static function members(?string $clusterId, string $exceptId = ''): array
    {
        if (!$clusterId) {
            return [];
        }
        return Database::all("SELECT id,slug,title,source_name,source_url,source_author,COALESCE(published_at,created_at) AS t FROM stories WHERE cluster_id=? AND status='published' AND id<>? ORDER BY t ASC", [$clusterId, $exceptId]);
    }

    public static function get(?string $clusterId): ?array
    {
        if (!$clusterId) {
            return null;
        }
        $c = Database::one('SELECT * FROM story_clusters WHERE id=?', [$clusterId]);
        if ($c) {
            $c['outlets'] = json_decode((string)$c['outlets_json'], true) ?: [];
        }
        return $c;
    }
}
