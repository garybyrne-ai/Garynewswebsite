<!doctype html>
<html lang="en-IE" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Install — ME News Ireland</title>
<meta name="robots" content="noindex">
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/css/menews.css?v=<?= e(ME_VERSION) ?>">
<style>.install{max-width:640px;margin:48px auto;padding:0 20px}.install .panel{padding:28px}.install h1{font-size:2rem;margin:10px 0 6px}.install .lede{color:var(--ink-2);margin-bottom:22px}.check{display:flex;gap:10px;align-items:center;padding:8px 0;border-bottom:1px solid var(--line);font-size:.9rem}.check:last-child{border:0}.cred{display:grid;grid-template-columns:1fr auto;gap:6px 16px;font-size:.9rem;padding:8px 0;border-bottom:1px solid var(--line)}.cred code{font-family:var(--mono);font-size:.8rem;color:var(--cy)}.errorbox{border:1px solid color-mix(in srgb,var(--hot) 50%,transparent);background:color-mix(in srgb,var(--hot) 10%,transparent);padding:12px 14px;border-radius:10px;margin-bottom:16px;color:var(--ink)}</style>
</head>
<body>
<div class="aurora" aria-hidden="true"><i></i><i></i><i></i></div>
<div class="gridlines" aria-hidden="true"></div>
<main class="install">
  <a class="brand" href="/" style="margin-bottom:18px"><svg class="brand__mark" viewBox="0 0 64 64" aria-hidden="true"><defs><linearGradient id="bg1" x1="0" x2="1" y1="0" y2="1"><stop offset="0" stop-color="#2ef2a8"/><stop offset=".55" stop-color="#47d5ff"/><stop offset="1" stop-color="#9b8cff"/></linearGradient></defs><rect x="2" y="2" width="60" height="60" rx="16" fill="none" stroke="url(#bg1)" stroke-width="2"/><path d="M14 46V18l10 14 10-14v28" fill="none" stroke="url(#bg1)" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/><path d="M40 20h10M40 32h10M40 44h10" stroke="url(#bg1)" stroke-width="5" stroke-linecap="round"/></svg><span class="brand__word"><b>ME</b> News<small>Ireland</small></span></a>

  <?php if ($result): ?>
    <section class="panel">
      <span class="kicker">Installed</span>
      <h1>ME News is live.</h1>
      <p class="lede"><?= (int)$result['stories'] ?> real Irish stories are published (<?= (int)$result['wire']['snapshot'] ?> from the bundled snapshot<?= $result['wire']['live'] ? ', ' . (int)$result['wire']['live'] . ' fresh from the live wire' : '' ?>). Save these details now — passwords are shown only once.</p>
      <div class="cred"><span>Administrator / newsroom</span><code><?= e($result['email']) ?></code></div>
      <?php foreach ($result['contributors'] as $name => $pw): ?>
        <div class="cred"><span><?= e($name) ?></span><code><?= $pw === null ? 'existing account' : e($pw) ?></code></div>
      <?php endforeach; ?>
      <?php if ($result['wire']['errors']): ?><p class="form__legal">Some sources could not be fetched right now: <?= e(implode('; ', $result['wire']['errors'])) ?>. The wire retries automatically.</p><?php endif; ?>
      <div class="form__actions" style="justify-content:flex-start;margin-top:20px">
        <a class="btn btn--primary" href="/">Open the site</a>
        <a class="btn btn--ghost" href="/newsroom">Open the newsroom</a>
      </div>
      <div class="trustnote" style="margin-top:18px"><b>Automatic news updates are on.</b> The wire refreshes itself in the background whenever it is older than <?= e(\MeNews\Config::get('WIRE_REFRESH_MINUTES', '20')) ?> minutes and a page is visited. For guaranteed updates even with no visitors, point a cron job or uptime monitor at your private refresh URL (shown once here, and again in Newsroom → News wire):</div>
      <div class="cred"><span>Refresh URL (keep private)</span><code style="word-break:break-all"><?= e($result['cron_url']) ?></code></div>
      <div class="cred"><span>Cloudways cron (every 15 min)</span><code>curl -s "<?= e($result['cron_url']) ?>"</code></div>
    </section>
  <?php else: ?>
    <section class="panel">
      <span class="kicker">Setup</span>
      <h1>Install ME News Ireland</h1>
      <p class="lede">One form. This writes your configuration, creates the SQLite database, the administrator, five desk editors and loads today's Irish news.</p>

      <div style="margin-bottom:20px">
        <div class="check"><span class="<?= PHP_VERSION_ID >= 80200 ? 'is-good' : 'is-bad' ?>">●</span> PHP <?= e(PHP_VERSION) ?><?= PHP_VERSION_ID >= 80200 ? '' : ' — 8.2 or newer required' ?></div>
        <div class="check"><span class="<?= $missing ? 'is-bad' : 'is-good' ?>">●</span> Extensions: <?= $missing ? 'missing ' . e(implode(', ', $missing)) : 'pdo_sqlite, curl, mbstring, fileinfo, simplexml' ?></div>
        <div class="check"><span class="<?= $writable ? 'is-good' : 'is-bad' ?>">●</span> Configuration file (.env) <?= $writable ? 'can be written' : 'is not writable — fix folder permissions' ?></div>
        <div class="check"><span class="<?= $storageWritable ? 'is-good' : 'is-bad' ?>">●</span> Storage folder <?= $storageWritable ? 'is writable' : 'is not writable' ?></div>
      </div>

      <?php if ($error): ?><div class="errorbox"><?= nl2br(e($error)) ?></div><?php endif; ?>

      <form class="form" method="post" action="/install">
        <label>Site address<input name="public_base_url" type="url" required value="<?= e($values['PUBLIC_BASE_URL']) ?>"></label>
        <label>Administrator email<input name="admin_email" type="email" required autocomplete="email" value="<?= e($values['ADMIN_EMAIL']) ?>"></label>
        <label>Administrator password (12+ characters)<input name="admin_password" type="password" required minlength="8" autocomplete="new-password"></label>
        <label>Password for the five contributor accounts (optional — blank generates random ones)<input name="contributor_password" type="text" autocomplete="off" placeholder="e.g. Desk-2090-Secure"></label>
        <div class="form__row">
          <label>Mode<select name="app_env"><option value="production" <?= $values['APP_ENV'] === 'production' ? 'selected' : '' ?>>Production</option><option value="development" <?= $values['APP_ENV'] === 'development' ? 'selected' : '' ?>>Development</option></select></label>
          <label>News wire<select name="load_live"><option value="1">Load bundled + live news now</option><option value="0">Bundled snapshot only</option></select></label>
        </div>
        <button class="btn btn--primary btn--block" type="submit">Install ME News</button>
        <p class="form__legal">Installation takes 10–30 seconds while the wire fetches from RTÉ, The Irish Times, TheJournal.ie and the other sources. This page disappears once the site is installed.</p>
      </form>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
