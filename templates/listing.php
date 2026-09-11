<?php use MeNews\Ui; ?>
<section class="container pagehead">
  <span class="kicker"><?= e($kicker) ?></span>
  <h1 class="pagehead__title"><span class="pagehead__icon"><?= icon($icon) ?></span><?= e($heading) ?></h1>
  <p class="pagehead__blurb"><?= e($blurb) ?></p>
  <?php if (isset($q)): ?>
    <form class="search search--big" action="/search" role="search"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search stories, towns, counties…" aria-label="Search" autofocus><button type="submit">Search</button></form>
  <?php endif; ?>
  <div class="pagehead__meta mono"><?= (int)$total ?> published · page <?= (int)$page ?> of <?= (int)$pages ?></div>
</section>
<?php if (!empty($banner)): ?><div class="container adslot adslot--top"><?= \MeNews\Services\Ads::render($banner, 'banner') ?></div><?php endif; ?>
<div class="container layout">
  <div class="layout__main">
    <?php if (!empty($leaderboard)): ?>
      <section class="block reveal" style="margin-bottom:28px">
        <header class="block__head"><span class="block__index mono"><?= icon('trophy') ?></span><h2 class="block__title">Who's reporting</h2><p class="block__blurb">The towns and neighbours keeping their county on the map this month. Reports are screened and read by an editor before they appear.</p><button class="btn btn--hot" type="button" data-open-report><?= icon('report') ?> Report a story</button></header>
        <div class="grid grid--2 leaderboards">
          <div class="panel"><header class="panel__head"><span class="kicker">Busiest counties · 30 days</span></header>
            <?php if ($leaderboard['counties']): $max = max(array_column($leaderboard['counties'], 'n')); ?><ol class="bars"><?php foreach ($leaderboard['counties'] as $c): ?><li><a href="/county/<?= e(slugify($c['county'])) ?>"><span><?= e($c['county']) ?></span><i style="--v:<?= (int)round($c['n'] / max(1, $max) * 100) ?>"></i><b class="mono"><?= (int)$c['n'] ?></b></a></li><?php endforeach; ?></ol><?php else: ?><p class="panel__note" style="margin:0">The first county on the board gets bragging rights. <?= !empty($leaderboard['whatsapp']) ? 'WhatsApp ' . e($leaderboard['whatsapp']) . ' or tap Report.' : 'Tap Report to start.' ?></p><?php endif; ?>
          </div>
          <div class="panel"><header class="panel__head"><span class="kicker">Top reporters</span></header>
            <?php if ($leaderboard['reporters']): ?><ul class="desk"><?php foreach ($leaderboard['reporters'] as $u): ?><li><a href="<?= $u['handle'] ? '/contributors/' . e($u['handle']) : '#' ?>"><?= \MeNews\Ui::avatar($u, 'sm') ?><span><b><?= e($u['display_name']) ?></b><small><?= e($u['home_county'] ? 'Co. ' . $u['home_county'] : '') ?> · <?= (int)$u['reports_published'] ?> published · <?= (int)$u['confirmations'] ?> confirmations</small></span><em class="mono"><?= $u['reports_filed'] ? (int)round($u['reports_published'] / max(1, $u['reports_filed']) * 100) . '%' : '' ?></em></a></li><?php endforeach; ?></ul><?php else: ?><p class="panel__note" style="margin:0">Publish a report and your name goes here with your track record: filed, published and how often neighbours confirmed you.</p><?php endif; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>
    <?php if ($rows): ?>
      <div class="grid grid--3">
        <?php foreach ($rows as $i => $s): ?><?= Ui::card($s, $i === 0 && $page === 1 ? 'feature' : 'standard', $i) ?><?php endforeach; ?>
      </div>
      <?= Ui::pagination($page, $pages, $basePath, isset($q) ? '&q=' . rawurlencode($q) : '') ?>
    <?php else: ?>
      <div class="empty"><div class="empty__glyph"><?= icon('clock') ?></div><h3>Nothing published here yet.</h3><p>The wire refreshes every few minutes. Try another section or county, or search for a town.</p></div>
    <?php endif; ?>
  </div>
  <?= \MeNews\View::partial('partials/sidebar', ['trending' => $trending ?? [], 'ads' => $ads ?? []]) ?>
</div>
