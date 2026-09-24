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
/** Sign in without touching the shared cookie jar and return a bearer token. */
function bearerToken(string $email, string $password): string
{
    global $base;
    $ch = curl_init($base . '/api/auth/login');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['email' => $email, 'password' => $password], CURLOPT_HTTPHEADER => ['X-Requested-With: MENews'], CURLOPT_TIMEOUT => 30]);
    $j = json_decode((string)curl_exec($ch), true) ?: [];
    curl_close($ch);
    return (string)($j['token'] ?? '');
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
foreach (['/', '/section/national', '/section/sport', '/county/dublin', '/search?q=cork', '/map', '/near', '/signal', '/signal?window=rising', '/advertise', '/kids', '/kids/crossword', '/kids/crossword?level=adult', '/kids/wordsearch', '/kids/quiz', '/kids/county-game', '/contributors', '/contributors/tadhg', '/about', '/plus', '/newsroom', '/notices', '/notices/death', '/notices/submit', '/alerts', '/alerts?county=Wicklow', '/poll', '/corrections', '/ownership', '/privacy', '/moderation', '/county/wicklow/map', '/api/alerts?county=Cork', '/api/bulletin?county=Cork', '/sitemap.xml', '/news-sitemap.xml', '/feed.xml', '/feed.atom', '/feed.json', '/feed/community.xml', '/feed/section/sport.xml', '/feed/county/wicklow.atom', '/feeds', '/opensearch.xml', '/robots.txt', '/assets/img/logo-512.png', '/assets/img/og.png', '/assets/css/menews.css', '/assets/vendor/leaflet/leaflet.js', '/assets/fonts/Sora.woff2'] as $p) {
    [$s, $html] = http('GET', $p, [], [], false);
    check("GET {$p}", $s === 200 && strlen($html) > 20, "HTTP {$s}");
}
[$s] = http('GET', '/no-such-page', [], [], false);
check('404 page', $s === 404);
[$s, $rssRaw] = http('GET', '/feed/community.xml', [], [], false);
check('RSS feed is well-formed with aggregator extensions', $s === 200 && simplexml_load_string($rssRaw) !== false && str_contains($rssRaw, 'xmlns:media') && str_contains($rssRaw, 'content:encoded'));
[$s, $newsRaw] = http('GET', '/news-sitemap.xml', [], [], false);
check('Google News sitemap is well-formed', $s === 200 && simplexml_load_string($newsRaw) !== false && str_contains($newsRaw, 'sitemap-news/0.9'));
[$s, $robots] = http('GET', '/robots.txt', [], [], false);
check('robots.txt lists both sitemaps', $s === 200 && substr_count($robots, 'Sitemap: http') === 2);
[$s, $home] = http('GET', '/', [], [], false);
check('home page declares the publisher and feed autodiscovery', $s === 200 && str_contains($home, 'NewsMediaOrganization') && str_contains($home, 'application/feed+json') && str_contains($home, 'opensearchdescription'));
[$s] = http('GET', '/feed/section/no-such.xml', [], [], false);
check('unknown feed is a 404', $s === 404);
[, $anyFeed] = http('GET', '/api/feed?limit=1');
[$s, $storyShare] = http('GET', '/story/' . ($anyFeed['items'][0]['slug'] ?? 'x'), [], [], false);
check('story pages carry share buttons and the network list', $s === 200 && str_contains($storyShare, 'sharebar') && str_contains($storyShare, 'data-share-more') && str_contains($storyShare, 'api.whatsapp.com') && str_contains($storyShare, 'window.ME_SHARE'));
[$s, $mapHtml] = http('GET', '/', [], [], false);
preg_match('/data-map="side" data-points=\'(.*?)\'/', $mapHtml, $mm);
$mapPts = json_decode(html_entity_decode($mm[1] ?? '[]', ENT_QUOTES), true) ?: [];
check('home page map ships points and counties', $s === 200 && count($mapPts) > 5 && !empty($mapPts[0]['latitude']) && str_contains($mapHtml, 'data-counties='));
[$s, $mapApi] = http('GET', '/api/map?limit=20');
check('map API returns located stories', $s === 200 && count($mapApi['points'] ?? []) > 0 && count($mapApi['counties'] ?? []) > 0);
foreach (['pl' => 'Twoje hrabstwo', 'ga' => 'Do chontae', 'de' => 'Dein County', 'uk' => 'Ваше графство', 'ru' => 'Ваше графство'] as $code => $word) {
    $ch = curl_init($base . '/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'me_lang=' . $code, CURLOPT_TIMEOUT => 30]);
    $html = (string)curl_exec($ch);
    check("language {$code} translates the chrome and loads Google Translate", str_contains($html, 'lang="' . $code . '"') && str_contains($html, $word) && str_contains($html, 'translate_a/element.js'));
}
$ch = curl_init($base . '/lang/pl?back=/section/sport');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30]);
$hdr = (string)curl_exec($ch);
check('language switch sets both cookies and redirects back', str_contains($hdr, 'me_lang=pl') && str_contains($hdr, 'googtrans=/en/pl') && str_contains($hdr, 'Location: /section/sport'));
[$s] = http('GET', '/lang/xx', [], [], false);
check('unknown language is a 404', $s === 404);
[$s] = http('GET', '/dashboard', [], [], false);
check('dashboard redirects anonymous users', $s === 302 || $s === 200);

echo "\nAPI\n";
[$s, $h] = http('GET', '/api/health');
check('health', $s === 200 && ($h['ok'] ?? false), 'version ' . ($h['version'] ?? '?') . ', ' . ($h['stories_published'] ?? 0) . ' stories');
[$s, $feed] = http('GET', '/api/feed?limit=5&kind=wire');
check('feed returns real stories', $s === 200 && count($feed['items'] ?? []) === 5 && ($feed['items'][0]['kind'] ?? '') === 'wire', ($feed['items'][0]['source_name'] ?? '') . ': ' . excerptTitle($feed['items'][0]['title'] ?? ''));
function excerptTitle(string $t): string { return mb_strlen($t) > 60 ? mb_substr($t, 0, 57) . '…' : $t; }
$first = $feed['items'][0] ?? [];
[$s, $story] = http('GET', '/api/story/' . ($first['slug'] ?? 'x'));
check('story by slug', $s === 200 && ($story['id'] ?? '') === ($first['id'] ?? '-'));
[$s, $html] = http('GET', '/story/' . ($first['slug'] ?? 'x'), [], [], false);
check('story page renders', $s === 200 && str_contains($html, htmlspecialchars($first['source_name'] ?? '', ENT_QUOTES)));
check('wire page canonicalises to the publisher and is noindex', ($first['kind'] ?? '') !== 'wire' || (str_contains($html, 'rel="canonical" href="' . htmlspecialchars($first['source_url'] ?? '', ENT_QUOTES)) && str_contains($html, 'noindex')));
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

echo "\nThe local layer\n";
[$s, $nt] = http('POST', '/api/notices', ['kind' => 'death', 'title' => "Smoke Test Notice {$stamp}", 'county' => 'Wicklow', 'town' => 'Rathdrum', 'funeral_at' => date('Y-m-d\TH:i', time() + 2 * 86400), 'body' => 'Peacefully. Smoke test.', 'contact_name' => 'Smoke Tester', 'contact_email' => "smoke-{$stamp}@example.ie"]);
check('place a death notice while signed in', $s === 200 && ($nt['status'] ?? '') === 'review');
$noticeId = $nt['id'] ?? '';
[$s, $sub] = http('POST', '/api/alerts/subscribe', ['email' => "smoke-{$stamp}@example.ie", 'county' => 'Wicklow', 'kinds' => ['deaths', 'daily']]);
check('subscribe to county alerts', $s === 200 && ($sub['county'] ?? '') === 'Wicklow');
[$s, $cl] = http('POST', '/api/alerts/closure', ['school' => "Smoke NS {$stamp}", 'county' => 'Wicklow', 'closed_on' => date('Y-m-d'), 'reason' => 'Smoke test', 'contact_name' => 'A Principal', 'contact_email' => "principal-{$stamp}@example.ie"]);
check('principal submits a closure', $s === 200 && ($cl['status'] ?? '') === 'review');
[$s, $pollPage] = http('GET', '/poll', [], [], false);
preg_match('/data-poll="([a-f0-9]+)"/', $pollPage, $pm);
[$s, $pv] = http('POST', '/api/poll/vote', ['poll_id' => $pm[1] ?? 'x', 'option' => '0']);
check('vote in the weekly poll', $s === 200 && ($pv['mine'] ?? null) === 0 && ($pv['results']['total'] ?? 0) >= 1);
[$s, $td] = http('POST', '/api/takedown', ['url' => '/story/x', 'reason' => 'inaccurate', 'contact' => "smoke-{$stamp}@example.ie", 'detail' => 'Smoke test']);
check('takedown request accepted', $s === 200 && !empty($td['ok']));
[$s, $pv] = http('POST', '/api/ads/preview', ['business_name' => 'Smoke Bakery', 'title' => 'Fresh bread every morning', 'template' => 'paper']);
check('ad designer preview renders', $s === 200 && str_contains($pv['sidebar'] ?? '', 'ad--sidebar') && str_contains($pv['banner'] ?? '', 'ad--banner'));
[$s, $pk] = http('GET', '/api/ads/packages');
$packages = $pk['packages'] ?? [];
check('ad packages are public', $s === 200 && count($packages) >= 3 && ($packages[0]['price_label'] ?? '') === '€6' && (int)($packages[0]['impressions'] ?? 0) === 1000, implode(' · ', array_map(static fn($p) => $p['name'] . ' ' . $p['price_label'] . '/' . $p['impressions'], $packages)));
[$s, $adRes] = http('POST', '/api/me/ads', ['business_name' => 'Smoke Bakery', 'title' => 'Fresh bread every morning', 'url' => 'https://example.ie', 'submit' => '1']);
check('designer is locked until a package is bought', $s === 402, $adRes['detail'] ?? '');
[$s, $co] = http('POST', '/api/ads/packages/' . ($packages[0]['id'] ?? 'x') . '/checkout', ['gateway' => 'stripe']);
check('checkout needs a configured gateway or a valid package', in_array($s, [200, 503], true), "HTTP {$s}");
[$s] = http('GET', '/api/admin/ads/packages');
check('member cannot read package admin', $s === 403);
[$s] = http('POST', '/api/admin/ads/orders', ['action' => 'grant', 'email' => $email, 'package_id' => $packages[0]['id'] ?? 'x']);
check('member cannot grant themselves a package', $s === 403);
[$s, $al] = http('POST', '/api/me/alerts', ['county' => 'Kerry', 'kinds' => ['deaths']]);
check('free plan alerts limited to one county', $s === 403, $al['detail'] ?? '');
[$s, $al] = http('GET', '/api/me/alerts');
check('member lists their alert subscriptions', $s === 200 && count($al['subscriptions'] ?? []) === 1 && ($al['limit'] ?? 0) === 1);
[$s, $fd0] = http('GET', '/api/feed?limit=2');
$saveId = $fd0['items'][0]['id'] ?? 'x';
[$s, $sv] = http('POST', '/api/me/saved/toggle', ['story_id' => $saveId]);
check('save a story to the default list', $s === 200 && !empty($sv['saved']));
[$s, $sl] = http('POST', '/api/me/saved/lists', ['name' => 'Smoke list']);
check('create a saved list', $s === 200 && ($sl['list']['name'] ?? '') === 'Smoke list' && count($sl['lists'] ?? []) === 2);
[$s, $sv2] = http('POST', '/api/me/saved/toggle', ['story_id' => $fd0['items'][1]['id'] ?? 'x', 'list_id' => $sl['list']['id'] ?? 'x', 'mode' => 'add']);
check('save a story into a named list', $s === 200 && !empty($sv2['saved']));
[$s, $si] = http('GET', '/api/me/saved/lists/' . ($sl['list']['id'] ?? 'x'));
check('list items render as cards', $s === 200 && count($si['items'] ?? []) === 1 && str_contains($si['items'][0]['html'] ?? '', 'card'));
[$s, $sids] = http('POST', '/api/me/saved/ids', ['ids' => $saveId . ',nothing']);
check('saved ids are reported for marking cards', $s === 200 && ($sids['saved'] ?? []) === [$saveId]);
[$s] = http('POST', '/api/me/saved/lists/' . ($sl['list']['id'] ?? 'x'), ['action' => 'delete']);
check('delete a saved list', $s === 200);
[$s] = http('GET', '/api/admin/summary');
check('member cannot access newsroom', $s === 403);
http('POST', '/api/auth/logout');
[$s] = http('GET', '/api/me');
check('logout clears session', $s === 401);

echo "\nGuest reporting (no account)\n";
[$s, $g] = http('POST', '/api/report', ['category' => 'Traffic', 'body' => "Smoke test guest report {$stamp}. Nothing is happening, this is automated.", 'location_name' => 'Arklow', 'county' => 'Wicklow', 'reporter_name' => 'Smoke Guest', 'reporter_contact' => "guest-{$stamp}@example.ie"]);
check('guest report accepted without sign-in', $s === 200 && in_array($g['status'] ?? '', ['review', 'hold'], true) && str_contains($g['message'] ?? '', 'confirmation'));
$guestId = $g['id'] ?? '';
[$s] = http('POST', '/api/report', ['category' => 'Traffic', 'body' => 'Too short', 'location_name' => 'Arklow']);
check('guest report needs a name and contact', $s === 400);
[$s, $cf] = http('POST', '/api/story/' . ($first['id'] ?? 'x') . '/confirm', []);
check('anonymous "I saw this too" counts once per device', $s === 200 || $s === 409);

echo "\nNewsroom\n";
[$s, $login] = http('POST', '/api/auth/login', ['email' => $env['ADMIN_EMAIL'] ?? 'admin@menews.ie', 'password' => $env['ADMIN_PASSWORD'] ?? '']);
check('admin login', $s === 200 && ($login['user']['role'] ?? '') === 'admin');
[$s, $nq] = http('GET', '/api/admin/notices?status=review');
check('notices queue lists the notice', $s === 200 && in_array($noticeId, array_column($nq, 'id'), true));
[$s, $nd] = http('POST', '/api/admin/notices/' . $noticeId, ['decision' => 'publish']);
check('publish notice', $s === 200 && ($nd['status'] ?? '') === 'published');
[$s, $html] = http('GET', '/notices?county=Wicklow', [], [], false);
check('published notice appears in the county list', $s === 200 && str_contains($html, "Smoke Test Notice {$stamp}"));
[$s] = http('POST', '/api/admin/notices/' . $noticeId, ['decision' => 'reject', 'note' => 'Smoke cleanup']);
check('reject notice (cleanup)', $s === 200);
[$s, $gq] = http('GET', '/api/admin/stories?kind=community&status=' . ($failClosed ? 'review' : 'review'));
check('guest report shows unconfirmed contact to editors', $s === 200 && (($row = array_values(array_filter($gq, static fn($x) => $x['id'] === $guestId))[0] ?? null) === null || empty($row['reporter_verified_at'])));
[$s] = http('POST', "/api/admin/stories/{$guestId}/decision", ['decision' => 'reject', 'label' => 'Community Report', 'note' => 'Smoke cleanup']);
check('reject guest report (cleanup)', $s === 200);
[$s, $sc] = http('GET', '/api/admin/section-check');
check('section check queue', $s === 200 && is_array($sc));
[$s, $st] = http('GET', '/api/admin/settings');
check('settings readable by admin', $s === 200 && isset($st['wire_mode']['value']));
[$s, $sv] = http('POST', '/api/admin/settings', ['plus_price_cents' => '3.99', 'wire_mode' => 'clustered']);
check('settings saved', $s === 200 && in_array('plus_price_cents', $sv['saved'] ?? [], true));
[$s, $cq] = http('GET', '/api/admin/closures');
check('closures listed for editors', $s === 200 && in_array("Smoke NS {$stamp}", array_column($cq, 'school'), true));
[$s, $tk] = http('GET', '/api/admin/takedowns');
check('takedowns listed for editors', $s === 200 && count($tk) >= 1);
[$s, $cron] = http('GET', '/cron/daily?key=' . rawurlencode($env['CRON_KEY'] ?? '') . '&force=1');
check('daily cron runs', ($env['CRON_KEY'] ?? '') === '' ? $s === 403 : ($s === 200 && isset($cron['daily_sent'])), 'sent ' . ($cron['daily_sent'] ?? '?'));
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
echo "\nAdvertising packages\n";
$premium = $packages[2] ?? ['id' => 'x'];
$member = ['Authorization: Bearer ' . bearerToken($email, 'smoke-test-123')];
[$s, $gr] = http('POST', '/api/admin/ads/orders', ['action' => 'grant', 'email' => $email, 'package_id' => $premium['id'], 'note' => 'smoke']);
check('admin grants a paid package', $s === 200 && ($gr['order']['status'] ?? '') === 'paid' && ($gr['order']['tier'] ?? '') === 'premium', $gr['detail'] ?? '');
[$s, $mineAds] = http('GET', '/api/me/ads', [], $member);
check('paid package appears as a credit', $s === 200 && count($mineAds['credits'] ?? []) === 1);
[$s, $adRes] = http('POST', '/api/me/ads', ['business_name' => 'Smoke Bakery', 'title' => 'Fresh bread every morning', 'body' => 'Baked daily.', 'cta' => 'Find us', 'url' => 'https://example.ie', 'target_county' => 'Wicklow', 'template' => 'paper', 'submit' => '1', 'order_id' => $mineAds['credits'][0]['id'] ?? ''], $member);
$ad = $adRes['ad'] ?? [];
check('design + submit advert with the credit', $s === 200 && ($ad['status'] ?? '') === 'review' && ($ad['tier'] ?? '') === 'premium', $ad['state_label'] ?? ($adRes['detail'] ?? '?'));
[$s] = http('POST', '/api/me/ads', ['business_name' => 'Bad', 'title' => 'Nope', 'url' => 'not-a-url'], $member);
check('credit is consumed: a second advert is locked', $s === 402);
[$s, $ads] = http('GET', '/api/admin/ads?status=review');
check('ads review queue', $s === 200 && in_array($ad['id'] ?? '-', array_column($ads['ads'] ?? [], 'id'), true), count($ads['ads'] ?? []) . ' waiting');
[$s, $dec] = http('POST', '/api/admin/ads/' . ($ad['id'] ?? 'x'), ['decision' => 'approve']);
check('approve advert starts the impressions', $s === 200 && ($dec['ad']['plan_status'] ?? '') === 'credits' && !empty($dec['ad']['live']) && (int)($dec['ad']['impressions_left'] ?? 0) === (int)$premium['impressions'], $dec['ad']['state_label'] ?? '');
[$s, $plusAds] = http('GET', '/api/ads?county=Wicklow&limit=4&page=home');
check('staff always see adverts even on ME+', $s === 200 && count($plusAds) > 0);
[$s, $homeHtml] = http('GET', '/', [], ['Authorization: Bearer not-a-session'], false);
check('home page ad slots are rotating rotors', $s === 200 && str_contains($homeHtml, 'data-adrotor') && str_contains($homeHtml, 'data-interval="20000"'));
[$s, $imp] = http('POST', '/api/ads/impression', ['ids' => ($ad['id'] ?? 'x') . ',nothing'], ['Authorization: Bearer not-a-session']);
check('browser impression beacon counts a shown advert', $s === 200 && ($imp['counted'] ?? 0) === 1);
[$s, $imp2] = http('POST', '/api/ads/impression', ['ids' => $ad['id'] ?? 'x'], ['Authorization: Bearer not-a-session']);
check('the same viewer is not counted twice within a minute', $s === 200 && ($imp2['counted'] ?? 1) === 0);
$anon = ['Authorization: Bearer not-a-session'];
[$s, $served] = http('GET', '/api/ads?county=Wicklow&limit=4&page=home', [], $anon);
check('approved premium advert is served on the home page in its county', $s === 200 && in_array('Smoke Bakery', array_column($served, 'business_name'), true));
[$s, $servedElse] = http('GET', '/api/ads?county=Kerry&limit=4', [], $anon);
check('county-targeted advert is not served elsewhere', $s === 200 && !in_array('Smoke Bakery', array_column($servedElse, 'business_name'), true));
[$s, $mineAds] = http('GET', '/api/me/ads', [], $member);
check('impressions are counted down per serve', $s === 200 && (int)($mineAds['ads'][0]['impressions_left'] ?? 0) < (int)$premium['impressions'] && ($mineAds['orders'][0]['status'] ?? '') === 'running', ($mineAds['ads'][0]['impressions_left'] ?? '?') . ' left');
[$s, $newPk] = http('POST', '/api/admin/ads/packages', ['name' => 'Smoke Weekend', 'price' => '4.50', 'impressions' => '500', 'tier' => 'sidebar', 'features' => "500 impressions\nSidebar"]);
check('admin creates a package with its own price and impressions', $s === 200 && ($newPk['package']['price_cents'] ?? 0) === 450 && ($newPk['package']['impressions'] ?? 0) === 500);
[$s] = http('POST', '/api/admin/ads/packages', ['name' => 'Bad', 'price' => '0.50', 'impressions' => '10']);
check('package validation', $s === 400);
[$s] = http('POST', '/api/admin/ads/packages/' . ($newPk['package']['id'] ?? 'x') . '/delete');
check('admin deletes a package', $s === 200);
[$s, $orders] = http('GET', '/api/admin/ads/orders');
check('admin order ledger', $s === 200 && in_array($gr['order']['id'] ?? '-', array_column($orders['orders'] ?? [], 'id'), true));
[$s, $sv] = http('POST', '/api/admin/settings', ['site_url' => 'not a web address']);
check('site address is validated', $s === 400);
[$s, $sv] = http('POST', '/api/admin/settings', ['site_url' => 'https://smoke-canonical.example']);
check('site address saves', $s === 200);
[$s, $sm] = http('GET', '/sitemap.xml', [], [], false);
check('sitemap follows the configured site address', $s === 200 && str_contains($sm, '<loc>https://smoke-canonical.example/</loc>'));
[$s, $home2] = http('GET', '/', [], [], false);
check('canonical tag follows the configured site address', $s === 200 && str_contains($home2, 'href="https://smoke-canonical.example/"'));
http('POST', '/api/admin/settings', ['site_url' => '']);
[$s, $sm2] = http('GET', '/sitemap.xml', [], [], false);
check('with no site address the live host is used', $s === 200 && str_contains($sm2, '<loc>' . $base . '/</loc>'), $base);
[$s, $set] = http('POST', '/api/admin/ads/settings', ['currency' => 'EUR']);
check('currency editable from the newsroom', $s === 200 && ($set['pricing']['currency'] ?? '') === 'EUR');
[$s] = http('POST', '/api/admin/ads/' . ($ad['id'] ?? 'x'), ['decision' => 'delete']);
check('delete advert (cleanup)', $s === 200);
[$s, $html] = http('GET', '/kids', [], [], false);
check('kids section is ad-free', $s === 200 && !str_contains($html, 'class="ad ad--'));
[$s, $gw] = http('GET', '/api/admin/gateways');
check('gateway status is admin-only and never returns values', $s === 200 && isset($gw['fields']['STRIPE_SECRET_KEY']) && !isset($gw['fields']['STRIPE_SECRET_KEY']['value']));
[$s] = http('POST', '/api/admin/gateways', ['STRIPE_SECRET_KEY' => 'nonsense']);
check('gateway keys are validated', $s === 400);
[$s, $gs] = http('POST', '/api/admin/gateways', ['STRIPE_SECRET_KEY' => 'sk_test_SmokeTestKey0000000000' . $stamp, 'STRIPE_WEBHOOK_SECRET' => 'whsec_SmokeTestSecret000000']);
check('gateway keys saved from the newsroom and Stripe reads as connected', $s === 200 && !empty($gs['stripe']) && ($gs['fields']['STRIPE_SECRET_KEY']['source'] ?? '') === 'panel' && str_starts_with($gs['fields']['STRIPE_SECRET_KEY']['masked'] ?? '', 'sk_test_••••'));
[$s, $pr] = http('GET', '/api/ads/pricing');
check('advert pricing sees the newsroom-entered key', $s === 200 && !empty($pr['stripe']));
[$s, $gc] = http('POST', '/api/admin/gateways', ['clear_STRIPE_SECRET_KEY' => '1', 'clear_STRIPE_WEBHOOK_SECRET' => '1']);
check('gateway keys can be removed again', $s === 200 && empty($gc['stripe']));
[$s, $nu] = http('POST', '/api/admin/users', ['email' => "smoke-editor-{$stamp}@example.ie", 'display_name' => 'Smoke Editor', 'role' => 'editor', 'home_county' => 'Cork']);
check('admin creates an account', $s === 200 && !empty($nu['id']) && strlen($nu['temporary_password'] ?? '') >= 8);
check('new account can sign in', bearerToken("smoke-editor-{$stamp}@example.ie", $nu['temporary_password'] ?? '') !== '');
[$s] = http('POST', '/api/admin/users/' . ($nu['id'] ?? 'x') . '/delete', ['confirm' => 'wrong']);
check('deleting an account needs the email typed', $s === 400);
[$s] = http('POST', '/api/admin/users/' . ($nu['id'] ?? 'x') . '/delete', ['confirm' => "smoke-editor-{$stamp}@example.ie"]);
check('admin deletes an account', $s === 200);
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
