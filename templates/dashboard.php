<?php use MeNews\Ui; ?>
<div class="container appshell">
  <nav class="appnav" aria-label="Dashboard">
    <div class="appnav__head"><?= Ui::avatar($user, 'sm') ?><span><b><?= e($user['display_name']) ?></b><small><?= e($user['plan'] === 'ME+' ? 'ME+ member' : 'Free member') ?></small></span></div>
    <button class="is-active" data-view="overview">Overview</button>
    <button data-view="reports">My reports <span class="badge" id="nav-reports-count">0</span></button>
    <button data-view="profile">Reporter profile</button>
    <button data-view="follows">Followed areas</button>
    <button data-view="membership">ME+ membership</button>
    <button data-view="advertising">Local advertising</button>
    <button data-view="notifications">Notifications <span class="badge" id="nav-notif-count" hidden>0</span></button>
    <button data-view="security">Security</button>
  </nav>
  <section>
    <div id="view-overview" class="view is-active">
      <span class="kicker">My ME News</span>
      <h1 id="hello">Hello</h1>
      <p class="pagehead__blurb">Track your reports, reputation, membership and local activity.</p>
      <div class="stats" id="stats"></div>
      <div class="panel"><h2>How reporting works</h2><p style="margin:8px 0">Every submission goes into private quarantine first. The Trust Engine screens it for safety, then the newsroom decides whether to publish, hold or reject it and which editorial label applies.</p><div class="trustnote">Safety score ≠ truth. Confidence score ≠ verification. Verification labels remain editorial decisions.</div><button class="btn btn--hot" type="button" data-open-report><span class="livedot livedot--white"></span> Report a story</button></div>
    </div>

    <div id="view-reports" class="view"><h1>My reports</h1><div id="report-table"></div></div>

    <div id="view-profile" class="view">
      <h1>Reporter profile</h1>
      <form id="profile-form" class="form panel">
        <label>Display name<input name="display_name" id="p-name" required minlength="2"></label>
        <div class="form__row">
          <label>Home town<input name="home_town" id="p-town" list="all-locations" autocomplete="off"></label>
          <label>Home county<select name="home_county" id="p-county" data-county-select><option value="">Select county</option><?php foreach ($counties as $c): ?><option><?= e($c) ?></option><?php endforeach; ?></select></label>
        </div>
        <label>Bio<textarea name="bio" id="p-bio" maxlength="500" rows="3"></textarea></label>
        <div class="form__actions"><button class="btn btn--primary" type="submit">Save profile</button></div>
      </form>
    </div>

    <div id="view-follows" class="view">
      <h1>Followed areas</h1>
      <p class="pagehead__blurb">Local alerts arrive when an editor publishes a report in a place you follow. <span class="mono" id="follow-hint"></span></p>
      <form id="follow-form" class="form panel">
        <div class="form__row">
          <label>Town / area<input name="location_name" list="all-locations" placeholder="e.g. Bray" autocomplete="off"></label>
          <label>County<select name="county" data-county-select><option value="">Any county</option><?php foreach ($counties as $c): ?><option><?= e($c) ?></option><?php endforeach; ?></select></label>
        </div>
        <div class="form__actions"><button class="btn btn--primary" type="submit">Follow area</button></div>
      </form>
      <div class="panel" id="follow-list"></div>
    </div>

    <div id="view-membership" class="view">
      <div class="panel panel--plus">
        <span class="chip chip--plus">ME+</span>
        <h2 style="margin-top:10px">ME+ membership</h2>
        <p>Follow up to ten areas, receive local alerts first, fewer adverts and a member badge.</p>
        <div class="price"><b><?= e(\MeNews\Config::get('ME_PLUS_PRICE_LABEL', '€6.99/month')) ?></b></div>
        <p id="plan-state"></p>
        <div class="form__actions" style="justify-content:flex-start;margin-top:12px"><button class="btn btn--primary" id="checkout-btn" type="button">Upgrade with Stripe</button><a class="btn btn--ghost" href="/plus">Compare plans</a></div>
        <p class="form__legal">Stripe Checkout becomes live when the Stripe keys and the ME+ price are configured in .env.</p>
      </div>
    </div>

    <div id="view-advertising" class="view">
      <h1>Local business advertising</h1>
      <p class="pagehead__blurb">Advertising remains separate from editorial decisions and must be approved before it appears. Adverts are always marked as sponsored.</p>
      <form id="ad-form" class="form panel">
        <div class="form__row"><label>Business name<input name="business_name" required maxlength="120"></label><label>Advert headline<input name="title" required maxlength="140"></label></div>
        <label>Advert copy<textarea name="body" required maxlength="500" rows="3"></textarea></label>
        <label>Website URL<input name="url" type="url" placeholder="https://"></label>
        <div class="form__row"><label>Target county<select name="target_county" data-county-select><option value="">All Ireland</option><?php foreach ($counties as $c): ?><option><?= e($c) ?></option><?php endforeach; ?></select></label><label>Target town<input name="target_town" list="all-locations" autocomplete="off" placeholder="All towns"></label></div>
        <div class="form__actions"><button class="btn btn--primary" type="submit">Send for approval</button></div>
      </form>
      <div id="ad-list"></div>
    </div>

    <div id="view-notifications" class="view">
      <div class="inline" style="justify-content:space-between"><h1>Notifications</h1><button class="btn btn--ghost btn--sm" id="mark-read" type="button">Mark all read</button></div>
      <div class="panel" id="notif-list"></div>
    </div>

    <div id="view-security" class="view">
      <h1>Security</h1>
      <form id="password-form" class="form panel">
        <label>Current password<input name="current_password" type="password" autocomplete="current-password" required></label>
        <label>New password (8+ characters)<input name="new_password" type="password" autocomplete="new-password" minlength="8" required></label>
        <div class="form__actions"><button class="btn btn--primary" type="submit">Update password</button></div>
      </form>
      <div class="panel"><h2>Session</h2><p style="margin:8px 0">Sessions are stored as SHA-256 hashes and expire automatically. Sign out on shared devices.</p><button class="btn btn--ghost" type="button" data-logout>Sign out everywhere on this device</button></div>
    </div>
  </section>
</div>
<?php $extraScripts = '<script src="/assets/js/dashboard.js?v=' . e(ME_VERSION) . '" defer></script>'; ?>
