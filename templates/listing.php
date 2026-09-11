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
