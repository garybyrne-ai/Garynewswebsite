<?php /** Newsprint masthead for Wire Junior pages. Expects $edition, $longDate, optional $kicker */ ?>
<header class="paper__mast">
  <div class="paper__ears mono"><span>India's free daily for curious minds</span><span>Edition No. <?= (int)$edition ?></span><span>Price: FREE</span></div>
  <a class="paper__title" href="/kids"><small>Wire Junior presents</small>The Junior Post</a>
  <div class="paper__dateline mono"><span><?= e($longDate) ?></span><span>Printed fresh every morning in India</span><span>Puzzles · Hindi · Things to do</span></div>
</header>
