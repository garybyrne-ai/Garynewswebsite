<?php $p = $puzzle; ?>
<div class="container kidspage" data-game="wordsearch" data-puzzle='<?= e(json_encode($p, JSON_UNESCAPED_UNICODE)) ?>'>
  <div class="paper paper--puzzle">
    <?= \MeNews\View::partial('partials/paper-head', ['edition' => $edition, 'longDate' => \MeNews\Support\Daily::longDate($date)]) ?>
    <div class="puzzle__head">
      <div><span class="paper__stamp">Word search</span><h1>Theme: <?= e($p['theme']) ?> <small>· <?= count($p['words']) ?> words</small></h1></div>
      <div class="puzzle__levels"><button class="btn btn--sm btn--ghost" type="button" onclick="window.print()">Print</button></div>
    </div>
    <div class="puzzle__bar"><span class="mono" data-timer>00:00</span><span class="mono" data-progress>0 / <?= count($p['words']) ?> found</span><span class="puzzle__actions"><button class="btn btn--sm btn--ghost" type="button" data-hint>Hint</button><button class="btn btn--sm btn--ghost" type="button" data-clear>Clear</button></span></div>
    <div class="puzzle__done" data-done hidden>🎉 <b>Maith thú!</b> All <?= count($p['words']) ?> words found.</div>
    <div class="ws">
      <div class="ws__grid" data-grid style="--n:<?= (int)$p['size'] ?>" aria-label="Word search grid">
        <?php foreach ($p['grid'] as $r => $row): foreach ($row as $c => $ch): ?><div class="ws-cell" data-r="<?= $r ?>" data-c="<?= $c ?>"><?= e($ch) ?></div><?php endforeach; endforeach; ?>
      </div>
      <ul class="ws__words"><?php foreach ($p['words'] as $w): ?><li data-word="<?= e($w['word']) ?>"><?= e($w['word']) ?></li><?php endforeach; ?></ul>
    </div>
    <p class="paper__note">Words run across, down and diagonally. Press and drag from the first letter to the last.</p>
    <nav class="puzzle__archive mono" aria-label="Archive"><span>Archive:</span><?php foreach ($archive as $d): ?><a href="/kids/wordsearch/<?= e($d) ?>" class="<?= $d === $date ? 'is-active' : '' ?>"><?= e(date('j M', strtotime($d))) ?></a><?php endforeach; ?></nav>
  </div>
</div>
