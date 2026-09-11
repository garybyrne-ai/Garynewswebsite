<?php /** Sidebar "Today's edition" panel. Expects $weather (nullable), $edition, $longDate, $focal, $greeting */ ?>
<section class="panel panel--edition reveal">
  <header class="panel__head"><span class="kicker">Today's edition</span><span class="mono panel__hint">No. <?= (int)$edition ?></span></header>
  <div class="edition__date"><?= e($greeting) ?>. <b><?= e($longDate) ?></b></div>
  <?php if ($weather): ?>
    <div class="edition__sun mono"><span><?= icon('sun') ?> Sunrise <?= e($weather['sunrise']) ?></span><span><?= icon('moon') ?> Sunset <?= e($weather['sunset']) ?></span></div>
    <ul class="wx">
      <?php foreach ($weather['cities'] as $c): ?>
        <li title="<?= e($c['label']) ?>, wind <?= (int)$c['wind'] ?> km/h"><span class="wx__icon" aria-hidden="true"><?= e($c['icon']) ?></span><span class="wx__name"><?= e($c['name']) ?></span><b class="wx__temp"><?= (int)$c['temp'] ?>°</b><span class="wx__range mono"><?= (int)$c['min'] ?>–<?= (int)$c['max'] ?>°</span></li>
      <?php endforeach; ?>
    </ul>
    <p class="panel__note mono">Weather via Open-Meteo · updated <?= e(time_ago($weather['updated'])) ?></p>
  <?php endif; ?>
  <div class="edition__focal"><span class="mono">Focal an lae</span><b><?= e($focal['irish']) ?></b><span><?= e($focal['english']) ?> · <em><?= e($focal['say']) ?></em></span></div>
  <a class="btn btn--ghost btn--block" href="/kids/crossword">Today's crossword →</a>
</section>
