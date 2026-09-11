<?php use MeNews\Ui; ?>
<section class="container pagehead">
  <span class="kicker"><?= e($kicker) ?></span>
  <h1 class="pagehead__title"><span class="pagehead__icon"><?= icon($icon) ?></span><?= e($heading) ?></h1>
  <p class="pagehead__blurb"><?= e($blurb) ?></p>
  <?php if (isset($q)): ?>
    <form class="search search--big" action="/search" role="search"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search stories, towns, counties…" aria-label="Search" autofocus><button type="submit">Search</button></form>
  <?php endif; ?>
  <div class="pagehead__meta mono"><?= (int)$total ?> published · page <?= (int)$page ?> of <?= (int)$pages ?></div>
</section>
<?php if (!empty($banner)): ?><div class="container adslot adslot--top"><?= \MeNews\Services\Ads::render($banner, 'banner') ?></div><?php endif; ?>
<?php if (!empty($countyStrip)): $cs = $countyStrip; ?>
<div class="container countystrip">
  <a class="countystrip__item <?= $cs['warning'] ? 'is-live' : '' ?>" href="/alerts?county=<?= rawurlencode($county) ?>"><?= icon('alert') ?><span><b><?= $cs['warning'] ? e($cs['warning']) : 'No weather warnings' ?></b><small><?= $cs['closures'] ? count($cs['closures']) . ' school closure' . (count($cs['closures']) === 1 ? '' : 's') . ' today' : 'No school closures reported' ?> · alerts by email →</small></span></a>
  <a class="countystrip__item" href="/notices?county=<?= rawurlencode($county) ?>"><?= icon('candle') ?><span><b><?= $cs['deaths'] ? 'Deaths: ' . e(implode(', ', array_map(static fn($n) => $n['title'], array_slice($cs['deaths'], 0, 2)))) . (count($cs['deaths']) > 2 ? ' +' . (count($cs['deaths']) - 2) : '') : 'Death notices & funerals' ?></b><small><?= $cs['deaths'] ? 'All ' . e($county) . ' notices →' : 'Free to place · alerts by email →' ?></small></span></a>
  <a class="countystrip__item" href="/notices/event?county=<?= rawurlencode($county) ?>"><?= icon('calendar') ?><span><b><?= $cs['events'] ? e($cs['events'][0]['title']) : "What's on in " . e($county) ?></b><small><?= $cs['events'] ? e(date_irish($cs['events'][0]['event_at'], 'D j M')) . ' · more events →' : 'Add your event, free →' ?></small></span></a>
  <a class="countystrip__item" href="/county/<?= e($cs['slug']) ?>/map"><?= icon('map') ?><span><b><?= e($county) ?> on the map</b><small><?= $cs['towns'] ? 'Busiest: ' . e(implode(', ', array_map(static fn($t) => $t['location_name'], array_slice($cs['towns'], 0, 3)))) : 'Every story placed' ?> →</small></span></a>
  <?php if (!empty($cs['whatsapp'])): ?><a class="countystrip__item" href="<?= e($cs['whatsapp']) ?>" target="_blank" rel="noopener"><?= icon('whatsapp') ?><span><b>WhatsApp channel</b><small>Join the <?= e($county) ?> channel →</small></span></a><?php endif; ?>
</div>
<?php endif; ?>
<div class="container layout">
  <div class="layout__main">
    <?php if (!empty($clubResults)): ?>
      <section class="block reveal" style="margin-bottom:28px">
        <header class="block__head"><span class="block__index mono"><?= icon('trophy') ?></span><h2 class="block__title"><a href="/notices/result">Club sport</a></h2><p class="block__blurb">Results, fixtures and notes sent in by club PROs. National sport is everywhere; this is not.</p><a class="btn btn--ghost btn--sm" href="/notices/submit?kind=result"><?= icon('plus') ?> Send a result</a></header>
        <div class="noticegrid"><?php foreach ($clubResults as $n): ?><?= \MeNews\View::partial('partials/notice-card', ['n' => $n]) ?><?php endforeach; ?></div>
      </section>
    <?php elseif (isset($clubResults)): ?>
      <section class="reportcta" style="margin-bottom:28px"><div class="reportcta__text"><h3>Club results, fixtures and notes.</h3><p>Junior B scores, underage fixtures and club notes are nearly impossible to find online. Club PROs can post them here in a minute, free, and they go out in the county's 7am email.</p><a class="btn btn--primary" href="/notices/submit?kind=result"><?= icon('trophy') ?> Send your club's result</a></div><div class="reportcta__card"><span class="kicker">What it looks like</span><article class="noticecard"><span class="noticecard__kind mono"><?= icon('trophy') ?> Club result · Example</span><h3>Rathdrum through to county semi-final</h3><p class="noticecard__score"><span>Rathdrum</span><b>1-12 – 0-09</b><span>Avondale</span></p><p class="noticecard__line mono">Wicklow Junior B Football Championship</p></article></div></section>
    <?php endif; ?>
    <?php if (!empty($leaderboard)): ?>
      <section class="block reveal" style="margin-bottom:28px">
        <header class="block__head"><span class="block__index mono"><?= icon('trophy') ?></span><h2 class="block__title">Who's reporting</h2><p class="block__blurb">The towns and neighbours keeping their county on the map this month. Reports are screened and read by an editor before they appear.</p><button class="btn btn--hot" type="button" data-open-report><?= icon('report') ?> Report a story</button></header>
        <div class="grid grid--2 leaderboards">
          <div class="panel"><header class="panel__head"><span class="kicker">Busiest counties · 30 days</span></header>
            <?php if ($leaderboard['counties']): $max = max(array_column($leaderboard['counties'], 'n')); ?><ol class="bars"><?php foreach ($leaderboard['counties'] as $c): ?><li><a href="/county/<?= e(slugify($c['county'])) ?>"><span><?= e($c['county']) ?></span><i style="--v:<?= (int)round($c['n'] / max(1, $max) * 100) ?>"></i><b class="mono"><?= (int)$c['n'] ?></b></a></li><?php endforeach; ?></ol><?php else: ?><p class="panel__note" style="margin:0">The first county on the board gets bragging rights. <?= !empty($leaderboard['whatsapp']) ? 'WhatsApp ' . e($leaderboard['whatsapp']) . ' or tap Report.' : 'Tap Report to start.' ?></p><?php endif; ?>
          </div>
          <div class="panel"><header class="panel__head"><span class="kicker">Top reporters</span></header>
            <?php if ($leaderboard['reporters']): ?><ul class="desk"><?php foreach ($leaderboard['reporters'] as $u): ?><li><a href="<?= $u['handle'] ? '/contributors/' . e($u['handle']) : '#' ?>"><?= \MeNews\Ui::avatar($u, 'sm') ?><span><b><?= e($u['display_name']) ?></b><small><?= e($u['home_county'] ? 'Co. ' . $u['home_county'] : '') ?> · <?= (int)$u['reports_published'] ?> published · <?= (int)$u['confirmations'] ?> confirmations</small></span><em class="mono"><?= $u['reports_filed'] ? (int)round($u['reports_published'] / max(1, $u['reports_filed']) * 100) . '%' : '' ?></em></a></li><?php endforeach; ?></ul><?php else: ?><p class="panel__note" style="margin:0">Publish a report and your name goes here with your track record: filed, published and how often neighbours confirmed you.</p><?php endif; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>
    <?php if ($rows): ?>
      <div class="grid grid--3">
        <?php foreach ($rows as $i => $s): ?><?= Ui::card($s, $i === 0 && $page === 1 ? 'feature' : 'standard', $i) ?><?php endforeach; ?>
      </div>
      <?= Ui::pagination($page, $pages, $basePath, isset($q) ? '&q=' . rawurlencode($q) : '') ?>
    <?php else: ?>
      <div class="empty"><div class="empty__glyph"><?= icon('clock') ?></div><h3>Nothing published here yet.</h3><p>The wire refreshes every few minutes. Try another section or county, or search for a town.</p></div>
    <?php endif; ?>
  </div>
  <?= \MeNews\View::partial('partials/sidebar', ['trending' => $trending ?? [], 'ads' => $ads ?? []]) ?>
</div>
