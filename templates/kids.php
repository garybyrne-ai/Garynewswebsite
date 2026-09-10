<?php use MeNews\Ui; $q = $quiz['questions'][0] ?? null; ?>
<div class="container kidspage">
  <div class="paper">
    <?= \MeNews\View::partial('partials/paper-head', ['edition' => $edition, 'longDate' => $longDate]) ?>
    <div class="paper__lead">
      <h1><span class="dropcap">W</span>elcome to the puzzle corner.</h1>
      <p>Every morning The Junior Post prints a brand-new crossword, a word search, five quiz questions and a new Irish word. Nothing to buy, nothing to download — grab a pencil or play on screen. Grown-ups get a bigger crossword too.</p>
    </div>

    <div class="paper__cols paper__cols--3">
      <a class="paper__item paper__item--big" href="/kids/crossword">
        <span class="paper__stamp">Daily</span>
        <h2>Crossword No. <?= (int)$crossword['edition'] ?></h2>
        <div class="xw-mini xw-mini--lg" aria-hidden="true"><?php for ($r = 0; $r < min(7, $crossword['rows']); $r++): for ($c = 0; $c < min(9, $crossword['cols']); $c++): ?><i class="<?= $crossword['cells'][$r][$c] === null ? 'is-block' : '' ?>"></i><?php endfor; endfor; ?></div>
        <p><?= (int)$crossword['words'] ?> Irish-flavoured clues in the junior grid. Switch to the grown-up grid for a proper challenge. Print it, or solve it on screen with check and reveal.</p>
        <span class="btn btn--dark">Solve today's crossword →</span>
      </a>
      <a class="paper__item paper__item--big" href="/kids/wordsearch">
        <span class="paper__stamp">Daily</span>
        <h2>Word search: <?= e($wordsearch['theme']) ?></h2>
        <div class="ws-mini" aria-hidden="true"><?php foreach (array_slice($wordsearch['grid'], 0, 5) as $row): ?><?php foreach (array_slice($row, 0, 8) as $ch): ?><i><?= e($ch) ?></i><?php endforeach; ?><?php endforeach; ?></div>
        <p>Ten hidden words on the theme of <b><?= e($wordsearch['theme']) ?></b>. Drag across the letters to find them.</p>
        <span class="btn btn--dark">Find the words →</span>
      </a>
      <a class="paper__item paper__item--big" href="/kids/quiz">
        <span class="paper__stamp">Quiz</span>
        <h2>Know Your Ireland</h2>
        <?php if ($q): ?><p><b>Q1.</b> <?= e($q['q']) ?></p><ul class="paper__opts"><?php foreach ($q['options'] as $o): ?><li><?= e($o) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <p>Five questions a day about our island. Every answer comes with a fact worth remembering.</p>
        <span class="btn btn--dark">Take the quiz →</span>
      </a>
    </div>

    <div class="paper__rule"></div>

    <div class="paper__cols paper__cols--3">
      <div class="paper__item">
        <span class="paper__stamp">Gaeilge</span>
        <h2>Focal an lae</h2>
        <p class="paper__focal paper__focal--lg"><b><?= e($focal['irish']) ?></b><span><?= e($focal['english']) ?></span><small>say: <?= e($focal['say']) ?></small></p>
        <p><em><?= e($focal['example']) ?></em></p>
        <p>A new word every day. Learn one, use it at the dinner table tonight.</p>
      </div>
      <a class="paper__item" href="/kids/county-game">
        <span class="paper__stamp">Map game</span>
        <h2>Find the County</h2>
        <p>Ten counties, one map, no labels. Tap where you think Co. Leitrim is — then find out how close you were. Can you get all ten?</p>
        <span class="btn btn--dark">Play →</span>
      </a>
      <div class="paper__item">
        <span class="paper__stamp">Junior reporter</span>
        <h2>Seen something happening?</h2>
        <p>Kids make brilliant reporters. With a parent or guardian's account, tell the newsroom what you saw near you — a fun day, a new playground, a local hero. Editors read every report.</p>
        <button class="btn btn--dark" type="button" data-open-report>Report a story →</button>
      </div>
    </div>

    <?php if ($stories): ?>
      <div class="paper__rule"></div>
      <h2 class="paper__section" id="young">Bright side: stories for young readers</h2>
      <p class="paper__note">Picked from today's wire — sport, culture and things to do, with the grim stuff left out. Grown-ups: these link to the original publishers.</p>
      <div class="paper__stories">
        <?php foreach ($stories as $s): ?>
          <a class="paper__story" href="<?= e($s['url']) ?>"><span class="mono"><?= e($s['category']) ?> · <?= e($s['source_name']) ?></span><b><?= e($s['title']) ?></b><small><?= e(excerpt($s['summary'], 110)) ?></small></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="paper__rule"></div>
    <h2 class="paper__section" id="free">Free things to do across Ireland</h2>
    <p class="paper__note">All genuinely free. Check opening times before you travel.</p>
    <div class="paper__cols paper__cols--3 paper__cols--tight">
      <?php foreach ($stuff as $item): ?>
        <a class="paper__item paper__item--free" href="<?= e($item['url']) ?>" target="_blank" rel="noopener">
          <span class="paper__stamp"><?= e($item['tag']) ?></span>
          <h3><?= e($item['name']) ?></h3>
          <p><?= e($item['blurb']) ?></p>
          <span class="mono"><?= e($item['where']) ?> ↗</span>
        </a>
      <?php endforeach; ?>
    </div>
    <footer class="paper__foot mono"><span>The Junior Post is part of ME News Ireland</span><span>Puzzles reset at midnight, Irish time</span><span>Yesterday's puzzles are in the archive on each puzzle page</span></footer>
  </div>
</div>
