/* ME News Ireland — member dashboard */
(function () {
  'use strict';
  const { api, esc, toast } = window.ME;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  let me = null;
  const fmt = iso => new Date(iso).toLocaleString('en-IE', { dateStyle: 'medium', timeStyle: 'short' });

  function show(id) {
    $$('.view').forEach(v => v.classList.toggle('is-active', v.id === 'view-' + id));
    $$('.appnav button').forEach(b => b.classList.toggle('is-active', b.dataset.view === id));
    history.replaceState(null, '', '#' + id);
  }
  $$('.appnav button').forEach(b => b.addEventListener('click', () => show(b.dataset.view)));

  async function init() {
    try { me = await api('/api/me'); } catch (e) { location.href = '/?auth=signin&next=/dashboard'; return; }
    $('#hello').textContent = 'Hello, ' + me.display_name.split(' ')[0];
    $('#p-name').value = me.display_name; $('#p-town').value = me.home_town || ''; $('#p-county').value = me.home_county || ''; $('#p-bio').value = me.bio || '';
    window.ME.loadCounties().then(() => { $('#p-county').value = me.home_county || ''; });
    await Promise.all([loadReports(), loadBilling(), loadNotifications(), loadFollows(), loadAds()]);
    const hash = location.hash.replace('#', '');
    if (hash && $('#view-' + hash)) show(hash);
    const qs = new URLSearchParams(location.search);
    if (qs.get('billing') === 'success') toast('Thank you — ME+ will activate as soon as Stripe confirms payment.');
    if (qs.get('ad') === 'success' || qs.get('ad') === 'paypal-success') { toast('Thank you — your advertising subscription is being confirmed.'); show('advertising'); }
    if (qs.get('ad') === 'paypal-pending') { toast('PayPal is still confirming your subscription — check back in a minute.'); show('advertising'); }
  }

  async function loadReports() {
    const a = await api('/api/me/reports');
    const published = a.filter(x => x.status === 'published').length;
    $('#stats').innerHTML = [
      ['Reports', a.length], ['Published', published], ['Reputation', me.reputation + '/100'], ['Plan', me.plan === 'ME+' ? 'ME+' : 'Free'],
    ].map(([k, v]) => `<div class="stat"><span class="mono">${k}</span><strong>${v}</strong></div>`).join('');
    $('#report-table').innerHTML = a.length ? `<div class="tablewrap"><table class="table"><thead><tr><th>Report</th><th>Area</th><th>Status</th><th>Safety</th><th>Confidence</th><th>Views</th></tr></thead><tbody>${a.map(x => `<tr><td><b>${x.status === 'published' ? `<a href="/story/${esc(x.slug)}">${esc(x.title)}</a>` : esc(x.title)}</b><span class="sub">${fmt(x.created_at)} · ${esc(x.category)}</span>${x.editorial_note ? `<span class="sub is-warn">Editor: ${esc(x.editorial_note)}</span>` : ''}</td><td>${esc(x.location_name || '')}<span class="sub">${esc(x.county || '')}</span></td><td><span class="status ${esc(x.status)}">${esc(x.status)}</span><span class="sub">${esc(x.verification_label)}</span></td><td>${x.safety_score}/100</td><td>${x.trust_score}/100</td><td>${x.views || 0}</td></tr>`).join('')}</tbody></table></div>`
      : '<div class="empty"><div class="empty__glyph">⬡</div><h3>You have not submitted a report yet.</h3><p>Tap <b>Report</b> in the header when you see something happening near you.</p></div>';
    $('#nav-reports-count').textContent = a.length;
  }

  async function loadFollows() {
    const a = await api('/api/me/follows');
    $('#follow-list').innerHTML = a.length ? a.map(x => `<div class="notif inline"><b style="flex:1">${esc(x.location_name || x.county)} <span class="mono">${esc(x.county && x.location_name ? x.county : '')}</span></b><button class="btn btn--ghost btn--sm" data-unfollow="${x.id}">Remove</button></div>`).join('') : '<p class="panel__note">No followed areas yet.</p>';
    $$('[data-unfollow]').forEach(b => b.addEventListener('click', async () => { try { await api('/api/me/follows/' + b.dataset.unfollow, { method: 'DELETE' }); loadFollows(); } catch (e) { toast(e.message); } }));
    $('#follow-hint').textContent = me.plan === 'ME+' ? `${a.length} of 10 areas used` : `${a.length} of 1 area used — ME+ allows 10`;
  }
  $('#follow-form').addEventListener('submit', async e => {
    e.preventDefault();
    try { await api('/api/me/follows', { method: 'POST', body: new FormData(e.target) }); toast('Area followed'); e.target.reset(); loadFollows(); }
    catch (err) { toast(err.message); }
  });

  async function loadBilling() {
    const j = await api('/api/billing/status');
    $('#plan-state').innerHTML = `Current plan: <b>${esc(j.plan)}</b>`;
    const btn = $('#checkout-btn');
    btn.disabled = !j.stripe_configured || j.plan === 'ME+';
    btn.textContent = j.plan === 'ME+' ? 'ME+ is active' : (j.stripe_configured ? 'Upgrade with Stripe · ' + j.price_label : 'Stripe not configured yet');
  }
  $('#checkout-btn').addEventListener('click', async () => { try { const j = await api('/api/billing/checkout', { method: 'POST' }); location.href = j.url; } catch (e) { toast(e.message); } });

  $('#profile-form').addEventListener('submit', async e => {
    e.preventDefault();
    try { me = await api('/api/me/profile', { method: 'POST', body: new FormData(e.target) }); toast('Profile saved'); } catch (err) { toast(err.message); }
  });
  $('#password-form').addEventListener('submit', async e => {
    e.preventDefault();
    try { await api('/api/me/password', { method: 'POST', body: new FormData(e.target) }); toast('Password updated'); e.target.reset(); } catch (err) { toast(err.message); }
  });
  async function loadAds() { if (window.ME.loadAds) window.ME.loadAds(); }

  async function loadNotifications() {
    const a = await api('/api/me/notifications');
    $('#notif-list').innerHTML = a.length ? a.map(x => `<div class="notif ${x.is_read ? '' : 'is-new'}"><b>${x.story_slug ? `<a href="/story/${esc(x.story_slug)}">${esc(x.title)}</a>` : esc(x.title)}</b><span class="mono">${fmt(x.created_at)}</span><div>${esc(x.body || '')}</div></div>`).join('') : '<p class="panel__note">No notifications yet.</p>';
    const unread = a.filter(x => !x.is_read).length;
    $('#nav-notif-count').textContent = unread; $('#nav-notif-count').hidden = !unread;
  }
  $('#mark-read').addEventListener('click', async () => { await api('/api/me/notifications/read', { method: 'POST' }); loadNotifications(); const b = $('#notify-badge'); if (b) b.hidden = true; });

  init();
})();
