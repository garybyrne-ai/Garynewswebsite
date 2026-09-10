<?php /** Home page kids block. Expects $crossword, $quiz, $focal, $edition, $longDate, $youngReaders */ $q = $quiz['questions'][0] ?? null; ?>
<section class="block reveal" id="kids">
  <header class="block__head">
    <span class="block__index mono">✦</span>
    <h2 class="block__title"><a href="/kids">ME Óg · The Junior Post</a></h2>
    <p class="block__blurb">Old-school newspaper fun, printed fresh every day: a crossword, a word search, a quiz, the Irish word of the day and free things to do.</p>
    <a class="block__more mono" href="/kids">Open today's edition →</a>
  </header>
  <div class="paper paper--corner">
    <?= \MeNews\View::partial('partials/paper-head', ['edition' => $edition, 'longDate' => $longDate]) ?>
    <div class="paper__cols paper__cols--4">
      <a class="paper__item" href="/kids/crossword">
        <span class="paper__stamp">Daily</span>
        <h3>Crossword No. <?= (int)$crossword['edition'] ?></h3>
        <div class="xw-mini" aria-hidden="true"><?php for ($r = 0; $r < min(5, $crossword['rows']); $r++): for ($c = 0; $c < min(7, $crossword['cols']); $c++): ?><i class="<?= $crossword['cells'][$r][$c] === null ? 'is-block' : '' ?>"></i><?php endfor; endfor; ?></div>
        <p><?= (int)$crossword['words'] ?> clues in the junior grid, a bigger one for grown-ups. <em>1 Across: <?= e($crossword['across'][0]['clue'] ?? '') ?></em></p>
      </a>
      <div class="paper__item">
        <span class="paper__stamp">Gaeilge</span>
        <h3>Focal an lae</h3>
        <p class="paper__focal"><b><?= e($focal['irish']) ?></b><span><?= e($focal['english']) ?></span><small>say: <?= e($focal['say']) ?></small></p>
        <p><em><?= e($focal['example']) ?></em></p>
      </div>
      <a class="paper__item" href="/kids/quiz">
        <span class="paper__stamp">Quiz</span>
        <h3>Know Your Ireland</h3>
        <?php if ($q): ?><p><b>Q1.</b> <?= e($q['q']) ?></p><ul class="paper__opts"><?php foreach ($q['options'] as $o): ?><li><?= e($o) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <p><em>Four more questions inside →</em></p>
      </a>
      <div class="paper__item">
        <span class="paper__stamp">More</span>
        <h3>Also inside</h3>
        <ul class="paper__list">
          <li><a href="/kids/wordsearch">Word search of the day</a></li>
          <li><a href="/kids/county-game">Find the County map game</a></li>
          <li><a href="/kids#free">12 free things to do across Ireland</a></li>
          <li><a href="/kids#young">Bright stories for young readers</a></li>
        </ul>
      </div>
    </div>
  </div>
</section>
