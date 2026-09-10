<div class="container kidspage" data-game="quiz" data-puzzle='<?= e(json_encode($quiz, JSON_UNESCAPED_UNICODE)) ?>'>
  <div class="paper paper--puzzle">
    <?= \MeNews\View::partial('partials/paper-head', ['edition' => $edition, 'longDate' => \MeNews\Support\Daily::longDate($date)]) ?>
    <div class="puzzle__head"><div><span class="paper__stamp">Quiz</span><h1>Know Your Ireland <small>· five questions</small></h1></div></div>
    <form class="quiz" data-quiz>
      <?php foreach ($quiz['questions'] as $q): ?>
        <fieldset class="quiz__q" data-q="<?= (int)$q['n'] - 1 ?>">
          <legend><b><?= (int)$q['n'] ?>.</b> <?= e($q['q']) ?></legend>
          <?php foreach ($q['options'] as $i => $o): ?><label class="quiz__opt"><input type="radio" name="q<?= (int)$q['n'] ?>" value="<?= $i ?>"><span><?= e($o) ?></span></label><?php endforeach; ?>
          <p class="quiz__fact" hidden><?= e($q['fact']) ?></p>
        </fieldset>
      <?php endforeach; ?>
      <div class="puzzle__bar"><span class="mono" data-score></span><span class="puzzle__actions"><button class="btn btn--dark" type="submit">Check my answers</button><button class="btn btn--ghost btn--sm" type="button" data-reset hidden>Try again</button></span></div>
    </form>
    <div class="puzzle__done" data-done hidden></div>
    <nav class="puzzle__archive mono" aria-label="Archive"><span>Archive:</span><?php foreach ($archive as $d): ?><a href="/kids/quiz/<?= e($d) ?>" class="<?= $d === $date ? 'is-active' : '' ?>"><?= e(date('j M', strtotime($d))) ?></a><?php endforeach; ?></nav>
  </div>
</div>
