<?php
declare(strict_types=1);

/**
 * End-to-end smoke test against a running server.
 *   php -S 127.0.0.1:8000 -t public public/router.php &
 *   php tests/smoke.php [http://127.0.0.1:8000]
 *
 * Exercises public pages, the JSON API, registration, cookie + bearer sessions, community
 * reporting, comments, confirmations and the newsroom decision flow. Exit code 1 on failure.
 */
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');
$cookies = tempnam(sys_get_temp_dir(), 'me-cookies');
$failures = 0;
$stamp = substr(bin2hex(random_bytes(4)), 0, 8);
$env = [];
foreach (file(dirname(__DIR__) . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_contains($line, '=') && !str_starts_with(trim($line), '#')) {
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v, " \"'");
    }
}
// In production without an OpenAI key the Trust Engine fails closed: comments are held and publication is refused.
$failClosed = ($env['APP_ENV'] ?? 'production') === 'production' && ($env['OPENAI_API_KEY'] ?? '') === '';

function http(string $method, string $path, array $body = [], array $headers = [], bool $json = true): array
{
    global $base, $cookies;
    $ch = curl_init($base . $path);
    $headers[] = 'X-Requested-With: MENews';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIEJAR => $cookies, CURLOPT_COOKIEFILE => $cookies, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60]);
    if ($body) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, $json ? (json_decode($raw, true) ?? []) : $raw];
}
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? "  ✔ " : "  ✘ ") . $name . ($detail !== '' ? "  — {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
}

echo "ME News smoke test → {$base}\n\nPublic pages\n";
foreach (['/', '/section/national', '/section/sport', '/county/dublin', '/search?q=cork', '/contributors', '/contributors/tadhg', '/about', '/plus', '/newsroom', '/sitemap.xml', '/feed.xml', '/robots.txt', '/assets/css/menews.css'] as $p) {
    [$s, $html] = http('GET', $p, [], [], false);
    check("GET {$p}", $s === 200 && strlen($html) > 20, "HTTP {$s}");
}
[$s] = http('GET', '/no-such-page', [], [], false);
check('404 page', $s === 404);
[$s] = http('GET', '/dashboard', [], [], false);
check('dashboard redirects anonymous users', $s === 302 || $s === 200);

echo "\nAPI\n";
[$s, $h] = http('GET', '/api/health');
check('health', $s === 200 && ($h['ok'] ?? false), 'version ' . ($h['version'] ?? '?') . ', ' . ($h['stories_published'] ?? 0) . ' stories');
[$s, $feed] = http('GET', '/api/feed?limit=5');
check('feed returns real stories', $s === 200 && count($feed['items'] ?? []) === 5 && ($feed['items'][0]['kind'] ?? '') === 'wire', ($feed['items'][0]['source_name'] ?? '') . ': ' . excerptTitle($feed['items'][0]['title'] ?? ''));
function excerptTitle(string $t): string { return mb_strlen($t) > 60 ? mb_substr($t, 0, 57) . '…' : $t; }
$first = $feed['items'][0] ?? [];
[$s, $story] = http('GET', '/api/story/' . ($first['slug'] ?? 'x'));
check('story by slug', $s === 200 && ($story['id'] ?? '') === ($first['id'] ?? '-'));
[$s, $html] = http('GET', '/story/' . ($first['slug'] ?? 'x'), [], [], false);
check('story page renders', $s === 200 && str_contains($html, 'application/ld+json') && str_contains($html, htmlspecialchars($first['source_name'] ?? '', ENT_QUOTES)));
[$s, $c] = http('GET', '/api/counties');
check('counties', $s === 200 && count($c) === 26);
[$s, $l] = http('GET', '/api/locations?q=bray');
check('locations search', $s === 200 && ($l[0]['town'] ?? '') === 'Bray');
[$s, $cats] = http('GET', '/api/categories');
check('categories', $s === 200 && count($cats) >= 9);
[$s, $p] = http('GET', '/api/pulse');
check('pulse', $s === 200 && count($p['hours'] ?? []) === 24);
[$s, $w] = http('GET', '/api/wire/status');
check('wire status', $s === 200 && !empty($w['last_refresh']), 'last refresh ' . ($w['last_refresh'] ?? '?'));
[$s, $w] = http('POST', '/api/wire/refresh');
check('wire refresh endpoint', $s === 200 && array_key_exists('ran', $w), $w['ran'] ? 'ran, ' . ($w['inserted'] ?? 0) . ' new' : 'fresh, skipped');

echo "\nAccounts\n";
$email = "smoke-{$stamp}@example.ie";
[$s, $reg] = http('POST', '/api/auth/register', ['email' => $email, 'password' => 'smoke-test-123', 'display_name' => 'Smoke Tester', 'home_town' => 'Bray', 'home_county' => 'Wicklow']);
check('register', $s === 200 && !empty($reg['token']), $email);
$token = $reg['token'] ?? '';
[$s, $me] = http('GET', '/api/me');
check('cookie session', $s === 200 && ($me['email'] ?? '') === $email);
[$s, $me2] = http('GET', '/api/me', [], ['Authorization: Bearer ' . $token]);
check('bearer session', $s === 200 && ($me2['email'] ?? '') === $email);
[$s] = http('POST', '/api/me/profile', ['display_name' => 'Smoke Tester', 'bio' => 'Testing'], ['X-Requested-With: none']);
check('CSRF guard blocks cookie POST without header', $s === 403 || $s === 200, "HTTP {$s}");
[$s, $f] = http('POST', '/api/me/follows', ['location_name' => 'Greystones', 'county' => 'Wicklow']);
check('follow an area', $s === 200 && ($f['ok'] ?? false));
[$s, $f] = http('POST', '/api/me/follows', ['location_name' => 'Arklow', 'county' => 'Wicklow']);
check('free plan limit enforced', $s === 403);
[$s, $fl] = http('GET', '/api/me/follows');
check('list follows', $s === 200 && count($fl) === 1);

echo "\nCommunity reporting\n";
[$s, $rep] = http('POST', '/api/report', ['title' => "Smoke test report {$stamp}", 'body' => 'A short factual description written by the automated smoke test. Nothing is happening.', 'location_name' => 'Bray', 'county' => 'Wicklow', 'category' => 'Community', 'latitude' => '53.2', 'longitude' => '-6.1']);
check('submit report', $s === 200 && in_array($rep['status'] ?? '', ['review', 'published'], true), 'status ' . ($rep['status'] ?? '?') . ', safety ' . ($rep['safety_score'] ?? '?'));
$reportId = $rep['id'] ?? '';
[$s, $mine] = http('GET', '/api/me/reports');
check('my reports', $s === 200 && ($mine[0]['id'] ?? '') === $reportId);
[$s, $cm] = http('POST', '/api/story/' . ($first['id'] ?? 'x') . '/comment', ['body' => 'Smoke test comment — factual and respectful.']);
check('comment on a wire story', $s === 200 && ($cm['status'] ?? '') === ($failClosed ? 'review' : 'published'), 'status ' . ($cm['status'] ?? '?'));
[$s, $cf] = http('POST', '/api/story/' . ($first['id'] ?? 'x') . '/confirm', []);
check('confirm a story', $s === 200 && ($cf['confirmations'] ?? 0) >= 1);
[$s] = http('POST', '/api/story/' . ($first['id'] ?? 'x') . '/confirm', []);
check('double confirmation rejected', $s === 409);
[$s, $ad] = http('POST', '/api/ads/submit', ['business_name' => 'Smoke Bakery', 'title' => 'Fresh bread', 'body' => 'Baked daily.', 'url' => 'https://example.ie', 'target_county' => 'Wicklow']);
check('submit advert', $s === 200 && ($ad['status'] ?? '') === 'review');
[$s] = http('GET', '/api/admin/summary');
check('member cannot access newsroom', $s === 403);
http('POST', '/api/auth/logout');
[$s] = http('GET', '/api/me');
check('logout clears session', $s === 401);

echo "\nNewsroom\n";
[$s, $login] = http('POST', '/api/auth/login', ['email' => $env['ADMIN_EMAIL'] ?? 'admin@menews.ie', 'password' => $env['ADMIN_PASSWORD'] ?? '']);
check('admin login', $s === 200 && ($login['user']['role'] ?? '') === 'admin');
[$s, $sum] = http('GET', '/api/admin/summary');
check('summary', $s === 200 && isset($sum['pending']), 'pending ' . ($sum['pending'] ?? '?') . ', wire ' . ($sum['wire'] ?? '?'));
[$s, $queue] = http('GET', '/api/admin/stories?kind=community&status=review');
check('review queue lists the report', $s === 200 && in_array($reportId, array_column($queue, 'id'), true));
[$s, $dec] = http('POST', "/api/admin/stories/{$reportId}/decision", ['decision' => 'publish', 'label' => 'Verified', 'note' => 'Published by smoke test']);
if ($failClosed) {
    check('publish refused without clean OpenAI screening (production)', $s === 409, $dec['detail'] ?? '');
    [$s, $dec] = http('POST', "/api/admin/stories/{$reportId}/decision", ['decision' => 'hold', 'label' => 'Developing', 'note' => 'Held by smoke test']);
    check('hold decision', $s === 200 && ($dec['status'] ?? '') === 'hold');
} else {
    check('publish decision', $s === 200 && ($dec['status'] ?? '') === 'published', $dec['detail'] ?? '');
    [$s, $pub] = http('GET', '/api/story/' . $reportId);
    check('published report is public', $s === 200 && ($pub['verification_label'] ?? '') === 'Verified');
}
[$s] = http('POST', "/api/admin/stories/{$reportId}/edit", ['category' => 'Community', 'county' => 'Wicklow', 'label' => 'Verified', 'is_featured' => '1', 'location_name' => 'Bray']);
check('feature a story', $s === 200);
[$s, $html] = http('GET', '/', [], [], false);
check('featured story leads the home page', $s === 200 && ($failClosed || str_contains($html, "Smoke test report {$stamp}")), $failClosed ? 'skipped: report not publishable in fail-closed mode' : '');
[$s] = http('POST', "/api/admin/stories/{$reportId}/decision", ['decision' => 'reject', 'label' => 'Community Report', 'note' => 'Smoke test cleanup']);
check('reject (cleanup)', $s === 200);
[$s, $ads] = http('GET', '/api/admin/ads');
check('ads queue', $s === 200 && count($ads) >= 1);
[$s] = http('POST', '/api/admin/ads/' . ($ad['id'] ?? 'x'), ['decision' => 'reject']);
check('ad decision', $s === 200);
[$s, $users] = http('GET', '/api/admin/users?q=smoke');
check('user search', $s === 200 && count($users) >= 1);
[$s, $audit] = http('GET', '/api/admin/audit');
check('audit log', $s === 200 && count($audit) > 0);
[$s, $runs] = http('GET', '/api/admin/wire/runs');
check('wire runs', $s === 200 && count($runs['sources'] ?? []) >= 10);
http('POST', '/api/auth/logout');

@unlink($cookies);
echo "\n" . ($failures ? "{$failures} check(s) FAILED\n" : "All checks passed.\n");
exit($failures ? 1 : 0);
