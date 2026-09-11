<?php
declare(strict_types=1);

namespace MeNews;

/** Additive schema upgrades for existing installations (SQLite: ADD COLUMN only). */
final class Migrations
{
    /** table => column => definition */
    private const COLUMNS = [
        'stories' => [
            'votes_total' => 'INTEGER NOT NULL DEFAULT 0',
            'signal_json' => 'TEXT',
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
        // Default advertising settings
        $pdo->exec("INSERT OR IGNORE INTO settings(key,value) VALUES('ads_price_cents','2500'),('ads_trial_days','7'),('ads_currency','EUR')");
    }
}
