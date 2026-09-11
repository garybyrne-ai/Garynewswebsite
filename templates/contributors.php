<?php use MeNews\Ui; ?>
<section class="container pagehead">
  <span class="kicker">The desk</span>
  <h1 class="pagehead__title">Five desks. <em>One island.</em></h1>
  <p class="pagehead__blurb">Desk editors curate the wire (they choose and file headlines from established publishers; they do not write them) and check community reports before publication. Real bylines on ME are reserved for original work: community reports our desk has verified, council coverage and county roundups.</p>
</section>
<div class="container">
  <div class="profiles">
    <?php foreach ($contributors as $i => $c): ?>
      <article class="profile reveal" style="--h:<?= (int)$c['accent'] ?>;--i:<?= $i ?>">
        <div class="profile__ring"><?= Ui::avatar($c, 'xl') ?></div>
        <div class="profile__body">
          <span class="kicker">Curates the <?= e($c['desk']) ?> desk</span>
          <h2><a href="/contributors/<?= e($c['handle']) ?>"><?= e($c['display_name']) ?></a></h2>
          <p class="profile__title"><?= e($c['title']) ?></p>
          <p><?= e($c['bio']) ?></p>
          <div class="profile__meta mono">
            <span><?= icon('pin') ?> <?= e($c['home_town']) ?>, Co. <?= e($c['home_county']) ?></span>
            <span><?= (int)$c['reported'] ?> original <?= $c['reported'] === 1 ? 'report' : 'reports' ?></span>
            <span><?= (int)$c['curated'] ?> wire stories curated</span>
            <span>Reputation <?= (int)$c['reputation'] ?>/100</span>
            <?php if ($c['latest']): ?><span>Last filed <?= e(time_ago($c['latest'])) ?></span><?php endif; ?>
          </div>
          <a class="btn btn--ghost" href="/contributors/<?= e($c['handle']) ?>"><?= e(explode(' ', $c['display_name'])[0]) ?>'s desk →</a>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</div>
