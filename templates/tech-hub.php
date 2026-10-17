<?php use MeNews\Ui; ?>
<section class="container pagehead pagehead--tech">
  <span class="kicker">Tech Corner</span>
  <h1 class="pagehead__title"><span class="pagehead__icon"><?= icon('cpu') ?></span>Technology</h1>
  <p class="pagehead__blurb">Startups, AI, gadgets, software and the companies building them — from Indian unicorns to the biggest names in the world, gathered from the wire.</p>
</section>
<?php if (!empty($banner)): ?><div class="container adslot adslot--top"><?= \MeNews\Services\Ads::slot($banner, 'banner') ?></div><?php endif; ?>
<div class="container layout">
  <div class="layout__main">
    <?php if ($featured): ?>
      <div class="grid grid--3" style="margin-bottom:28px">
        <?= Ui::card($featured, 'feature', 0) ?>
      </div>
    <?php endif; ?>
    <?php if ($startups): ?>
      <section class="block reveal" style="margin-bottom:28px">
        <header class="block__head"><span class="block__index mono"><?= icon('sparkle') ?></span><h2 class="block__title">Startups to watch</h2><p class="block__blurb">Funding, launches and the founders building India's startup wave.</p></header>
        <div class="grid grid--4"><?php foreach ($startups as $i => $s): ?><?= Ui::card($s, 'compact', $i) ?><?php endforeach; ?></div>
      </section>
    <?php endif; ?>
    <?php if ($latest): ?>
      <section class="block reveal">
        <header class="block__head"><span class="block__index mono"><?= icon('cpu') ?></span><h2 class="block__title"><a href="/section/technology">Latest in tech</a></h2><p class="block__blurb">Everything from the Technology desk, newest first.</p><a class="block__more mono" href="/section/technology">All Technology →</a></header>
        <div class="grid grid--3"><?php foreach ($latest as $i => $s): ?><?= Ui::card($s, 'standard', $i) ?><?php endforeach; ?></div>
      </section>
    <?php else: ?>
      <div class="empty"><div class="empty__glyph"><?= icon('cpu') ?></div><h3>Nothing published here yet.</h3><p>The wire refreshes every few minutes. Check back shortly.</p></div>
    <?php endif; ?>
  </div>
  <?= \MeNews\View::partial('partials/sidebar', ['trending' => $trending ?? [], 'ads' => $ads ?? [], 'county' => null]) ?>
</div>
