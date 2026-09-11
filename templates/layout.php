<?php
/** @var array|null $user */
$user = $user ?? null;
$title = $title ?? 'ME News Ireland';
$description = $description ?? 'Your Community. Your News. Live.';
$bodyClass = $bodyClass ?? '';
$ogImage = $ogImage ?? absolute_url('/assets/img/og.svg');
$nav = $nav ?? \MeNews\Support\Categories::NAV;
$path = $_SERVER['REQUEST_URI'] ?? '/';
$isStaff = \MeNews\Auth::isStaff($user);
$isApp = str_contains($bodyClass, 'page-app');
?><!doctype html>
<html lang="en-IE" data-theme="dark" style="--day-shift:<?= \MeNews\Support\Daily::hueShift() ?>deg">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($description) ?>">
<meta name="theme-color" content="#05070d">
<meta property="og:site_name" content="ME News Ireland">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($description) ?>">
<meta property="og:image" content="<?= e($ogImage) ?>">
<meta property="og:type" content="<?= isset($story) ? 'article' : 'website' ?>">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="alternate" type="application/rss+xml" title="ME News Ireland" href="/feed.xml">
<link rel="preload" href="/assets/fonts/Sora.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/Manrope.woff2" as="font" type="font/woff2" crossorigin>
<?php if (str_contains($bodyClass, 'page-kids') || str_contains($bodyClass, 'page-home')): ?><link rel="preload" href="/assets/fonts/Fraunces.woff2" as="font" type="font/woff2" crossorigin><?php endif; ?>
<link rel="stylesheet" href="/assets/vendor/leaflet/leaflet.css">
<link rel="stylesheet" href="/assets/css/menews.css?v=<?= e(ME_VERSION) ?>">
<script>try{var t=localStorage.getItem('me_theme');if(t)document.documentElement.setAttribute('data-theme',t)}catch(e){}</script>
</head>
<body class="<?= e($bodyClass) ?>">
<div class="aurora" aria-hidden="true"><i></i><i></i><i></i></div>
<div class="gridlines" aria-hidden="true"></div>
<a class="skip" href="#main">Skip to content</a>

<div class="hud mono">
  <div class="container hud__in">
    <div class="hud__left">
      <span class="livedot" aria-hidden="true"></span><span>LIVE WIRE</span>
      <span class="hud__sep">·</span>
      <span id="wire-status" data-last="<?= e($wireLast ?? '') ?>"><?= !empty($wireLast) ? 'Updated ' . e(time_ago($wireLast)) : 'Wire standing by' ?></span>
    </div>
    <div class="hud__mid"><span id="hud-clock">--:--:--</span><span class="hud__sep">·</span><span id="hud-date">Ireland</span></div>
    <div class="hud__right">
      <span id="hud-stats"><?= e(compact_number((int)($totalPublished ?? (\MeNews\Database::installed() ? \MeNews\Stories::countPublished() : 0)))) ?> stories</span><span class="hud__sep">·</span><span><?= count(\MeNews\Services\NewsWire::sources()) ?> sources</span><span class="hud__sep">·</span><span>26 counties</span>
    </div>
  </div>
</div>

<header class="topbar">
  <div class="container topbar__in">
    <a class="brand" href="/" aria-label="ME News Ireland home">
      <svg class="brand__mark" viewBox="0 0 64 64" aria-hidden="true"><defs><linearGradient id="bg1" x1="0" x2="1" y1="0" y2="1"><stop offset="0" stop-color="#2ef2a8"/><stop offset=".55" stop-color="#47d5ff"/><stop offset="1" stop-color="#9b8cff"/></linearGradient></defs><rect x="2" y="2" width="60" height="60" rx="16" fill="none" stroke="url(#bg1)" stroke-width="2"/><path d="M14 46V18l10 14 10-14v28" fill="none" stroke="url(#bg1)" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/><path d="M40 20h10M40 32h10M40 44h10" stroke="url(#bg1)" stroke-width="5" stroke-linecap="round"/></svg>
      <span class="brand__word"><b>ME</b> News<small>Ireland</small></span>
    </a>
    <form class="search" action="/search" role="search">
      <input type="search" name="q" placeholder="Search stories, towns, counties…" value="<?= e($q ?? '') ?>" aria-label="Search">
      <button type="submit" aria-label="Search">⌕</button>
    </form>
    <div class="topbar__actions">
      <button class="iconbtn" id="theme-toggle" type="button" aria-label="Toggle light and dark theme"><span class="sun">☼</span><span class="moon">☾</span></button>
      <button class="btn btn--hot" type="button" data-open-report><span class="livedot livedot--white"></span> Report</button>
      <?php if ($user): ?>
        <div class="account">
          <button class="account__btn" type="button" id="account-toggle" aria-haspopup="true" aria-expanded="false">
            <?= \MeNews\Ui::avatar($user, 'sm') ?><span class="account__name"><?= e(explode(' ', $user['display_name'])[0]) ?></span><span class="badge" id="notify-badge" hidden>0</span>
          </button>
          <div class="account__menu" id="account-menu">
            <a href="/dashboard">My dashboard</a>
            <?php if ($isStaff): ?><a href="/newsroom">Newsroom</a><?php endif; ?>
            <?php if (!empty($user['handle']) && in_array($user['role'], ['contributor','editor','admin'], true)): ?><a href="/contributors/<?= e($user['handle']) ?>">Public profile</a><?php endif; ?>
            <button type="button" data-logout>Sign out</button>
          </div>
        </div>
      <?php else: ?>
        <button class="btn btn--ghost" type="button" data-open-auth="signin">Sign in</button>
      <?php endif; ?>
    </div>
  </div>
  <nav class="sections" aria-label="Sections">
    <div class="container sections__in">
      <a href="/" class="<?= $path === '/' ? 'is-active' : '' ?>">Top stories</a>
      <a href="/near" class="sections__near <?= $path === '/near' ? 'is-active' : '' ?>">◎ Near me</a>
      <a href="/signal" class="sections__signal <?= $path === '/signal' ? 'is-active' : '' ?>">◉ Signal</a>
      <?php foreach ($nav as $name): $slug = \MeNews\Support\Categories::slug($name); ?>
        <a href="/section/<?= e($slug) ?>" class="<?= str_starts_with($path, '/section/' . $slug) ? 'is-active' : '' ?>"><?= e($name) ?></a>
      <?php endforeach; ?>
      <a href="/map" class="<?= $path === '/map' ? 'is-active' : '' ?>">Map</a>
      <a href="/kids" class="sections__kids <?= str_starts_with($path, '/kids') ? 'is-active' : '' ?>">ME Óg · Kids</a>
      <a href="/contributors" class="<?= str_starts_with($path, '/contributors') ? 'is-active' : '' ?>">Contributors</a>
      <a href="/plus" class="sections__plus <?= $path === '/plus' ? 'is-active' : '' ?>">ME+</a>
    </div>
  </nav>
</header>

<div class="locbar" id="locbar" hidden>
  <div class="container locbar__in">
    <span class="locbar__text"><b>Local stories for your area.</b> Allow location on your phone or computer and ME adds a section for your town and county.</span>
    <span class="locbar__actions"><button class="btn btn--primary btn--sm" type="button" data-locate-me>◎ Use my location</button><button class="btn btn--ghost btn--sm" type="button" data-locbar-dismiss>Not now</button></span>
  </div>
</div>

<main id="main" class="<?= $isApp ? 'app' : 'site' ?>">
<?= $content ?>
</main>

<?php if (!$isApp): ?>
<footer class="footer">
  <div class="container footer__in">
    <div class="footer__brand">
      <div class="brand__word brand__word--lg"><b>ME</b> News<small>Ireland</small></div>
      <p>Your Community. Your News. Live.<br>Ireland-wide reporting from established publishers, county-by-county community stories, screened by the ME Trust Engine and labelled by human editors.</p>
    </div>
    <div class="footer__col">
      <h4>Sections</h4>
      <?php foreach (array_slice($nav, 0, 6) as $name): ?><a href="/section/<?= e(\MeNews\Support\Categories::slug($name)) ?>"><?= e($name) ?></a><?php endforeach; ?>
    </div>
    <div class="footer__col">
      <h4>ME News</h4>
      <a href="/signal">The Signal · most-voted</a>
      <a href="/kids">ME Óg · Kids &amp; puzzles</a>
      <a href="/contributors">Contributors</a>
      <a href="/about">How ME works</a>
      <a href="/about#sources">Our sources</a>
      <a href="/plus">ME+ membership</a>
      <a href="/advertise">Advertise with ME</a>
      <a href="/feed.xml">RSS</a>
    </div>
    <div class="footer__col">
      <h4>Trust</h4>
      <p class="footer__small">Safety is not truth. Safety scores screen for harm; confidence scores weigh evidence. Only editors set <em>Community Report</em>, <em>Developing</em>, <em>Verified</em> and <em>Official</em>.</p>
      <p class="footer__small">Wire headlines link to their original publishers, who retain all rights.</p>
    </div>
  </div>
  <div class="footer__watermark" aria-hidden="true">ME</div>
</footer>
<?php endif; ?>

<!-- Auth modal -->
<div class="modal" id="auth-modal" role="dialog" aria-modal="true" aria-labelledby="auth-title" hidden>
  <div class="modal__box">
    <header class="modal__head"><h2 id="auth-title">Sign in to ME News</h2><button class="iconbtn" type="button" data-close aria-label="Close">×</button></header>
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

<!-- Report modal -->
<div class="modal" id="report-modal" role="dialog" aria-modal="true" aria-labelledby="report-title" hidden>
  <div class="modal__box modal__box--wide">
    <header class="modal__head"><h2 id="report-title"><span class="livedot"></span> Report what's happening</h2><button class="iconbtn" type="button" data-close aria-label="Close">×</button></header>
    <div class="modal__body">
      <div class="trustnote"><b>ME Trust Engine</b> — nothing you upload is shown publicly before safety screening and editorial review. Safety screening is separate from factual verification.</div>
      <form id="report-form" class="form" enctype="multipart/form-data">
        <label>Headline<input name="title" maxlength="180" required placeholder="What is happening?"></label>
        <label>What did you see?<textarea name="body" rows="4" placeholder="Describe only what you personally know. Avoid speculation."></textarea></label>
        <div class="form__row">
          <label>Town / area<input name="location_name" id="report-town" list="all-locations" required placeholder="e.g. Bray" autocomplete="off"></label>
          <label>County<select name="county" id="report-county" data-county-select><option value="">Select county</option></select></label>
        </div>
        <div class="form__row">
          <label>Local area / street (optional)<input name="local_area" placeholder="e.g. Seafront"></label>
          <label>Category<select name="category"><?php foreach (\MeNews\Support\Categories::community() as $c): ?><option><?= e($c) ?></option><?php endforeach; ?></select></label>
        </div>
        <label>Photo or video (optional)<input name="media" type="file" accept="image/*,video/*"></label>
        <input type="hidden" name="latitude" id="report-lat"><input type="hidden" name="longitude" id="report-lng"><input type="hidden" name="province" id="report-province">
        <div class="form__actions">
          <button type="button" class="btn btn--ghost" data-gps>◎ Add my GPS</button>
          <button class="btn btn--hot" type="submit">Send to newsroom</button>
        </div>
      </form>
      <p class="form__result" id="report-result" role="status"></p>
      <p class="form__legal">Sensitive allegations, identifiable minors, graphic events and high-impact claims always receive human editorial review before publication.</p>
    </div>
  </div>
</div>
<datalist id="all-locations"></datalist>
<div class="toast mono" id="toast" role="status" aria-live="polite"></div>
<div class="newpill mono" id="new-stories" hidden><button type="button">◌ New stories on the wire — refresh</button></div>

<script>window.ME={located:<?= (\MeNews\Support\Visitor::position() || \MeNews\Support\Visitor::county()) ? 'true' : 'false' ?>,user:<?= json_encode($user ? ['id' => $user['id'], 'name' => $user['display_name'], 'role' => $user['role'], 'plan' => $user['plan']] : null, JSON_UNESCAPED_UNICODE) ?>,wireEnabled:<?= \MeNews\Services\NewsWire::enabled() ? 'true' : 'false' ?>};</script>
<script src="/assets/js/menews.js?v=<?= e(ME_VERSION) ?>" defer></script>
<script src="/assets/vendor/leaflet/leaflet.js" defer></script>
<script src="/assets/js/map.js?v=<?= e(ME_VERSION) ?>" defer></script>
<script src="/assets/js/signal.js?v=<?= e(ME_VERSION) ?>" defer></script>
<?php if (str_contains($bodyClass, 'page-kids')): ?><script src="/assets/js/kids.js?v=<?= e(ME_VERSION) ?>" defer></script><?php endif; ?>
<?php if (!empty($extraScripts)) echo $extraScripts; ?>
<?php if (str_contains($bodyClass, 'page-app')): ?><script src="/assets/js/ads.js?v=<?= e(ME_VERSION) ?>" defer></script><?php endif; ?>
</body>
</html>
