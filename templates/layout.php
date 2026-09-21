<?php
/** @var array|null $user */
use MeNews\Support\Categories;
use MeNews\Support\Locations;
use MeNews\Support\Visitor;
use MeNews\Support\Lang;

$user = $user ?? null;
$title = $title ?? 'ME News Ireland';
$description = $description ?? t('Your Community. Your News. Live.');
$lang = Lang::current();
$gt = Lang::googleCode();
$backPath = ($_SERVER['REQUEST_URI'] ?? '/');
$bodyClass = $bodyClass ?? '';
$ogImage = $ogImage ?? absolute_url('/assets/img/og.png');
$feedLinks = array_values(array_filter($feedLinks ?? []));
$nav = $nav ?? Categories::NAV;
$path = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($path, PHP_URL_PATH) ?: '/';
$isStaff = \MeNews\Auth::isStaff($user);
$isApp = str_contains($bodyClass, 'page-app');
$myCounty = \MeNews\Database::installed() ? (Visitor::county() ?? (Visitor::position() ? (\MeNews\Support\Geo::nearest(Visitor::position()['lat'], Visitor::position()['lng'])['county'] ?? null) : null)) : null;
$countySlug = $myCounty ? slugify($myCounty) : '';
$canonical = $canonical ?? null;
$robots = $robots ?? null;
$active = static fn(string $p, bool $prefix = false): string => ($prefix ? str_starts_with($path, $p) : $path === $p) ? 'is-active' : '';
$primary = [
    ['/section/national', 'National', 'national'],
    ['/section/world', 'World', 'world'],
    ['/section/local', 'Local', 'pin'],
    ['/section/sport', 'Sport', 'trophy'],
    ['/notices', 'Deaths & Notices', 'candle'],
    ['/section/whats-on', "What's On", 'calendar'],
];
$more = [
    'Sections' => [['/section/business', 'Business'], ['/section/culture', 'Culture'], ['/section/council', 'Council'], ['/section/community', 'Community reports'], ['/section/traffic', 'Traffic']],
    'ME News' => [['/signal', 'The Signal · most-voted'], ['/alerts', 'Weather & school alerts'], ['/map', 'Live map'], ['/kids', 'ME Óg · Kids & puzzles'], ['/saved', 'Saved stories'], ['/contributors', 'The desk'], ['/plus', 'ME+ membership']],
    'About' => [['/about', 'How ME works'], ['/about#sources', 'Our sources'], ['/corrections', 'Corrections'], ['/ownership', 'Who owns ME'], ['/advertise', 'Advertise'], ['/feeds', 'RSS & feeds'], ['/privacy', 'Privacy & cookies']],
];
?><!doctype html>
<html lang="<?= e(Lang::htmlLang()) ?>" data-theme="light" style="--day-shift:<?= \MeNews\Support\Daily::hueShift() ?>deg">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($description) ?>">
<?php if ($robots): ?><meta name="robots" content="<?= e($robots) ?>"><?php endif; ?>
<?php if ($canonical): ?><link rel="canonical" href="<?= e($canonical) ?>"><?php endif; ?>
<meta name="theme-color" content="#139a5c">
<meta property="og:site_name" content="ME News Ireland">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($description) ?>">
<meta property="og:image" content="<?= e($ogImage) ?>">
<meta property="og:type" content="<?= isset($story) ? 'article' : 'website' ?>">
<meta property="og:url" content="<?= e($canonical ?: absolute_url($path)) ?>">
<meta property="og:locale" content="en_IE">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($title) ?>">
<meta name="twitter:description" content="<?= e($description) ?>">
<meta name="twitter:image" content="<?= e($ogImage) ?>">
<?php if (isset($story)): ?>
<meta property="article:published_time" content="<?= e($story['time']) ?>">
<meta property="article:modified_time" content="<?= e($story['updated_at'] ?: $story['time']) ?>">
<meta property="article:section" content="<?= e($story['category']) ?>">
<meta property="article:publisher" content="<?= e(absolute_url('/')) ?>">
<?php foreach (array_filter([$story['county'] ? 'County ' . $story['county'] : null, $story['location_name'] ?: null, $story['category']]) as $tag): ?><meta property="article:tag" content="<?= e($tag) ?>"><?php endforeach; ?>
<meta name="news_keywords" content="<?= e(implode(', ', array_filter([$story['category'], $story['county'] ? 'County ' . $story['county'] : null, $story['location_name'] ?: null, 'Ireland']))) ?>">
<?php if ($story['kind'] !== 'wire'): ?><meta name="author" content="<?= e($story['author_name'] ?: 'ME News community') ?>"><?php endif; ?>
<?php endif; ?>
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="alternate" type="application/rss+xml" title="ME News Ireland" href="/feed.xml">
<link rel="alternate" type="application/atom+xml" title="ME News Ireland (Atom)" href="/feed.atom">
<link rel="alternate" type="application/feed+json" title="ME News Ireland (JSON Feed)" href="/feed.json">
<link rel="alternate" type="application/rss+xml" title="ME News Ireland — original reporting" href="/feed/community.xml">
<?php foreach ($feedLinks as [$href, $label]): ?><link rel="alternate" type="application/rss+xml" title="ME News Ireland — <?= e($label) ?>" href="<?= e($href) ?>"><?php endforeach; ?>
<link rel="search" type="application/opensearchdescription+xml" title="ME News" href="/opensearch.xml">
<meta name="application-name" content="ME News Ireland">
<link rel="preload" href="/assets/fonts/Sora.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/Manrope.woff2" as="font" type="font/woff2" crossorigin>
<?php if (str_contains($bodyClass, 'page-kids') || str_contains($bodyClass, 'page-home')): ?><link rel="preload" href="/assets/fonts/Fraunces.woff2" as="font" type="font/woff2" crossorigin><?php endif; ?>
<link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
<link rel="stylesheet" href="/assets/css/menews.css?v=<?= e(ME_ASSETS) ?>">
<?php if (!empty($jsonld) && !isset($story) && !isset($notice)): ?><script type="application/ld+json"><?= json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script><?php endif; ?>
<script>try{var t=localStorage.getItem('me_theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);var ts=localStorage.getItem('me_textsize');if(ts)document.documentElement.setAttribute('data-textsize',ts)}catch(e){}</script>
</head>
<body class="<?= e($bodyClass) ?>">
<div class="aurora" aria-hidden="true"><i></i><i></i><i></i></div>
<div class="gridlines" aria-hidden="true"></div>
<a class="skip" href="#main"><?= e(t('Skip to content')) ?></a>

<div class="hud mono notranslate">
  <div class="container hud__in">
    <div class="hud__left">
      <span class="livedot" aria-hidden="true"></span><span><?= e(t('LIVE')) ?></span>
      <span class="hud__sep">·</span>
      <span id="wire-status" data-last="<?= e($wireLast ?? '') ?>"><?= !empty($wireLast) ? e(t('Updated')) . ' ' . e(time_ago($wireLast)) : e(t('Wire standing by')) ?></span>
    </div>
    <div class="hud__mid"><span id="hud-clock">--:--:--</span><span class="hud__sep">·</span><span id="hud-date">Ireland</span></div>
    <div class="hud__right"><a href="/alerts" id="hud-alerts"><?= icon('alert') ?> <?= e(t('Alerts')) ?></a><span class="hud__sep">·</span><a href="/notices"><?= e(t('Notices')) ?></a><span class="hud__sep">·</span><a href="/about"><?= e(t('How we check things')) ?></a></div>
  </div>
</div>

<header class="topbar notranslate">
  <div class="container topbar__in">
    <a class="brand" href="/" aria-label="ME News Ireland home">
      <svg class="brand__mark" viewBox="0 0 64 64" aria-hidden="true"><defs><linearGradient id="bg1" x1="0" x2="1" y1="0" y2="1"><stop offset="0" stop-color="#0f8a52"/><stop offset=".55" stop-color="#26c072"/><stop offset="1" stop-color="#8ae8b3"/></linearGradient></defs><rect x="2" y="2" width="60" height="60" rx="16" fill="none" stroke="url(#bg1)" stroke-width="2"/><path d="M14 46V18l10 14 10-14v28" fill="none" stroke="url(#bg1)" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/><path d="M40 20h10M40 32h10M40 44h10" stroke="url(#bg1)" stroke-width="5" stroke-linecap="round"/></svg>
      <span class="brand__word"><b>ME</b> News<small>Ireland</small></span>
    </a>
    <button class="countychip" type="button" data-open-county aria-haspopup="dialog" title="<?= e($myCounty ? t('Change your county') : t('Choose your county')) ?>">
      <?= icon('pin') ?><span class="countychip__label"><?= $myCounty ? e($myCounty) : e(t('Your county')) ?></span><?= icon('chevron', 'countychip__caret') ?>
    </button>
    <form class="search" action="/search" role="search">
      <input type="search" name="q" placeholder="<?= e(t('Search stories, towns, counties…')) ?>" value="<?= e($q ?? '') ?>" aria-label="<?= e(t('Search')) ?>">
      <button type="submit" aria-label="<?= e(t('Search')) ?>"><?= icon('search') ?></button>
    </form>
    <div class="topbar__actions">
      <div class="langswitch">
        <button class="iconbtn langswitch__btn" id="lang-toggle" type="button" aria-haspopup="true" aria-expanded="false" aria-label="<?= e(t('Language')) ?>" title="<?= e(t('Language')) ?>"><?= icon('globe') ?><span class="langswitch__code"><?= e(strtoupper(substr($lang, 0, 2))) ?></span></button>
        <div class="langmenu" id="lang-menu" role="menu">
          <span class="langmenu__head mono"><?= e(t('Language')) ?></span>
          <?php foreach (Lang::LANGS as $code => [$label, $native, $g, $flag]): ?><a role="menuitem" href="/lang/<?= e($code) ?>?back=<?= rawurlencode($backPath) ?>" class="<?= $code === $lang ? 'is-active' : '' ?>" hreflang="<?= e($code) ?>" lang="<?= e($code) ?>"><span class="langmenu__flag" aria-hidden="true"><?= $flag ?></span><span><?= e($native) ?></span><small><?= e($label) ?></small></a><?php endforeach; ?>
        </div>
      </div>
      <button class="iconbtn" id="theme-toggle" type="button" aria-label="<?= e(t('Toggle light and dark theme')) ?>"><span class="sun"><?= icon('sun') ?></span><span class="moon"><?= icon('moon') ?></span></button>
      <button class="btn btn--hot btn--report" type="button" data-open-report><?= icon('report') ?><span><?= e(t('Report')) ?></span></button>
      <?php if ($user): ?>
        <div class="account">
          <button class="account__btn" type="button" id="account-toggle" aria-haspopup="true" aria-expanded="false">
            <?= \MeNews\Ui::avatar($user, 'sm') ?><span class="account__name"><?= e(explode(' ', $user['display_name'])[0]) ?></span><span class="badge" id="notify-badge" hidden>0</span>
          </button>
          <div class="account__menu" id="account-menu">
            <a href="/dashboard"><?= e(t('My dashboard')) ?></a>
            <a href="/dashboard#saved"><?= icon('bookmark') ?> <?= e(t('Saved stories')) ?></a>
            <?php if ($isStaff): ?><a href="/newsroom"><?= e(t('Newsroom')) ?></a><?php endif; ?>
            <?php if (!empty($user['handle']) && in_array($user['role'], ['contributor','editor','admin'], true)): ?><a href="/contributors/<?= e($user['handle']) ?>"><?= e(t('Public profile')) ?></a><?php endif; ?>
            <button type="button" data-logout><?= e(t('Sign out')) ?></button>
          </div>
        </div>
      <?php else: ?>
        <button class="btn btn--ghost btn--signin" type="button" data-open-auth="signin"><?= e(t('Sign in')) ?></button>
      <?php endif; ?>
    </div>
  </div>
  <nav class="sections" aria-label="Sections">
    <div class="container sections__in">
      <a href="<?= $myCounty ? '/county/' . e($countySlug) : '/near' ?>" class="sections__county <?= $active('/near') ?: ($myCounty && $path === '/county/' . $countySlug ? 'is-active' : '') ?>"><?= icon('pin') ?> <?= $myCounty ? e($myCounty) : e(t('Your county')) ?></a>
      <?php foreach ($primary as [$href, $label, $ic]): ?>
        <a href="<?= e($href) ?>" class="<?= $active($href, $href === '/notices') ?>"><?= e(t($label)) ?></a>
      <?php endforeach; ?>
      <a href="/signal" class="sections__signal <?= $active('/signal') ?>"><?= icon('signal') ?> <?= e(t('Signal')) ?></a>
      <a href="/advertise" class="sections__advertise <?= $active('/advertise') ?>"><?= icon('megaphone') ?> <?= e(t('Advertise')) ?></a>
      <a href="/plus" class="sections__plus <?= $active('/plus') ?>"><?= icon('star') ?> ME+<?= $user && ($user['plan'] ?? '') === 'ME+' ? ' <span class="sections__plus-tag">' . e(t('member')) . '</span>' : '' ?></a>
      <div class="moremenu" data-more>
        <button type="button" class="moremenu__btn" aria-expanded="false" aria-controls="more-panel"><?= e(t('More')) ?> <?= icon('chevron') ?></button>
        <div class="moremenu__panel" id="more-panel" hidden>
          <?php foreach ($more as $group => $links): ?>
            <div class="moremenu__col"><h4 class="mono"><?= e(t($group)) ?></h4><?php foreach ($links as [$href, $label]): ?><a href="<?= e($href) ?>"><?= e(t($label)) ?></a><?php endforeach; ?></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </nav>
</header>

<?php if ($gt): ?><div class="translated notranslate"><div class="container translated__in"><?= icon('globe') ?> <?= e(t('Content translated automatically by Google Translate. The original is in English.')) ?> <a href="/lang/en-GB?back=<?= rawurlencode($backPath) ?>"><?= e(t('Read in English')) ?></a></div></div><?php endif; ?>
<main id="main" class="<?= $isApp ? 'app' : 'site' ?>">
<?= $content ?>
</main>

<?php if (!$isApp): ?>
<footer class="footer notranslate">
  <div class="container footer__in">
    <div class="footer__brand">
      <div class="brand__word brand__word--lg"><b>ME</b> News<small>Ireland</small></div>
      <p><?= e(t('Your Community. Your News. Live.')) ?><br>The local layer Ireland lost: deaths and notices, school closures and weather warnings, council decisions, club sport and what your neighbours are seeing, county by county.</p>
      <p class="footer__small">Wire headlines link to their original publishers, who retain all rights. Wire stories are labelled <em>Wire</em>; <em>Verified</em> is reserved for reports our desk has checked.</p>
    </div>
    <div class="footer__col">
      <h4><?= e(t('Sections')) ?></h4>
      <?php foreach (array_slice($nav, 0, 8) as $name): ?><a href="/section/<?= e(Categories::slug($name)) ?>"><?= e(t($name)) ?></a><?php endforeach; ?>
    </div>
    <div class="footer__col">
      <h4><?= e(t('Local layer')) ?></h4>
      <a href="/notices"><?= e(t('Deaths & notices')) ?></a>
      <a href="/alerts"><?= e(t('Weather & school alerts')) ?></a>
      <a href="/signal"><?= e(t('The Signal · most-voted')) ?></a>
      <a href="/map"><?= e(t('Live map')) ?></a>
      <a href="/kids"><?= e(t('ME Óg · Kids & puzzles')) ?></a>
      <a href="/plus"><?= e(t('ME+ membership')) ?></a>
      <a href="/advertise"><?= e(t('Advertise with ME')) ?></a>
      <a href="/feeds">RSS</a>
    </div>
    <div class="footer__col">
      <h4><?= e(t('Trust')) ?></h4>
      <a href="/about"><?= e(t('How we check things')) ?></a>
      <a href="/corrections"><?= e(t('Corrections policy & log')) ?></a>
      <a href="/ownership"><?= e(t('Ownership & funding')) ?></a>
      <a href="/moderation"><?= e(t('Moderation & takedowns')) ?></a>
      <a href="/privacy"><?= e(t('Privacy & cookies')) ?></a>
      <a href="/contributors"><?= e(t('The desk')) ?></a>
      <p class="footer__small">Safety is not truth. Safety scores screen for harm; confidence scores weigh evidence. Only editors set <em>Community Report</em>, <em>Developing</em>, <em>Verified</em> and <em>Official</em>.</p>
    </div>
  </div>
  <div class="container footer__bar">
    <span class="footer__copy">&copy; <?= date('Y') ?> ME News Ireland</span>
    <a class="crest" href="https://www.crestwebmedia.com" target="_blank" rel="noopener" aria-label="Made by Crest Web Media (opens in a new tab)">
      <span class="crest__ring" aria-hidden="true"></span>
      <span class="crest__in">
        <span class="crest__dot" aria-hidden="true"></span>
        <span class="crest__label"><?= e(t('Made by')) ?></span>
        <span class="crest__name">Crest</span>
        <svg class="crest__arrow" width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M2.5 9.5 9.5 2.5M4 2.5h5.5V8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </span>
    </a>
  </div>
  <div class="footer__watermark" aria-hidden="true">ME</div>
</footer>

<nav class="bottombar notranslate" aria-label="Quick navigation">
  <a href="<?= $myCounty ? '/county/' . e($countySlug) : '#' ?>" <?= $myCounty ? '' : 'data-open-county' ?> class="<?= $myCounty && $path === '/county/' . $countySlug ? 'is-active' : '' ?>"><?= icon('pin') ?><span><?= $myCounty ? e(mb_strimwidth($myCounty, 0, 9, '…')) : e(t('County')) ?></span></a>
  <a href="/signal" class="<?= $active('/signal') ?>"><?= icon('signal') ?><span><?= e(t('Signal')) ?></span></a>
  <button type="button" class="bottombar__report" data-open-report><?= icon('report') ?><span><?= e(t('Report')) ?></span></button>
  <a href="/search" class="<?= $active('/search') ?>"><?= icon('search') ?><span><?= e(t('Search')) ?></span></a>
  <button type="button" data-open-drawer><?= icon('menu') ?><span><?= e(t('More')) ?></span></button>
</nav>
<?php endif; ?>

<!-- Mobile drawer -->
<div class="drawer notranslate" id="drawer" hidden>
  <div class="drawer__panel" role="dialog" aria-modal="true" aria-label="Menu">
    <header class="modal__head"><span class="brand__word"><b>ME</b> News</span><button class="iconbtn" type="button" data-close-drawer aria-label="Close"><?= icon('close') ?></button></header>
    <div class="drawer__body">
      <a class="drawer__county" href="#" data-open-county><?= icon('pin') ?> <b><?= $myCounty ? e($myCounty) : e(t('Choose your county')) ?></b><small><?= e(t('Local stories, deaths, alerts')) ?></small></a>
      <div class="drawer__group"><h4 class="mono"><?= e(t('Sections')) ?></h4><?php foreach ($primary as [$href, $label]): ?><a href="<?= e($href) ?>"><?= e(t($label)) ?></a><?php endforeach; ?><?php foreach ($more['Sections'] as [$href, $label]): ?><a href="<?= e($href) ?>"><?= e(t($label)) ?></a><?php endforeach; ?></div>
      <?php foreach (['ME News', 'About'] as $g): ?><div class="drawer__group"><h4 class="mono"><?= e(t($g)) ?></h4><?php foreach ($more[$g] as [$href, $label]): ?><a href="<?= e($href) ?>"><?= e(t($label)) ?></a><?php endforeach; ?></div><?php endforeach; ?>
      <div class="drawer__group"><h4 class="mono"><?= e(t('Language')) ?></h4><div class="drawer__langs"><?php foreach (Lang::LANGS as $code => [$label, $native, $g, $flag]): ?><a href="/lang/<?= e($code) ?>?back=<?= rawurlencode($backPath) ?>" class="<?= $code === $lang ? 'is-active' : '' ?>" lang="<?= e($code) ?>"><?= $flag ?> <?= e($native) ?></a><?php endforeach; ?></div></div>
      <div class="drawer__group"><?php if ($user): ?><a href="/dashboard"><?= e(t('My dashboard')) ?></a><?php if ($isStaff): ?><a href="/newsroom"><?= e(t('Newsroom')) ?></a><?php endif; ?><button type="button" data-logout><?= e(t('Sign out')) ?></button><?php else: ?><button type="button" data-open-auth="signin"><?= e(t('Sign in')) ?></button><button type="button" data-open-auth="register"><?= e(t('Create account')) ?></button><?php endif; ?></div>
    </div>
  </div>
</div>

<!-- County picker -->
<div class="modal modal--sheet" id="county-modal" role="dialog" aria-modal="true" aria-labelledby="county-title" hidden>
  <div class="modal__box modal__box--wide">
    <header class="modal__head"><h2 id="county-title"><?= icon('pin') ?> <?= e(t('Where are you?')) ?></h2><button class="iconbtn" type="button" data-close aria-label="Close"><?= icon('close') ?></button></header>
    <div class="modal__body">
      <p class="modal__lede">One tap and ME leads with your county: local stories, deaths and notices, school closures and weather warnings. Stored only on this device.</p>
      <div class="countygrid">
        <?php foreach (Locations::provinces() as $province => $counties): ?>
          <div class="countygrid__prov"><h4 class="mono"><?= e($province) ?></h4><div class="countygrid__list"><?php foreach ($counties as $c): ?><button type="button" class="countybtn <?= $myCounty === $c ? 'is-active' : '' ?>" data-county="<?= e($c) ?>"><?= e($c) ?></button><?php endforeach; ?></div></div>
        <?php endforeach; ?>
      </div>
      <div class="form__actions form__actions--split">
        <button class="btn btn--ghost" type="button" data-locate-me><?= icon('gps') ?> Use my location instead</button>
        <span><?php if ($myCounty): ?><button class="btn btn--ghost" type="button" data-forget-location>Forget my county</button><?php endif; ?><button class="btn btn--dark" type="button" data-county-later>Not now</button></span>
      </div>
    </div>
  </div>
</div>

<!-- Auth modal -->
<div class="modal" id="auth-modal" role="dialog" aria-modal="true" aria-labelledby="auth-title" hidden>
  <div class="modal__box">
    <header class="modal__head"><h2 id="auth-title">Sign in to ME News</h2><button class="iconbtn" type="button" data-close aria-label="Close"><?= icon('close') ?></button></header>
    <div class="modal__body">
      <div class="tabs mono" role="tablist"><button type="button" class="is-active" data-auth-tab="signin" role="tab">Sign in</button><button type="button" data-auth-tab="register" role="tab">Create account</button></div>
      <form id="signin-form" class="form" data-auth-view="signin">
        <label>Email<input name="email" type="email" autocomplete="email" required></label>
        <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
        <button class="btn btn--primary btn--block" type="submit">Sign in</button>
      </form>
      <form id="register-form" class="form" data-auth-view="register" hidden>
        <label>Your name<input name="display_name" required minlength="2" autocomplete="name"></label>
        <div class="form__row">
          <label>Home town<input name="home_town" list="all-locations" autocomplete="off" placeholder="e.g. Bray"></label>
          <label>County<select name="home_county" data-county-select><option value="">Select county</option></select></label>
        </div>
        <label>Email<input name="email" type="email" autocomplete="email" required></label>
        <label>Password (8+ characters)<input name="password" type="password" minlength="8" autocomplete="new-password" required></label>
        <button class="btn btn--primary btn--block" type="submit">Create free account</button>
      </form>
      <p class="form__result" id="auth-result" role="status"></p>
    </div>
  </div>
</div>

<?= \MeNews\View::partial('partials/report-modal', ['user' => $user, 'county' => $myCounty]) ?>
<datalist id="all-locations"></datalist>
<div class="toast mono" id="toast" role="status" aria-live="polite"></div>
<div class="newpill mono notranslate" id="new-stories" hidden><button type="button"><?= icon('refresh') ?> <?= e(t('New stories on the wire — refresh')) ?></button></div>
<?= \MeNews\View::partial('partials/consent') ?>

<script>window.ME={located:<?= (Visitor::position() || Visitor::county()) ? 'true' : 'false' ?>,county:<?= json_encode($myCounty) ?>,user:<?= json_encode($user ? ['id' => $user['id'], 'name' => $user['display_name'], 'role' => $user['role'], 'plan' => $user['plan']] : null, JSON_UNESCAPED_UNICODE) ?>,wireEnabled:<?= \MeNews\Services\NewsWire::enabled() ? 'true' : 'false' ?>};</script>
<script src="/assets/js/menews.js?v=<?= e(ME_ASSETS) ?>" defer></script>
<?php if ($gt): ?>
<div id="google_translate_element" class="notranslate" hidden></div>
<script>function meGoogleTranslate(){try{new google.translate.TranslateElement({pageLanguage:'en',includedLanguages:'<?= e(Lang::googleCodes()) ?>',autoDisplay:false},'google_translate_element');}catch(e){}}</script>
<script src="https://translate.google.com/translate_a/element.js?cb=meGoogleTranslate" defer></script>
<?php endif; ?>
<script src="/assets/vendor/leaflet/leaflet.js" defer></script>
<script src="/assets/js/map.js?v=<?= e(ME_ASSETS) ?>" defer></script>
<script src="/assets/js/signal.js?v=<?= e(ME_ASSETS) ?>" defer></script>
<?php if (str_contains($bodyClass, 'page-kids')): ?><script src="/assets/js/kids.js?v=<?= e(ME_ASSETS) ?>" defer></script><?php endif; ?>
<?php if (!empty($extraScripts)) echo $extraScripts; ?>
<?php if (str_contains($bodyClass, 'page-app')): ?><script src="/assets/js/ads.js?v=<?= e(ME_ASSETS) ?>" defer></script><?php endif; ?>
</body>
</html>
