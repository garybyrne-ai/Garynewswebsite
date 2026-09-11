<?php use MeNews\Ui; use MeNews\Support\Categories; use MeNews\Services\Ads;
$county = $locality['county'] ?? null;
$countySlug = $county ? slugify($county) : '';
?>
<div class="ticker" aria-label="Latest headlines">
  <div class="ticker__track">
    <?php for ($loop = 0; $loop < 2; $loop++): foreach ($ticker as $t): ?>
      <a href="<?= e($t['url']) ?>"><span class="mono"><?= e(mb_strtoupper($t['category'])) ?></span><?= e($t['title']) ?></a>
    <?php endforeach; endfor; ?>
  </div>
</div>

<div class="masthead">
  <div class="container masthead__in mono">
    <span class="masthead__ed">Edition No. <?= (int)$edition ?></span>
    <span class="masthead__date"><?= e($longDate) ?></span>
    <?php if ($weather): ?>
      <span class="masthead__sun"><?= icon('sun') ?> <?= e($weather['sunrise']) ?> · <?= icon('moon') ?> <?= e($weather['sunset']) ?></span>
      <span class="masthead__wx"><?php foreach (array_slice($weather['cities'], 0, 5) as $c): ?><span><?= e($c['icon']) ?> <?= e($c['name']) ?> <b><?= (int)$c['temp'] ?>°</b></span><?php endforeach; ?></span>
    <?php endif; ?>
    <?php if (!empty($warnings)): ?><a class="masthead__warn" href="/alerts"><?= icon('alert') ?> <?= e($warnings) ?></a><?php endif; ?>
    <span class="masthead__focal">Focal an lae: <b><?= e($focal['irish']) ?></b> — <?= e($focal['english']) ?></span>
  </div>
</div>

<?php if ($hero): ?>
<section class="container lead">
  <article class="lead__story reveal" style="--h:<?= Ui::hue($hero['title']) ?>">
    <a class="lead__media <?= $hero['image'] ? '' : 'is-typo' ?>" href="<?= e($hero['url']) ?>" tabindex="-1" aria-hidden="true">
      <?php if ($hero['image']): ?><img src="<?= e($hero['image']) ?>" alt="" fetchpriority="high" decoding="async" referrerpolicy="no-referrer" onerror="this.closest('.lead__media').classList.add('is-typo')"><?php endif; ?>
      <span class="lead__fallback"><?= icon(Categories::ALL[$hero['category']]['icon'] ?? 'national') ?></span>
    </a>
    <div class="lead__body">
      <div class="card__meta"><span class="kicker kicker--glow"><?= $leadKicker ?></span></div>
      <h1 class="lead__title"><a href="<?= e($hero['url']) ?>"><?= e($hero['title']) ?></a></h1>
      <?php if ($hero['summary']): ?><p class="lead__summary"><?= e(excerpt($hero['summary'], 300)) ?></p><?php endif; ?>
      <div class="lead__foot">
        <?= Ui::categoryChip($hero['category']) ?><?= Ui::label($hero['verification_label']) ?>
        <span class="mono"><?= e($hero['kind'] === 'wire' ? $hero['source_name'] : 'Community report') ?><?= $hero['location_name'] ? ' · ' . e($hero['location_name']) : '' ?> · <?= e(time_ago($hero['time'])) ?></span>
      </div>
      <?php if (!empty($hero['cluster_count']) && $hero['cluster_count'] > 1): ?><p class="lead__cluster mono"><?= icon('layers') ?> <?= (int)$hero['cluster_count'] ?> outlets covering this story</p><?php endif; ?>
    </div>
  </article>
  <aside class="lead__latest">
    <header class="panel__head"><span class="kicker">Latest on the wire</span><span class="mono panel__hint" id="latest-hint">live</span></header>
    <div class="rows">
      <?php foreach (array_slice($latest, 0, 6) as $i => $s): ?><?= Ui::card($s, 'row', $i) ?><?php endforeach; ?>
    </div>
  </aside>
</section>
<?php endif; ?>

<?php if (!empty($banner) && !\MeNews\Services\Membership::isPlus($user ?? null)): ?><div class="container adslot adslot--top"><?= Ads::render($banner, 'banner') ?></div><?php endif; ?>

<div class="container layout">
  <div class="layout__main">

    <?= \MeNews\View::partial('partials/near', ['locality' => $locality, 'counties' => $countyNames, 'compact' => true, 'notices' => $countyNotices ?? [], 'first' => true]) ?>

    <?= \MeNews\View::partial('partials/signal-slider', compact('signalBoard', 'signalStats')) ?>

    <section class="block block--community reveal" id="community">
      <header class="block__head">
        <span class="block__index mono"><?= icon('community') ?></span>
        <h2 class="block__title"><a href="/section/community">From the ground</a></h2>
        <p class="block__blurb">What people in <?= e($county ? 'Co. ' . $county : 'Ireland') ?> are seeing right now. Reports are screened, then an editor decides. No account needed for your first one.</p>
        <button class="btn btn--hot" type="button" data-open-report><?= icon('report') ?> Report a story</button>
      </header>
      <?php if ($community): ?>
        <div class="grid grid--4">
          <?php foreach ($community as $i => $s): ?><?= Ui::card($s, 'compact', $i) ?><?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="reportcta">
          <div class="reportcta__card">
            <span class="kicker">What a good report looks like</span>
            <article class="card card--compact">
              <div class="card__body">
                <div class="card__meta"><?= Ui::categoryChip('Traffic') ?><?= Ui::label('Corroborated') ?><time>Example</time></div>
                <h3 class="card__title">Water main burst on Church Road, one lane closed at the school</h3>
                <p class="card__summary">Council crew on site since 8am, traffic being let through in turns. Photo attached, GPS on. Three neighbours tapped "I saw this too".</p>
                <div class="card__foot"><span class="card__source card__source--community">Community report</span><span class="card__place"><?= icon('pin') ?> Greystones</span></div>
              </div>
            </article>
          </div>
          <div class="reportcta__text">
            <h3>Be the first voice from <?= e($county ? $county : 'your town') ?>.</h3>
            <p>A photo, a location and one honest line is enough: a road closed, a pitch flooded, a shop opening, a council notice on a lamppost. Every report is safety-screened and read by an editor before it appears, and you'll hear back either way.</p>
            <div class="form__actions" style="justify-content:flex-start"><button class="btn btn--hot" type="button" data-open-report><?= icon('report') ?> Report what you can see</button><?php if (!empty($whatsapp)): ?><a class="btn btn--ghost" href="https://wa.me/<?= e(preg_replace('/\D+/', '', $whatsapp)) ?>" target="_blank" rel="noopener"><?= icon('whatsapp') ?> WhatsApp <?= e($whatsapp) ?></a><?php endif; ?></div>
          </div>
        </div>
      <?php endif; ?>
    </section>

    <?php $n = 1; foreach ($sections as $name => $rows): ?>
      <section class="block reveal">
        <header class="block__head">
          <span class="block__index mono"><?= str_pad((string)$n++, 2, '0', STR_PAD_LEFT) ?></span>
          <h2 class="block__title"><a href="/section/<?= e(Categories::slug($name)) ?>"><?= e($name) ?></a></h2>
          <p class="block__blurb"><?= e(Categories::ALL[$name]['blurb']) ?></p>
          <a class="block__more mono" href="/section/<?= e(Categories::slug($name)) ?>">All <?= e($name) ?> →</a>
        </header>
        <div class="grid grid--4">
          <?php foreach ($rows as $i => $s): ?><?= Ui::card($s, $i === 0 ? 'standard' : 'compact', $i) ?><?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>

    <?= \MeNews\View::partial('partials/kids-corner', compact('crossword', 'quiz', 'focal', 'edition', 'longDate', 'youngReaders')) ?>

  </div>
  <?= \MeNews\View::partial('partials/sidebar', compact('mapPoints', 'mapCounties', 'counties', 'trending', 'ads', 'weather', 'edition', 'longDate', 'focal', 'greeting') + ['notices' => $latestNotices ?? [], 'county' => $county, 'poll' => $poll ?? null]) ?>
</div>
