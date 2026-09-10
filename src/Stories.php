<?php
declare(strict_types=1);

namespace MeNews;

use MeNews\Support\Categories;

/** Read-model helpers for stories (wire + community). */
final class Stories
{
    private const SELECT = "SELECT s.*, u.is_verified AS reporter_verified, u.reputation AS reporter_reputation, u.handle AS author_handle, u.title AS author_title, u.accent AS author_accent
        FROM stories s LEFT JOIN users u ON u.id=s.author_user_id";

    public static function makeSlug(string $title, string $id): string
    {
        return slugify($title, 70) . '-' . substr($id, 0, 6);
    }

    /**
     * Published stories. Filters: q, category, county, location, kind, author, exclude, featured.
     */
    public static function feed(array $f = [], int $limit = 40, int $offset = 0): array
    {
        $sql = self::SELECT . " WHERE s.status='published'";
        $args = [];
        if (!empty($f['category'])) {
            $sql .= ' AND s.category=?';
            $args[] = $f['category'];
        }
        if (!empty($f['county'])) {
            $sql .= ' AND s.county LIKE ?';
            $args[] = $f['county'];
        }
        if (!empty($f['location'])) {
            $sql .= ' AND (s.location_name LIKE ? OR s.local_area LIKE ? OR s.county LIKE ?)';
            array_push($args, '%' . $f['location'] . '%', '%' . $f['location'] . '%', '%' . $f['location'] . '%');
        }
        if (!empty($f['kind'])) {
            $sql .= ' AND s.kind=?';
            $args[] = $f['kind'];
        }
        if (!empty($f['author'])) {
            $sql .= ' AND s.author_user_id=?';
            $args[] = $f['author'];
        }
        if (!empty($f['exclude'])) {
            $sql .= ' AND s.id NOT IN (' . implode(',', array_fill(0, count($f['exclude']), '?')) . ')';
            array_push($args, ...$f['exclude']);
        }
        if (!empty($f['with_image'])) {
            $sql .= " AND (s.image_url IS NOT NULL OR s.media_public IS NOT NULL)";
        }
        if (!empty($f['q'])) {
            $sql .= ' AND (s.title LIKE ? OR s.summary LIKE ? OR s.body LIKE ? OR s.location_name LIKE ? OR s.county LIKE ?)';
            array_push($args, ...array_fill(0, 5, '%' . $f['q'] . '%'));
        }
        $sql .= ' ORDER BY COALESCE(s.published_at, s.created_at) DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
        return array_map([self::class, 'present'], Database::all($sql, $args));
    }

    public static function countPublished(array $f = []): int
    {
        $sql = "SELECT COUNT(*) FROM stories s WHERE s.status='published'";
        $args = [];
        if (!empty($f['category'])) {
            $sql .= ' AND s.category=?';
            $args[] = $f['category'];
        }
        if (!empty($f['county'])) {
            $sql .= ' AND s.county LIKE ?';
            $args[] = $f['county'];
        }
        if (!empty($f['q'])) {
            $sql .= ' AND (s.title LIKE ? OR s.summary LIKE ? OR s.body LIKE ? OR s.location_name LIKE ? OR s.county LIKE ?)';
            array_push($args, ...array_fill(0, 5, '%' . $f['q'] . '%'));
        }
        return Database::count($sql, $args);
    }

    public static function bySlug(string $slug): ?array
    {
        $row = Database::one(self::SELECT . " WHERE s.slug=? AND s.status='published'", [$slug]);
        return $row ? self::present($row) : null;
    }

    public static function byId(string $id, bool $publishedOnly = true): ?array
    {
        $row = Database::one(self::SELECT . ' WHERE s.id=?' . ($publishedOnly ? " AND s.status='published'" : ''), [$id]);
        return $row ? self::present($row) : null;
    }

    public static function related(array $story, int $limit = 4): array
    {
        $rows = self::feed(['category' => $story['category'], 'exclude' => [$story['id']]], $limit);
        if (count($rows) < $limit && !empty($story['county'])) {
            $more = self::feed(['county' => $story['county'], 'exclude' => array_merge([$story['id']], array_column($rows, 'id'))], $limit - count($rows));
            $rows = array_merge($rows, $more);
        }
        return $rows;
    }

    /** Featured hero: editor-featured first, otherwise the freshest story with an image. */
    public static function hero(): ?array
    {
        $row = Database::one(self::SELECT . " WHERE s.status='published' AND s.is_featured=1 ORDER BY COALESCE(s.published_at,s.created_at) DESC LIMIT 1");
        if (!$row) {
            $row = Database::one(self::SELECT . " WHERE s.status='published' AND s.category IN ('National','Local') AND (s.image_url IS NOT NULL OR s.media_public IS NOT NULL) ORDER BY COALESCE(s.published_at,s.created_at) DESC LIMIT 1");
        }
        return $row ? self::present($row) : null;
    }

    public static function trending(int $limit = 6): array
    {
        $since = gmdate('Y-m-d\TH:i:s', time() - 3 * 86400) . '+00:00';
        $rows = Database::all(self::SELECT . " WHERE s.status='published' AND COALESCE(s.published_at,s.created_at)>? ORDER BY s.views DESC LIMIT ?", [$since, $limit]);
        return array_map([self::class, 'present'], $rows);
    }

    /** Stories published per hour for the last 24 hours (oldest → newest). */
    public static function pulse(): array
    {
        $since = gmdate('Y-m-d\TH:i:s', time() - 24 * 3600) . '+00:00';
        $rows = Database::all("SELECT strftime('%Y-%m-%dT%H', COALESCE(published_at,created_at)) AS h, COUNT(*) AS n FROM stories WHERE status='published' AND COALESCE(published_at,created_at)>? GROUP BY h", [$since]);
        $map = array_column($rows, 'n', 'h');
        $out = [];
        for ($i = 23; $i >= 0; $i--) {
            $out[] = (int)($map[gmdate('Y-m-d\TH', time() - $i * 3600)] ?? 0);
        }
        return $out;
    }

    /** Most active counties this week. */
    public static function countyActivity(int $limit = 8): array
    {
        $since = gmdate('Y-m-d\TH:i:s', time() - 7 * 86400) . '+00:00';
        return Database::all("SELECT county, COUNT(*) AS n FROM stories WHERE status='published' AND county IS NOT NULL AND county<>'' AND COALESCE(published_at,created_at)>? GROUP BY county ORDER BY n DESC LIMIT ?", [$since, $limit]);
    }

    public static function categoryCounts(): array
    {
        $rows = Database::all("SELECT category, COUNT(*) AS n FROM stories WHERE status='published' GROUP BY category");
        return array_column($rows, 'n', 'category');
    }

    public static function mapPoints(int $limit = 200, string $category = '', string $kind = ''): array
    {
        $sql = "SELECT id,slug,title,location_name,county,category,kind,source_name,image_url,media_type,media_public,latitude,longitude,COALESCE(published_at,created_at) AS time FROM stories WHERE status='published' AND latitude IS NOT NULL AND longitude IS NOT NULL";
        $args = [];
        if ($category !== '') {
            $sql .= ' AND category=?';
            $args[] = $category;
        }
        if ($kind !== '') {
            $sql .= ' AND kind=?';
            $args[] = $kind;
        }
        $rows = Database::all($sql . ' ORDER BY time DESC LIMIT ' . (int)$limit, $args);
        foreach ($rows as &$r) {
            $r['url'] = '/story/' . $r['slug'];
            $r['image'] = $r['media_type'] === 'image' && $r['media_public'] ? '/media/' . $r['id'] : ($r['image_url'] ?: null);
            $r['ago'] = time_ago($r['time']);
            $r['latitude'] = (float)$r['latitude'];
            $r['longitude'] = (float)$r['longitude'];
            unset($r['image_url'], $r['media_type'], $r['media_public']);
        }
        return $rows;
    }

    /** Published stories per county (last 7 days) with county centroids, for the map heat layer. */
    public static function countyPoints(): array
    {
        $since = gmdate('Y-m-d\TH:i:s', time() - 7 * 86400) . '+00:00';
        $rows = Database::all("SELECT county, COUNT(*) AS n FROM stories WHERE status='published' AND county IS NOT NULL AND county<>'' AND COALESCE(published_at,created_at)>? GROUP BY county", [$since]);
        $out = [];
        foreach ($rows as $r) {
            $pt = \MeNews\Support\Geo::county($r['county']);
            if ($pt) {
                $out[] = ['county' => $r['county'], 'n' => (int)$r['n'], 'latitude' => $pt[0], 'longitude' => $pt[1], 'url' => '/county/' . slugify($r['county'])];
            }
        }
        return $out;
    }

    public static function comments(string $storyId): array
    {
        return Database::all("SELECT c.id,c.created_at,c.author,c.body,u.handle,u.accent,u.is_verified FROM comments c LEFT JOIN users u ON u.id=c.user_id WHERE c.story_id=? AND c.status='published' ORDER BY c.created_at", [$storyId]);
    }

    public static function confirmations(string $storyId): int
    {
        return Database::count('SELECT COUNT(*) FROM confirmations WHERE story_id=?', [$storyId]);
    }

    /** Shape a database row for templates / JSON, removing private fields. */
    public static function present(array $s): array
    {
        $s['url'] = '/story/' . $s['slug'];
        $s['media_url'] = !empty($s['media_public']) ? '/media/' . $s['id'] : null;
        $s['image'] = $s['media_type'] === 'image' && $s['media_url'] ? $s['media_url'] : ($s['image_url'] ?: null);
        $s['reporter_verified'] = (bool)($s['reporter_verified'] ?? false);
        $s['is_featured'] = (bool)($s['is_featured'] ?? false);
        $s['category_slug'] = Categories::slug((string)$s['category']);
        $s['time'] = $s['published_at'] ?: $s['created_at'];
        unset($s['media_original'], $s['media_public'], $s['moderation_json'], $s['title_hash'], $s['transcript']);
        return $s;
    }
}
