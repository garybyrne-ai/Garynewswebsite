<?php use MeNews\Ui; ?>
<div class="container appshell">
  <nav class="appnav" aria-label="Dashboard">
    <div class="appnav__head"><?= Ui::avatar($user, 'sm') ?><span><b><?= e($user['display_name']) ?></b><small><?= e($user['plan'] === 'ME+' ? 'ME+ member' : 'Free member') ?></small></span></div>
    <button class="is-active" data-view="overview">Overview</button>
    <button data-view="reports">My reports <span class="badge" id="nav-reports-count">0</span></button>
    <button data-view="profile">Reporter profile</button>
    <button data-view="saved">Saved stories <span class="badge" id="nav-saved-count" hidden>0</span></button>
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
      <div class="panel" id="track-record"><h2>Your track record</h2><div class="stats" style="margin:12px 0"><div class="stat"><span class="mono">Filed</span><strong><?= (int)($user['reports_filed'] ?? 0) ?></strong></div><div class="stat"><span class="mono">Published</span><strong><?= (int)($user['reports_published'] ?? 0) ?></strong></div><div class="stat"><span class="mono">Confirmed by neighbours</span><strong><?= (int)\MeNews\Database::count('SELECT COUNT(*) FROM confirmations c JOIN stories s ON s.id=c.story_id WHERE s.author_user_id=?', [$user['id']]) ?></strong></div><div class="stat"><span class="mono">Standing</span><strong><?= (int)$user['reputation'] ?>/100</strong></div></div><p class="panel__note" style="margin:0">Published reports and confirmations raise your standing; good standing earns a verified tick and faster publication.</p></div>
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

    <div id="view-saved" class="view">
      <div class="inline" style="justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
        <div><h1>Saved stories</h1><p class="pagehead__blurb">Tap the bookmark on any story to keep it here. Make lists for whatever you are following — a planning row, a season, a trip home.</p></div>
        <form id="saved-new" class="inline"><input name="name" placeholder="New list, e.g. Council watch" maxlength="60" required style="min-width:220px"><button class="btn btn--primary" type="submit">Create list</button></form>
      </div>
      <div class="listtabs" id="saved-lists"></div>
      <div class="inline" id="saved-tools" style="justify-content:space-between;margin:6px 0 14px" hidden><span class="mono" id="saved-count"></span><span class="inline"><button class="btn btn--ghost btn--sm" type="button" id="saved-rename">Rename</button><button class="btn btn--ghost btn--sm" type="button" id="saved-delete">Delete list</button></span></div>
      <div class="grid grid--3" id="saved-items"></div>
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
      <h2 style="margin-top:28px">Email alerts by county</h2>
      <p class="pagehead__blurb">Death notices, school closures, Met Éireann warnings and the 7am morning email, sent to <b><?= e($user['email']) ?></b>. <span class="mono" id="alerts-hint"></span></p>
      <form id="alerts-form" class="form panel">
        <div class="form__row">
          <label>County<select name="county" data-county-select required><option value="">Choose a county</option><?php foreach ($counties as $c): ?><option><?= e($c) ?></option><?php endforeach; ?></select></label>
          <label>Town (optional)<input name="town" list="all-locations" autocomplete="off" placeholder="e.g. Bray"></label>
        </div>
        <div class="checks"><?php foreach (\MeNews\Services\Alerts::KINDS as $k => $label): ?><label class="check"><input type="checkbox" name="kinds[]" value="<?= e($k) ?>" <?= in_array($k, ['deaths', 'closures', 'warnings', 'daily'], true) ? 'checked' : '' ?>><span><?= e($label) ?></span></label><?php endforeach; ?></div>
        <div class="form__actions"><button class="btn btn--primary" type="submit">Add county alerts</button></div>
      </form>
      <div class="panel" id="alerts-list"></div>
    </div>

    <div id="view-membership" class="view">
      <div class="panel panel--plus">
        <span class="chip chip--plus">ME+</span>
        <h2 style="margin-top:10px">ME+ membership</h2>
        <p>Ad-free reading, death notice and closure alerts for up to ten areas, the 7am email for each, the full archive and the members' county newsletter.</p>
        <div class="price"><b><?= e(\MeNews\Services\Membership::priceLabel()) ?></b><small>or <?= e(\MeNews\Services\Membership::annualLabel()) ?></small></div>
        <p id="plan-state"></p>
        <div class="form__actions" style="justify-content:flex-start;margin-top:12px"><button class="btn btn--primary" id="checkout-btn" type="button" data-interval="month">Monthly with Stripe</button><button class="btn btn--ghost" id="checkout-year-btn" type="button" data-interval="year">Annual · <?= e(\MeNews\Services\Membership::annualLabel()) ?></button><a class="btn btn--ghost" href="/plus">Compare plans</a></div>
        <p class="form__legal">Stripe Checkout becomes live when the Stripe secret key is configured in .env. Prices are set in the newsroom.</p>
      </div>
    </div>

    <div id="view-advertising" class="view">
      <div class="inline" style="justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
        <div><h1>Advertising</h1><p class="pagehead__blurb">Buy a package of impressions, design your advert, submit it for review, and watch it run. <a href="/advertise">Packages and how it works →</a></p></div>
        <div class="inline"><a class="btn btn--ghost" href="/advertise"><?= icon('plus') ?> Buy a package</a><button class="btn btn--primary" type="button" data-ad-new hidden>+ New advert</button></div>
      </div>
      <div id="my-credits"></div>
      <div id="my-ads"></div>
      <div id="my-orders"></div>
      <?= \MeNews\View::partial('partials/ad-designer', ['counties' => $counties, 'formId' => 'ad-form', 'admin' => false]) ?>
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
<?php $extraScripts = '<script src="/assets/js/dashboard.js?v=' . e(ME_ASSETS) . '" defer></script>'; ?>
