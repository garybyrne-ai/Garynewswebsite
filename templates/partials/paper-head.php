<?php /** Newsprint masthead for ME Óg pages. Expects $edition, $longDate, optional $kicker */ ?>
<header class="paper__mast">
  <div class="paper__ears mono"><span>Ireland's free daily for curious minds</span><span>Edition No. <?= (int)$edition ?></span><span>Price: FREE</span></div>
  <a class="paper__title" href="/kids"><small>ME Óg presents</small>The Junior Post</a>
  <div class="paper__dateline mono"><span><?= e($longDate) ?></span><span>Printed fresh every morning in Ireland</span><span>Puzzles · Gaeilge · Things to do</span></div>
</header>
