<?php use MeNews\Services\Notices; $kinds = Notices::KINDS; ?>
<section class="container pagehead">
  <span class="kicker"><?= $county ? 'Co. ' . e($county) : 'Ireland' ?> · <?= $meta ? e($meta['plural']) : 'Notices' ?></span>
  <h1 class="pagehead__title"><span class="pagehead__icon"><?= icon($meta['icon'] ?? 'candle') ?></span><?= $meta ? e($meta['plural']) : 'Deaths &amp; notices' ?><?= $county ? ' <em>· ' . e($county) . '</em>' : '' ?></h1>
  <p class="pagehead__blurb"><?= $meta ? e($meta['blurb']) : 'Funeral arrangements, in memoriam, what’s on, local jobs, planning notices, lost pets and club results. Free for families and clubs; alerts by email for your county.' ?></p>
  <form class="noticefilter" method="get" action="<?= e($kind ? '/notices/' . $kind : '/notices') ?>">
    <label class="sr-only" for="nf-county">County</label>
    <select id="nf-county" name="county"><option value="">All counties</option><?php foreach ($counties as $c): ?><option <?= $c === $county ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select>
    <label class="sr-only" for="nf-q">Search</label>
    <input id="nf-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Search a name, town or club…">
    <button class="btn btn--primary btn--sm" type="submit">Filter</button>
    <a class="btn btn--hot btn--sm" href="/notices/submit<?= $kind ? '?kind=' . e($kind) : '' ?>"><?= icon('plus') ?> Place a notice</a>
  </form>
</section>

<div class="container">
  <nav class="kindtabs" aria-label="Notice types">
    <a href="/notices<?= $county ? '?county=' . rawurlencode($county) : '' ?>" class="<?= $kind === '' ? 'is-active' : '' ?>">All</a>
    <?php foreach ($kinds as $k => $m): ?><a href="/notices/<?= e($k) ?><?= $county ? '?county=' . rawurlencode($county) : '' ?>" class="<?= $kind === $k ? 'is-active' : '' ?>"><?= icon($m['icon']) ?> <?= e($m['plural']) ?><?php if (!empty($counts[$k])): ?><b><?= (int)$counts[$k] ?></b><?php endif; ?></a><?php endforeach; ?>
  </nav>
</div>

<div class="container layout">
  <div class="layout__main">
    <?php if ($rows): ?>
      <div class="noticegrid">
        <?php foreach ($rows as $n): ?>
          <?= \MeNews\View::partial('partials/notice-card', ['n' => $n]) ?>
        <?php endforeach; ?>
      </div>
      <?= \MeNews\Ui::pagination($page, $pages, $kind ? '/notices/' . $kind : '/notices', ($county ? '&county=' . rawurlencode($county) : '') . ($q ? '&q=' . rawurlencode($q) : '')) ?>
    <?php else: ?>
      <div class="reportcta">
        <div class="reportcta__text">
          <h3>No <?= $meta ? mb_strtolower(e($meta['plural'])) : 'notices' ?> <?= $county ? 'for Co. ' . e($county) : '' ?> yet.</h3>
          <p><?= $kind === 'death' || $kind === '' ? 'Funeral directors and families can place a death notice in two minutes, free. It is confirmed by email, checked by an editor and emailed to everyone in the county who asked for alerts.' : ($meta['who'] ?? 'Anyone') . ' can place one in two minutes. It is confirmed by email and checked by an editor before it appears.' ?></p>
          <div class="form__actions" style="justify-content:flex-start"><a class="btn btn--hot" href="/notices/submit<?= $kind ? '?kind=' . e($kind) : '' ?>"><?= icon('plus') ?> Place a notice</a></div>
        </div>
        <div class="reportcta__card">
          <span class="kicker">What it looks like</span>
          <article class="noticecard noticecard--death">
            <span class="noticecard__kind mono"><?= icon('candle') ?> Death notice · Example</span>
            <h3>Mary (Máire) Ó Briain</h3>
            <p class="noticecard__where"><?= icon('pin') ?> Rathdrum, Co. Wicklow</p>
            <p>Peacefully, at home, surrounded by her family. Reposing at her residence Thursday from 4pm to 8pm. Funeral Mass Friday at 11am, burial afterwards in the adjoining cemetery. Family flowers only; donations, if desired, to Wicklow Hospice.</p>
          </article>
        </div>
      </div>
    <?php endif; ?>
  </div>
  <aside class="side">
    <section class="panel panel--alerts reveal">
      <header class="panel__head"><span class="kicker"><?= icon('bell') ?> Alerts for <?= $county ? 'Co. ' . e($county) : 'your county' ?></span></header>
      <p class="panel__note" style="margin:0 0 10px">Death notices the moment they are published, and the 7am county morning email.</p>
      <form class="form" data-subscribe>
        <input type="hidden" name="kinds[]" value="deaths"><input type="hidden" name="kinds[]" value="daily">
        <label>County<select name="county" data-subscribe-county><?php foreach ($counties as $c): ?><option <?= $c === $county ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></label>
        <label>Town (optional)<input name="town" list="all-locations" placeholder="Only notices from this town" autocomplete="off"></label>
        <label>Email<input name="email" type="email" required value="<?= e($user['email'] ?? '') ?>"></label>
        <button class="btn btn--primary btn--block" type="submit">Get alerts</button>
        <p class="form__result" data-subscribe-result></p>
      </form>
    </section>
    <section class="panel reveal">
      <header class="panel__head"><span class="kicker">For funeral directors</span></header>
      <p class="panel__note" style="margin:0">Notices are free while ME News grows in your county. Place them from your own email address and they are confirmed in one tap; an editor publishes within hours, usually minutes. <a href="/notices/submit?kind=death">Place a death notice →</a></p>
    </section>
    <section class="panel reveal">
      <header class="panel__head"><span class="kicker">In memoriam</span></header>
      <p class="panel__note" style="margin:0">Anniversary and remembrance notices run for 45 days and can be renewed every year. <a href="/notices/submit?kind=memoriam">Place an in memoriam →</a></p>
    </section>
  </aside>
</div>
