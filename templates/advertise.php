<?php use MeNews\Services\Ads; $pr = $pricing; ?>
<section class="container pagehead pagehead--center">
  <span class="kicker">Advertise with ME</span>
  <h1 class="pagehead__title">Your business, in front of <em>Ireland.</em></h1>
  <p class="pagehead__blurb">Design your own ad in minutes. It runs in the sidebar and as a banner on the home page, section pages and inside every article — targeted to your county or town if you like. <b><?= e($pr['price_label']) ?></b>, after a <b><?= (int)$pr['trial_days'] ?>-day free trial</b>. Cancel any time.</p>
  <div class="form__actions" style="justify-content:center;margin-top:8px">
    <?php if ($user): ?><a class="btn btn--primary" href="/dashboard#advertising">Design your ad →</a><?php else: ?><button class="btn btn--primary" type="button" data-open-auth="register">Create an account &amp; design your ad →</button><button class="btn btn--ghost" type="button" data-open-auth="signin">Sign in</button><?php endif; ?>
  </div>
</section>

<div class="container">
  <div class="adv-demo reveal">
    <div>
      <span class="kicker">Sidebar card</span>
      <div class="adv-demo__side"><?= Ads::render($demo, 'sidebar', true) ?></div>
    </div>
    <div>
      <span class="kicker">Banner · home, sections &amp; articles</span>
      <div class="adv-demo__banner"><?= Ads::render($demo, 'banner', true) ?></div>
      <p class="panel__note">Six templates, your colours, your logo. Every ad is reviewed by the newsroom before it goes live and is always labelled Sponsored.</p>
    </div>
  </div>

  <div class="steps" style="margin:34px 0">
    <div class="step reveal"><span class="mono">01</span><h3>Design</h3><p>Pick a template, add your headline, offer, logo and colours. See it live as a card and a banner while you type.</p></div>
    <div class="step reveal"><span class="mono">02</span><h3>Review</h3><p>An editor checks it against our guidelines — usually within a day. Advertising never touches editorial decisions.</p></div>
    <div class="step reveal"><span class="mono">03</span><h3>Free trial</h3><p>Approved ads go live at once for <?= (int)$pr['trial_days'] ?> days, free. Watch impressions and clicks in your dashboard.</p></div>
    <div class="step reveal"><span class="mono">04</span><h3>Subscribe</h3><p>Keep it running for <?= e($pr['price_label']) ?> by card<?= $pr['paypal'] ? ' or PayPal' : '' ?>. Pause or cancel whenever you like.</p></div>
  </div>

  <div class="plans" style="padding-top:0">
    <div class="plan plan--plus reveal">
      <span class="chip chip--plus">ME Ads</span>
      <div class="price"><b><?= e($pr['price_label']) ?></b><small><?= (int)$pr['trial_days'] ?>-day free trial</small></div>
      <ul>
        <li>Sidebar card on the home page, sections, counties and articles</li>
        <li>Banner on the home page, section pages and inside articles</li>
        <li>Target all of Ireland, one county or one town</li>
        <li>Self-serve designer with six templates, logo and photo</li>
        <li>Live impressions, clicks and click-through rate</li>
        <li>Pay by card (Stripe)<?= $pr['paypal'] ? ' or PayPal' : '' ?> · cancel any time</li>
      </ul>
      <?php if ($user): ?><a class="btn btn--primary btn--block" href="/dashboard#advertising">Start your free trial</a><?php else: ?><button class="btn btn--primary btn--block" type="button" data-open-auth="register">Start your free trial</button><?php endif; ?>
      <p class="form__legal"><?= (int)$liveCount ?> advert<?= $liveCount === 1 ? '' : 's' ?> running right now. Ads never appear in the kids' section. Prices include VAT where applicable.</p>
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
      <p class="panel__note">Questions or an invoice for a longer run? The newsroom can activate an ad manually for bank-transfer customers.</p>
    </div>
  </div>
</div>
