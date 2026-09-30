<?php
declare(strict_types=1);

/**
 * Erase IP addresses and User-Agent strings recorded against submissions once they are older
 * than the retention window (Newsroom -> Settings -> "PII retention (days)", default 180 —
 * long enough to satisfy law-enforcement/abuse-trace requests, short enough to limit what a
 * data breach could expose). The content of the report/comment/notice/closure itself, and the
 * account rows they belong to, are left untouched — only the forensic IP/UA trail is cleared.
 *
 *   php scripts/purge-pii.php            # uses the configured retention window
 *   php scripts/purge-pii.php --days=30  # override for one run
 *
 * Safe to run from cron daily; every UPDATE is a no-op once a row has already been cleared.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/src/bootstrap.php';

use MeNews\Database;

$override = null;
foreach ($argv as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $override = (int)$m[1];
    }
}
$days = $override ?? max(1, (int)(Database::setting('pii_retention_days', '180') ?? 180));
$cutoff = gmdate('Y-m-d\TH:i:s', time() - $days * 86400) . '+00:00';

$jobs = [
    ['stories', "created_at<? AND (submitter_ip IS NOT NULL OR submitter_user_agent IS NOT NULL)", 'submitter_ip=NULL,submitter_user_agent=NULL'],
    ['comments', "created_at<? AND (ip_address IS NOT NULL OR user_agent IS NOT NULL)", 'ip_address=NULL,user_agent=NULL'],
    ['notices', "created_at<? AND (ip_address IS NOT NULL OR user_agent IS NOT NULL)", 'ip_address=NULL,user_agent=NULL'],
    ['closures', "created_at<? AND (ip_address IS NOT NULL OR user_agent IS NOT NULL)", 'ip_address=NULL,user_agent=NULL'],
    ['confirmations', "created_at<? AND (ip_address IS NOT NULL OR user_agent IS NOT NULL)", 'ip_address=NULL,user_agent=NULL'],
];

$pdo = Database::pdo();
$total = 0;
foreach ($jobs as [$table, $where, $set]) {
    $n = $pdo->prepare("UPDATE {$table} SET {$set} WHERE {$where}");
    $n->execute([$cutoff]);
    $count = $n->rowCount();
    $total += $count;
    echo str_pad($table, 16) . $count . " row(s) cleared\n";
}
// Registration/login IPs on accounts older than the window: keep the account, clear the trail.
$u = $pdo->prepare("UPDATE users SET registration_ip=NULL, registration_user_agent=NULL WHERE created_at<? AND (registration_ip IS NOT NULL OR registration_user_agent IS NOT NULL)");
$u->execute([$cutoff]);
$total += $u->rowCount();
echo str_pad('users', 16) . $u->rowCount() . " row(s) cleared\n";

echo "Done. {$total} row(s) cleared (older than {$days} day(s), cutoff {$cutoff}).\n";
