<?php /** Home slider. Expects $signalBoard, $signalStats */ use MeNews\Services\Signal; use MeNews\Ui; ?>
<section class="block block--signal reveal" id="signal">
  <header class="block__head">
    <span class="block__index mono"><?= icon('signal') ?></span>
    <h2 class="block__title"><a href="/signal">The Signal · Ireland's most-voted stories</a></h2>
    <p class="block__blurb">Readers vote on <em>why</em> a story matters. Local votes weigh more, fresh stories rise, everything decays. <a href="/signal#how">How it works →</a></p>
    <div class="signal__stats mono"><?php if ((int)$signalStats['votes_today'] >= 5): ?><span><b><?= (int)$signalStats['votes_today'] ?></b> votes today</span><span><b><?= (int)$signalStats['stories_today'] ?></b> stories</span><?php else: ?><span>Today's board is open — your vote sets it</span><?php endif; ?><a class="block__more" href="/signal">Full leaderboard →</a></div>
  </header>
  <div class="slider" data-slider>
    <button type="button" class="slider__arrow slider__arrow--prev" data-slide="-1" aria-label="Previous">‹</button>
    <div class="slider__track" data-track>
      <?php foreach ($signalBoard as $i => $s): $hue = Ui::hue($s['title']); $top = $s['signal_top'] ? Signal::SIGNALS[$s['signal_top']] : null; ?>
        <article class="sigcard <?= !empty($s['signal_warmup']) ? 'sigcard--warmup' : '' ?>" style="--h:<?= $hue ?>;--c:<?= e($top['colour'] ?? '#139a5c') ?>" data-story="<?= e($s['id']) ?>">
          <a class="sigcard__media" href="<?= e($s['url']) ?>" tabindex="-1" aria-hidden="true">
            <?php if ($s['image']): ?><img src="<?= e($s['image']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()"><?php endif; ?>
            <span class="sigcard__rank mono"><?= !empty($s['signal_warmup']) ? icon('sparkle') : '#' . (int)$s['signal_rank'] ?></span>
            <span class="sigcard__ring" style="--pct:<?= min(100, (int)$s['signal_total'] * 8) ?>" <?= (int)$s['signal_total'] === 0 ? 'hidden' : '' ?>><b><?= (int)$s['signal_total'] ?></b><small>votes</small></span>
          </a>
          <div class="sigcard__body">
            <div class="card__meta"><?= Ui::categoryChip($s['category']) ?><?php if ($top): ?><span class="chip" style="color:var(--c);border-color:color-mix(in srgb,var(--c) 40%,transparent)"><?= e($top['icon']) ?> <?= e($top['label']) ?></span><?php else: ?><span class="chip">Trending · be the first to vote</span><?php endif; ?></div>
            <h3 class="sigcard__title"><a href="<?= e($s['url']) ?>"><?= e($s['title']) ?></a></h3>
            <div class="sigmix" aria-hidden="true"><?php foreach (Signal::SIGNALS as $k => $m): ?><i style="--c:<?= e($m['colour']) ?>;width:<?= (int)$s['signal_mix'][$k] ?>%"></i><?php endforeach; ?></div>
            <div class="sigcard__quick" data-vote data-story="<?= e($s['id']) ?>" data-total="<?= (int)$s['signal_total'] ?>">
              <?php foreach (Signal::SIGNALS as $k => $m): ?><button type="button" class="votebtn votebtn--mini" data-signal="<?= e($k) ?>" style="--c:<?= e($m['colour']) ?>" title="<?= e($m['label']) ?> · <?= e($m['blurb']) ?>" aria-label="<?= e($m['label']) ?>"><span aria-hidden="true"><?= e($m['icon']) ?></span><b data-count="<?= e($k) ?>" class="<?= (int)$s['signal_counts'][$k] === 0 ? 'is-zero' : '' ?>"><?= (int)$s['signal_counts'][$k] ?></b></button><?php endforeach; ?>
            </div>
            <div class="sigcard__foot mono"><span><?= e($s['source_name'] ?: 'Community') ?></span><span><?= e($s['location_name'] ?: ($s['county'] ? 'Co. ' . $s['county'] : 'Ireland')) ?></span><span><?= e(time_ago($s['time'])) ?></span></div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <button type="button" class="slider__arrow slider__arrow--next" data-slide="1" aria-label="Next">›</button>
    <div class="slider__dots" data-dots></div>
  </div>
</section>
