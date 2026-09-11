<?php $byCat = []; foreach ($points as $p) { $byCat[$p['category']] = ($byCat[$p['category']] ?? 0) + 1; } arsort($byCat); $focus = $focus ?? null; $focusCounty = $focusCounty ?? null; ?>
<section class="container pagehead">
  <span class="kicker"><?= $focusCounty ? 'Co. ' . e($focusCounty) . ' · map' : 'Live map' ?></span>
  <h1 class="pagehead__title"><span class="pagehead__icon"><?= icon('map') ?></span><?= $focusCounty ? e($focusCounty) . ', <em>on the map.</em>' : 'Ireland, <em>right now.</em>' ?></h1>
  <p class="pagehead__blurb"><?= $focusCounty ? 'Every story placed in County ' . e($focusCounty) . ': wire stories by the town they mention, community reports exactly where they were filed. Filter by section and by time.' : 'Every published story pinned to the island. Zoomed out, counties cluster into one bubble each; zoom in for individual pins. Filter by section and by time.' ?></p>
  <div class="mapstats mono"><span><b><?= count($points) ?></b> stories placed</span><?php if (!$focusCounty): ?><span><b><?= count($counties) ?></b> counties active</span><?php endif; ?><span><b><?= (int)array_sum(array_column($counties, 'n')) ?></b> stories this week</span><?php if ($focusCounty): ?><a href="/map">All of Ireland →</a><?php else: ?><a href="/notices">Deaths &amp; notices →</a><?php endif; ?></div>
</section>
<div class="container mappage" data-map-root>
  <div>
    <div class="mapchips">
      <button type="button" class="is-active" data-map-chip>All</button>
      <button type="button" data-map-chip data-kind="community" style="--c:<?= e($colours['Community']) ?>"><i></i>Community</button>
      <?php foreach (array_keys($byCat) as $c): if (!isset($colours[$c])) continue; ?><button type="button" data-map-chip data-category="<?= e($c) ?>" style="--c:<?= e($colours[$c]) ?>"><i></i><?= e($c) ?> · <?= (int)$byCat[$c] ?></button><?php endforeach; ?>
      <span class="mapchips__sep"></span>
      <button type="button" class="is-active" data-map-time data-hours="0">All time</button>
      <button type="button" data-map-time data-hours="24">Last 24 h</button>
      <button type="button" data-map-time data-hours="168">Last 7 days</button>
      <button type="button" class="btn btn--ghost btn--sm" data-locate style="margin-left:auto"><?= icon('gps') ?> Use my location</button>
    </div>
    <div id="map" class="map map--full" data-map="full" data-points='<?= e(json_encode($points, JSON_UNESCAPED_UNICODE)) ?>' data-counties='<?= e(json_encode($counties, JSON_UNESCAPED_UNICODE)) ?>' data-colours='<?= e(json_encode($colours)) ?>' <?= $focus ? "data-focus='" . e(json_encode($focus)) . "'" : '' ?>></div>
    <div class="maplegend">
      <?php foreach ($colours as $c => $hex): ?><span style="--c:<?= e($hex) ?>"><i class="<?= $c === 'Community' ? 'is-community' : '' ?>"></i><?= e($c) ?></span><?php endforeach; ?>
      <span><?= icon('sparkle') ?> newest stories pulse</span>
    </div>
    <?php if (!$focusCounty): ?><p class="panel__note">Every county has its own permanent map: <?php foreach (\MeNews\Support\Locations::countyNames() as $i => $c): ?><a href="/county/<?= e(slugify($c)) ?>/map"><?= e($c) ?></a><?= $i < 25 ? ' · ' : '' ?><?php endforeach; ?></p><?php endif; ?>
  </div>
  <aside>
    <header class="panel__head"><span class="kicker">On the map</span><span class="mono panel__hint" data-map-count><?= count($points) ?> pins</span></header>
    <div class="maplist" data-map-list></div>
  </aside>
</div>
