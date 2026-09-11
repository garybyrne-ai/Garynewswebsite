<section class="container pagehead pagehead--narrow">
  <span class="kicker">Corrections</span>
  <h1 class="pagehead__title">When we get it wrong, <em>we say so.</em></h1>
  <p class="pagehead__blurb">Every correction we make to a published story is logged here, in the open, with the date and what changed.</p>
</section>
<div class="container prose">
  <h2>Our policy</h2>
  <p><b>Errors of fact are corrected as soon as we know about them.</b> The story is updated, a dated note is added to the foot of the story, and the change is recorded in the log below. We do not quietly edit.</p>
  <p><b>Wire headlines belong to their publishers.</b> If a publisher corrects a story we picked up, we update our headline and summary to match and link to their correction.</p>
  <p><b>Community reports are labelled, not laundered.</b> A report that turns out to be wrong is corrected or withdrawn, the contributor is told why, and repeated inaccuracy lowers a contributor’s standing.</p>
  <p><b>Tell us.</b> Email <a href="/ownership#contact">the editors</a> or use the <a href="/moderation#takedown">removal and correction form</a>. We aim to reply within two working days, faster for anything that names a person.</p>
  <p>ME News Ireland intends to apply for membership of the Press Council of Ireland and to abide by the Code of Practice of the Office of the Press Ombudsman. Until then, complaints are handled by the managing editor under the process above.</p>

  <h2>Corrections log</h2>
  <?php if ($log): ?>
    <div class="tablewrap"><table class="table"><thead><tr><th>Date</th><th>Story</th><th>Correction</th></tr></thead><tbody>
      <?php foreach ($log as $c): ?><tr><td class="mono"><?= e(date_irish($c['created_at'], 'j M Y')) ?></td><td><?= $c['slug'] ? '<a href="/story/' . e($c['slug']) . '">' . e($c['story_title'] ?: $c['title']) . '</a>' : e($c['title']) ?></td><td><?= e($c['summary']) ?><?= $c['detail'] ? '<br><small style="color:var(--muted)">' . e($c['detail']) . '</small>' : '' ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  <?php else: ?>
    <p>No corrections have been needed yet. When one is, it will appear here the same day.</p>
  <?php endif; ?>
</div>
