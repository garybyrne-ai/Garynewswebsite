<section class="container pagehead pagehold pagehead--center">
  <span class="chip chip--plus">ME+</span>
  <h1 class="pagehead__title">Ad-free, and every place <em>you love, first.</em></h1>
  <p class="pagehead__blurb">ME+ is membership, not a paywall: everything stays free to read. Members get an ad-free site, alerts for up to ten towns and counties, the 7am email for each of them, the full archive and a members' county newsletter, and they fund the council and court reporting nobody else does.</p>
</section>
<div class="container plans">
  <div class="plan reveal">
    <span class="kicker">Free</span>
    <div class="price"><b>€0</b><small>forever</small></div>
    <ul><li>Every story, notice, alert and poll</li><li>Report, comment and “I saw this too”</li><li>Follow one town or county</li><li>The 7am email for one county</li><li>Advertising supported</li></ul>
    <button class="btn btn--ghost btn--block" type="button" data-open-auth="register">Create free account</button>
  </div>
  <div class="plan plan--plus reveal">
    <span class="chip chip--plus">ME+</span>
    <div class="price"><b><?= e($priceLabel) ?></b><small>or <?= e($annualLabel) ?> · cancel any time</small></div>
    <ul><?php foreach ($benefits as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul>
    <div class="form__actions" style="justify-content:stretch;flex-direction:column">
      <a class="btn btn--primary btn--block" href="/dashboard?upgrade=month#membership">Monthly · <?= e($priceLabel) ?></a>
      <a class="btn btn--ghost btn--block" href="/dashboard?upgrade=year#membership">Annual · <?= e($annualLabel) ?> <span class="mono" style="margin-left:6px;color:var(--em)">two months free</span></a>
    </div>
    <p class="form__legal">Secure checkout via Stripe. Your card details never touch ME servers. Prices set by the newsroom and shown here before you pay.</p>
  </div>
</div>
<div class="container prose" style="padding-bottom:40px">
  <h2>Where the money goes</h2>
  <p>Membership pays for the boring, essential local layer: someone reading council minutes for your county every month, court reports done properly and legally, and the editors who check community reports before they go live. Advertising covers the servers; members cover the journalism. Our <a href="/ownership">ownership and funding page</a> says who we are.</p>
</div>
