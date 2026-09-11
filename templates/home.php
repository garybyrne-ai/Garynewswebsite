<?php use MeNews\Ui; use MeNews\Support\Categories; ?>
<div class="ticker" aria-label="Latest headlines">
  <div class="ticker__track">
    <?php for ($loop = 0; $loop < 2; $loop++): foreach (array_slice($latest, 0, 9) as $t): ?>
      <a href="<?= e($t['url']) ?>"><span class="mono"><?= e(mb_strtoupper($t['category'])) ?></span><?= e($t['title']) ?></a>
    <?php endforeach; endfor; ?>
  </div>
</div>

<div class="masthead">
  <div class="container masthead__in mono">
    <span class="masthead__ed">Edition No. <?= (int)$edition ?></span>
    <span class="masthead__date"><?= e($longDate) ?></span>
    <?php if ($weather): ?>
      <span class="masthead__sun">☀ <?= e($weather['sunrise']) ?> · ☾ <?= e($weather['sunset']) ?></span>
      <span class="masthead__wx"><?php foreach (array_slice($weather['cities'], 0, 5) as $c): ?><span><?= e($c['icon']) ?> <?= e($c['name']) ?> <b><?= (int)$c['temp'] ?>°</b></span><?php endforeach; ?></span>
    <?php endif; ?>
    <span class="masthead__focal">Focal an lae: <b><?= e($focal['irish']) ?></b> — <?= e($focal['english']) ?></span>
  </div>
</div>

<?php if (!empty($banner)): ?><div class="container adslot adslot--top"><?= \MeNews\Services\Ads::render($banner, 'banner') ?></div><?php endif; ?>

<section class="container hero">
  <div class="hero__intro">
    <span class="kicker kicker--glow">Around me · Ireland</span>
    <h1 class="hero__title">What's happening <em>around you</em>, right now.</h1>
    <p class="hero__lede">Real headlines from Ireland's newsrooms, county-by-county reporting and community stories from the people who live there — screened by the ME Trust Engine, labelled by human editors.</p>
    <div class="hero__stats mono">
      <span><b><?= e(compact_number((int)$totalPublished)) ?></b> stories</span>
      <span><b><?= e((string)count($contributors)) ?></b> desk editors</span>
      <span><b><?= e((string)array_sum($pulse)) ?></b> in 24 h</span>
      <span><b><?= e((string)count($counts)) ?></b> sections</span>
    </div>
  </div>

  <?php if ($hero): ?>
    <div class="hero__feature">
      <?= Ui::card($hero, 'feature', 0) ?>
    </div>
  <?php endif; ?>

  <div class="hero__latest">
    <header class="panel__head"><span class="kicker">Latest on the wire</span><span class="mono panel__hint" id="latest-hint">live</span></header>
    <div class="rows">
      <?php foreach (array_slice($latest, 0, 6) as $i => $s): ?><?= Ui::card($s, 'row', $i) ?><?php endforeach; ?>
    </div>
  </div>
</section>

<div class="container layout">
  <div class="layout__main">

    <?= \MeNews\View::partial('partials/signal-slider', compact('signalBoard', 'signalStats')) ?>

    <?= \MeNews\View::partial('partials/near', ['locality' => $locality, 'counties' => $countyNames, 'compact' => true]) ?>

    <?php $n = 1; foreach ($sections as $name => $rows): ?>
      <section class="block reveal">
        <header class="block__head">
          <span class="block__index mono"><?= str_pad((string)$n++, 2, '0', STR_PAD_LEFT) ?></span>
          <h2 class="block__title"><a href="/section/<?= e(Categories::slug($name)) ?>"><?= e($name) ?></a></h2>
          <p class="block__blurb"><?= e(Categories::ALL[$name]['blurb']) ?></p>
          <a class="block__more mono" href="/section/<?= e(Categories::slug($name)) ?>">All <?= e($name) ?> · <?= (int)($counts[$name] ?? 0) ?> →</a>
        </header>
        <div class="grid grid--4">
          <?php foreach ($rows as $i => $s): ?><?= Ui::card($s, $i === 0 ? 'standard' : 'compact', $i) ?><?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>

    <section class="block block--community reveal" id="community">
      <header class="block__head">
        <span class="block__index mono">⬡</span>
        <h2 class="block__title"><a href="/section/community">From the ground</a></h2>
        <p class="block__blurb">Community reports go through private quarantine, safety screening and an editor before they appear here.</p>
        <button class="btn btn--hot" type="button" data-open-report><span class="livedot livedot--white"></span> Report a story</button>
      </header>
      <?php if ($community): ?>
        <div class="grid grid--4">
          <?php foreach ($community as $i => $s): ?><?= Ui::card($s, 'compact', $i) ?><?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty">
          <div class="empty__glyph" aria-hidden="true">⬡</div>
          <h3>No community reports published yet.</h3>
          <p>Be the first: sign in, tap <b>Report</b>, and tell the newsroom what you can see. Every submission is screened before an editor decides.</p>
        </div>
      <?php endif; ?>
    </section>

    <?= \MeNews\View::partial('partials/kids-corner', compact('crossword', 'quiz', 'focal', 'edition', 'longDate', 'youngReaders')) ?>

    <section class="block reveal">
      <header class="block__head">
        <span class="block__index mono">◈</span>
        <h2 class="block__title">Meet the desk</h2>
        <p class="block__blurb">Five editors, five desks, one island. Every story on ME has a named person responsible for it.</p>
        <a class="block__more mono" href="/contributors">All contributors →</a>
      </header>
      <div class="grid grid--5 people">
        <?php foreach ($contributors as $c): ?>
          <a class="person" href="/contributors/<?= e($c['handle']) ?>" style="--h:<?= (int)$c['accent'] ?>">
            <?= Ui::avatar($c, 'lg') ?>
            <b><?= e($c['display_name']) ?></b>
            <small><?= e($c['title']) ?></small>
            <span class="mono"><?= (int)$c['stories'] ?> stories · <?= e($c['home_county']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>

  </div>
  <?= \MeNews\View::partial('partials/sidebar', compact('pulse', 'mapPoints', 'mapCounties', 'counties', 'trending', 'contributors', 'ads', 'weather', 'edition', 'longDate', 'focal', 'greeting')) ?>
</div>
