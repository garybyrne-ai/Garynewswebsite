<?php use MeNews\Ui; ?>
<section class="container pagehead pagehead--mysteries">
  <span class="kicker">Mystery Files</span>
  <h1 class="pagehead__title"><span class="pagehead__icon"><?= icon('magnifier') ?></span>Unsolved cases</h1>
  <p class="pagehead__blurb">Cold cases and unsolved crimes from across India — cases the original publishers themselves have reported as still open.</p>
</section>
<div class="container" style="padding-top:0">
  <div class="corner-policy"><?= icon('shield') ?><span><b>How this section works:</b> every story here comes straight from the wire — The Times of India, The Hindu, Hindustan Times and the rest of our sources — and only appears when the publisher's own reporting frames the case as unsolved, cold or still under investigation. We don't write, summarise or speculate about any case ourselves, and we never name a suspect who hasn't been charged. If a case is later solved, it drops out of this list on its own.</span></div>
</div>
<?php if (!empty($banner)): ?><div class="container adslot adslot--top"><?= \MeNews\Services\Ads::slot($banner, 'banner') ?></div><?php endif; ?>
<div class="container layout">
  <div class="layout__main">
    <?php if ($cases): ?>
      <div class="grid grid--3">
        <?php foreach ($cases as $i => $s): ?><?= Ui::card($s, $i === 0 ? 'feature' : 'standard', $i) ?><?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty">
        <div class="empty__glyph"><?= icon('magnifier') ?></div>
        <h3>No open cases on the wire right now.</h3>
        <p>This list only fills up when a publisher we carry actively reports a case as unsolved or cold — that doesn't happen every day, so an empty page here is a good sign, not a broken one. Check back, or <a href="/section/national">browse National news</a> in the meantime.</p>
      </div>
    <?php endif; ?>
  </div>
  <?= \MeNews\View::partial('partials/sidebar', ['trending' => $trending ?? [], 'ads' => $ads ?? [], 'county' => null]) ?>
</div>
