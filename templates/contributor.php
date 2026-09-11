<?php use MeNews\Ui; $c = $contributor; ?>
<section class="container profilehead" style="--h:<?= (int)$c['accent'] ?>">
  <div class="profile__ring"><?= Ui::avatar($c, 'xl') ?></div>
  <div>
    <span class="kicker">Desk editor · curates the <?= e($c['desk'] ?: 'ME News') ?> desk</span>
    <h1 class="pagehead__title"><?= e($c['display_name']) ?></h1>
    <p class="profile__title"><?= e($c['title']) ?></p>
    <p class="pagehead__blurb"><?= e($c['bio']) ?></p>
    <div class="profile__meta mono">
      <?php if ($c['home_town']): ?><span><?= icon('pin') ?> <?= e($c['home_town']) ?>, Co. <?= e($c['home_county']) ?></span><?php endif; ?>
      <span><?= (int)$c['reported'] ?> original <?= $c['reported'] === 1 ? 'report' : 'reports' ?></span>
      <span><?= (int)$c['curated'] ?> wire stories curated</span>
      <span>Reputation <?= (int)$c['reputation'] ?>/100</span>
      <span>Joined <?= e(date_irish($c['created_at'], 'M Y')) ?></span>
    </div>
  </div>
</section>
<div class="container">
  <?php if ($rows): ?>
    <div class="grid grid--3"><?php foreach ($rows as $i => $s): ?><?= Ui::card($s, 'standard', $i) ?><?php endforeach; ?></div>
    <?= Ui::pagination($page, $pages, $basePath) ?>
  <?php else: ?>
    <div class="empty"><div class="empty__glyph"><?= icon('clock') ?></div><h3>Nothing curated or filed yet.</h3></div>
  <?php endif; ?>
</div>
