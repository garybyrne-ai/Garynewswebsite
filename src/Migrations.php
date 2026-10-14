<?php
declare(strict_types=1);

namespace MeNews;

/** Additive schema upgrades for existing installations (SQLite: ADD COLUMN only). */
final class Migrations
{
    /** table => column => definition */
    private const COLUMNS = [
        'stories' => [
            'pushed_at' => 'TEXT',
            'votes_total' => 'INTEGER NOT NULL DEFAULT 0',
            'signal_json' => 'TEXT',
            'cluster_id' => 'TEXT',
            'cluster_count' => 'INTEGER NOT NULL DEFAULT 1',
            'category_locked' => 'INTEGER NOT NULL DEFAULT 0',
            'suggested_category' => 'TEXT',
            'reporter_contact' => 'TEXT',
            'reporter_verified_at' => 'TEXT',
            'verify_token' => 'TEXT',
            'image_hash' => 'TEXT',
            'exif_json' => 'TEXT',
            'corroborations' => 'INTEGER NOT NULL DEFAULT 0',
            'cluster_checked' => 'INTEGER NOT NULL DEFAULT 0',
            'submitter_ip' => 'TEXT',
            'submitter_user_agent' => 'TEXT',
            'language' => "TEXT NOT NULL DEFAULT 'en'",
        ],
        'confirmations' => [
            'voter_key' => 'TEXT',
            'ip_address' => 'TEXT',
            'user_agent' => 'TEXT',
        ],
        'sessions' => [
            'ip_address' => 'TEXT',
            'user_agent' => 'TEXT',
            'last_seen_at' => 'TEXT',
        ],
        'subscriptions' => [
            'gateway' => 'TEXT',
            'razorpay_customer_id' => 'TEXT',
            'razorpay_subscription_id' => 'TEXT',
        ],
        'comments' => [
            'ip_address' => 'TEXT',
            'user_agent' => 'TEXT',
        ],
        'notices' => [
            'ip_address' => 'TEXT',
            'user_agent' => 'TEXT',
        ],
        'closures' => [
            'ip_address' => 'TEXT',
            'user_agent' => 'TEXT',
        ],
        'users' => [
            'reports_filed' => 'INTEGER NOT NULL DEFAULT 0',
            'reports_published' => 'INTEGER NOT NULL DEFAULT 0',
            'last_monthly_at' => 'TEXT',
            'failed_logins' => 'INTEGER NOT NULL DEFAULT 0',
            'locked_until' => 'TEXT',
            'registration_ip' => 'TEXT',
            'registration_user_agent' => 'TEXT',
            'last_login_ip' => 'TEXT',
            'last_login_at' => 'TEXT',
        ],
        'alert_subscriptions' => [
            'language' => 'TEXT',
        ],
        'ads' => [
            'design_json' => 'TEXT',
            'logo_path' => 'TEXT',
            'image_path' => 'TEXT',
            'cta' => 'TEXT',
            'badge' => 'TEXT',
            'placement' => "TEXT NOT NULL DEFAULT 'both'",
            'is_house' => 'INTEGER NOT NULL DEFAULT 0',
            'plan_status' => "TEXT NOT NULL DEFAULT 'none'",
            'gateway' => 'TEXT',
            'gateway_customer_id' => 'TEXT',
            'gateway_subscription_id' => 'TEXT',
            'trial_ends_at' => 'TEXT',
            'current_period_end' => 'TEXT',
            'approved_at' => 'TEXT',
            'updated_at' => 'TEXT',
            'paused' => 'INTEGER NOT NULL DEFAULT 0',
            'weight' => 'INTEGER NOT NULL DEFAULT 1',
            'notes' => 'TEXT',
            'tier' => "TEXT NOT NULL DEFAULT 'sidebar'",
            'order_id' => 'TEXT',
        ],
    ];

    public static function run(\PDO $pdo): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            $existing = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(\PDO::FETCH_ASSOC), 'name');
            foreach ($columns as $name => $definition) {
                if (!in_array($name, $existing, true)) {
                    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$name} {$definition}");
                }
            }
        }
        // Default advertising, membership and content-position settings
        $pdo->exec("INSERT OR IGNORE INTO settings(key,value) VALUES('ads_price_cents','99900'),('ads_trial_days','7'),('ads_currency','INR'),('plus_price_cents','9900'),('plus_annual_cents','99900'),('wire_mode','clustered'),('wire_images','1')");
        // Wire stories are labelled by their source, never "Verified" (that word is reserved for reports our desk checked).
        $pdo->exec("UPDATE stories SET verification_label='Wire' WHERE kind='wire' AND verification_label='Verified'");
        $pdo->exec("UPDATE stories SET signal_json=NULL WHERE signal_json LIKE '%clustered%'");
        // Cluster and classify existing wire stories on the next page view (bounded, once).
        if ((int)$pdo->query("SELECT COUNT(*) FROM stories WHERE kind='wire' AND suggested_category IS NULL")->fetchColumn() > 0) {
            $pdo->exec("INSERT INTO settings(key,value) VALUES('wire_backfill_pending','1') ON CONFLICT(key) DO UPDATE SET value='1'");
        }
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stories_cluster ON stories(cluster_id)");
        $pdo->exec("INSERT OR IGNORE INTO settings(key,value) VALUES('archive_days','14')");
        \MeNews\Services\AdPackages::seedDefaults($pdo);
        $pdo->exec("UPDATE ads SET tier='premium' WHERE is_house=1 AND tier<>'premium'");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_confirmations_voter ON confirmations(story_id, voter_key)");
        // Lets the newsroom spot one IP filing many reports/comments/notices (abuse patterns).
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stories_submitter_ip ON stories(submitter_ip)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comments_ip ON comments(ip_address)");
        $pdo->exec("INSERT OR IGNORE INTO settings(key,value) VALUES('pii_retention_days','180')");
        $pdo->exec("CREATE TABLE IF NOT EXISTS razorpay_events (id TEXT PRIMARY KEY, created_at TEXT NOT NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS pow_challenges (id TEXT PRIMARY KEY, difficulty INTEGER NOT NULL, created_at TEXT NOT NULL, expires_at TEXT NOT NULL)");
        $pdo->exec("INSERT OR IGNORE INTO settings(key,value) VALUES('pow_difficulty','5')");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stories_language ON stories(language)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
            id TEXT PRIMARY KEY,
            user_id TEXT,
            endpoint TEXT UNIQUE NOT NULL,
            p256dh TEXT NOT NULL,
            auth TEXT NOT NULL,
            county TEXT,
            user_agent TEXT,
            created_at TEXT NOT NULL,
            last_sent_at TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_push_county ON push_subscriptions(county)");

        // Hourly view buckets per story — cheap enough to keep forever-ish, used only to compute
        // a recent-views "velocity" score (breaking now) rather than a cumulative total.
        $pdo->exec("CREATE TABLE IF NOT EXISTS story_view_hours (
            story_id TEXT NOT NULL,
            hour TEXT NOT NULL,
            views INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (story_id, hour)
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_story_view_hours_hour ON story_view_hours(hour)");

        // One row per (signed-in reader, story) they've actually opened — the "because you read
        // X" recommender's only input. Never collected for anonymous visitors.
        $pdo->exec("CREATE TABLE IF NOT EXISTS story_reads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id TEXT NOT NULL,
            story_id TEXT NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE(user_id, story_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_story_reads_user ON story_reads(user_id, created_at)");

        // "Follow this story": notified when the wire adds a new outlet to the same cluster.
        $pdo->exec("CREATE TABLE IF NOT EXISTS story_follows (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id TEXT NOT NULL,
            story_id TEXT NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE(user_id, story_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_story_follows_story ON story_follows(story_id)");

        // Daily AQI / mandi-price snapshots, written alongside the existing 30-min/1-hour file
        // cache, so /my-state can chart a real trend instead of only showing today's reading.
        $pdo->exec("CREATE TABLE IF NOT EXISTS aqi_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            county TEXT NOT NULL,
            aqi INTEGER NOT NULL,
            label TEXT,
            recorded_at TEXT NOT NULL
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_aqi_history_county ON aqi_history(county, recorded_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS mandi_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            county TEXT NOT NULL,
            commodity TEXT NOT NULL,
            modal TEXT,
            recorded_at TEXT NOT NULL
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mandi_history_county ON mandi_history(county, commodity, recorded_at)");

        // Social sign-in: one row per (provider, that provider's own user id), linked to our
        // users table. A reader can hold several rows (Google + Facebook) against one account.
        $pdo->exec("CREATE TABLE IF NOT EXISTS oauth_identities (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider TEXT NOT NULL,
            provider_user_id TEXT NOT NULL,
            user_id TEXT NOT NULL,
            email TEXT,
            created_at TEXT NOT NULL,
            UNIQUE(provider, provider_user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_oauth_identities_user ON oauth_identities(user_id)");
    }
}
