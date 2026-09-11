<?php /** Vote widget. Expects $story, $signal (tally + mine), optional $signalRank, $compact */ use MeNews\Services\Signal;
$compact = $compact ?? false; $counts = $signal['counts']; $total = (int)$signal['total']; $mine = $signal['mine'] ?? null; ?>
<div class="vote <?= $compact ? 'vote--compact' : '' ?>" data-vote data-story="<?= e($story['id']) ?>" data-total="<?= $total ?>">
  <?php if (!$compact): ?>
  <header class="vote__head">
    <div><span class="kicker">The Signal</span><h2 class="vote__title">Why does this story matter?</h2><p class="vote__blurb">One vote per reader. Change it any time. Local readers count for more.</p></div>
    <div class="vote__score"><b data-vote-total><?= $total ?></b><span class="mono"><?= $total === 1 ? 'vote' : 'votes' ?></span><?php if (!empty($signalRank)): ?><span class="vote__rank mono">#<?= (int)$signalRank ?> today</span><?php endif; ?></div>
  </header>
  <?php endif; ?>
  <div class="vote__buttons">
    <?php foreach (Signal::SIGNALS as $k => $m): ?>
      <button type="button" class="votebtn <?= $mine === $k ? 'is-mine' : '' ?>" data-signal="<?= e($k) ?>" style="--c:<?= e($m['colour']) ?>" title="<?= e($m['blurb']) ?>" aria-pressed="<?= $mine === $k ? 'true' : 'false' ?>">
        <span class="votebtn__icon" aria-hidden="true"><?= e($m['icon']) ?></span>
        <span class="votebtn__label"><?= e($m['label']) ?></span>
        <b class="votebtn__n" data-count="<?= e($k) ?>"><?= (int)$counts[$k] ?></b>
      </button>
    <?php endforeach; ?>
  </div>
  <div class="sigmix" data-mix aria-hidden="true"><?php foreach (Signal::SIGNALS as $k => $m): ?><i style="--c:<?= e($m['colour']) ?>;width:<?= $total ? round($counts[$k] / $total * 100) : 0 ?>%" data-mix-bar="<?= e($k) ?>"></i><?php endforeach; ?></div>
  <?php if (!$compact): ?><p class="vote__foot mono" data-vote-foot><?= $mine ? 'You said: ' . e(Signal::SIGNALS[$mine]['label']) . ' · tap again to withdraw' : 'Tap a signal to vote' ?><?= $total ? ' · <a href="/signal">see the leaderboard →</a>' : '' ?></p><?php endif; ?>
</div>
