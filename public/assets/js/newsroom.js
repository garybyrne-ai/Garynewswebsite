/* ME News Ireland — newsroom */
(function () {
  'use strict';
  const { api, esc, toast } = window.ME;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const LABELS = window.NEWSROOM.labels, CATS = window.NEWSROOM.categories, COUNTIES = window.NEWSROOM.counties;
  let me = null;
  const fmt = iso => iso ? new Date(iso).toLocaleString('en-IE', { dateStyle: 'medium', timeStyle: 'short' }) : '—';
  const opts = (list, sel) => list.map(x => `<option ${x === sel ? 'selected' : ''}>${esc(x)}</option>`).join('');
  const fd = obj => { const f = new FormData(); Object.entries(obj).forEach(([k, v]) => f.append(k, v)); return f; };

  function show(id) {
    $$('.view').forEach(v => v.classList.toggle('is-active', v.id === 'view-' + id));
    $$('.appnav button').forEach(b => b.classList.toggle('is-active', b.dataset.view === id));
    history.replaceState(null, '', '#' + id);
    ({ stories: loadStories, comments: loadComments, users: loadUsers, ads: loadAds, wire: loadWire, audit: loadAudit, review: loadQueue })[id]?.();
  }
  $$('.appnav button').forEach(b => b.addEventListener('click', () => show(b.dataset.view)));

  async function init() {
    try { me = await api('/api/me'); window.NEWSROOM.role = me.role; if (['editor', 'admin'].includes(me.role)) return open(); } catch (e) { }
    $('#gate').hidden = false;
  }
  $('#gate-form').addEventListener('submit', async e => {
    e.preventDefault();
    const out = $('#gate-result');
    try {
      const j = await api('/api/auth/login', { method: 'POST', body: new FormData(e.target) });
      if (!['editor', 'admin'].includes(j.user.role)) throw new Error('This account does not have newsroom access');
      location.reload();
    } catch (err) { out.classList.add('is-error'); out.textContent = err.message; }
  });
  async function open() {
    $('#gate').hidden = true; $('#newsroom').hidden = false;
    $('#who').textContent = me.display_name; $('#who-role').textContent = me.role;
    await Promise.all([summary(), loadQueue()]);
    const hash = location.hash.replace('#', '');
    if (hash && $('#view-' + hash)) show(hash);
  }

  async function summary() {
    const s = await api('/api/admin/summary');
    $('#stats').innerHTML = [['Review', s.pending, 'warn'], ['Held', s.held, 'bad'], ['Published', s.published], ['Community', s.community], ['Wire', s.wire], ['Users', s.users], ['Comments', s.comments_review], ['Ads', s.ads_review]]
      .map(([k, v, c]) => `<div class="stat"><span class="mono">${k}</span><strong class="${c ? 'is-' + c : ''}">${v}</strong></div>`).join('');
    $('#nav-review-count').textContent = s.pending; $('#nav-comments-count').textContent = s.comments_review; $('#nav-ads-count').textContent = s.ads_review;
    $('#wire-last').textContent = s.wire_last_refresh ? 'Wire updated ' + fmt(s.wire_last_refresh) + (s.wire_stale ? ' (stale)' : '') : 'Wire never refreshed';
  }

  function reviewCard(x) {
    const media = x.media_url ? `<div class="review__media">${x.media_type === 'video' ? `<video controls preload="metadata" src="${x.media_url}"></video>` : `<img src="${x.media_url}" alt="">`}</div>` : '';
    return `<article class="review" data-id="${x.id}">
      <div class="inline"><span class="status ${esc(x.status)}">${esc(x.status)}</span><span class="chip chip--cat">${esc(x.category)}</span><span class="mono" style="margin-left:auto;color:var(--muted)">${fmt(x.created_at)}</span></div>
      <h3>${esc(x.title)}</h3>
      <div class="meta">◎ ${esc(x.location_name || '')}${x.county ? ', ' + esc(x.county) : ''} · ${esc(x.author_name || '')}${x.reporter_verified ? ' ✓' : ''} · reputation ${x.reporter_reputation ?? '—'}${x.latitude ? ' · GPS' : ''}</div>
      ${media}
      <p>${esc(x.body || x.summary || '')}</p>
      <div class="scores"><div class="score"><span class="mono">Safety</span><b class="${x.safety_score > 50 ? 'is-good' : 'is-bad'}">${x.safety_score}/100</b></div><div class="score"><span class="mono">Confidence</span><b>${x.trust_score}/100</b></div></div>
      <div class="trustnote">Confidence is an evidence signal only. It does not prove the report is true — you decide the public label.</div>
      <div class="form__row"><label class="form-label">Editorial label<select data-label>${opts(LABELS, x.verification_label)}</select></label><label class="form-label">Section<select data-cat>${opts(CATS, x.category)}</select></label></div>
      <label class="form-label">Editor note (sent to the reporter)<textarea data-note rows="2">${esc(x.editorial_note || '')}</textarea></label>
      <details><summary>Moderation audit</summary><pre class="audit">${esc(JSON.stringify(x.moderation, null, 2))}</pre></details>
      <div class="actions"><button class="btn btn--good btn--sm" data-decide="publish">Publish</button><button class="btn btn--warn btn--sm" data-decide="hold">Hold</button><button class="btn btn--hot btn--sm" data-decide="reject">Reject</button><button class="btn btn--dark btn--sm" data-rerun>Re-run safety</button></div>
    </article>`;
  }
  async function loadQueue() {
    const status = $('#queue-status').value;
    const a = await api('/api/admin/stories?kind=community&status=' + encodeURIComponent(status));
    $('#queue').innerHTML = a.length ? a.map(reviewCard).join('') : '<div class="empty"><div class="empty__glyph">◌</div><h3>Nothing waiting.</h3><p>Community reports appear here after the Trust Engine screens them.</p></div>';
  }
  $('#queue-status').addEventListener('change', loadQueue);
  $('#queue').addEventListener('click', async e => {
    const card = e.target.closest('.review'); if (!card) return;
    const id = card.dataset.id;
    try {
      if (e.target.dataset.decide) {
        await api('/api/admin/stories/' + id + '/edit', { method: 'POST', body: fd({ category: $('[data-cat]', card).value, label: $('[data-label]', card).value }) });
        await api('/api/admin/stories/' + id + '/decision', { method: 'POST', body: fd({ decision: e.target.dataset.decide, label: $('[data-label]', card).value, note: $('[data-note]', card).value }) });
        toast('Decision saved'); await Promise.all([summary(), loadQueue()]);
      } else if (e.target.hasAttribute('data-rerun')) {
        e.target.disabled = true; await api('/api/admin/stories/' + id + '/rerun-safety', { method: 'POST' }); toast('Safety screening re-run'); await Promise.all([summary(), loadQueue()]);
      }
    } catch (err) { toast(err.message); }
  });

  async function loadStories() {
    const q = $('#stories-q').value, kind = $('#stories-kind').value, status = $('#stories-status').value;
    const a = await api(`/api/admin/stories?q=${encodeURIComponent(q)}&kind=${kind}&status=${status}&limit=150`);
    $('#stories-table').innerHTML = `<div class="tablewrap"><table class="table"><thead><tr><th>Story</th><th>Section</th><th>County</th><th>Label</th><th>Status</th><th>Featured</th><th></th></tr></thead><tbody>${a.map(x => `<tr data-id="${x.id}">
      <td><b>${x.status === 'published' ? `<a href="${esc(x.url)}" target="_blank">${esc(x.title)}</a>` : esc(x.title)}</b><span class="sub">${esc(x.kind)} · ${esc(x.source_name || x.author_name || '')} · ${fmt(x.published_at || x.created_at)} · ${x.views} views</span></td>
      <td><select data-cat>${opts(CATS, x.category)}</select></td>
      <td><select data-county><option value="">—</option>${opts(COUNTIES, x.county)}</select></td>
      <td><select data-label>${opts(LABELS, x.verification_label)}</select></td>
      <td><span class="status ${esc(x.status)}">${esc(x.status)}</span></td>
      <td><input type="checkbox" data-featured ${x.is_featured ? 'checked' : ''}></td>
      <td class="actions"><button class="btn btn--ghost btn--sm" data-save>Save</button>${x.status === 'published' ? '<button class="btn btn--warn btn--sm" data-unpublish>Unpublish</button>' : (x.kind === 'wire' ? '<button class="btn btn--good btn--sm" data-republish>Publish</button>' : '')}</td></tr>`).join('')}</tbody></table></div>`;
  }
  ['stories-q', 'stories-kind', 'stories-status'].forEach(id => $('#' + id).addEventListener('change', loadStories));
  $('#stories-q').addEventListener('input', () => { clearTimeout(window._sq); window._sq = setTimeout(loadStories, 300); });
  $('#stories-table').addEventListener('click', async e => {
    const row = e.target.closest('tr[data-id]'); if (!row) return;
    const id = row.dataset.id;
    try {
      if (e.target.hasAttribute('data-save')) {
        await api('/api/admin/stories/' + id + '/edit', { method: 'POST', body: fd({ category: $('[data-cat]', row).value, county: $('[data-county]', row).value, label: $('[data-label]', row).value, is_featured: $('[data-featured]', row).checked ? '1' : '0' }) });
        toast('Saved'); loadStories();
      } else if (e.target.hasAttribute('data-unpublish')) {
        await api('/api/admin/stories/' + id + '/decision', { method: 'POST', body: fd({ decision: 'unpublish', label: $('[data-label]', row).value, note: 'Unpublished from the newsroom' }) }); toast('Unpublished'); loadStories(); summary();
      } else if (e.target.hasAttribute('data-republish')) {
        await api('/api/admin/stories/' + id + '/decision', { method: 'POST', body: fd({ decision: 'publish', label: $('[data-label]', row).value, note: '' }) }); toast('Published'); loadStories(); summary();
      }
    } catch (err) { toast(err.message); }
  });

  async function loadComments() {
    const a = await api('/api/admin/comments');
    $('#comments-list').innerHTML = a.length ? a.map(x => `<div class="review" data-id="${x.id}"><div class="meta"><b>${esc(x.author)}</b> on <a href="/story/${esc(x.slug)}" target="_blank">${esc(x.title)}</a> · ${fmt(x.created_at)}</div><p>${esc(x.body)}</p><details><summary>Moderation</summary><pre class="audit">${esc(x.moderation_json || '')}</pre></details><div class="actions"><button class="btn btn--good btn--sm" data-c="publish">Publish</button><button class="btn btn--hot btn--sm" data-c="reject">Reject</button></div></div>`).join('') : '<div class="empty"><div class="empty__glyph">◌</div><h3>No flagged comments.</h3></div>';
  }
  $('#comments-list').addEventListener('click', async e => {
    const d = e.target.dataset.c; if (!d) return;
    try { await api('/api/admin/comments/' + e.target.closest('[data-id]').dataset.id, { method: 'POST', body: fd({ decision: d }) }); loadComments(); summary(); } catch (err) { toast(err.message); }
  });

  async function loadUsers() {
    const a = await api('/api/admin/users?q=' + encodeURIComponent($('#users-q').value));
    const admin = me.role === 'admin';
    $('#users-table').innerHTML = `<div class="tablewrap"><table class="table"><thead><tr><th>User</th><th>Role</th><th>Title / desk</th><th>Verified</th><th>Plan</th><th>Reputation</th><th></th></tr></thead><tbody>${a.map(x => `<tr data-id="${x.id}">
      <td><b>${esc(x.display_name)}</b><span class="sub">${esc(x.email)}</span><span class="sub">${esc(x.home_town || '')} ${esc(x.home_county || '')} · joined ${fmt(x.created_at)}</span></td>
      <td><select data-role ${admin ? '' : 'disabled'}>${opts(['member', 'contributor', 'editor', 'admin'], x.role)}</select></td>
      <td><input data-title value="${esc(x.title || '')}" placeholder="Title" ${admin ? '' : 'disabled'}><br><select data-desk ${admin ? '' : 'disabled'}><option value="">— desk —</option>${opts(['National', 'Local', 'Business', 'Sport', 'Culture'], x.desk)}</select></td>
      <td><input type="checkbox" data-verified ${x.is_verified ? 'checked' : ''} ${admin ? '' : 'disabled'}></td>
      <td>${esc(x.plan)}</td>
      <td><input type="number" data-rep min="0" max="100" value="${x.reputation}" style="width:70px" ${admin ? '' : 'disabled'}></td>
      <td>${admin ? '<button class="btn btn--ghost btn--sm" data-save-user>Save</button>' : ''}</td></tr>`).join('')}</tbody></table></div>`;
  }
  $('#users-q').addEventListener('input', () => { clearTimeout(window._uq); window._uq = setTimeout(loadUsers, 300); });
  $('#users-table').addEventListener('click', async e => {
    if (!e.target.hasAttribute('data-save-user')) return;
    const row = e.target.closest('tr');
    try { await api('/api/admin/users/' + row.dataset.id, { method: 'POST', body: fd({ role: $('[data-role]', row).value, is_verified: $('[data-verified]', row).checked ? '1' : '0', reputation: $('[data-rep]', row).value, title: $('[data-title]', row).value, desk: $('[data-desk]', row).value }) }); toast('User saved'); } catch (err) { toast(err.message); }
  });

  async function loadAds() { if (window.ME.loadAds) window.ME.loadAds(); }
  async function loadWire() {
    const j = await api('/api/admin/wire/runs');
    $('#wire-schedule').innerHTML = `<span class="kicker">Automatic updates</span>
      <div class="stats" style="margin:12px 0"><div class="stat"><span class="mono">Last refresh</span><strong style="font-size:1.1rem">${j.last_refresh ? fmt(j.last_refresh) : 'never'}</strong></div><div class="stat"><span class="mono">Interval</span><strong>${j.interval_minutes} min</strong></div><div class="stat"><span class="mono">Refreshes so far</span><strong>${j.refresh_count}</strong></div><div class="stat"><span class="mono">Background mode</span><strong style="font-size:1.1rem" class="${j.auto_refresh ? 'is-good' : 'is-bad'}">${j.auto_refresh ? (j.background_capable ? 'server (PHP-FPM)' : 'browser ping') : 'off'}</strong></div></div>
      <p style="color:var(--ink-2);font-size:.9rem">The wire refreshes itself whenever it is older than ${j.interval_minutes} minutes and someone visits the site. To guarantee daily updates even without visitors, call the private refresh URL from a cron job or an uptime monitor:</p>
      ${j.cron_url ? `<div class="cred" style="display:grid;grid-template-columns:1fr;gap:6px;padding:10px 0"><code class="mono" style="text-transform:none;letter-spacing:0;color:var(--cy);word-break:break-all">${esc(j.cron_url)}</code><code class="mono" style="text-transform:none;letter-spacing:0;color:var(--muted);word-break:break-all">*/15 * * * *  curl -s "${esc(j.cron_url)}"</code><code class="mono" style="text-transform:none;letter-spacing:0;color:var(--muted);word-break:break-all">*/15 * * * *  ${esc(j.cron_cli)}</code></div>` : '<p class="form__legal">Sign in as an administrator to see the private refresh URL.</p>'}`;
    $('#wire-sources').innerHTML = j.sources.map(s => `<li><a href="${esc(s.home || s.url)}" target="_blank" rel="noopener"><b>${esc(s.name)}</b><span class="mono">${esc(s.key)} · ${esc(s.category)}</span></a></li>`).join('');
    $('#wire-runs').innerHTML = `<div class="tablewrap"><table class="table"><thead><tr><th>Source</th><th>Started</th><th>Fetched</th><th>New</th><th>Result</th></tr></thead><tbody>${j.runs.map(r => `<tr><td>${esc(r.source_key)}</td><td>${fmt(r.started_at)}</td><td>${r.fetched}</td><td>${r.inserted}</td><td>${r.error ? `<span class="is-bad">${esc(r.error)}</span>` : '<span class="is-good">ok</span>'}</td></tr>`).join('')}</tbody></table></div>`;
  }
  $('#wire-refresh').addEventListener('click', async e => {
    e.target.disabled = true; e.target.textContent = 'Fetching…';
    try { const j = await api('/api/admin/wire/refresh', { method: 'POST' }); toast(`${j.inserted} new stories from ${j.fetched} fetched`); loadWire(); summary(); }
    catch (err) { toast(err.message); }
    e.target.disabled = false; e.target.textContent = 'Refresh wire now';
  });
  $('#loc-refresh').addEventListener('click', async e => {
    const out = $('#loc-result'); out.textContent = 'Downloading official CSO / Tailte Éireann urban areas…'; e.target.disabled = true;
    try { const j = await api('/api/admin/locations/refresh', { method: 'POST' }); out.textContent = `Loaded ${j.count} official urban areas (${j.source}).`; } catch (err) { out.textContent = err.message; }
    e.target.disabled = false;
  });

  async function loadAudit() {
    try {
      const a = await api('/api/admin/audit');
      $('#audit-list').innerHTML = `<div class="tablewrap"><table class="table"><thead><tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>Detail</th></tr></thead><tbody>${a.map(x => `<tr><td>${fmt(x.created_at)}</td><td>${esc(x.display_name || x.user_id || 'system')}</td><td class="mono">${esc(x.action)}</td><td>${esc(x.entity_type || '')} <span class="sub">${esc(x.entity_id || '')}</span></td><td>${esc(x.detail || '')}</td></tr>`).join('')}</tbody></table></div>`;
    } catch (e) { $('#audit-list').innerHTML = '<div class="empty"><h3>Audit log requires administrator access.</h3></div>'; }
  }

  init();
})();
