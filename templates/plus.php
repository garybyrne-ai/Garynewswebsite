<?php $user = $user ?? null; ?>
<section class="container pagehead pagehead--center">
  <span class="chip chip--plus">ME+ membership</span>
  <h1 class="pagehead__title">Ad-free, and every place <em>you love, first.</em></h1>
  <p class="pagehead__blurb">ME+ is membership, not a paywall: everything today stays free to read. Members read without adverts, follow up to ten towns and counties, keep the full archive, and fund the council and court reporting nobody else does. <b><?= e($priceLabel) ?></b> or <b><?= e($annualLabel) ?></b>, cancel any time.</p>
  <?php if ($isPlus): ?>
    <p class="form__result" style="text-align:center">You are an ME+ member — thank you. <a href="/dashboard#membership">Manage your membership</a> · <a href="/dashboard#follows">Set your ten areas</a></p>
  <?php endif; ?>
</section>

<div class="container plans" id="plans">
  <div class="plan reveal">
    <span class="kicker">Free</span>
    <div class="price"><b>€0</b><small>forever</small></div>
    <ul><li>Every story, notice, alert and poll from the last <?= (int)$archiveDays ?> days</li><li>Report, comment and “I saw this too”</li><li>Follow one town or county</li><li>Email alerts and the 7am email for one county</li><li>Advertising supported</li></ul>
    <?php if (!$user): ?><button class="btn btn--ghost btn--block" type="button" data-open-auth="register">Create free account</button><?php else: ?><a class="btn btn--ghost btn--block" href="/dashboard">Your dashboard</a><?php endif; ?>
  </div>
  <div class="plan plan--plus reveal">
    <span class="chip chip--plus">ME+</span>
    <div class="price"><b><?= e($priceLabel) ?></b><small>or <?= e($annualLabel) ?> · cancel any time</small></div>
    <ul><?php foreach ($benefits as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul>
    <div class="form__actions" style="justify-content:stretch;flex-direction:column">
      <?php if ($isPlus): ?>
        <a class="btn btn--primary btn--block" href="/dashboard#membership">ME+ is active · manage</a>
      <?php elseif (!$user): ?>
        <button class="btn btn--primary btn--block" type="button" data-open-auth="register" data-next="/dashboard?upgrade=month#membership">Join monthly · <?= e($priceLabel) ?></button>
        <button class="btn btn--ghost btn--block" type="button" data-open-auth="register" data-next="/dashboard?upgrade=year#membership">Join annual · <?= e($annualLabel) ?> <span class="mono" style="margin-left:6px;color:var(--em)">two months free</span></button>
      <?php else: ?>
        <a class="btn btn--primary btn--block" href="/dashboard?upgrade=month#membership">Monthly · <?= e($priceLabel) ?></a>
        <a class="btn btn--ghost btn--block" href="/dashboard?upgrade=year#membership">Annual · <?= e($annualLabel) ?> <span class="mono" style="margin-left:6px;color:var(--em)">two months free</span></a>
      <?php endif; ?>
    </div>
    <p class="form__legal"><?= $stripe ? 'Secure checkout via Stripe. Your card details never touch ME servers.' : 'Online payment is being connected — email the newsroom and we will set you up.' ?> Prices are set by the newsroom and shown here before you pay.</p>
  </div>
</div>

<section class="container" style="padding-bottom:30px">
  <header class="block__head"><h2 class="block__title">What membership actually does</h2><p class="block__blurb">Every perk is switched on the moment payment clears, and switched off if you cancel. Nothing here is a promise for later.</p></header>
  <div class="perks">
    <div class="perk reveal"><span class="perk__icon"><?= icon('signal') ?></span><h3>No adverts, anywhere</h3><p>Sidebar cards, banners and the client-side slots all disappear for members on every page, on every device you are signed in on.</p></div>
    <div class="perk reveal"><span class="perk__icon"><?= icon('pin') ?></span><h3>Ten places, not one</h3><p>Follow up to ten towns and counties for editor-published reports, and get death notices, school closures and Met Éireann warnings by email for up to ten counties.</p></div>
    <div class="perk reveal"><span class="perk__icon"><?= icon('national') ?></span><h3>The full archive</h3><p>Non-members see the last <?= (int)$archiveDays ?> days in sections and search. Members read every community report and wire headline we have ever published.</p></div>
    <div class="perk reveal"><span class="perk__icon"><?= icon('star') ?></span><h3>Members' monthly</h3><p>On the first of the month you get your county's most-read and most-confirmed reports, who reported them, and what is coming up.</p></div>
    <div class="perk reveal"><span class="perk__icon"><?= icon('plus') ?></span><h3>A member badge</h3><p>An ME+ mark beside your name on the reports you file and the comments you leave, so neighbours know who keeps the lights on.</p></div>
    <div class="perk reveal"><span class="perk__icon"><?= icon('layers') ?></span><h3>Funds real reporting</h3><p>Membership pays for council minutes read every month, court reports done properly, and editors checking community reports before they go live.</p></div>
  </div>
</section>

<section class="container" style="padding-bottom:30px">
  <header class="block__head"><h2 class="block__title">Free vs ME+</h2></header>
  <div class="tablewrap"><table class="table compare">
    <thead><tr><th>What you get</th><th>Free</th><th><span class="chip chip--plus">ME+</span></th></tr></thead>
    <tbody>
      <tr><td>Read every story, notice, alert and poll</td><td>✓</td><td>✓</td></tr>
      <tr><td>Report, comment, confirm and vote in the Signal</td><td>✓</td><td>✓</td></tr>
      <tr><td>Adverts</td><td>Shown</td><td><b>None</b></td></tr>
      <tr><td>Followed towns and counties</td><td>1</td><td><b>10</b></td></tr>
      <tr><td>Email alert counties (deaths, closures, warnings, 7am email)</td><td>1</td><td><b>10</b></td></tr>
      <tr><td>Archive</td><td>Last <?= (int)$archiveDays ?> days</td><td><b>Everything</b></td></tr>
      <tr><td>Members' monthly county newsletter</td><td>—</td><td><b>✓</b></td></tr>
      <tr><td>ME+ badge on reports and comments</td><td>—</td><td><b>✓</b></td></tr>
      <tr><td>Price</td><td>€0</td><td><b><?= e($priceLabel) ?></b> or <?= e($annualLabel) ?></td></tr>
    </tbody>
  </table></div>
</section>

<section class="container" style="padding-bottom:50px">
  <header class="block__head"><h2 class="block__title">Questions</h2><?php if ($memberCount >= 25): ?><span class="mono"><?= (int)$memberCount ?> members and counting</span><?php endif; ?></header>
  <div class="faq">
    <details open><summary>Is this a paywall?</summary><p>No. Today's news, notices, alerts and polls stay free for everyone. Membership removes adverts, widens what you can follow, opens the archive and funds the reporting.</p></details>
    <details><summary>Can I cancel?</summary><p>Any time, from your dashboard or the Stripe billing portal link in your receipt. Perks stay on until the end of the period you paid for.</p></details>
    <details><summary>Where does the money go?</summary><p>Advertising covers the servers; members cover the journalism: someone reading council minutes for your county every month, court reports done properly and legally, and the editors who check community reports before they go live. Our <a href="/ownership">ownership and funding page</a> says who we are.</p></details>
    <details><summary>Do I need a separate account?</summary><p>No. ME+ attaches to the free account you already use to report and comment. Sign in, choose monthly or annual, and the perks switch on when Stripe confirms payment.</p></details>
    <details><summary>Can a business buy ME+ to advertise?</summary><p>Membership and advertising are separate on purpose. Adverts are sold as <a href="/advertise">impression packages</a> and reviewed by the newsroom; membership never buys coverage.</p></details>
  </div>
</section>
