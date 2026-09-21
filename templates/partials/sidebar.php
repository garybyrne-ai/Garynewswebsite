<?php
/** Shared right-hand column. Optional: $mapPoints, $counties, $trending, $ads, $notices, $county, $poll, $edition… */
$ads = $ads ?? [];
$county = $county ?? null;
$user = $user ?? \MeNews\Auth::user();
$adFree = $user && ($user['plan'] ?? '') === 'ME+';
?>
<aside class="side">
  <?php if (isset($edition)): ?><?= \MeNews\View::partial('partials/edition', ['weather' => $weather ?? null, 'edition' => $edition, 'longDate' => $longDate, 'focal' => $focal, 'greeting' => $greeting ?? 'Dia duit']) ?><?php endif; ?>

  <?php if (!empty($notices)): ?>
  <section class="panel panel--notices reveal">
    <header class="panel__head"><span class="kicker"><?= icon('candle') ?> Notices<?= $county ? ' · ' . e($county) : '' ?></span><a class="mono panel__hint" href="/notices<?= $county ? '?county=' . rawurlencode($county) : '' ?>">All →</a></header>
    <ul class="noticelist">
      <?php foreach ($notices as $n): ?>
        <li><a href="<?= e($n['url']) ?>"><span class="noticelist__kind mono"><?= icon($n['icon']) ?> <?= e($n['kind_label']) ?></span><b><?= e($n['title']) ?></b><small><?= e(($n['town'] ? $n['town'] . ', ' : '') . 'Co. ' . $n['county']) ?><?= $n['kind'] === 'death' && $n['funeral_at'] ? ' · Funeral ' . e(date_irish($n['funeral_at'], 'D H:i')) : '' ?></small></a></li>
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
    <header class="panel__head"><span class="kicker">Live map</span><a class="mono panel__hint" href="<?= $county ? '/county/' . e(slugify($county)) . '/map' : '/map' ?>"><?= $county ? 'Co. ' . e($county) : 'Ireland' ?> →</a></header>
    <div class="mapchips">
      <button type="button" class="is-active" data-map-chip>All</button>
      <button type="button" data-map-chip data-kind="community" style="--c:<?= e($colours['Community']) ?>"><i></i>Community</button>
      <?php foreach (['Local', 'Traffic', 'Sport'] as $c): ?><button type="button" data-map-chip data-category="<?= e($c) ?>" style="--c:<?= e($colours[$c]) ?>"><i></i><?= e($c) ?></button><?php endforeach; ?>
    </div>
    <div id="map" class="map" data-map="side" data-points='<?= e(json_encode($mapPoints, JSON_UNESCAPED_UNICODE)) ?>' data-counties='<?= e(json_encode($mapCounties ?? [], JSON_UNESCAPED_UNICODE)) ?>' data-colours='<?= e(json_encode($colours)) ?>' data-focus-county="<?= e((string)$county) ?>"></div>
    <p class="panel__note"><b class="mono" data-map-count></b> across Ireland. Counties cluster when zoomed out; zoom in for pins. <a href="/map">Full map →</a></p>
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

  <section class="panel panel--alerts reveal">
    <header class="panel__head"><span class="kicker"><?= icon('mail') ?> Your 7am morning</span></header>
    <p class="panel__note" style="margin:0 0 10px">Five stories, the weather, deaths and what's on for <?= e($county ? 'Co. ' . $county : 'your county') ?>, in your inbox at seven.</p>
    <form class="form form--inline" data-subscribe>
      <input type="hidden" name="kinds[]" value="daily">
      <input type="hidden" name="county" value="<?= e((string)$county) ?>" data-subscribe-county>
      <label class="sr-only" for="side-sub-email">Email</label>
      <div class="inline"><input id="side-sub-email" name="email" type="email" required placeholder="you@example.ie" value="<?= e($user['email'] ?? '') ?>"><button class="btn btn--primary btn--sm" type="submit">Sign up</button></div>
      <p class="form__result" data-subscribe-result></p>
    </form>
  </section>

  <?php if (!$adFree): ?>
  <section class="panel panel--plus reveal">
    <span class="chip chip--plus">ME+</span>
    <h3>Ad-free, with alerts for every place you love</h3>
    <p>Death notices and closures for up to ten areas, the full archive, and no adverts.</p>
    <div class="price"><b><?= e(\MeNews\Services\Membership::priceLabel()) ?></b><small>or <?= e(\MeNews\Services\Membership::annualLabel()) ?></small></div>
    <a class="btn btn--primary btn--block" href="/plus">Get ME+</a>
  </section>

  <section class="panel panel--ads reveal">
    <header class="panel__head"><span class="kicker">Local businesses</span><a class="mono panel__hint" href="/advertise">Advertise →</a></header>
    <?php if ($ads): ?>
      <?= \MeNews\Services\Ads::slot($ads, 'sidebar') ?>
    <?php else: ?>
      <p class="panel__note">Your business could be here — designed in minutes, from <?= e(\MeNews\Services\AdPackages::money((int)(\MeNews\Services\AdPackages::all()[0]['price_cents'] ?? 600))) ?>. <a href="/advertise">Advertise with ME</a>.</p>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</aside>
