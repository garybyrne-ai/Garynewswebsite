<?php /** Notice card. Expects $n (Notices::present) */ $x = $n['extra']; ?>
<article class="noticecard noticecard--<?= e($n['kind']) ?> <?= $n['promoted'] ? 'is-promoted' : '' ?> reveal">
  <span class="noticecard__kind mono"><?= icon($n['icon']) ?> <?= e($n['kind_label']) ?><?= $n['promoted'] ? ' · Promoted' : '' ?><?php if ($n['kind'] === 'pet' && !empty($x['pet_status'])): ?> · <?= e(ucfirst($x['pet_status'])) ?><?php endif; ?></span>
  <h3><a href="<?= e($n['url']) ?>"><?= e($n['title']) ?></a></h3>
  <p class="noticecard__where"><?= icon('pin') ?> <?= e(($n['town'] ? $n['town'] . ', ' : '') . 'Co. ' . $n['county']) ?></p>
  <?php if ($n['kind'] === 'death'): ?>
    <?php if ($n['date_of_death']): ?><p class="noticecard__line"><b>Died</b> <?= e(date('j F Y', strtotime($n['date_of_death']) ?: time())) ?></p><?php endif; ?>
    <?php if ($n['reposing']): ?><p class="noticecard__line"><b>Reposing</b> <?= e(excerpt($n['reposing'], 120)) ?></p><?php endif; ?>
    <?php if ($n['funeral_at']): ?><p class="noticecard__line"><b>Funeral</b> <?= e(date_irish($n['funeral_at'], 'l j F, H:i')) ?><?= $n['funeral_venue'] ? ', ' . e($n['funeral_venue']) : '' ?></p><?php endif; ?>
  <?php elseif ($n['kind'] === 'event'): ?>
    <p class="noticecard__line"><b><?= e(date_irish($n['event_at'], 'D j M · H:i')) ?></b><?= $n['venue'] ? ' · ' . e($n['venue']) : '' ?><?= $n['price'] ? ' · ' . e($n['price']) : '' ?></p>
  <?php elseif ($n['kind'] === 'result' && !empty($x['home'])): ?>
    <p class="noticecard__score"><span><?= e($x['home']) ?></span><b><?= e($x['home_score'] ?? '') ?> – <?= e($x['away_score'] ?? '') ?></b><span><?= e($x['away']) ?></span></p>
    <?php if (!empty($x['competition'])): ?><p class="noticecard__line mono"><?= e($x['competition']) ?></p><?php endif; ?>
  <?php elseif ($n['kind'] === 'job'): ?>
    <p class="noticecard__line"><b><?= e($n['contact_org'] ?: 'Local employer') ?></b><?= $n['price'] ? ' · ' . e($n['price']) : '' ?></p>
  <?php endif; ?>
  <?php if ($n['body']): ?><p class="noticecard__body"><?= e(excerpt($n['body'], $n['kind'] === 'death' ? 140 : 180)) ?></p><?php endif; ?>
  <footer class="noticecard__foot mono"><span><?= e($n['contact_org'] ?: ($n['contact_name'] ?: 'Community')) ?></span><time datetime="<?= e($n['published_at']) ?>"><?= e(time_ago($n['published_at'])) ?></time></footer>
</article>
