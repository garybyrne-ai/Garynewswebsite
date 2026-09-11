<?php use MeNews\Ui; use MeNews\Support\Categories;
$isWire = $story['kind'] === 'wire';
$cluster = $cluster ?? null; $members = $members ?? [];
$jsonld = $isWire ? null : [
    '@context' => 'https://schema.org', '@type' => 'NewsArticle', 'headline' => $story['title'],
    'datePublished' => $story['time'], 'dateModified' => $story['updated_at'] ?: $story['time'],
    'description' => $story['summary'], 'image' => $story['image'] ? [absolute_url($story['image'])] : [],
    'author' => ['@type' => 'Person', 'name' => $story['author_name']],
    'publisher' => ['@type' => 'Organization', 'name' => 'ME News Ireland', 'url' => absolute_url('/')],
    'mainEntityOfPage' => absolute_url($story['url']),
];
?>
<div class="progress" aria-hidden="true"><i id="read-progress"></i></div>
<?php if ($jsonld): ?><script type="application/ld+json"><?= json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script><?php endif; ?>

<article class="container article" data-story-id="<?= e($story['id']) ?>">
  <header class="article__head">
    <nav class="crumbs mono" aria-label="Breadcrumb"><a href="/">Around Me</a><span>/</span><a href="/section/<?= e($story['category_slug']) ?>"><?= e($story['category']) ?></a><?php if ($story['county']): ?><span>/</span><a href="/county/<?= e(slugify($story['county'])) ?>">Co. <?= e($story['county']) ?></a><?php endif; ?></nav>
    <div class="article__labels"><?= $isWire ? '<a class="chip chip--label is-wire" href="/about#labels" title="How we check things"><i></i>Wire · ' . e($story['source_name']) . '</a>' : Ui::label($story['verification_label']) ?><?= Ui::categoryChip($story['category']) ?><?= $cluster && $cluster['count'] > 1 ? '<a class="chip chip--cluster" href="#coverage">' . icon('layers') . ' ' . (int)$cluster['count'] . ' outlets covering this</a>' : '' ?><?= !$isWire && (int)($story['corroborations'] ?? 0) >= 3 ? '<span class="chip chip--label is-corroborated"><i></i>Corroborated ×' . (int)$story['corroborations'] . '</span>' : '' ?></div>
    <h1 class="article__title"><?= e($story['title']) ?></h1>
    <?php if ($story['summary']): ?><p class="article__standfirst"><?= e($story['summary']) ?></p><?php endif; ?>
    <div class="article__byline">
      <?php if ($isWire): ?>
        <span class="byline"><?= Ui::avatar(null, 'md', $story['source_name']) ?><span><b><?= $story['source_author'] ? 'By ' . e($story['source_author']) . ', ' : '' ?><?= e($story['source_name']) ?></b><small>Wire story · curated by <?= $author && !empty($author['handle']) ? '<a href="/contributors/' . e($author['handle']) . '">' . e($author['display_name']) . '</a>' : 'the ME desk' ?> for the <?= e($author['desk'] ?? $story['category']) ?> desk</small></span></span>
      <?php elseif ($author): ?>
        <a class="byline" href="<?= !empty($author['handle']) ? '/contributors/' . e($author['handle']) : '#' ?>"><?= Ui::avatar($author, 'md') ?><span><b><?= e($author['display_name']) ?></b><small><?= e($author['title'] ?: 'Community reporter') ?><?= !empty($author['reports_published']) ? ' · ' . (int)$author['reports_published'] . ' published reports' : '' ?></small></span></a>
      <?php else: ?>
        <span class="byline"><?= Ui::avatar(null, 'md', $story['author_name'] ?: 'ME') ?><span><b><?= e($story['author_name'] ?: 'Community reporter') ?></b><small>Community reporter</small></span></span>
      <?php endif; ?>
      <div class="textsize" role="group" aria-label="Text size"><button type="button" data-textsize="" aria-label="Normal text">A</button><button type="button" data-textsize="lg" aria-label="Larger text">A</button><button type="button" data-textsize="xl" aria-label="Largest text">A</button></div>
      <div class="article__time mono">
        <time datetime="<?= e($story['time']) ?>"><?= e(date_irish($story['time'])) ?></time>
        <span>·</span><span><?= e(time_ago($story['time'])) ?></span>
        <?php if (views_label((int)$story['views']) !== ''): ?><span>·</span><span><?= e(views_label((int)$story['views'])) ?> reads</span><?php endif; ?>
        <?php if ($story['location_name']): ?><span>·</span><span><?= icon('pin') ?> <?= e($story['location_name']) ?><?= $story['county'] && !str_contains((string)$story['location_name'], $story['county']) ? ', Co. ' . e($story['county']) : '' ?></span><?php endif; ?>
      </div>
    </div>
  </header>

  <div class="article__grid">
    <div class="article__main">
      <?php if ($story['image']): ?>
        <figure class="article__media" style="--h:<?= Ui::hue($story['title']) ?>">
          <img src="<?= e($story['image']) ?>" alt="<?= e($story['image_credit'] ?: $story['title']) ?>" fetchpriority="high" decoding="async" referrerpolicy="no-referrer" onerror="this.closest('figure').classList.add('is-broken')">
          <span class="card__corners"></span>
          <?php if ($story['image_credit'] || $isWire): ?><figcaption class="mono"><?= e($story['image_credit'] ?: 'Image via ' . $story['source_name']) ?></figcaption><?php endif; ?>
        </figure>
      <?php elseif ($story['media_url'] && $story['media_type'] === 'video'): ?>
        <figure class="article__media"><video controls preload="metadata" src="<?= e($story['media_url']) ?>"></video></figure>
      <?php endif; ?>

      <?php if (!empty($banner)): ?><div class="adslot adslot--inline"><?= \MeNews\Services\Ads::render($banner, 'banner') ?></div><?php endif; ?>

      <?php if ($isWire): ?>
        <div class="article__body">
          <p class="article__lede"><?= e($story['summary']) ?></p>
          <div class="sourcebox">
            <span class="kicker">Full story at the source</span>
            <p>This is a wire headline from <b><?= e($story['source_name']) ?></b><?= $story['source_author'] ? ', reported by ' . e($story['source_author']) : '' ?>. ME did not report or verify it; we curated it into our <?= e($story['category']) ?> desk and link you straight to the publisher, who holds all rights to the full article, photographs and updates.</p>
            <a class="btn btn--primary" href="<?= e($story['source_url']) ?>" target="_blank" rel="noopener">Continue reading at <?= e($story['source_name']) ?> <?= icon('external') ?></a>
            <span class="mono sourcebox__url"><?= e(parse_url($story['source_url'], PHP_URL_HOST)) ?></span>
          </div>
        </div>
        <?php if ($cluster && $members): ?>
          <section class="coverage" id="coverage">
            <header class="block__head block__head--sm"><h2 class="block__title"><?= icon('layers') ?> <?= (int)$cluster['count'] ?> outlets covering this</h2><span class="mono">How we got here</span></header>
            <p class="coverage__framing"><?= e($cluster['framing']) ?> ME's framing; each outlet's own headline is below.</p>
            <ol class="timeline">
              <?php $all = array_merge([['id' => $story['id'], 'slug' => $story['slug'], 'title' => $story['title'], 'source_name' => $story['source_name'], 'source_url' => $story['source_url'], 'source_author' => $story['source_author'], 't' => $story['time']]], $members); usort($all, static fn($a, $b) => strcmp($a['t'], $b['t'])); foreach ($all as $m): ?>
                <li class="<?= $m['id'] === $story['id'] ? 'is-current' : '' ?>"><time class="mono"><?= e(date_irish($m['t'], 'D H:i')) ?></time><div><b><?= e($m['source_name']) ?></b><?= $m['source_author'] ? ' <span class="mono">' . e($m['source_author']) . '</span>' : '' ?><br><a href="<?= e($m['source_url']) ?>" target="_blank" rel="noopener"><?= e($m['title']) ?> <?= icon('external') ?></a></div></li>
              <?php endforeach; ?>
            </ol>
          </section>
        <?php endif; ?>
      <?php else: ?>
        <div class="article__body"><?= nl2br(e($story['body'])) ?></div>
        <?php if ($story['editorial_note']): ?><div class="editornote"><span class="kicker">Editor's note</span><p><?= e($story['editorial_note']) ?></p></div><?php endif; ?>
      <?php endif; ?>

      <?= \MeNews\View::partial('partials/vote', ['story' => $story, 'signal' => $signal, 'signalRank' => $signalRank]) ?>

      <section class="trust">
        <header class="trust__head"><span class="kicker">ME Trust Engine</span><span class="mono"><?= e($story['verification_label']) ?> — editorial label</span></header>
        <div class="trust__meters">
          <div><span class="mono">Safety</span><div class="meter"><i style="--v:<?= (int)$story['safety_score'] ?>"></i></div><b class="mono"><?= (int)$story['safety_score'] ?>/100</b></div>
          <div><span class="mono">Confidence</span><div class="meter meter--alt"><i style="--v:<?= (int)$story['trust_score'] ?>"></i></div><b class="mono"><?= (int)$story['trust_score'] ?>/100</b></div>
          <div><span class="mono">Confirmations</span><b class="trust__big" id="confirm-count"><?= (int)$confirmations ?></b></div>
        </div>
        <p class="trust__note"><?= $isWire ? 'Wire stories come from established Irish publishers and carry their own editorial standards. ME labels them <b>Wire</b>: the source is known and linked, but ME has not independently checked the story. <b>Verified</b> is reserved for reports our desk has checked.' : 'Safety is not truth. The safety score screens for harmful content; the confidence score weighs evidence such as media, GPS and reporter track record. Only editors set the public label. Three independent “I saw this too” confirmations earn a <b>Corroborated</b> label.' ?> <a href="/about#labels">How we check things →</a></p>
        <div class="trust__actions">
          <button class="btn btn--ghost" type="button" data-confirm="<?= e($story['id']) ?>"><?= $isWire ? 'I can add local context' : icon('eye') . ' I saw this too' ?></button>
          <button class="btn btn--ghost" type="button" data-share data-title="<?= e($story['title']) ?>">Share ↗</button>
        </div>
      </section>

      <section class="discussion" id="discussion">
        <header class="block__head block__head--sm"><h2 class="block__title">Community discussion</h2><span class="mono" id="comment-count"><?= count($comments) ?> published</span></header>
        <div class="comments" id="comments">
          <?php if ($comments): foreach ($comments as $c): ?>
            <div class="comment"><?= Ui::avatar(['display_name' => $c['author'], 'accent' => $c['accent'] ?? null, 'is_verified' => $c['is_verified'] ?? 0], 'sm', $c['author']) ?><div><b><?= e($c['author']) ?></b><time class="mono"><?= e(time_ago($c['created_at'])) ?></time><p><?= nl2br(e($c['body'])) ?></p></div></div>
          <?php endforeach; else: ?>
            <p class="panel__note" id="no-comments">No published comments yet. Keep it factual and respectful — comments are screened before they appear.</p>
          <?php endif; ?>
        </div>
        <form class="form" id="comment-form" data-story="<?= e($story['id']) ?>">
          <label>Add a factual, respectful comment<textarea name="body" rows="3" required minlength="2" maxlength="1200" placeholder="<?= $user ? 'Share what you know…' : 'Sign in to join the discussion' ?>"></textarea></label>
          <div class="form__actions"><button class="btn btn--primary" type="submit"><?= $user ? 'Post comment' : 'Sign in to comment' ?></button></div>
        </form>
      </section>
    </div>

    <aside class="side side--article">
      <?php if (!$isWire && $author && in_array($author['role'] ?? '', ['contributor', 'editor', 'admin'], true)): ?>
        <section class="panel panel--author reveal" style="--h:<?= (int)$author['accent'] ?>">
          <?= Ui::avatar($author, 'lg') ?>
          <b><?= e($author['display_name']) ?></b>
          <small><?= e($author['title']) ?></small>
          <p><?= e(excerpt($author['bio'], 190)) ?></p>
          <a class="mono" href="/contributors/<?= e($author['handle']) ?>">Profile & stories →</a>
        </section>
      <?php endif; ?>
      <?php if ($related): ?>
        <section class="panel reveal">
          <header class="panel__head"><span class="kicker">Related</span><span class="mono panel__hint"><?= e($story['category']) ?></span></header>
          <div class="minis"><?php foreach ($related as $r): ?><?= Ui::card($r, 'mini') ?><?php endforeach; ?></div>
        </section>
      <?php endif; ?>
      <section class="panel reveal">
        <header class="panel__head"><span class="kicker">Latest</span><a class="mono panel__hint" href="/">Around Me →</a></header>
        <div class="minis"><?php foreach ($more as $r): ?><?= Ui::card($r, 'mini') ?><?php endforeach; ?></div>
      </section>
      <?php if ($ads): ?>
        <section class="panel panel--ads reveal"><header class="panel__head"><span class="kicker">Local businesses</span><a class="mono panel__hint" href="/advertise">Advertise →</a></header>
          <?php foreach ($ads as $ad): ?><?= \MeNews\Services\Ads::render($ad, 'sidebar') ?><?php endforeach; ?>
        </section>
      <?php endif; ?>
    </aside>
  </div>
</article>
