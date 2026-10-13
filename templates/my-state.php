<?php if (!$county): ?>
<section class="container pagehead pagehead--center">
  <span class="pagehead__icon"><?= icon('sparkle') ?></span>
  <h1 class="pagehead__title">My <em>State.</em></h1>
  <p class="pagehead__blurb">Weather, air quality, severe-weather alerts, school closures and trending searches for your state — one page instead of hunting across the site. Pick a state to see it.</p>
  <div class="countygrid" style="margin-top:10px">
    <?php $byProvince = []; foreach ($counties as $c) { $byProvince[\MeNews\Support\Locations::provinceFor($c) ?: 'Other'][] = $c; } ?>
    <?php foreach ($byProvince as $province => $list): ?>
      <div class="countygrid__prov"><h4 class="mono"><?= e($province) ?></h4><div class="countygrid__list"><?php foreach ($list as $c): ?><a class="countybtn" href="/my-state?state=<?= e(slugify($c)) ?>"><?= e($c) ?></a><?php endforeach; ?></div></div>
    <?php endforeach; ?>
  </div>
</section>
<?php return; endif; ?>

<section class="container pagehead pagehead--state">
  <span class="kicker"><?= icon('sparkle') ?> My State</span>
  <h1 class="pagehead__title"><?= e($county) ?><em>, at a glance.</em></h1>
  <p class="pagehead__blurb">Everything about <?= e($county) ?> that isn't a news story: live weather, air quality, severe-weather alerts, school closures and what people here are searching for right now.</p>
  <form class="noticefilter" method="get" action="/my-state">
    <label class="sr-only" for="ms-state">State</label>
    <select id="ms-state" name="state" onchange="this.form.submit()"><?php foreach ($counties as $c): ?><option value="<?= e(slugify($c)) ?>" <?= $c === $county ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select>
    <noscript><button class="btn btn--primary btn--sm" type="submit">Go</button></noscript>
    <a class="btn btn--ghost btn--sm" href="/county/<?= e($countySlug) ?>"><?= icon('national') ?> <?= e($county) ?> news →</a>
  </form>
</section>

<div class="container">
  <div class="grid grid--2" style="margin-bottom:24px">
    <div class="panel statcard reveal">
      <header class="panel__head"><span class="kicker"><?= icon('sun') ?> Weather</span><?php if ($weather): ?><span class="mono panel__hint">updated <?= e(time_ago($weather['updated'])) ?></span><?php endif; ?></header>
      <?php if ($weather): ?>
        <div class="statcard__main"><span class="statcard__ic"><?= e($weather['icon']) ?></span><b class="statcard__num"><?= (int)$weather['temp'] ?>°</b><span class="statcard__label"><?= e($weather['label']) ?></span></div>
        <p class="statcard__sub">High <?= (int)$weather['max'] ?>° · Low <?= (int)$weather['min'] ?>° near <?= e($county) ?>'s capital area</p>
      <?php else: ?>
        <div class="empty"><div class="empty__glyph"><?= icon('sun') ?></div><p>Weather isn't available right now.</p></div>
      <?php endif; ?>
    </div>
    <div class="panel statcard reveal">
      <header class="panel__head"><span class="kicker"><?= icon('wind') ?> Air quality</span><?php if ($aqi): ?><span class="mono panel__hint">updated <?= e(time_ago($aqi['updated'])) ?></span><?php endif; ?></header>
      <?php if ($aqi): ?>
        <div class="statcard__main"><i class="aqidot aqidot--lg" style="--c:<?= e($aqi['colour']) ?>"></i><b class="statcard__num"><?= (int)$aqi['aqi'] ?></b><span class="statcard__label"><?= e($aqi['label']) ?></span></div>
        <p class="statcard__sub">India's AQI scale, 0 (best) to 500+ (severe) — from the World Air Quality Index project</p>
      <?php else: ?>
        <div class="empty"><div class="empty__glyph"><?= icon('wind') ?></div><p>Air quality data isn't switched on for this site yet.</p></div>
      <?php endif; ?>
    </div>
  </div>

  <section class="block">
    <header class="block__head"><span class="block__index mono"><?= icon('alert') ?></span><h2 class="block__title">Alerts &amp; closures</h2><p class="block__blurb">SACHET severe-weather warnings and school closures confirmed by principals.</p><a class="block__more mono" href="/alerts?county=<?= e($countySlug) ?>">Full alerts page →</a></header>
    <?php if ($warnings): ?>
      <div class="warnlist">
        <?php foreach ($warnings as $w): ?>
          <article class="warn warn--<?= strtolower(e($w['level'])) ?> <?= $w['advisory'] ? 'warn--advisory' : '' ?>">
            <span class="warn__level mono"><?= e($w['level']) ?><?= $w['advisory'] ? ' advisory' : ' warning' ?></span>
            <h3><?= e($w['headline']) ?></h3>
            <p><?= e($w['description']) ?></p>
            <p class="warn__meta mono"><?= $w['onset'] ? 'From ' . e(date_in($w['onset'], 'D H:i')) : '' ?><?= $w['expiry'] ? ' until ' . e(date_in($w['expiry'], 'D H:i')) : '' ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty"><div class="empty__glyph"><?= icon('check') ?></div><h3>Nothing in force for <?= e($county) ?>.</h3><p>This section fills in the moment SACHET issues a warning.</p></div>
    <?php endif; ?>
    <?php if ($closures): ?>
      <ul class="noticelist" style="margin-top:14px">
        <?php foreach ($closures as $c): ?><li><div><b><?= icon('school') ?> <?= e($c['school']) ?></b><small><?= e($c['town'] ?: $county) ?> · closed <?= e(date_in($c['closed_on'], 'D j M')) ?><?= $c['reopens_on'] ? ' · reopens ' . e(date_in($c['reopens_on'], 'D j M')) : '' ?><?= $c['reason'] ? ' · ' . e($c['reason']) : '' ?></small></div></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <?php if ($trends): ?>
  <section class="block">
    <header class="block__head"><span class="block__index mono"><?= icon('flame') ?></span><h2 class="block__title">Trending searches</h2><p class="block__blurb">Real-time search demand from Google Trends in <?= e($county) ?> — the closest free, public signal to "what people are searching about".</p></header>
    <?= \MeNews\View::partial('partials/trending-searches', ['trends' => $trends, 'limit' => 10]) ?>
  </section>
  <?php endif; ?>

  <?php if ($festivals): ?>
  <section class="block">
    <header class="block__head"><span class="block__index mono"><?= icon('calendar') ?></span><h2 class="block__title">Festivals &amp; holidays</h2><p class="block__blurb">Coming up in <?= e($county) ?>.</p></header>
    <ul class="noticelist noticelist--row">
      <?php foreach ($festivals as $f): ?><li><div><b><?= e($f['name']) ?></b><small><?= $f['date'] ? e(date_in($f['date'], 'D j M')) . ($f['in_days'] === 0 ? ' · today' : ($f['in_days'] === 1 ? ' · tomorrow' : ' · in ' . (int)$f['in_days'] . ' days')) : 'Date varies — check locally' ?></small></div></li><?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>
</div>
