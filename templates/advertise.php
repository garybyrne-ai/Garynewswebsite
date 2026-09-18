<?php use MeNews\Services\Ads; $pr = $pricing; $gateways = ($pr['stripe'] ? 1 : 0) + ($pr['paypal'] ? 1 : 0); ?>
<section class="container pagehead pagehead--center">
  <span class="kicker">Advertise with ME</span>
  <h1 class="pagehead__title">Buy impressions. Design your ad. <em>Be seen across Ireland.</em></h1>
  <p class="pagehead__blurb">Pick a package, pay once, and the designer unlocks. Your ad runs in sidebars and banners until every impression you bought has been shown, paced over the month so it is seen day after day. No subscription, no surprises.</p>
  <?php if ($cancelled): ?><p class="form__result is-error" style="text-align:center">Checkout was cancelled — nothing was charged.</p><?php endif; ?>
  <?php if ($topup): ?><p class="form__result" style="text-align:center">Topping up <b><?= e($topup['business_name']) ?></b> — the package you buy is added straight to that advert.</p><?php endif; ?>
  <?php if ($credits): ?><p class="form__result" style="text-align:center">You have <?= count($credits) ?> paid package<?= count($credits) === 1 ? '' : 's' ?> waiting for a design. <a href="/dashboard#advertising">Open the designer →</a></p><?php endif; ?>
</section>

<div class="container">
  <div class="packages" id="packages" data-topup="<?= e($topup['id'] ?? '') ?>">
    <?php foreach ($packages as $i => $p): ?>
      <article class="package <?= $p['tier'] === 'premium' ? 'package--premium' : ($p['badge'] ? 'package--featured' : '') ?> reveal" data-package="<?= e($p['id']) ?>" style="--i:<?= $i ?>">
        <?php if ($p['badge']): ?><span class="package__badge"><?= e($p['badge']) ?></span><?php endif; ?>
        <span class="kicker"><?= e($p['tier_label']) ?></span>
        <h2><?= e($p['name']) ?></h2>
        <p class="package__tag"><?= e($p['tagline']) ?></p>
        <div class="price"><b><?= e($p['price_label']) ?></b><small>once</small></div>
        <div class="package__imp"><b><?= number_format((int)$p['impressions']) ?></b> impressions <span class="mono">· <?= e($p['per_thousand']) ?> per 1,000</span></div>
        <ul><?php foreach ($p['features'] as $f): ?><li><?= e($f) ?></li><?php endforeach; ?></ul>
        <div class="package__buy">
          <?php if (!$user): ?>
            <button class="btn btn--primary btn--block" type="button" data-open-auth="register">Create an account to buy</button>
          <?php elseif ($gateways === 0): ?>
            <span class="form__legal">Online payment is being connected. Email the newsroom and we can set your package up manually.</span>
          <?php else: ?>
            <?php if ($pr['stripe']): ?><button class="btn btn--primary btn--block" type="button" data-buy="<?= e($p['id']) ?>" data-gateway="stripe">Pay by card · <?= e($p['price_label']) ?></button><?php endif; ?>
            <?php if ($pr['paypal']): ?><button class="btn btn--ghost btn--block" type="button" data-buy="<?= e($p['id']) ?>" data-gateway="paypal">PayPal</button><?php endif; ?>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <div class="adv-demo reveal">
    <div>
      <span class="kicker">Sidebar card</span>
      <div class="adv-demo__side"><?= Ads::render($demo, 'sidebar', true) ?></div>
    </div>
    <div>
      <span class="kicker">Banner · sections, articles and (Front Page package) the home page</span>
      <div class="adv-demo__banner"><?= Ads::render($demo, 'banner', true) ?></div>
      <p class="panel__note">Six templates, your colours, your logo or photo. Every ad is reviewed by the newsroom before it goes live and is always labelled Sponsored. Members on ME+ read ad-free, so every impression you buy lands on a reader who sees adverts.</p>
    </div>
  </div>

  <div class="steps" style="margin:34px 0">
    <div class="step reveal"><span class="mono">01</span><h3>Choose a package</h3><p>Pay once by card<?= $pr['paypal'] ? ' or PayPal' : '' ?>. Your package is credited to your account the moment payment clears.</p></div>
    <div class="step reveal"><span class="mono">02</span><h3>Design</h3><p>The designer unlocks: pick a template, add your headline, offer, logo and colours, and target Ireland, a county or a town.</p></div>
    <div class="step reveal"><span class="mono">03</span><h3>Review</h3><p>An editor checks it against the guidelines below, usually within a day. Advertising never touches editorial decisions.</p></div>
    <div class="step reveal"><span class="mono">04</span><h3>Run</h3><p>Impressions are paced across a month and counted live in your dashboard. When they are used up, top up the same ad with another package.</p></div>
  </div>

  <div class="plans" style="padding-top:0">
    <div class="plan reveal">
      <span class="kicker">Where each tier appears</span>
      <ul>
        <?php foreach ($tiers as $k => $t): ?><li><b><?= e($t['label']) ?></b> — <?= e($t['blurb']) ?></li><?php endforeach; ?>
        <li>Never in the kids' section, never for ME+ members, and never inside editorial text.</li>
      </ul>
      <p class="form__legal"><?= (int)$liveCount ?> advert<?= $liveCount === 1 ? '' : 's' ?> running right now. Prices include VAT where applicable. Impressions are counted server-side each time your ad is placed on a page.</p>
    </div>
    <div class="plan reveal">
      <span class="kicker">Guidelines</span>
      <ul>
        <li>Be honest and specific: what you offer, where, from how much.</li>
        <li>Your own business only — no affiliate links or redirects.</li>
        <li>No gambling, tobacco, vapes, adult content or political ads.</li>
        <li>Prices in euro, Irish English, no misleading urgency.</li>
        <li>Landing page must work on a phone.</li>
      </ul>
      <p class="panel__note">Need an invoice or a bank transfer? The newsroom can credit a package to your account manually.</p>
    </div>
  </div>
</div>
<?php $extraScripts = <<<'JS'
<script>
(function () {
  var wrap = document.getElementById('packages'); if (!wrap) return;
  wrap.addEventListener('click', function (e) {
    var b = e.target.closest('[data-buy]'); if (!b) return;
    var buttons = wrap.querySelectorAll('[data-buy]'); buttons.forEach(function (x) { x.disabled = true; });
    var label = b.textContent; b.textContent = 'Opening secure checkout…';
    var fd = new FormData(); fd.append('gateway', b.dataset.gateway); if (wrap.dataset.topup) fd.append('ad_id', wrap.dataset.topup);
    window.ME.api('/api/ads/packages/' + b.dataset.buy + '/checkout', { method: 'POST', body: fd })
      .then(function (j) { location.href = j.url; })
      .catch(function (err) { window.ME.toast(err.message); b.textContent = label; buttons.forEach(function (x) { x.disabled = false; }); });
  });
})();
</script>
JS; ?>
