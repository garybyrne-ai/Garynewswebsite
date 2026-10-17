<?php
/** Shared "trending searches" list. Required: $trends (Services\Trends::forState() rows). Optional: $county, $limit. */
$limit = $limit ?? 6;
?>
<ol class="trendlist">
  <?php foreach (array_slice($trends, 0, $limit) as $i => $tr): ?>
    <li>
      <span class="trendlist__n mono"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
      <div class="trendlist__body">
        <div class="trendlist__q"><a href="<?= e($tr['internal_url']) ?>"><b><?= e($tr['query']) ?></b></a><?php if ($tr['traffic']): ?><span class="trendlist__traffic mono"><?= e($tr['traffic']) ?></span><?php endif; ?></div>
        <?php if ($tr['news_title']): ?><a class="trendlist__news" href="<?= e($tr['internal_url']) ?>"><?= e(excerpt($tr['news_title'], 70)) ?></a><?php endif; ?>
        <?php if ($tr['news_url'] && $tr['news_source']): ?><a class="trendlist__source" href="<?= e($tr['news_url']) ?>" target="_blank" rel="noopener">Originally: <?= e($tr['news_source']) ?> ↗</a><?php endif; ?>
      </div>
      <?php if ($i === 0 && $tr['picture']): ?><img class="trendlist__pic" src="<?= e($tr['picture']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php endif; ?>
    </li>
  <?php endforeach; ?>
</ol>
