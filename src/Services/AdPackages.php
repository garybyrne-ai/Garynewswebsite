<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Database;
use MeNews\Http\HttpException;

/**
 * Ad packages: a fixed number of impressions for a fixed price, bought up front.
 * The newsroom sets the price, the impression count and the tier of each package.
 *
 * Tiers decide where an advert may appear:
 *   sidebar - sidebar cards on section, county, story and notices pages
 *   site    - sidebar cards + banners on section, county and story pages
 *   premium - everything at once, including the home page banner and sidebar, with priority
 */
final class AdPackages
{
    public const TIERS = [
        'sidebar' => ['label' => 'Sidebar', 'weight' => 1, 'home' => false, 'banner' => false, 'blurb' => 'Sidebar cards on section, county, story and notices pages'],
        'site' => ['label' => 'Site-wide', 'weight' => 2, 'home' => false, 'banner' => true, 'blurb' => 'Sidebar cards and banners on section, county and story pages'],
        'premium' => ['label' => 'Front page', 'weight' => 3, 'home' => true, 'banner' => true, 'blurb' => 'Every placement at once, including the home page, with priority'],
    ];

    /** Bundled defaults, created once on install/upgrade and editable afterwards. */
    private const DEFAULTS = [
        ['slug' => 'starter', 'name' => 'Starter', 'tagline' => 'Try local advertising', 'price_cents' => 600, 'impressions' => 1000, 'tier' => 'sidebar', 'badge' => '', 'sort' => 1,
            'features' => ['1,000 impressions', 'Sidebar card on section, county and story pages', 'Target all of Ireland, one county or one town', 'Self-serve designer, six templates', 'Live impressions and clicks']],
        ['slug' => 'local-reach', 'name' => 'Local Reach', 'tagline' => 'Most popular', 'price_cents' => 900, 'impressions' => 4000, 'tier' => 'site', 'badge' => 'Best value', 'sort' => 2,
            'features' => ['4,000 impressions', 'Sidebar card and banner on section, county and story pages', 'County or town targeting', 'Self-serve designer, six templates', 'Live impressions and clicks']],
        ['slug' => 'front-page', 'name' => 'Front Page', 'tagline' => 'Everywhere at once', 'price_cents' => 2000, 'impressions' => 10000, 'tier' => 'premium', 'badge' => 'Maximum reach', 'sort' => 3,
            'features' => ['10,000 impressions', 'Home page banner and sidebar plus every other placement', 'Priority over other adverts', 'County or town targeting', 'Self-serve designer, six templates', 'Live impressions and clicks']],
    ];

    public static function seedDefaults(\PDO $pdo): void
    {
        if ((int)$pdo->query('SELECT COUNT(*) FROM ad_packages')->fetchColumn() > 0) {
            return;
        }
        $stmt = $pdo->prepare('INSERT INTO ad_packages(id,slug,name,tagline,price_cents,impressions,tier,features_json,badge,sort,active,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,1,?)');
        foreach (self::DEFAULTS as $d) {
            $stmt->execute([uuid(), $d['slug'], $d['name'], $d['tagline'], $d['price_cents'], $d['impressions'], $d['tier'], json_encode($d['features']), $d['badge'], $d['sort'], now()]);
        }
    }

    public static function all(bool $activeOnly = true): array
    {
        $rows = Database::all('SELECT * FROM ad_packages' . ($activeOnly ? ' WHERE active=1' : '') . ' ORDER BY sort, price_cents');
        return array_map([self::class, 'present'], $rows);
    }

    public static function find(string $id): ?array
    {
        $row = Database::one('SELECT * FROM ad_packages WHERE id=?', [$id]);
        return $row ? self::present($row) : null;
    }

    public static function present(array $p): array
    {
        $p['features'] = json_decode((string)($p['features_json'] ?? '[]'), true) ?: [];
        $p['tier_label'] = self::TIERS[$p['tier']]['label'] ?? $p['tier'];
        $p['tier_blurb'] = self::TIERS[$p['tier']]['blurb'] ?? '';
        $p['price_label'] = self::money((int)$p['price_cents']);
        $p['per_thousand'] = self::money((int)round($p['price_cents'] / max(1, (int)$p['impressions']) * 1000));
        $p['active'] = (bool)$p['active'];
        unset($p['features_json']);
        return $p;
    }

    public static function money(int $cents): string
    {
        $symbol = ['EUR' => '€', 'GBP' => '£', 'USD' => '$'][Ads::currency()] ?? Ads::currency() . ' ';
        return $symbol . number_format($cents / 100, $cents % 100 === 0 ? 0 : 2);
    }

    /** Admin: create or update a package. Prices are given in units (e.g. 6 or 6.50). */
    public static function save(?string $id, array $in): array
    {
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 60);
        $price = (int)round((float)str_replace(',', '.', (string)($in['price'] ?? '0')) * 100);
        $impressions = (int)($in['impressions'] ?? 0);
        $tier = in_array($in['tier'] ?? '', array_keys(self::TIERS), true) ? $in['tier'] : 'sidebar';
        if ($name === '') {
            throw new HttpException(400, 'Give the package a name');
        }
        if ($price < 100) {
            throw new HttpException(400, 'Price must be at least 1.00');
        }
        if ($impressions < 100 || $impressions > 10_000_000) {
            throw new HttpException(400, 'Impressions must be between 100 and 10,000,000');
        }
        $features = array_values(array_filter(array_map(static fn($f) => mb_substr(trim((string)$f), 0, 120), preg_split('/\r?\n/', (string)($in['features'] ?? '')) ?: [])));
        $row = [
            'name' => $name, 'tagline' => mb_substr(trim((string)($in['tagline'] ?? '')), 0, 80), 'price_cents' => $price, 'impressions' => $impressions, 'tier' => $tier,
            'features_json' => json_encode($features, JSON_UNESCAPED_UNICODE), 'badge' => mb_substr(trim((string)($in['badge'] ?? '')), 0, 30),
            'sort' => (int)($in['sort'] ?? 0), 'active' => ($in['active'] ?? '1') === '1' ? 1 : 0, 'updated_at' => now(),
        ];
        if ($id) {
            if (!Database::one('SELECT id FROM ad_packages WHERE id=?', [$id])) {
                throw new HttpException(404, 'Package not found');
            }
            Database::update('ad_packages', $row, 'id=?', [$id]);
        } else {
            $id = uuid();
            $slug = slugify($name, 40);
            $base = $slug;
            $i = 1;
            while (Database::one('SELECT id FROM ad_packages WHERE slug=?', [$slug])) {
                $slug = $base . '-' . (++$i);
            }
            Database::insert('ad_packages', $row + ['id' => $id, 'slug' => $slug, 'created_at' => now()]);
        }
        return self::find($id);
    }

    public static function delete(string $id): void
    {
        // Orders keep the package name/tier, so deleting a package never breaks running adverts.
        Database::query('DELETE FROM ad_packages WHERE id=?', [$id]);
    }
}
