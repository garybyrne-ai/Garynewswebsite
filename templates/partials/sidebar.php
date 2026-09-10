<?php
/** Shared right-hand column. Expects optional: $mapPoints, $counties, $contributors, $trending, $ads, $pulse */
$ads = $ads ?? [];
?>
<aside class="side">
  <?php if (isset($edition)): ?><?= \MeNews\View::partial('partials/edition', ['weather' => $weather ?? null, 'edition' => $edition, 'longDate' => $longDate, 'focal' => $focal, 'greeting' => $greeting ?? 'Dia duit']) ?><?php endif; ?>
  <?php if (isset($pulse)): ?>
  <section class="panel panel--pulse reveal">
    <header class="panel__head"><span class="kicker">Newsroom pulse</span><span class="mono panel__hint">24 h</span></header>
    <?= \MeNews\Ui::sparkline($pulse) ?>
    <div class="pulse__stats mono">
      <span><b><?= e((string)array_sum($pulse)) ?></b> stories / 24 h</span>
      <span><b><?= e((string)max($pulse)) ?></b> peak per hour</span>
    </div>
  </section>
  <?php endif; ?>

  <?php if (isset($mapPoints)): $colours = \MeNews\Support\Geo::COLOURS; ?>
  <section class="panel panel--map reveal" data-map-root>
    <header class="panel__head"><span class="kicker">Live map</span><a class="mono panel__hint" href="/map" data-map-count><?= count($mapPoints) ?> pins</a></header>
    <div class="mapchips">
      <button type="button" class="is-active" data-map-chip>All</button>
      <button type="button" data-map-chip data-kind="community" style="--c:<?= e($colours['Community']) ?>"><i></i>Community</button>
      <?php foreach (['Local', 'Traffic', 'Sport', 'Business'] as $c): ?><button type="button" data-map-chip data-category="<?= e($c) ?>" style="--c:<?= e($colours[$c]) ?>"><i></i><?= e($c) ?></button><?php endforeach; ?>
    </div>
    <div id="map" class="map" data-map="side" data-points='<?= e(json_encode($mapPoints, JSON_UNESCAPED_UNICODE)) ?>' data-counties='<?= e(json_encode($mapCounties ?? [], JSON_UNESCAPED_UNICODE)) ?>' data-colours='<?= e(json_encode($colours)) ?>'></div>
    <p class="panel__note">Pins are placed by town or county; community reports with GPS sit exactly where they were filed. <button type="button" class="linkbtn" data-locate>Use my location</button> · <a href="/map">Full map →</a></p>
  </section>
  <?php endif; ?>

  <?php if (!empty($counties)): $max = max(array_column($counties, 'n')); ?>
  <section class="panel reveal">
    <header class="panel__head"><span class="kicker">Signal by county</span><span class="mono panel__hint">7 days</span></header>
    <ol class="bars">
      <?php foreach ($counties as $c): ?>
        <li><a href="/county/<?= e(slugify($c['county'])) ?>"><span><?= e($c['county']) ?></span><i style="--v:<?= (int)round($c['n'] / max(1, $max) * 100) ?>"></i><b class="mono"><?= (int)$c['n'] ?></b></a></li>
      <?php endforeach; ?>
    </ol>
  </section>
  <?php endif; ?>

  <?php if (!empty($trending)): ?>
  <section class="panel reveal">
    <header class="panel__head"><span class="kicker">Most read</span><span class="mono panel__hint">72 h</span></header>
    <ol class="ranked">
      <?php foreach ($trending as $i => $t): ?>
        <li><span class="mono ranked__n"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span><a href="<?= e($t['url']) ?>"><?= e($t['title']) ?></a><span class="mono ranked__v"><?= e(compact_number((int)$t['views'])) ?></span></li>
      <?php endforeach; ?>
    </ol>
  </section>
  <?php endif; ?>

  <?php if (!empty($contributors)): ?>
  <section class="panel reveal">
    <header class="panel__head"><span class="kicker">The desk</span><a class="mono panel__hint" href="/contributors">All →</a></header>
    <ul class="desk">
      <?php foreach ($contributors as $c): ?>
        <li><a href="/contributors/<?= e($c['handle']) ?>"><?= \MeNews\Ui::avatar($c, 'sm') ?><span><b><?= e($c['display_name']) ?></b><small><?= e($c['title']) ?></small></span><em class="mono"><?= (int)$c['stories'] ?></em></a></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <section class="panel panel--plus reveal">
    <span class="chip chip--plus">ME+</span>
    <h3>More of your community</h3>
    <p>Follow up to ten local areas, get local alerts first and support independent Irish community journalism.</p>
    <div class="price"><b><?= e(\MeNews\Config::get('ME_PLUS_PRICE_LABEL', '€6.99/month')) ?></b></div>
    <a class="btn btn--primary btn--block" href="/plus">Get ME+</a>
  </section>

  <section class="panel panel--ads reveal">
    <header class="panel__head"><span class="kicker">Local businesses</span><a class="mono panel__hint" href="/advertise">Advertise →</a></header>
    <?php if ($ads): foreach ($ads as $ad): ?>
      <?= \MeNews\Services\Ads::render($ad, 'sidebar') ?>
    <?php endforeach; else: ?>
      <p class="panel__note">Your business could be here — designed in minutes, <?= e(\MeNews\Services\Ads::priceLabel()) ?> after a free trial. <a href="/advertise">Advertise with ME</a>.</p>
    <?php endif; ?>
  </section>
</aside>
