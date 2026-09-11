<?php use MeNews\Services\Signal; use MeNews\Ui; $S = Signal::SIGNALS; $top3 = array_slice($board, 0, 3); $rest = array_slice($board, 3); $st = $stats; ?>
<section class="container pagehead">
  <span class="kicker">The Signal</span>
  <h1 class="pagehead__title"><span class="pagehead__icon"><?= icon('signal') ?></span>What Ireland <em>voted for.</em></h1>
  <p class="pagehead__blurb">Not likes. Not clicks. Readers say why a story matters — and the Signal ranks the answer with an algorithm you can read, below.</p>
  <div class="signal__stats mono"><span><b><?= (int)$st['votes_today'] ?></b> votes today</span><span><b><?= (int)$st['voters_today'] ?></b> readers</span><span><b><?= (int)$st['stories_today'] ?></b> stories</span><span><b><?= (int)$st['votes_all'] ?></b> all time</span></div>
</section>
<div class="container">
  <div class="signal__bar">
    <nav class="mapchips" aria-label="Window">
      <?php foreach (['today' => 'Today', 'week' => 'This week', 'rising' => 'Rising now'] as $w => $label): ?><a class="<?= $window === $w ? 'is-active' : '' ?>" href="/signal?window=<?= e($w) ?><?= $county ? '&county=' . rawurlencode($county) : '' ?>"><?= e($label) ?></a><?php endforeach; ?>
    </nav>
    <form class="signal__county" method="get" action="/signal"><input type="hidden" name="window" value="<?= e($window) ?>"><select name="county" onchange="this.form.submit()"><option value="">All Ireland</option><?php foreach ($counties as $c): ?><option <?= $county === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select><?php if ($myCounty && $county !== $myCounty): ?><a class="mono" href="/signal?window=<?= e($window) ?>&county=<?= rawurlencode($myCounty) ?>">Near me: Co. <?= e($myCounty) ?> →</a><?php endif; ?></form>
    <div class="signal__legend mono"><?php foreach ($S as $k => $m): ?><span style="--c:<?= e($m['colour']) ?>"><i></i><?= e($m['icon']) ?> <?= e($m['label']) ?><?= isset($st['signals_today'][$k]) ? ' · ' . (int)$st['signals_today'][$k] : '' ?></span><?php endforeach; ?></div>
  </div>

  <?php if ($top3): ?>
    <div class="podium">
      <?php foreach ($top3 as $i => $s): $top = $s['signal_top'] ? $S[$s['signal_top']] : null; ?>
        <article class="podium__card podium__card--<?= $i + 1 ?> reveal" style="--i:<?= $i ?>;--c:<?= e($top['colour'] ?? '#139a5c') ?>;--h:<?= Ui::hue($s['title']) ?>">
          <a class="podium__media" href="<?= e($s['url']) ?>"><?php if ($s['image']): ?><img src="<?= e($s['image']) ?>" alt="" referrerpolicy="no-referrer" onerror="this.remove()"><?php endif; ?><span class="podium__rank mono">#<?= (int)$s['signal_rank'] ?></span></a>
          <div class="podium__body">
            <div class="card__meta"><?= Ui::categoryChip($s['category']) ?><?php if ($top): ?><span class="chip" style="color:var(--c)"><?= e($top['icon']) ?> <?= e($top['label']) ?></span><?php endif; ?><span class="mono" style="margin-left:auto;color:var(--muted)">score <?= e(number_format((float)$s['signal_score'], 1)) ?></span></div>
            <h2><a href="<?= e($s['url']) ?>"><?= e($s['title']) ?></a></h2>
            <?php if ($s['summary']): ?><p><?= e(excerpt($s['summary'], 140)) ?></p><?php endif; ?>
            <?= \MeNews\View::partial('partials/vote', ['story' => $s, 'signal' => ['counts' => $s['signal_counts'], 'total' => $s['signal_total'], 'mine' => $s['mine'] ?? null], 'compact' => true]) ?>
            <div class="sigcard__foot mono"><span><?= (int)$s['signal_votes_window'] ?> votes in window</span><span><?= (int)$s['signal_recent'] ?> in last 3 h</span><span><?= (int)$s['signal_counties'] ?> count<?= $s['signal_counties'] === 1 ? 'y' : 'ies' ?></span><span><?= e(time_ago($s['time'])) ?></span></div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($rest): ?>
    <ol class="sigboard">
      <?php foreach ($rest as $s): $top = $s['signal_top'] ? $S[$s['signal_top']] : null; ?>
        <li class="sigrow reveal" style="--c:<?= e($top['colour'] ?? '#139a5c') ?>">
          <span class="sigrow__rank mono">#<?= (int)$s['signal_rank'] ?></span>
          <div class="sigrow__body">
            <div class="card__meta"><?= Ui::categoryChip($s['category']) ?><?php if ($top): ?><span class="chip" style="color:var(--c)"><?= e($top['icon']) ?> <?= e($top['label']) ?></span><?php endif; ?><span class="mono" style="color:var(--muted)"><?= e($s['location_name'] ?: 'Ireland') ?> · <?= e(time_ago($s['time'])) ?></span></div>
            <h3><a href="<?= e($s['url']) ?>"><?= e($s['title']) ?></a></h3>
            <div class="sigmix" aria-hidden="true"><?php foreach ($S as $k => $m): ?><i style="--c:<?= e($m['colour']) ?>;width:<?= (int)$s['signal_mix'][$k] ?>%"></i><?php endforeach; ?></div>
          </div>
          <div class="sigrow__side">
            <b class="sigrow__score"><?= e(number_format((float)$s['signal_score'], 1)) ?></b><span class="mono"><?= (int)$s['signal_total'] ?> votes</span>
            <div class="sigcard__quick" data-vote data-story="<?= e($s['id']) ?>" data-total="<?= (int)$s['signal_total'] ?>"><?php foreach ($S as $k => $m): ?><button type="button" class="votebtn votebtn--mini <?= ($s['mine'] ?? null) === $k ? 'is-mine' : '' ?>" data-signal="<?= e($k) ?>" style="--c:<?= e($m['colour']) ?>" title="<?= e($m['label']) ?>" aria-label="<?= e($m['label']) ?>"><span aria-hidden="true"><?= e($m['icon']) ?></span><b data-count="<?= e($k) ?>"><?= (int)$s['signal_counts'][$k] ?></b></button><?php endforeach; ?></div>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>

  <?php if (!$board): ?>
    <div class="empty"><div class="empty__glyph"><?= icon('signal') ?></div><h3>No votes <?= $window === 'today' ? 'yet today' : 'in this window' ?><?= $county ? ' from Co. ' . e($county) : '' ?>.</h3><p>Be the first: open any story and tap a signal, or vote straight from the cards below.</p></div>
  <?php endif; ?>

  <?php if ($warmup): ?>
    <section class="block reveal" style="margin-top:36px">
      <header class="block__head"><span class="block__index mono"><?= icon('sparkle') ?></span><h2 class="block__title">Warming up · vote on these</h2><p class="block__blurb">The most-read stories right now. Your vote puts them on the board.</p></header>
      <div class="grid grid--4">
        <?php foreach ($warmup as $i => $s): ?>
          <article class="card card--compact" style="--i:<?= $i ?>">
            <div class="card__body">
              <div class="card__meta"><?= Ui::categoryChip($s['category']) ?><time><?= e(time_ago($s['time'])) ?></time></div>
              <h3 class="card__title"><a href="<?= e($s['url']) ?>"><?= e($s['title']) ?></a></h3>
              <div class="sigcard__quick" data-vote data-story="<?= e($s['id']) ?>" data-total="<?= (int)$s['signal_total'] ?>"><?php foreach ($S as $k => $m): ?><button type="button" class="votebtn votebtn--mini" data-signal="<?= e($k) ?>" style="--c:<?= e($m['colour']) ?>" title="<?= e($m['label']) ?>" aria-label="<?= e($m['label']) ?>"><span aria-hidden="true"><?= e($m['icon']) ?></span><b data-count="<?= e($k) ?>"><?= (int)$s['signal_counts'][$k] ?></b></button><?php endforeach; ?></div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <section class="how" id="how">
    <div class="how__grid">
      <div>
        <span class="kicker">How the Signal is calculated</span>
        <h2>An algorithm you can read.</h2>
        <p>Every vote carries a weight. Then each story's weights are summed, boosted by momentum and independent confirmations, and divided by its age so nothing sits on top forever.</p>
        <pre class="how__formula mono">weight  = signal × voter × local
signal  = ⚡ 1.4 · 🔎 1.2 · 💚 1.1 · 🔥 1.0
voter   = signed-in 1.25 · anonymous 1.0
local   = 1.5 when your county matches the story's

Signal  = ( Σ weights + 2×confirmations + 0.5×comments + 2×votes in last 3 h )
          ÷ ( hours since publication + 4 ) ^ 1.2</pre>
      </div>
      <ul class="how__rules">
        <li><b>One vote per reader per story.</b> Signed in, your vote follows you across devices; otherwise it's tied to this browser. Tap the same signal again to withdraw it.</li>
        <li><b>Local voices weigh more.</b> Turn on <a href="/near">Near me</a> and votes on stories from your county count ×1.5. Nobody knows Bray like Bray.</li>
        <li><b>Momentum matters, briefly.</b> Votes in the last three hours count double — that's what powers <a href="/signal?window=rising">Rising now</a>.</li>
        <li><b>Gravity is real.</b> A day-old story needs roughly five times the support of a fresh one to hold its place.</li>
        <li><b>No dark patterns.</b> Votes are rate-limited, never bought, and advertising can't touch the board.</li>
      </ul>
    </div>
    <?php if ($st['counties']): ?><div class="how__counties mono"><span>Voting today:</span><?php foreach ($st['counties'] as $c): ?><a href="/signal?window=<?= e($window) ?>&county=<?= rawurlencode($c['county']) ?>">Co. <?= e($c['county']) ?> <b><?= (int)$c['n'] ?></b></a><?php endforeach; ?></div><?php endif; ?>
  </section>
</div>
