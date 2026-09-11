<?php
/** "Near you" section. Expects $locality (Visitor::locality), $counties (names), optional $compact */
use MeNews\Ui;
$loc = $locality;
$compact = $compact ?? false;
$notices = $notices ?? [];
$first = $first ?? false;
$countyOptions = '';
foreach ($counties as $c) {
    $countyOptions .= '<option value="' . e($c) . '"' . ($loc['county'] === $c ? ' selected' : '') . '>' . e($c) . '</option>';
}
?>
<section class="block block--near reveal" id="near" data-near data-mode="<?= e($loc['mode']) ?>">
  <header class="block__head">
    <span class="block__index mono"><?= icon('pin') ?></span>
    <h2 class="block__title">
      <?php if ($loc['mode'] === 'none'): ?>Your county, first<?php elseif ($loc['mode'] === 'abroad'): ?>You're outside Ireland<?php else: ?><a href="/county/<?= e(slugify((string)$loc['county'])) ?>"><?= e($loc['title']) ?></a><?php endif; ?>
    </h2>
    <p class="block__blurb">
      <?php if ($loc['mode'] === 'gps'): ?>Stories within <?= (int)$loc['radius'] ?> km of you<?= $loc['place']['town'] ? ', nearest town ' . e($loc['place']['town']) : '' ?>, freshest and closest first.
      <?php elseif ($loc['mode'] === 'county'): ?>Everything published from County <?= e($loc['county']) ?>. Allow location for stories by distance.
      <?php elseif ($loc['mode'] === 'abroad'): ?>We can't find an Irish county near your position — pick the county you care about instead.
      <?php else: ?>Pick your county once and ME leads with it: local stories, deaths and notices, school closures and weather warnings. Stored only on this device.<?php endif; ?>
    </p>
    <div class="near__controls">
      <?php if ($loc['mode'] === 'none'): ?><button class="btn btn--primary btn--sm" type="button" data-open-county><?= icon('pin') ?> Choose my county</button><?php endif; ?>
      <button class="btn btn--ghost btn--sm" type="button" data-locate-me><?= icon('gps') ?> <?= $loc['mode'] === 'gps' ? 'Update location' : 'Use my location' ?></button>
      <label class="near__county"><span class="mono">county</span><select data-county-pick><option value="">Choose…</option><?= $countyOptions ?></select></label>
      <?php if ($loc['mode'] !== 'none'): ?><button class="btn btn--ghost btn--sm" type="button" data-forget-location>Forget</button><?php endif; ?>
    </div>
  </header>
  <?php if ($loc['stories']): ?>
    <div class="grid <?= $compact ? 'grid--4' : 'grid--3' ?>">
      <?php foreach ($loc['stories'] as $i => $s): ?>
        <?php $card = Ui::card($s, $compact ? ($i === 0 ? 'standard' : 'compact') : 'standard', $i); ?>
        <?= ($s['distance_km'] ?? null) !== null ? str_replace('<div class="card__foot">', '<div class="card__foot"><span class="card__km">' . e(number_format((float)$s['distance_km'], $s['distance_km'] < 10 ? 1 : 0)) . ' km</span>', $card) : $card ?>
      <?php endforeach; ?>
    </div>
    <?php if ($compact && $loc['county']): ?><p class="near__more"><a class="mono" href="/county/<?= e(slugify((string)$loc['county'])) ?>">All of Co. <?= e($loc['county']) ?> →</a><span class="mono">·</span><a class="mono" href="/notices?county=<?= rawurlencode((string)$loc['county']) ?>">Deaths &amp; notices →</a><span class="mono">·</span><a class="mono" href="/alerts?county=<?= rawurlencode((string)$loc['county']) ?>">Alerts →</a><span class="mono">·</span><a class="mono" href="/county/<?= e(slugify((string)$loc['county'])) ?>/map">Map →</a></p><?php endif; ?>
    <?php if ($notices): ?>
      <div class="near__notices">
        <span class="kicker"><?= icon('candle') ?> Recent deaths · Co. <?= e($loc['county']) ?></span>
        <ul class="noticelist noticelist--row"><?php foreach ($notices as $n): ?><li><a href="<?= e($n['url']) ?>"><b><?= e($n['title']) ?></b><small><?= e($n['town'] ?: 'Co. ' . $n['county']) ?><?= $n['funeral_at'] ? ' · ' . e(date_irish($n['funeral_at'], 'D H:i')) : '' ?></small></a></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>
  <?php elseif ($loc['mode'] === 'none'): ?>
    <div class="near__empty">
      <div class="near__radar" aria-hidden="true"><i></i><i></i><i></i></div>
      <div>
        <h3>Your town. Your county. Your front page.</h3>
        <p>Choose a county and it moves to the top of ME: local stories, deaths and funeral arrangements, school closures, weather warnings and what's on. Or share your location and we find the nearest of 227 Irish towns.</p>
      </div>
    </div>
  <?php elseif ($loc['mode'] === 'abroad'): ?>
    <div class="empty"><div class="empty__glyph"><?= icon('world') ?></div><h3>Nothing near <?= e(number_format((float)$loc['place']['county_km'])) ?> km from the nearest Irish county.</h3><p>Choose a county above and we'll keep it as your local section.</p></div>
  <?php else: ?>
    <div class="empty"><div class="empty__glyph"><?= icon('clock') ?></div><h3>Nothing published from here yet.</h3><p>The wire refreshes every few minutes. Try a wider county or report something yourself.</p></div>
  <?php endif; ?>
</section>
