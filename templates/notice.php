<?php $n = $notice; $x = $n['extra']; ?>
<?php if ($jsonld): ?><script type="application/ld+json"><?= json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script><?php endif; ?>
<article class="container article article--notice">
  <header class="article__head">
    <nav class="crumbs mono" aria-label="Breadcrumb"><a href="/notices">Notices</a><span>/</span><a href="/notices/<?= e($n['kind']) ?>"><?= e(\MeNews\Services\Notices::KINDS[$n['kind']]['plural']) ?></a><span>/</span><a href="/notices/<?= e($n['kind']) ?>?county=<?= rawurlencode($n['county']) ?>">Co. <?= e($n['county']) ?></a></nav>
    <div class="article__labels"><span class="chip chip--cat"><?= icon($n['icon']) ?> <?= e($n['kind_label']) ?></span><?php if ($n['promoted']): ?><span class="chip chip--plus">Promoted</span><?php endif; ?><?php if ($n['kind'] === 'pet' && !empty($x['pet_status'])): ?><span class="chip"><?= e(ucfirst($x['pet_status'])) ?></span><?php endif; ?></div>
    <h1 class="article__title"><?= e($n['title']) ?></h1>
    <p class="article__standfirst"><?= icon('pin') ?> <?= e(($n['town'] ? $n['town'] . ', ' : '') . 'Co. ' . $n['county']) ?><?= $n['address'] ? ' · ' . e($n['address']) : '' ?></p>
  </header>
  <div class="article__grid">
    <div class="article__main">
      <?php if ($n['kind'] === 'death'): ?>
        <dl class="factlist">
          <?php if ($n['date_of_death']): ?><div><dt>Died</dt><dd><?= e(date('l j F Y', strtotime($n['date_of_death']) ?: time())) ?></dd></div><?php endif; ?>
          <?php if ($n['reposing']): ?><div><dt>Reposing</dt><dd><?= nl2br(e($n['reposing'])) ?></dd></div><?php endif; ?>
          <?php if ($n['funeral_at']): ?><div><dt>Funeral</dt><dd><?= e(date_irish($n['funeral_at'], 'l j F Y, H:i')) ?><?= $n['funeral_venue'] ? '<br>' . e($n['funeral_venue']) : '' ?></dd></div><?php endif; ?>
          <?php if ($n['burial']): ?><div><dt>Burial / cremation</dt><dd><?= e($n['burial']) ?></dd></div><?php endif; ?>
        </dl>
      <?php elseif ($n['kind'] === 'event'): ?>
        <dl class="factlist">
          <div><dt>When</dt><dd><?= e(date_irish($n['event_at'], 'l j F Y, H:i')) ?><?= $n['event_end'] ? ' – ' . e(date_irish($n['event_end'], 'H:i')) : '' ?></dd></div>
          <?php if ($n['venue']): ?><div><dt>Where</dt><dd><?= e($n['venue']) ?></dd></div><?php endif; ?>
          <?php if ($n['price']): ?><div><dt>Tickets</dt><dd><?= e($n['price']) ?></dd></div><?php endif; ?>
        </dl>
      <?php elseif ($n['kind'] === 'result' && !empty($x['home'])): ?>
        <div class="scoreboard"><span><?= e($x['home']) ?></span><b><?= e($x['home_score'] ?? '') ?> – <?= e($x['away_score'] ?? '') ?></b><span><?= e($x['away']) ?></span></div>
        <?php if (!empty($x['competition'])): ?><p class="mono" style="color:var(--muted)"><?= e($x['competition']) ?></p><?php endif; ?>
      <?php elseif ($n['kind'] === 'job'): ?>
        <dl class="factlist"><?php if ($n['contact_org']): ?><div><dt>Employer</dt><dd><?= e($n['contact_org']) ?></dd></div><?php endif; ?><?php if ($n['price']): ?><div><dt>Pay</dt><dd><?= e($n['price']) ?></dd></div><?php endif; ?><?php if ($n['expires_at']): ?><div><dt>Listed until</dt><dd><?= e(date_irish($n['expires_at'], 'j F')) ?></dd></div><?php endif; ?></dl>
      <?php endif; ?>
      <?php if ($n['body']): ?><div class="article__body"><?= nl2br(e($n['body'])) ?></div><?php endif; ?>
      <?php if ($n['family_message']): ?><blockquote class="familymsg"><?= icon('quote') ?><?= nl2br(e($n['family_message'])) ?></blockquote><?php endif; ?>
      <?php if (!empty($x['club_notes'])): ?><div class="article__body"><h3>Club notes</h3><?= nl2br(e($x['club_notes'])) ?></div><?php endif; ?>
      <?php if ($n['url']): ?><p><a class="btn btn--primary" href="<?= e($n['url']) ?>" target="_blank" rel="noopener nofollow"><?= e($n['kind'] === 'job' ? 'Apply' : ($n['kind'] === 'event' ? 'Tickets & details' : 'More information')) ?> <?= icon('external') ?></a></p><?php endif; ?>
      <div class="trust__actions"><button class="btn btn--ghost" type="button" data-share data-title="<?= e($n['title']) ?>"><?= icon('share') ?> Share</button><a class="btn btn--ghost" href="/notices/submit?kind=<?= e($n['kind']) ?>">Place a similar notice</a></div>
      <p class="form__legal">Placed by <?= e($n['contact_org'] ?: ($n['contact_name'] ?: 'a member of the community')) ?> · published <?= e(date_irish($n['published_at'])) ?>. Something wrong? <a href="/moderation#takedown">Ask for a correction or removal</a>.</p>
    </div>
    <aside class="side side--article">
      <?php if ($related): ?>
        <section class="panel reveal">
          <header class="panel__head"><span class="kicker">More <?= e(mb_strtolower(\MeNews\Services\Notices::KINDS[$n['kind']]['plural'])) ?> · Co. <?= e($n['county']) ?></span></header>
          <ul class="noticelist"><?php foreach ($related as $r): ?><li><a href="<?= e($r['url']) ?>"><b><?= e($r['title']) ?></b><small><?= e($r['town'] ?: 'Co. ' . $r['county']) ?><?= $r['funeral_at'] ? ' · ' . e(date_irish($r['funeral_at'], 'D H:i')) : ($r['event_at'] ? ' · ' . e(date_irish($r['event_at'], 'D j M')) : '') ?></small></a></li><?php endforeach; ?></ul>
        </section>
      <?php endif; ?>
      <section class="panel panel--alerts reveal">
        <header class="panel__head"><span class="kicker"><?= icon('bell') ?> Alerts for Co. <?= e($n['county']) ?></span></header>
        <form class="form" data-subscribe>
          <input type="hidden" name="kinds[]" value="deaths"><input type="hidden" name="kinds[]" value="daily"><input type="hidden" name="county" value="<?= e($n['county']) ?>">
          <label>Email<input name="email" type="email" required value="<?= e($user['email'] ?? '') ?>"></label>
          <button class="btn btn--primary btn--block" type="submit">Death notices &amp; the 7am email</button>
          <p class="form__result" data-subscribe-result></p>
        </form>
      </section>
    </aside>
  </div>
</article>
