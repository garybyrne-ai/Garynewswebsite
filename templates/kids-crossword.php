<?php $p = $puzzle; ?>
<div class="container kidspage" data-game="crossword" data-puzzle='<?= e(json_encode($p, JSON_UNESCAPED_UNICODE)) ?>'>
  <div class="paper paper--puzzle">
    <?= \MeNews\View::partial('partials/paper-head', ['edition' => $edition, 'longDate' => \MeNews\Support\Daily::longDate($date)]) ?>
    <div class="puzzle__head">
      <div>
        <span class="paper__stamp">Crossword</span>
        <h1>Crossword No. <?= (int)$p['edition'] ?> <small>· <?= e($p['label']) ?> · <?= (int)$p['words'] ?> clues</small></h1>
      </div>
      <div class="puzzle__levels">
        <?php foreach ($levels as $key => $meta): ?><a class="btn btn--sm <?= $key === $level ? 'btn--dark' : 'btn--ghost' ?>" href="/kids/crossword/<?= e($date) ?>?level=<?= e($key) ?>"><?= e($meta['label']) ?> <?= $meta['size'] ?>×<?= $meta['size'] ?></a><?php endforeach; ?>
        <button class="btn btn--sm btn--ghost" type="button" onclick="window.print()">Print</button>
      </div>
    </div>
    <div class="puzzle__bar" data-toolbar>
      <span class="mono" data-timer>00:00</span>
      <span class="mono" data-progress>0 / <?= (int)$p['words'] ?> solved</span>
      <span class="puzzle__actions">
        <button class="btn btn--sm btn--ghost" type="button" data-check>Check</button>
        <button class="btn btn--sm btn--ghost" type="button" data-reveal-letter>Reveal letter</button>
        <button class="btn btn--sm btn--ghost" type="button" data-reveal-word>Reveal word</button>
        <button class="btn btn--sm btn--ghost" type="button" data-clear>Clear</button>
      </span>
    </div>
    <div class="puzzle__done" data-done hidden>🎉 <b>Maith thú!</b> You finished today's crossword. Come back tomorrow for No. <?= (int)$p['edition'] + 1 ?>.</div>
    <div class="xw">
      <div class="xw__gridwrap"><div class="xw__grid" data-grid style="--cols:<?= (int)$p['cols'] ?>;--rows:<?= (int)$p['rows'] ?>" role="grid" aria-label="Crossword grid">
        <?php for ($r = 0; $r < $p['rows']; $r++): for ($c = 0; $c < $p['cols']; $c++): $ch = $p['cells'][$r][$c]; ?>
          <?php if ($ch === null): ?><div class="xw-cell is-block"></div><?php else: ?><div class="xw-cell" data-r="<?= $r ?>" data-c="<?= $c ?>" tabindex="-1"><?php if (isset($p['numbers']["$r,$c"])): ?><span class="xw-cell__n"><?= (int)$p['numbers']["$r,$c"] ?></span><?php endif; ?><span class="xw-cell__l" data-letter></span></div><?php endif; ?>
        <?php endfor; endfor; ?>
      </div></div>
      <div class="xw__clues">
        <div><h3>Across</h3><ol class="clues"><?php foreach ($p['across'] as $a): ?><li data-clue="across-<?= (int)$a['n'] ?>" data-dir="across" data-r="<?= (int)$a['row'] ?>" data-c="<?= (int)$a['col'] ?>"><b><?= (int)$a['n'] ?></b> <?= e($a['clue']) ?> <span class="mono">(<?= (int)$a['len'] ?>)</span></li><?php endforeach; ?></ol></div>
        <div><h3>Down</h3><ol class="clues"><?php foreach ($p['down'] as $a): ?><li data-clue="down-<?= (int)$a['n'] ?>" data-dir="down" data-r="<?= (int)$a['row'] ?>" data-c="<?= (int)$a['col'] ?>"><b><?= (int)$a['n'] ?></b> <?= e($a['clue']) ?> <span class="mono">(<?= (int)$a['len'] ?>)</span></li><?php endforeach; ?></ol></div>
      </div>
    </div>
    <p class="paper__note">Tap a square and type. Tap again to switch between across and down. Your progress is saved on this device. Tomorrow's puzzle appears at midnight.</p>
    <nav class="puzzle__archive mono" aria-label="Archive"><span>Archive:</span><?php foreach ($archive as $d): ?><a href="/kids/crossword/<?= e($d) ?>?level=<?= e($level) ?>" class="<?= $d === $date ? 'is-active' : '' ?>"><?= e(date('j M', strtotime($d))) ?></a><?php endforeach; ?></nav>
    <div class="paper__hidden-answers" hidden>
      <?php foreach (array_merge($p['across'], $p['down']) as $a): ?><span><?= e($a['answer']) ?></span><?php endforeach; ?>
    </div>
  </div>
</div>
