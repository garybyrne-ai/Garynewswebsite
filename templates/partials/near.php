<?php
/** "Near you" section. Expects $locality (Visitor::locality), $counties (names), optional $compact */
use MeNews\Ui;
$loc = $locality;
$compact = $compact ?? false;
$countyOptions = '';
foreach ($counties as $c) {
    $countyOptions .= '<option value="' . e($c) . '"' . ($loc['county'] === $c ? ' selected' : '') . '>' . e($c) . '</option>';
}
?>
<section class="block block--near reveal" id="near" data-near data-mode="<?= e($loc['mode']) ?>">
  <header class="block__head">
    <span class="block__index mono">◎</span>
    <h2 class="block__title">
      <?php if ($loc['mode'] === 'none'): ?>Stories near you<?php elseif ($loc['mode'] === 'abroad'): ?>You're outside Ireland<?php else: ?>Near you · <a href="/county/<?= e(slugify((string)$loc['county'])) ?>"><?= e($loc['title']) ?></a><?php endif; ?>
    </h2>
    <p class="block__blurb">
      <?php if ($loc['mode'] === 'gps'): ?>Stories within <?= (int)$loc['radius'] ?> km of you<?= $loc['place']['town'] ? ', nearest town ' . e($loc['place']['town']) : '' ?>, freshest and closest first.
      <?php elseif ($loc['mode'] === 'county'): ?>Everything published from County <?= e($loc['county']) ?>. Allow location for stories by distance.
      <?php elseif ($loc['mode'] === 'abroad'): ?>We can't find an Irish county near your position — pick the county you care about instead.
      <?php else: ?>Allow location access on your phone or computer and ME builds a local section for your town and county. Your position stays in a cookie on this device and is never saved to an account.<?php endif; ?>
    </p>
    <div class="near__controls">
      <button class="btn btn--primary btn--sm" type="button" data-locate-me><?= $loc['mode'] === 'gps' ? '◎ Update location' : '◎ Use my location' ?></button>
      <label class="near__county"><span class="mono">or county</span><select data-county-pick><option value="">Choose…</option><?= $countyOptions ?></select></label>
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
    <?php if ($loc['mode'] === 'gps' && $compact): ?><p class="near__more"><a class="mono" href="/near">All stories near you →</a><span class="mono">·</span><a class="mono" href="/county/<?= e(slugify((string)$loc['county'])) ?>">Co. <?= e($loc['county']) ?> →</a></p><?php endif; ?>
  <?php elseif ($loc['mode'] === 'none'): ?>
    <div class="near__empty">
      <div class="near__radar" aria-hidden="true"><i></i><i></i><i></i></div>
      <div>
        <h3>Your town. Your county. Your section.</h3>
        <p>Tap <b>Use my location</b> and we'll find the nearest of 227 Irish towns, then gather every story within 40 km — wire reports and community posts alike. Works on phones and computers.</p>
      </div>
    </div>
  <?php elseif ($loc['mode'] === 'abroad'): ?>
    <div class="empty"><div class="empty__glyph" aria-hidden="true">◎</div><h3>Nothing near <?= e(number_format((float)$loc['place']['county_km'])) ?> km from the nearest Irish county.</h3><p>Choose a county above and we'll keep it as your local section.</p></div>
  <?php else: ?>
    <div class="empty"><div class="empty__glyph" aria-hidden="true">◌</div><h3>Nothing published from here yet.</h3><p>The wire refreshes every few minutes. Try a wider county or report something yourself.</p></div>
  <?php endif; ?>
</section>
