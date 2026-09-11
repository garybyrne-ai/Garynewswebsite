<section class="container pagehead">
  <span class="kicker">Weekly county poll · <?= e($poll['week'] ?? '') ?></span>
  <h1 class="pagehead__title"><?= $poll ? e($poll['question']) : 'No poll this week' ?></h1>
  <p class="pagehead__blurb">One question a week, answered anonymously by readers in every county. The county-by-county breakdown is published as soon as three people in a county have voted.</p>
</section>
<div class="container layout">
  <div class="layout__main">
    <?php if ($poll): ?>
      <?= \MeNews\View::partial('partials/poll', ['poll' => $poll, 'county' => $county, 'compact' => false]) ?>
      <section class="block reveal" style="margin-top:32px">
        <header class="block__head"><span class="block__index mono"><?= icon('map') ?></span><h2 class="block__title">County by county</h2><p class="block__blurb"><?= $poll['results']['total'] ?> votes so far<?= $poll['closed'] ? ' · closed' : ' · closes ' . e(date_irish($poll['closes_at'], 'l H:i')) ?></p></header>
        <?php if ($poll['breakdown']): ?>
          <div class="tablewrap"><table class="table"><thead><tr><th>County</th><th>Leaning</th><th>Share</th><th>Votes</th></tr></thead><tbody>
            <?php foreach ($poll['breakdown'] as $b): ?><tr><td><b><?= e($b['county']) ?></b></td><td><?= e($poll['options'][$b['lead']] ?? '') ?></td><td><div class="meter meter--sm"><i style="--v:<?= (int)$b['lead_pct'] ?>"></i></div> <?= (int)$b['lead_pct'] ?>%</td><td><?= (int)$b['total'] ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
        <?php else: ?>
          <div class="empty"><div class="empty__glyph"><?= icon('map') ?></div><h3>The county map fills in as votes arrive.</h3><p>Choose your county in the header so your vote counts for <?= $county ? 'Co. ' . e($county) : 'your county' ?>.</p></div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
    <?php if ($past): ?>
      <section class="block reveal"><header class="block__head block__head--sm"><h2 class="block__title">Previous weeks</h2></header>
        <ul class="noticelist"><?php foreach ($past as $p): ?><li><a href="/poll?week=<?= e($p['week']) ?>"><b><?= e($p['question']) ?></b><small><?= e($p['week']) ?></small></a></li><?php endforeach; ?></ul>
      </section>
    <?php endif; ?>
  </div>
  <?= \MeNews\View::partial('partials/sidebar', ['trending' => [], 'ads' => [], 'county' => $county]) ?>
</div>
