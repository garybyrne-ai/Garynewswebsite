<section class="container pagehead">
  <span class="kicker"><?= icon('shield') ?> Civic directory</span>
  <h1 class="pagehead__title"><span class="pagehead__icon"><?= icon('phone') ?></span>Helplines &amp; emergency numbers</h1>
  <p class="pagehead__blurb">India's unified national emergency numbers — the same everywhere in the country, free to dial from any phone, any network. State-specific control-room numbers change too often to publish reliably, so these are the ones we can stand behind.</p>
</section>
<div class="container">
  <div class="helplines helplines--grid">
    <?php foreach ($numbers as $n): ?>
      <div class="helplines__item"><b class="mono"><?= e($n['number']) ?></b><small><?= e($n['label']) ?></small></div>
    <?php endforeach; ?>
  </div>

  <section class="block reveal" style="margin-top:32px">
    <header class="block__head">
      <span class="block__index mono"><?= icon('pin') ?></span>
      <h2 class="block__title">Local, state by state</h2>
      <p class="block__blurb">For anything more local than these — municipal helplines, district control rooms, hospital listings — your state's own news and alerts page is the most current source we have.</p>
    </header>
    <div class="countygrid">
      <?php $byProvince = []; foreach ($counties as $c) { $byProvince[\MeNews\Support\Locations::provinceFor($c) ?: 'Other'][] = $c; } ?>
      <?php foreach ($byProvince as $province => $list): ?>
        <div class="countygrid__prov"><h4 class="mono"><?= e($province) ?></h4><div class="countygrid__list"><?php foreach ($list as $c): ?><a class="countybtn" href="/alerts?county=<?= rawurlencode($c) ?>"><?= e($c) ?></a><?php endforeach; ?></div></div>
      <?php endforeach; ?>
    </div>
  </section>
</div>
