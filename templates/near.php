<?php $loc = $locality; ?>
<section class="container pagehead">
  <span class="kicker">Around me</span>
  <h1 class="pagehead__title"><span class="pagehead__icon" aria-hidden="true">◎</span><?= $loc['mode'] === 'none' ? 'Stories near you' : e($loc['title']) ?></h1>
  <p class="pagehead__blurb"><?= $loc['mode'] === 'gps' ? 'Local news chosen by your position: the nearest town, the county around it and everything happening within ' . (int)$loc['radius'] . ' km.' : 'Allow location access and ME turns into your local paper: the nearest town, your county, and stories sorted by distance.' ?></p>
</section>
<div class="container layout">
  <div class="layout__main">
    <?= \MeNews\View::partial('partials/near', ['locality' => $loc, 'counties' => $counties, 'compact' => false]) ?>
  </div>
  <aside class="side">
    <?php if ($mapPoints): ?>
      <section class="panel panel--map reveal" data-map-root>
        <header class="panel__head"><span class="kicker">On the map</span><span class="mono panel__hint"><?= count($mapPoints) ?> nearby</span></header>
        <div id="map" class="map" data-map="side" data-points='<?= e(json_encode($mapPoints, JSON_UNESCAPED_UNICODE)) ?>' data-counties='[]' data-colours='<?= e(json_encode(\MeNews\Support\Geo::COLOURS)) ?>' data-center='<?= e(json_encode($loc['position'])) ?>'></div>
      </section>
    <?php endif; ?>
    <section class="panel reveal">
      <header class="panel__head"><span class="kicker">How it works</span></header>
      <p class="panel__note">Your browser asks once for permission. We round your position to about 100 m, keep it in a cookie on this device for 30 days, and never attach it to an account. Choose a county instead if you'd rather not share it, or tap <b>Forget</b> any time.</p>
      <p class="panel__note">Signed in? Follow your county in the dashboard to get alerts when an editor publishes a local report.</p>
    </section>
  </aside>
</div>
