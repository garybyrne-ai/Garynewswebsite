<?php use MeNews\Ui; use MeNews\Support\Categories;
$isWire = $story['kind'] === 'wire';
$jsonld = [
    '@context' => 'https://schema.org', '@type' => 'NewsArticle', 'headline' => $story['title'],
    'datePublished' => $story['time'], 'dateModified' => $story['updated_at'] ?: $story['time'],
    'description' => $story['summary'], 'image' => $story['image'] ? [$story['image']] : [],
    'author' => ['@type' => 'Person', 'name' => $story['author_name']],
    'publisher' => ['@type' => 'Organization', 'name' => 'ME News Ireland'],
    'mainEntityOfPage' => absolute_url($story['url']),
];
if ($isWire) { $jsonld['isBasedOn'] = $story['source_url']; }
?>
<div class="progress" aria-hidden="true"><i id="read-progress"></i></div>
<script type="application/ld+json"><?= json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>

<article class="container article" data-story-id="<?= e($story['id']) ?>">
  <header class="article__head">
    <nav class="crumbs mono" aria-label="Breadcrumb"><a href="/">Around Me</a><span>/</span><a href="/section/<?= e($story['category_slug']) ?>"><?= e($story['category']) ?></a><?php if ($story['county']): ?><span>/</span><a href="/county/<?= e(slugify($story['county'])) ?>">Co. <?= e($story['county']) ?></a><?php endif; ?></nav>
    <div class="article__labels"><?= Ui::label($story['verification_label']) ?><?= Ui::categoryChip($story['category']) ?><?= $isWire ? '<span class="chip chip--src">Wire · ' . e($story['source_name']) . '</span>' : '<span class="chip chip--src chip--community">Community report</span>' ?></div>
    <h1 class="article__title"><?= e($story['title']) ?></h1>
    <?php if ($story['summary']): ?><p class="article__standfirst"><?= e($story['summary']) ?></p><?php endif; ?>
    <div class="article__byline">
      <?php if ($author): ?>
        <a class="byline" href="<?= !empty($author['handle']) ? '/contributors/' . e($author['handle']) : '#' ?>"><?= Ui::avatar($author, 'md') ?><span><b><?= e($author['display_name']) ?></b><small><?= e($author['title'] ?: ($story['kind'] === 'community' ? 'Community reporter' : 'ME News')) ?><?= $isWire ? ' · filed to the ' . e($author['desk'] ?: $story['category']) . ' desk' : '' ?></small></span></a>
      <?php else: ?>
        <span class="byline"><?= Ui::avatar(null, 'md', $story['author_name'] ?: 'ME') ?><span><b><?= e($story['author_name'] ?: 'Community reporter') ?></b></span></span>
      <?php endif; ?>
      <div class="article__time mono">
        <time datetime="<?= e($story['time']) ?>"><?= e(date_irish($story['time'])) ?></time>
        <span>·</span><span><?= e(time_ago($story['time'])) ?></span>
        <span>·</span><span><?= e(compact_number((int)$story['views'])) ?> views</span>
        <?php if ($story['location_name']): ?><span>·</span><span>◎ <?= e($story['location_name']) ?><?= $story['county'] && !str_contains((string)$story['location_name'], $story['county']) ? ', Co. ' . e($story['county']) : '' ?></span><?php endif; ?>
      </div>
    </div>
  </header>

  <div class="article__grid">
    <div class="article__main">
      <?php if ($story['image']): ?>
        <figure class="article__media" style="--h:<?= Ui::hue($story['title']) ?>">
          <img src="<?= e($story['image']) ?>" alt="<?= e($story['image_credit'] ?: $story['title']) ?>" referrerpolicy="no-referrer" onerror="this.closest('figure').classList.add('is-broken')">
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
            <p>This headline was picked up by the ME wire from <b><?= e($story['source_name']) ?></b><?= $story['source_author'] ? ' (reporting by ' . e($story['source_author']) . ')' : '' ?> and filed to our <?= e($story['category']) ?> desk by <?= e($story['author_name']) ?>. The complete article, photographs and any updates are published by <?= e($story['source_name']) ?>.</p>
            <a class="btn btn--primary" href="<?= e($story['source_url']) ?>" target="_blank" rel="noopener">Continue reading at <?= e($story['source_name']) ?> ↗</a>
            <span class="mono sourcebox__url"><?= e(parse_url($story['source_url'], PHP_URL_HOST)) ?></span>
          </div>
        </div>
      <?php else: ?>
        <div class="article__body"><?= nl2br(e($story['body'])) ?></div>
        <?php if ($story['editorial_note']): ?><div class="editornote"><span class="kicker">Editor's note</span><p><?= e($story['editorial_note']) ?></p></div><?php endif; ?>
      <?php endif; ?>

      <section class="trust">
        <header class="trust__head"><span class="kicker">ME Trust Engine</span><span class="mono"><?= e($story['verification_label']) ?> — editorial label</span></header>
        <div class="trust__meters">
          <div><span class="mono">Safety</span><div class="meter"><i style="--v:<?= (int)$story['safety_score'] ?>"></i></div><b class="mono"><?= (int)$story['safety_score'] ?>/100</b></div>
          <div><span class="mono">Confidence</span><div class="meter meter--alt"><i style="--v:<?= (int)$story['trust_score'] ?>"></i></div><b class="mono"><?= (int)$story['trust_score'] ?>/100</b></div>
          <div><span class="mono">Confirmations</span><b class="trust__big" id="confirm-count"><?= (int)$confirmations ?></b></div>
        </div>
        <p class="trust__note"><?= $isWire ? 'Wire stories come from established Irish publishers and carry their own editorial standards. ME labels them <b>Verified</b> because the source is known and linked, not because ME independently re-reported them.' : 'Safety is not truth. The safety score screens for harmful content; the confidence score weighs evidence such as media, GPS and reporter track record. Only editors set the public label.' ?></p>
        <div class="trust__actions">
          <button class="btn btn--ghost" type="button" data-confirm="<?= e($story['id']) ?>"><?= $isWire ? 'I can add local context' : 'I can independently confirm this' ?></button>
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
      <?php if ($author && in_array($author['role'] ?? '', ['contributor', 'editor', 'admin'], true)): ?>
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
