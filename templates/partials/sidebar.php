<?php
/** Shared right-hand column. Optional: $mapPoints, $counties, $trending, $ads, $notices, $county, $poll, $edition… */
$ads = $ads ?? [];
$county = $county ?? null;
$user = $user ?? \MeNews\Auth::user();
$adFree = $user && ($user['plan'] ?? '') === 'Wire+';
?>
<aside class="side">
  <?php if (isset($edition)): ?><?= \MeNews\View::partial('partials/edition', ['weather' => $weather ?? null, 'edition' => $edition, 'longDate' => $longDate, 'focal' => $focal, 'greeting' => $greeting ?? \MeNews\Support\Daily::greeting()]) ?><?php endif; ?>

  <?php if (!empty($notices)): ?>
  <section class="panel panel--notices reveal">
    <header class="panel__head"><span class="kicker"><?= icon('candle') ?> Notices<?= $county ? ' · ' . e($county) : '' ?></span><a class="mono panel__hint" href="/notices<?= $county ? '?county=' . rawurlencode($county) : '' ?>">All →</a></header>
    <ul class="noticelist">
      <?php foreach ($notices as $n): ?>
        <li><a href="<?= e($n['url']) ?>"><span class="noticelist__kind mono"><?= icon($n['icon']) ?> <?= e($n['kind_label']) ?></span><b><?= e($n['title']) ?></b><small><?= e(($n['town'] ? $n['town'] . ', ' : '') . $n['county']) ?><?= $n['kind'] === 'death' && $n['funeral_at'] ? ' · Funeral ' . e(date_in($n['funeral_at'], 'D H:i')) : '' ?></small></a></li>
      <?php endforeach; ?>
    </ul>
    <p class="panel__note"><a href="/notices/submit">Place a notice</a> · free for families and clubs.</p>
  </section>
  <?php endif; ?>

  <?php if (!empty($poll)): ?>
  <?= \MeNews\View::partial('partials/poll', ['poll' => $poll, 'county' => $county, 'compact' => true]) ?>
  <?php endif; ?>

  <?php if (isset($mapPoints)): $colours = \MeNews\Support\Geo::COLOURS; ?>
  <section class="panel panel--map reveal" data-map-root>
    <header class="panel__head"><span class="kicker">Live map</span><a class="mono panel__hint" href="<?= $county ? '/county/' . e(slugify($county)) . '/map' : '/map' ?>"><?= $county ? e($county) : 'India' ?> →</a></header>
    <div class="mapchips">
      <button type="button" class="is-active" data-map-chip>All</button>
      <button type="button" data-map-chip data-kind="community" style="--c:<?= e($colours['Community']) ?>"><i></i>Community</button>
      <?php foreach (['Local', 'Traffic', 'Sport'] as $c): ?><button type="button" data-map-chip data-category="<?= e($c) ?>" style="--c:<?= e($colours[$c]) ?>"><i></i><?= e($c) ?></button><?php endforeach; ?>
    </div>
    <div id="map" class="map" data-map="side" data-points='<?= e(json_encode($mapPoints, JSON_UNESCAPED_UNICODE)) ?>' data-counties='<?= e(json_encode($mapCounties ?? [], JSON_UNESCAPED_UNICODE)) ?>' data-colours='<?= e(json_encode($colours)) ?>' data-focus-county="<?= e((string)$county) ?>"></div>
    <p class="panel__note"><b class="mono" data-map-count></b> across India. States cluster when zoomed out; zoom in for pins. <a href="/map">Full map →</a></p>
  </section>
  <?php endif; ?>

  <?php if (!empty($trending)): $shown = array_values(array_filter($trending, static fn($t) => views_label((int)$t['views']) !== '')); if (count($shown) >= 3): ?>
  <section class="panel reveal">
    <header class="panel__head"><span class="kicker">Most read</span><span class="mono panel__hint">72 h</span></header>
    <ol class="ranked">
      <?php foreach (array_slice($shown, 0, 6) as $i => $t): ?>
        <li><span class="mono ranked__n"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span><a href="<?= e($t['url']) ?>"><?= e($t['title']) ?></a><span class="mono ranked__v"><?= e(views_label((int)$t['views'])) ?></span></li>
      <?php endforeach; ?>
    </ol>
  </section>
  <?php endif; endif; ?>

  <?php if (!empty($counties) && count($counties) >= 4): $max = max(array_column($counties, 'n')); ?>
  <section class="panel reveal">
    <header class="panel__head"><span class="kicker">Busiest counties</span><span class="mono panel__hint">7 days</span></header>
    <ol class="bars">
      <?php foreach ($counties as $c): ?>
        <li><a href="/county/<?= e(slugify($c['county'])) ?>"><span><?= e($c['county']) ?></span><i style="--v:<?= (int)round($c['n'] / max(1, $max) * 100) ?>"></i><b class="mono"><?= (int)$c['n'] ?></b></a></li>
      <?php endforeach; ?>
    </ol>
  </section>
  <?php endif; ?>

  <?php $trends = \MeNews\Services\Trends::forState($county, 6); if ($trends): ?>
  <section class="panel panel--trends reveal">
    <header class="panel__head"><span class="kicker"><?= icon('flame') ?> Trending searches<?= $county ? ' · ' . e($county) : ' · India' ?></span><span class="mono panel__hint">Google Trends</span></header>
    <ol class="trendlist">
      <?php foreach ($trends as $i => $tr): ?>
        <li>
          <span class="trendlist__n mono"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
          <div class="trendlist__body">
            <div class="trendlist__q"><a href="<?= e($tr['internal_url']) ?>"><b><?= e($tr['query']) ?></b></a><?php if ($tr['traffic']): ?><span class="trendlist__traffic mono"><?= e($tr['traffic']) ?></span><?php endif; ?></div>
            <?php if ($tr['news_title']): ?><a class="trendlist__news" href="<?= e($tr['internal_url']) ?>"><?= e(excerpt($tr['news_title'], 70)) ?></a><?php endif; ?>
            <?php if ($tr['news_url'] && $tr['news_source']): ?><a class="trendlist__source" href="<?= e($tr['news_url']) ?>" target="_blank" rel="noopener">Originally: <?= e($tr['news_source']) ?> ↗</a><?php endif; ?>
          </div>
          <?php if ($i === 0 && $tr['picture']): ?><img class="trendlist__pic" src="<?= e($tr['picture']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
    <p class="panel__note">Real-time search demand from Google Trends<?= $county ? ' in ' . e($county) : ' across India' ?> — the closest free, public signal to "what people are searching about" since Facebook, Instagram and X don't publish one.</p>
  </section>
  <?php endif; ?>

  <?php $liveMatches = \MeNews\Services\Cricket::configured() ? \MeNews\Services\Cricket::liveMatches() : []; if ($liveMatches): ?>
  <section class="panel reveal">
    <header class="panel__head"><span class="kicker"><?= icon('trophy') ?> Live cricket</span></header>
    <ul class="noticelist">
      <?php foreach ($liveMatches as $m): ?>
        <li><div><b><?= e($m['teams']) ?></b><small><?= e($m['score'] ?: $m['status']) ?></small></div></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <?php $festivals = $county ? \MeNews\Support\Festivals::upcomingForState($county, 4) : \MeNews\Support\Festivals::upcomingNational(4); if ($festivals): ?>
  <section class="panel reveal">
    <header class="panel__head"><span class="kicker"><?= icon('calendar') ?> Festivals &amp; holidays<?= $county ? ' · ' . e($county) : '' ?></span></header>
    <ul class="noticelist">
      <?php foreach ($festivals as $f): ?>
        <li><div><b><?= e($f['name']) ?></b><small><?= $f['date'] ? e(date_in($f['date'], 'D j M')) . ($f['in_days'] === 0 ? ' · today' : ($f['in_days'] === 1 ? ' · tomorrow' : ' · in ' . (int)$f['in_days'] . ' days')) : 'Date varies — check locally' ?></small></div></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <section class="panel panel--alerts reveal">
    <header class="panel__head"><span class="kicker"><?= icon('mail') ?> Your 7am morning</span></header>
    <p class="panel__note" style="margin:0 0 10px">Five stories, the weather, deaths and what's on for <?= e($county ?: 'your state') ?>, in your inbox at seven.</p>
    <form class="form form--inline" data-subscribe>
      <input type="hidden" name="kinds[]" value="daily">
      <input type="hidden" name="county" value="<?= e((string)$county) ?>" data-subscribe-county>
      <label class="sr-only" for="side-sub-email">Email</label>
      <div class="inline"><input id="side-sub-email" name="email" type="email" required placeholder="you@example.in" value="<?= e($user['email'] ?? '') ?>"><button class="btn btn--primary btn--sm" type="submit">Sign up</button></div>
      <p class="form__result" data-subscribe-result></p>
    </form>
  </section>

  <?php if (!$adFree): ?>
  <section class="panel panel--plus reveal">
    <span class="chip chip--plus">Wire+</span>
    <h3>Ad-free, with alerts for every place you love</h3>
    <p>Death notices and closures for up to ten areas, the full archive, and no adverts.</p>
    <div class="price"><b><?= e(\MeNews\Services\Membership::priceLabel()) ?></b><small>or <?= e(\MeNews\Services\Membership::annualLabel()) ?></small></div>
    <a class="btn btn--primary btn--block" href="/plus">Get Wire+</a>
  </section>

  <section class="panel panel--ads reveal">
    <header class="panel__head"><span class="kicker">Local businesses</span><a class="mono panel__hint" href="/advertise">Advertise →</a></header>
    <?php if ($ads): ?>
      <?= \MeNews\Services\Ads::slot($ads, 'sidebar') ?>
    <?php else: ?>
      <p class="panel__note">Your business could be here — designed in minutes, from <?= e(\MeNews\Services\AdPackages::money((int)(\MeNews\Services\AdPackages::all()[0]['price_cents'] ?? 600))) ?>. <a href="/advertise">Advertise with us</a>.</p>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</aside>
