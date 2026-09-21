/* ME News Ireland — member dashboard */
(function () {
  'use strict';
  const { api, esc, toast } = window.ME;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  let me = null;
  const qs = new URLSearchParams(location.search);
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
    await Promise.all([loadReports(), loadBilling(), loadNotifications(), loadFollows(), loadAlerts(), loadSaved(), loadAds()]);
    const hash = location.hash.replace('#', '');
    if (hash && $('#view-' + hash)) show(hash);
    if (qs.get('billing') === 'success') toast('Thank you — ME+ will activate as soon as Stripe confirms payment.');
    if (qs.get('ad') === 'success' || qs.get('ad') === 'paypal-success') { toast('Thank you — your advertising subscription is being confirmed.'); show('advertising'); }
    if (qs.get('ad') === 'paypal-pending') { toast('PayPal is still confirming your subscription — check back in a minute.'); show('advertising'); }
    if (qs.get('order') === 'paid') { toast('Payment received — your package is ready. Design your advert below.'); show('advertising'); }
    if (qs.get('order') === 'pending') { toast('Payment is still being confirmed — your package will appear here in a minute.'); show('advertising'); }
  }

  async function loadReports() {
    const a = await api('/api/me/reports');
    const published = a.filter(x => x.status === 'published').length;
    $('#stats').innerHTML = [
      ['Reports', a.length], ['Published', published], ['Reputation', me.reputation + '/100'], ['Plan', me.plan === 'ME+' ? 'ME+' : 'Free'],
    ].map(([k, v]) => `<div class="stat"><span class="mono">${k}</span><strong>${v}</strong></div>`).join('');
    $('#report-table').innerHTML = a.length ? `<div class="tablewrap"><table class="table"><thead><tr><th>Report</th><th>Area</th><th>Status</th><th>Safety</th><th>Confidence</th><th>Views</th></tr></thead><tbody>${a.map(x => `<tr><td><b>${x.status === 'published' ? `<a href="/story/${esc(x.slug)}">${esc(x.title)}</a>` : esc(x.title)}</b><span class="sub">${fmt(x.created_at)} · ${esc(x.category)}</span>${x.editorial_note ? `<span class="sub is-warn">Editor: ${esc(x.editorial_note)}</span>` : ''}</td><td>${esc(x.location_name || '')}<span class="sub">${esc(x.county || '')}</span></td><td><span class="status ${esc(x.status)}">${esc(x.status)}</span><span class="sub">${esc(x.verification_label)}</span></td><td>${x.safety_score}/100</td><td>${x.trust_score}/100</td><td>${x.views || 0}</td></tr>`).join('')}</tbody></table></div>`
      : '<div class="empty"><div class="empty__glyph">—</div><h3>You have not submitted a report yet.</h3><p>Tap <b>Report</b> in the header when you see something happening near you.</p></div>';
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

  /* ---------- saved stories ---------- */
  let savedLists = [], currentList = null;
  async function loadSaved(keep) {
    const j = await api('/api/me/saved'); savedLists = j.lists;
    const total = savedLists.reduce((s, l) => s + l.count, 0);
    $('#nav-saved-count').textContent = total; $('#nav-saved-count').hidden = !total;
    if (!keep || !savedLists.some(l => l.id === currentList)) currentList = (savedLists.find(l => l.is_default) || savedLists[0]).id;
    $('#saved-lists').innerHTML = savedLists.map(l => `<button type="button" class="listtab ${l.id === currentList ? 'is-active' : ''}" data-list="${esc(l.id)}">${esc(l.name)} <span class="badge">${l.count}</span></button>`).join('');
    await loadSavedItems();
  }
  async function loadSavedItems() {
    const j = await api('/api/me/saved/lists/' + currentList);
    $('#saved-tools').hidden = false; $('#saved-count').textContent = `${j.items.length} in ${j.list.name}`;
    $('#saved-delete').hidden = !!j.list.is_default;
    $('#saved-items').innerHTML = j.items.length ? j.items.map(it => `<div class="savedcard" data-item="${it.item_id}">${it.html}<button class="btn btn--ghost btn--sm savedcard__remove" type="button" data-remove-item="${it.item_id}">Remove from list</button></div>`).join('')
      : '<div class="empty"><div class="empty__glyph">—</div><h3>Nothing saved here yet.</h3><p>Tap the bookmark on any story card or article to add it.</p><a class="btn btn--primary" href="/">Browse stories</a></div>';
    $$('#saved-items [data-save]').forEach(b => b.classList.add('is-saved'));
  }
  $('#saved-lists').addEventListener('click', e => { const b = e.target.closest('[data-list]'); if (!b) return; currentList = b.dataset.list; $$('#saved-lists .listtab').forEach(x => x.classList.toggle('is-active', x === b)); loadSavedItems(); });
  $('#saved-new').addEventListener('submit', async e => { e.preventDefault(); try { const j = await api('/api/me/saved/lists', { method: 'POST', body: new FormData(e.target) }); currentList = j.list.id; e.target.reset(); toast('List created'); loadSaved(true); } catch (err) { toast(err.message); } });
  $('#saved-rename').addEventListener('click', async () => { const l = savedLists.find(x => x.id === currentList); const name = prompt('Rename list', l ? l.name : ''); if (name === null) return; try { const fd = new FormData(); fd.append('name', name); await api('/api/me/saved/lists/' + currentList, { method: 'POST', body: fd }); loadSaved(true); } catch (err) { toast(err.message); } });
  $('#saved-delete').addEventListener('click', async () => { if (!confirm('Delete this list? The stories stay on the site.')) return; try { const fd = new FormData(); fd.append('action', 'delete'); await api('/api/me/saved/lists/' + currentList, { method: 'POST', body: fd }); loadSaved(false); } catch (err) { toast(err.message); } });
  $('#saved-items').addEventListener('click', async e => { const b = e.target.closest('[data-remove-item]'); if (!b) return; try { await api('/api/me/saved/items/' + b.dataset.removeItem, { method: 'DELETE' }); loadSaved(true); } catch (err) { toast(err.message); } });

  async function loadAlerts() {
    const j = await api('/api/me/alerts');
    const a = j.subscriptions || [];
    $('#alerts-list').innerHTML = a.length ? a.map(x => `<div class="notif inline"><b style="flex:1">Co. ${esc(x.county)}${x.town ? ' · ' + esc(x.town) : ''} <span class="mono">${esc(x.kind_labels.join(', '))}${x.confirmed ? '' : ' · unconfirmed — check your inbox'}</span></b><button class="btn btn--ghost btn--sm" data-unalert="${x.id}">Remove</button></div>`).join('') : '<p class="panel__note">No county alerts yet.</p>';
    $$('[data-unalert]').forEach(b => b.addEventListener('click', async () => { try { await api('/api/me/alerts/' + b.dataset.unalert, { method: 'DELETE' }); loadAlerts(); } catch (e) { toast(e.message); } }));
    $('#alerts-hint').textContent = j.plan === 'ME+' ? `${a.length} of ${j.limit} areas used` : `${a.length} of ${j.limit} area used — ME+ allows 10`;
  }
  $('#alerts-form').addEventListener('submit', async e => {
    e.preventDefault();
    try { const j = await api('/api/me/alerts', { method: 'POST', body: new FormData(e.target) }); toast(j.message || 'Alerts added'); e.target.reset(); loadAlerts(); }
    catch (err) { toast(err.message); }
  });

  async function loadBilling() {
    const j = await api('/api/billing/status');
    $('#plan-state').innerHTML = `Current plan: <b>${esc(j.plan)}</b>`;
    const btn = $('#checkout-btn');
    btn.disabled = !j.stripe_configured || j.plan === 'ME+';
    btn.textContent = j.plan === 'ME+' ? 'ME+ is active' : (j.stripe_configured ? 'Monthly · ' + j.price_label : 'Stripe not configured yet');
    const yb = $('#checkout-year-btn'); if (yb) { yb.disabled = !j.stripe_configured || j.plan === 'ME+'; yb.textContent = 'Annual · ' + j.annual_label; }
    const up = qs.get('upgrade'); if (up && j.stripe_configured && j.plan !== 'ME+') { const b = up === 'year' ? yb : btn; if (b) b.click(); }
  }
  ['#checkout-btn', '#checkout-year-btn'].forEach(sel => $(sel)?.addEventListener('click', async e => { const f = new FormData(); f.append('interval', e.currentTarget.dataset.interval || 'month'); try { const j = await api('/api/billing/checkout', { method: 'POST', body: f }); location.href = j.url; } catch (err) { toast(err.message); } }));

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
