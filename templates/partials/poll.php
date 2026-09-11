<?php /** Weekly county poll. Expects $poll (Polls::present), optional $county, $compact */
$compact = $compact ?? false;
$res = $poll['results'];
$mine = $poll['mine'];
$showResults = $mine !== null || $poll['closed'];
$countyRes = $poll['county_results'] ?? null;
?>
<section class="panel panel--poll reveal" data-poll="<?= e($poll['id']) ?>">
  <header class="panel__head"><span class="kicker">Weekly county poll</span><a class="mono panel__hint" href="/poll">By county →</a></header>
  <h3 class="poll__q"><?= e($poll['question']) ?></h3>
  <div class="poll__opts" data-poll-opts>
    <?php foreach ($poll['options'] as $i => $opt): $pct = $res['pct'][$i] ?? 0; ?>
      <button type="button" class="pollopt <?= $mine === $i ? 'is-mine' : '' ?> <?= $showResults ? 'is-result' : '' ?>" data-option="<?= $i ?>" <?= $poll['closed'] ? 'disabled' : '' ?>>
        <i style="--v:<?= $showResults ? $pct : 0 ?>"></i><span><?= e($opt) ?></span><b class="mono" data-pct><?= $showResults ? $pct . '%' : '' ?></b>
      </button>
    <?php endforeach; ?>
  </div>
  <p class="panel__note mono" data-poll-foot>
    <?php if ($poll['closed']): ?>Closed · <?= (int)$res['total'] ?> votes<?php elseif ($mine !== null): ?>Thanks · <?= $res['total'] >= 10 ? (int)$res['total'] . ' votes so far' : 'tap another option to change' ?><?= $countyRes && $countyRes['total'] >= 5 ? ' · ' . e($county) . ' leans ' . e($poll['options'][array_search(max($countyRes['counts']), $countyRes['counts'], true)] ?? '') : '' ?><?php else: ?>Closes <?= e(date_irish($poll['closes_at'], 'D H:i')) ?> · anonymous<?php endif; ?>
  </p>
</section>
