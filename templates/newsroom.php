<div class="container">
  <div id="gate" class="gate panel" hidden>
    <span class="kicker">Newsroom access</span>
    <h1 style="margin:10px 0">Sign in to the newsroom</h1>
    <p class="pagehead__blurb" style="margin-bottom:16px">Editor or administrator accounts only.</p>
    <form id="gate-form" class="form">
      <label>Email<input name="email" type="email" required autocomplete="email"></label>
      <label>Password<input name="password" type="password" required autocomplete="current-password"></label>
      <button class="btn btn--primary btn--block" type="submit">Open newsroom</button>
    </form>
    <p class="form__result" id="gate-result"></p>
  </div>

  <div id="newsroom" class="appshell" hidden>
    <nav class="appnav" aria-label="Newsroom">
      <div class="appnav__head"><span class="livedot"></span><span><b id="who">Newsroom</b><small id="who-role">editor</small></span></div>
      <button class="is-active" data-view="review">Review queue <span class="badge" id="nav-review-count">0</span></button>
      <button data-view="stories">All stories</button>
      <button data-view="sections">Section check <span class="badge" id="nav-sections-count">0</span></button>
      <button data-view="notices">Notices <span class="badge" id="nav-notices-count">0</span></button>
      <button data-view="closures">Closures</button>
      <button data-view="comments">Comments <span class="badge" id="nav-comments-count">0</span></button>
      <button data-view="users">People</button>
      <button data-view="ads">Advertising <span class="badge" id="nav-ads-count">0</span></button>
      <button data-view="wire">News wire</button>
      <button data-view="locations">Irish locations</button>
      <button data-view="trust">Corrections &amp; takedowns <span class="badge" id="nav-takedowns-count">0</span></button>
      <button data-view="settings">Settings</button>
      <button data-view="audit">Audit log</button>
    </nav>
    <section>
      <div class="stats" id="stats" style="margin-bottom:22px"></div>
      <p class="mono" id="wire-last" style="color:var(--muted);margin-bottom:18px"></p>

      <div id="view-review" class="view is-active">
        <div class="inline" style="justify-content:space-between"><div><h1>Editorial review</h1><p class="pagehead__blurb">AI safety screening assists the newsroom. Publication and verification labels remain human decisions.</p></div><select id="queue-status" class="filters"><option value="review">Awaiting review</option><option value="hold">Held</option><option value="processing">Processing</option><option value="rejected">Rejected</option><option value="published">Published community</option></select></div>
        <div class="queue" id="queue"></div>
      </div>

      <div id="view-stories" class="view">
        <h1>All stories</h1>
        <div class="filters"><input id="stories-q" placeholder="Search title, author, place…"><select id="stories-kind"><option value="">Wire + community</option><option value="wire">Wire</option><option value="community">Community</option></select><select id="stories-status"><option value="">Any status</option><option>published</option><option>review</option><option>hold</option><option>rejected</option></select></div>
        <div id="stories-table"></div>
      </div>

      <div id="view-comments" class="view"><h1>Flagged comments</h1><div id="comments-list"></div></div>

      <div id="view-users" class="view"><h1>People</h1><div class="filters"><input id="users-q" placeholder="Search name or email…"></div><div id="users-table"></div></div>

      <div id="view-ads" class="view">
        <div class="inline" style="justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap">
          <div><h1>Advertising</h1><p class="pagehead__blurb">Review advertiser submissions, run house ads for your own brands, and set the price and trial length.</p></div>
          <button class="btn btn--primary" type="button" data-ad-new>+ House ad</button>
        </div>
        <div class="panel" id="ads-settings"></div>
        <div class="panel" id="ads-packages"></div>
        <div class="panel" id="ads-orders"></div>
        <div class="filters"><select id="ads-status"><option value="">All adverts</option><option value="review">Awaiting review</option><option value="approved">Approved</option><option value="draft">Drafts</option><option value="rejected">Rejected</option></select></div>
        <div id="ads-list"></div>
        <?= \MeNews\View::partial('partials/ad-designer', ['counties' => $counties, 'formId' => 'ad-form', 'admin' => true]) ?>
      </div>

      <div id="view-wire" class="view">
        <div class="inline" style="justify-content:space-between"><div><h1>News wire</h1><p class="pagehead__blurb">Headlines are pulled from established Irish publishers every <?= e(\MeNews\Config::get('WIRE_REFRESH_MINUTES', '20')) ?> minutes and filed to the matching desk editor.</p></div><button class="btn btn--primary" id="wire-refresh" type="button">Refresh wire now</button></div>
        <div class="panel" id="wire-schedule"></div>
        <ul class="sources" id="wire-sources"></ul>
        <h2>Recent runs</h2>
        <div id="wire-runs"></div>
      </div>

      <div id="view-locations" class="view">
        <h1>Irish location database</h1>
        <div class="panel"><p style="margin-bottom:12px">ME ships with a curated fallback list of towns. Administrators can replace it with the complete official CSO / Tailte Éireann 2022 Urban Areas layer (CC BY 4.0).</p><button class="btn btn--primary" id="loc-refresh" type="button">Import official Irish towns</button><p class="form__result" id="loc-result"></p></div>
      </div>

      <div id="view-sections" class="view">
        <h1>Section check</h1><p class="pagehead__blurb">Wire stories where the classifier now suggests a different section. Accept in one click, or keep and lock the current section so it is never re-filed.</p>
        <div id="sections-table"></div>
      </div>

      <div id="view-notices" class="view">
        <div class="inline" style="justify-content:space-between"><div><h1>Notices</h1><p class="pagehead__blurb">Death notices, in memoriam, events, jobs, planning, pets and club results. Confirmed by the sender's email; you publish.</p></div><select id="notices-status" class="filters"><option value="review">Awaiting review</option><option value="published">Published</option><option value="rejected">Rejected</option><option value="expired">Expired</option><option value="all">All</option></select></div>
        <div class="queue" id="notices-queue"></div>
      </div>

      <div id="view-closures" class="view"><h1>School closures</h1><p class="pagehead__blurb">Closures confirmed from a school email go live immediately; unconfirmed ones wait here.</p><div id="closures-table"></div></div>

      <div id="view-trust" class="view">
        <h1>Corrections &amp; takedowns</h1>
        <div class="panel" style="margin-bottom:18px"><span class="kicker">Log a correction</span>
          <form class="form" id="correction-form" style="margin-top:10px">
            <div class="form__row"><label>Title<input name="title" required placeholder="What the correction concerns"></label><label>Story id (optional)<input name="story_id" placeholder="Paste the story id to attach a note"></label></div>
            <label>What changed<input name="summary" required placeholder="One sentence, published in the log"></label>
            <label>Detail (optional)<textarea name="detail" rows="2"></textarea></label>
            <div class="form__actions"><button class="btn btn--primary btn--sm" type="submit">Publish to the log</button></div>
          </form>
        </div>
        <h2>Removal requests</h2><div id="takedowns-table"></div>
        <h2 style="margin-top:22px">Corrections log</h2><div id="corrections-table"></div>
      </div>

      <div id="view-settings" class="view">
        <h1>Settings</h1><p class="pagehead__blurb">Prices, the wire's display mode, WhatsApp numbers and the ownership page. Administrators only.</p>
        <form class="form" id="settings-form"><div class="settingsgrid" id="settings-grid"></div><div class="form__actions"><button class="btn btn--primary" type="submit">Save settings</button></div><p class="form__result" id="settings-result"></p></form>
      </div>

      <div id="view-audit" class="view"><h1>Audit log</h1><div id="audit-list"></div></div>
    </section>
  </div>
</div>
<?php $extraScripts = '<script>window.NEWSROOM=' . json_encode(['labels' => $labels, 'categories' => $categories, 'counties' => $counties], JSON_UNESCAPED_UNICODE) . ';</script><script src="/assets/js/newsroom.js?v=' . e(ME_VERSION) . '" defer></script>'; ?>
