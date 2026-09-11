<?php $live = $status !== ''; ?>
<section class="container pagehead alertshead alertshead--<?= $live ? strtolower(e($status)) : 'calm' ?>">
  <span class="kicker"><?= icon('alert') ?> <?= $county ? 'Co. ' . e($county) : 'Ireland' ?> · <?= $live ? 'Live' : 'All clear' ?></span>
  <h1 class="pagehead__title"><?= $live ? e($status) . ' <em>warning in force.</em>' : 'No warnings <em>right now.</em>' ?></h1>
  <p class="pagehead__blurb"><?= $live ? 'Met Éireann has issued a ' . strtolower(e($status)) . ' warning' . ($county ? ' covering County ' . e($county) : '') . '. School closures appear below as principals confirm them.' : 'This page stays quiet until it matters: Met Éireann warnings by county, and school closures confirmed by principals, with email alerts the moment either changes.' ?></p>
  <form class="noticefilter" method="get" action="/alerts">
    <label class="sr-only" for="al-county">County</label>
    <select id="al-county" name="county" onchange="this.form.submit()"><option value="">All of Ireland</option><?php foreach ($counties as $c): ?><option <?= $c === $county ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select>
    <noscript><button class="btn btn--primary btn--sm" type="submit">Go</button></noscript>
    <a class="btn btn--ghost btn--sm" href="#closure-form"><?= icon('school') ?> Principals: report a closure</a>
    <button class="btn btn--ghost btn--sm" type="button" data-bulletin="<?= e((string)$county) ?>"><?= icon('audio') ?> Listen: 90-second bulletin</button>
  </form>
</section>

<div class="container layout">
  <div class="layout__main">
    <section class="block reveal">
      <header class="block__head"><span class="block__index mono"><?= icon('wind') ?></span><h2 class="block__title">Weather warnings</h2><p class="block__blurb">Straight from Met Éireann’s open data, refreshed every ten minutes.</p><a class="block__more mono" href="https://www.met.ie/warnings-today/" target="_blank" rel="noopener">met.ie ↗</a></header>
      <?php if ($warnings): ?>
        <div class="warnlist">
          <?php foreach ($warnings as $w): ?>
            <article class="warn warn--<?= strtolower(e($w['level'])) ?> <?= $w['advisory'] ? 'warn--advisory' : '' ?>">
              <span class="warn__level mono"><?= e($w['level']) ?><?= $w['advisory'] ? ' advisory' : ' warning' ?></span>
              <h3><?= e($w['headline']) ?></h3>
              <p><?= e($w['description']) ?></p>
              <p class="warn__meta mono"><?= $w['onset'] ? 'From ' . e(date_irish($w['onset'], 'D H:i')) : '' ?><?= $w['expiry'] ? ' until ' . e(date_irish($w['expiry'], 'D H:i')) : '' ?> · <?= $w['national'] ? 'All of Ireland' : e(implode(', ', $w['counties'])) ?></p>
            </article>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty"><div class="empty__glyph"><?= icon('sun') ?></div><h3>Nothing in force<?= $county ? ' for Co. ' . e($county) : '' ?>.</h3><p>Sign up below and you’ll hear the moment that changes.</p></div>
      <?php endif; ?>
    </section>

    <section class="block reveal" id="closures">
      <header class="block__head"><span class="block__index mono"><?= icon('school') ?></span><h2 class="block__title">School closures</h2><p class="block__blurb">Submitted directly by principals from a school email address and published the moment they confirm.</p></header>
      <?php if ($closures): ?>
        <div class="tablewrap"><table class="table">
          <thead><tr><th>School</th><th>Where</th><th>Closed</th><th>Reopens</th><th>Reason</th></tr></thead>
          <tbody><?php foreach ($closures as $c): ?><tr><td><b><?= e($c['school']) ?></b><?= $c['verified_at'] ? ' <span class="chip chip--label is-verified" title="Confirmed from the school’s email"><i></i>Confirmed</span>' : '' ?></td><td><?= e(($c['town'] ? $c['town'] . ', ' : '') . 'Co. ' . $c['county']) ?></td><td><?= e(date('D j M', strtotime($c['closed_on']) ?: time())) ?></td><td><?= $c['reopens_on'] ? e(date('D j M', strtotime($c['reopens_on']) ?: time())) : '—' ?></td><td><?= e($c['reason'] ?: '') ?></td></tr><?php endforeach; ?></tbody>
        </table></div>
      <?php else: ?>
        <div class="empty"><div class="empty__glyph"><?= icon('school') ?></div><h3>No closures reported<?= $county ? ' in Co. ' . e($county) : '' ?>.</h3><p>Principals: add yours below in under a minute. Parents: sign up for closure alerts and you’ll know before the school run.</p></div>
      <?php endif; ?>
      <form class="form panel" id="closure-form" data-closure style="margin-top:22px">
        <span class="kicker"><?= icon('school') ?> Principals &amp; boards of management: report a closure</span>
        <div class="form__row"><label>School name<input name="school" required minlength="3"></label><label>County<select name="county" required data-county-select><option value="">Select county</option><?php foreach ($counties as $c): ?><option <?= $c === $county ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></label></div>
        <div class="form__row"><label>Town<input name="town" list="all-locations" autocomplete="off"></label><label>Reason<input name="reason" placeholder="e.g. Storm Ciarán, no water supply, heating failure"></label></div>
        <div class="form__row"><label>Closed on<input name="closed_on" type="date" required value="<?= date('Y-m-d') ?>"></label><label>Reopens (optional)<input name="reopens_on" type="date"></label></div>
        <div class="form__row"><label>Your name<input name="contact_name" required></label><label>Role<input name="contact_role" value="Principal"></label></div>
        <label>School email address <small class="form__hint">We send a one-tap confirmation here. Closures go live the moment you confirm.</small><input name="contact_email" type="email" required></label>
        <div class="form__actions"><button class="btn btn--primary" type="submit">Send confirmation email</button></div>
        <p class="form__result" data-closure-result role="status"></p>
      </form>
    </section>
  </div>
  <aside class="side">
    <section class="panel panel--alerts reveal">
      <header class="panel__head"><span class="kicker"><?= icon('bell') ?> Email alerts</span></header>
      <form class="form" data-subscribe>
        <label>County<select name="county" data-subscribe-county><?php foreach ($counties as $c): ?><option <?= $c === $county ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></label>
        <div class="checks"><?php foreach ($kinds as $k => $label): ?><label class="check"><input type="checkbox" name="kinds[]" value="<?= e($k) ?>" <?= in_array($k, ['warnings', 'closures', 'daily'], true) ? 'checked' : '' ?>><span><?= e($label) ?></span></label><?php endforeach; ?></div>
        <label>Email<input name="email" type="email" required value="<?= e($user['email'] ?? '') ?>"></label>
        <button class="btn btn--primary btn--block" type="submit">Turn on alerts</button>
        <p class="form__result" data-subscribe-result></p>
        <p class="form__legal">Warnings and closures only when they happen; the morning email at 7am. One-tap unsubscribe on every email.</p>
      </form>
    </section>
    <?php if ($whatsapp): ?>
      <section class="panel reveal"><header class="panel__head"><span class="kicker"><?= icon('whatsapp') ?> WhatsApp channel</span></header><p class="panel__note" style="margin:0 0 10px">Warnings, closures and the day’s top local stories, on the app you already use.</p><a class="btn btn--ghost btn--block" href="<?= e($whatsapp) ?>" target="_blank" rel="noopener">Join the <?= $county ? e($county) : 'ME News' ?> channel</a></section>
    <?php endif; ?>
    <section class="panel reveal"><header class="panel__head"><span class="kicker">Elsewhere</span></header>
      <ul class="noticelist"><?php foreach ($allWarnings as $w): if ($w['advisory']) continue; ?><li><a href="/alerts"><b><?= e($w['level']) ?> · <?= e($w['headline']) ?></b><small><?= $w['national'] ? 'All of Ireland' : e(implode(', ', array_slice($w['counties'], 0, 6))) . (count($w['counties']) > 6 ? ' +' . (count($w['counties']) - 6) : '') ?></small></a></li><?php endforeach; ?></ul>
      <?php if (!array_filter($allWarnings, static fn($w) => !$w['advisory'])): ?><p class="panel__note" style="margin:0">No warnings anywhere in the country.</p><?php endif; ?>
    </section>
  </aside>
</div>
