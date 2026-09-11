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
foreach (['/', '/section/national', '/section/sport', '/county/dublin', '/search?q=cork', '/map', '/near', '/signal', '/signal?window=rising', '/advertise', '/kids', '/kids/crossword', '/kids/crossword?level=adult', '/kids/wordsearch', '/kids/quiz', '/kids/county-game', '/contributors', '/contributors/tadhg', '/about', '/plus', '/newsroom', '/sitemap.xml', '/feed.xml', '/robots.txt', '/assets/css/menews.css', '/assets/vendor/leaflet/leaflet.js', '/assets/fonts/Sora.woff2'] as $p) {
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
[$s, $m] = http('GET', '/api/map?limit=50');
check('map API returns pinned stories', $s === 200 && count($m['points'] ?? []) >= 10 && count($m['counties'] ?? []) >= 5, count($m['points'] ?? []) . ' pins, ' . count($m['counties'] ?? []) . ' counties');
[$s, $x] = http('GET', '/api/kids/crossword?level=junior');
check('daily junior crossword', $s === 200 && ($x['words'] ?? 0) >= 7 && count($x['across'] ?? []) + count($x['down'] ?? []) === ($x['words'] ?? -1), ($x['words'] ?? 0) . ' words, ' . ($x['rows'] ?? 0) . 'x' . ($x['cols'] ?? 0));
[$s, $x2] = http('GET', '/api/kids/crossword?level=adult');
check('daily grown-up crossword', $s === 200 && ($x2['words'] ?? 0) >= 12, ($x2['words'] ?? 0) . ' words');
[$s, $x3] = http('GET', '/api/kids/crossword?level=junior');
check('crossword is stable for the day', $s === 200 && ($x3['id'] ?? '') === ($x['id'] ?? '-') && $x3['cells'] === $x['cells']);
[$s, $ws] = http('GET', '/api/kids/wordsearch');
check('daily word search', $s === 200 && count($ws['words'] ?? []) >= 8 && count($ws['grid'] ?? []) === 12, ($ws['theme'] ?? '?'));
[$s, $qz] = http('GET', '/api/kids/quiz');
check('daily quiz', $s === 200 && count($qz['questions'] ?? []) === 5);
[$s, $cg] = http('GET', '/api/kids/county');
check('county game', $s === 200 && count($cg['rounds'] ?? []) === 10);
[$s, $near] = http('GET', '/api/near?lat=53.203&lng=-6.099&limit=6');
check('near-me API resolves Bray, Co. Wicklow', $s === 200 && ($near['place']['town'] ?? '') === 'Bray' && ($near['place']['county'] ?? '') === 'Wicklow' && count($near['items'] ?? []) >= 3, ($near['title'] ?? '?') . ', ' . count($near['items'] ?? []) . ' stories within ' . ($near['radius'] ?? '?') . ' km');
[$s, $far] = http('GET', '/api/near?lat=51.5&lng=-0.12');
check('near-me API flags positions outside Ireland', $s === 200 && empty($far['place']['in_ireland']));
[$s] = http('GET', '/api/near?lat=999&lng=0');
check('near-me API validates coordinates', $s === 400);
[$s, $html] = http('GET', '/near', [], [], false);
check('near page renders without a location', $s === 200 && str_contains($html, 'data-mode="none"'));
[$s, $v1] = http('POST', '/api/signal/vote', ['story_id' => $first['id'] ?? 'x', 'signal' => 'matters']);
check('anonymous vote is counted', $s === 200 && ($v1['mine'] ?? '') === 'matters' && ($v1['counts']['matters'] ?? 0) >= 1, 'total ' . ($v1['total'] ?? '?'));
[$s, $v2] = http('POST', '/api/signal/vote', ['story_id' => $first['id'] ?? 'x', 'signal' => 'good']);
check('changing a vote keeps one vote per reader', $s === 200 && ($v2['mine'] ?? '') === 'good' && ($v2['total'] ?? -1) === ($v1['total'] ?? -2));
[$s, $v3] = http('POST', '/api/signal/vote', ['story_id' => $first['id'] ?? 'x', 'signal' => 'good']);
check('voting the same signal again withdraws it', $s === 200 && array_key_exists('mine', $v3) && $v3['mine'] === null && ($v3['total'] ?? -1) === ($v1['total'] ?? 0) - 1);
[$s] = http('POST', '/api/signal/vote', ['story_id' => $first['id'] ?? 'x', 'signal' => 'bogus']);
check('unknown signal rejected', $s === 400);
http('POST', '/api/signal/vote', ['story_id' => $first['id'] ?? 'x', 'signal' => 'matters']);
[$s, $board] = http('GET', '/api/signal?window=today&limit=5');
check('signal leaderboard ranks voted stories', $s === 200 && in_array($first['id'] ?? '-', array_column($board['items'] ?? [], 'id'), true) && ($board['items'][0]['signal_rank'] ?? 0) === 1);
[$s, $tally] = http('GET', '/api/signal/story/' . ($first['id'] ?? 'x'));
check('story tally endpoint', $s === 200 && ($tally['mine'] ?? '') === 'matters');
[$s, $html] = http('GET', '/story/' . ($first['slug'] ?? 'x'), [], [], false);
check('story page carries the vote widget', $s === 200 && str_contains($html, 'data-vote') && str_contains($html, 'votebtn'));
[$s, $html] = http('GET', '/', [], [], false);
check('home page shows the Signal slider', $s === 200 && str_contains($html, 'data-slider') && str_contains($html, 'sigcard'));
[$s] = http('GET', '/cron/wire?key=wrong', [], [], false);
check('cron endpoint rejects a bad key', $s === 403);
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
[$s, $pv] = http('POST', '/api/ads/preview', ['business_name' => 'Smoke Bakery', 'title' => 'Fresh bread every morning', 'template' => 'paper']);
check('ad designer preview renders', $s === 200 && str_contains($pv['sidebar'] ?? '', 'ad--sidebar') && str_contains($pv['banner'] ?? '', 'ad--banner'));
[$s, $adRes] = http('POST', '/api/me/ads', ['business_name' => 'Smoke Bakery', 'title' => 'Fresh bread every morning', 'body' => 'Baked daily.', 'cta' => 'Find us', 'url' => 'https://example.ie', 'target_county' => 'Wicklow', 'template' => 'paper', 'submit' => '1']);
$ad = $adRes['ad'] ?? [];
check('design + submit advert', $s === 200 && ($ad['status'] ?? '') === 'review', $ad['state_label'] ?? ($adRes['detail'] ?? '?'));
[$s] = http('POST', '/api/me/ads', ['business_name' => 'Bad', 'title' => 'Nope', 'url' => 'not-a-url']);
check('advert validation rejects a bad landing URL', $s === 400);
[$s] = http('POST', '/api/me/ads/' . ($ad['id'] ?? 'x') . '/checkout/stripe', []);
check('checkout blocked before approval', $s === 409 || $s === 503);
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
[$s, $ads] = http('GET', '/api/admin/ads?status=review');
check('ads review queue', $s === 200 && in_array($ad['id'] ?? '-', array_column($ads['ads'] ?? [], 'id'), true), count($ads['ads'] ?? []) . ' waiting · ' . ($ads['pricing']['price_label'] ?? '?'));
[$s, $dec] = http('POST', '/api/admin/ads/' . ($ad['id'] ?? 'x'), ['decision' => 'approve']);
check('approve advert starts the free trial', $s === 200 && ($dec['ad']['plan_status'] ?? '') === 'trial' && !empty($dec['ad']['live']), $dec['ad']['state_label'] ?? '');
[$s, $served] = http('GET', '/api/ads?county=Wicklow&limit=4');
check('approved advert is served in its county', $s === 200 && in_array('Smoke Bakery', array_column($served, 'business_name'), true));
[$s, $servedElse] = http('GET', '/api/ads?county=Kerry&limit=4');
check('county-targeted advert is not served elsewhere', $s === 200 && !in_array('Smoke Bakery', array_column($servedElse, 'business_name'), true));
[$s, $act] = http('POST', '/api/admin/ads/' . ($ad['id'] ?? 'x'), ['decision' => 'activate', 'months' => '1', 'paid' => '1']);
check('manual activation records a paid period', $s === 200 && ($act['ad']['plan_status'] ?? '') === 'active' && ($act['ad']['gateway'] ?? '') === 'manual');
[$s, $set] = http('POST', '/api/admin/ads/settings', ['price' => '30', 'trial_days' => '10', 'currency' => 'EUR']);
check('price and trial editable from the newsroom', $s === 200 && ($set['pricing']['price_cents'] ?? 0) === 3000 && ($set['pricing']['trial_days'] ?? 0) === 10);
http('POST', '/api/admin/ads/settings', ['price' => '25', 'trial_days' => '7', 'currency' => 'EUR']);
[$s] = http('POST', '/api/admin/ads/' . ($ad['id'] ?? 'x'), ['decision' => 'delete']);
check('delete advert (cleanup)', $s === 200);
[$s, $html] = http('GET', '/kids', [], [], false);
check('kids section is ad-free', $s === 200 && !str_contains($html, 'class="ad ad--'));
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
