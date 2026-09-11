<?php $byCat = []; foreach ($points as $p) { $byCat[$p['category']] = ($byCat[$p['category']] ?? 0) + 1; } arsort($byCat); ?>
<section class="container pagehead">
  <span class="kicker">Live map</span>
  <h1 class="pagehead__title"><span class="pagehead__icon"><?= icon('map') ?></span>Ireland, <em>right now.</em></h1>
  <p class="pagehead__blurb">Every published story pinned to the island. Wire stories are placed by the town or county they mention; community reports with GPS sit exactly where they were filed. Circles show how busy each county has been this week.</p>
  <div class="mapstats mono"><span><b><?= count($points) ?></b> pins</span><span><b><?= count($counties) ?></b> counties active</span><span><b><?= (int)array_sum(array_column($counties, 'n')) ?></b> stories this week</span></div>
</section>
<div class="container mappage" data-map-root>
  <div>
    <div class="mapchips">
      <button type="button" class="is-active" data-map-chip>All</button>
      <button type="button" data-map-chip data-kind="community" style="--c:<?= e($colours['Community']) ?>"><i></i>Community</button>
      <?php foreach (array_keys($byCat) as $c): if (!isset($colours[$c])) continue; ?><button type="button" data-map-chip data-category="<?= e($c) ?>" style="--c:<?= e($colours[$c]) ?>"><i></i><?= e($c) ?> · <?= (int)$byCat[$c] ?></button><?php endforeach; ?>
      <button type="button" class="btn btn--ghost btn--sm" data-locate style="margin-left:auto"><?= icon('gps') ?> Use my location</button>
    </div>
    <div id="map" class="map map--full" data-map="full" data-points='<?= e(json_encode($points, JSON_UNESCAPED_UNICODE)) ?>' data-counties='<?= e(json_encode($counties, JSON_UNESCAPED_UNICODE)) ?>' data-colours='<?= e(json_encode($colours)) ?>'></div>
    <div class="maplegend">
      <?php foreach ($colours as $c => $hex): ?><span style="--c:<?= e($hex) ?>"><i class="<?= $c === 'Community' ? 'is-community' : '' ?>"></i><?= e($c) ?></span><?php endforeach; ?>
      <span><?= icon('sparkle') ?> newest stories pulse</span>
    </div>
  </div>
  <aside>
    <header class="panel__head"><span class="kicker">On the map</span><span class="mono panel__hint" data-map-count><?= count($points) ?> pins</span></header>
    <div class="maplist" data-map-list></div>
  </aside>
</div>
