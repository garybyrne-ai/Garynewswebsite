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
    ({ stories: loadStories, comments: loadComments, users: loadUsers, ads: loadAds, wire: loadWire, audit: loadAudit, review: loadQueue, sections: loadSections, notices: loadNotices, closures: loadClosures, trust: loadTrust, settings: loadSettings })[id]?.();
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
    $('#nav-sections-count').textContent = s.section_check; $('#nav-notices-count').textContent = s.notices_review; $('#nav-takedowns-count').textContent = s.takedowns_open;
    $('#wire-last').textContent = s.wire_last_refresh ? 'Wire updated ' + fmt(s.wire_last_refresh) + (s.wire_stale ? ' (stale)' : '') : 'Wire never refreshed';
  }

  function reviewCard(x) {
    const media = x.media_url ? `<div class="review__media">${x.media_type === 'video' ? `<video controls preload="metadata" src="${x.media_url}"></video>` : `<img src="${x.media_url}" alt="">`}</div>` : '';
    return `<article class="review" data-id="${x.id}">
      <div class="inline"><span class="status ${esc(x.status)}">${esc(x.status)}</span><span class="chip chip--cat">${esc(x.category)}</span><span class="mono" style="margin-left:auto;color:var(--muted)">${fmt(x.created_at)}</span></div>
      <h3>${esc(x.title)}</h3>
      <div class="meta">${esc(x.location_name || '')}${x.county ? ', ' + esc(x.county) : ''} · ${esc(x.author_name || '')}${x.reporter_verified ? ' ✓' : ''}${x.author_user_id ? ` · reputation ${x.reporter_reputation ?? '—'}` : (x.reporter_verified_at ? ' · <span class="is-good">guest, contact confirmed</span>' : ` · <span class="is-bad">guest, not yet confirmed</span> (${esc(x.reporter_contact || '')})`)}${x.latitude ? ' · GPS' : ''}</div>
      ${x.incident_reports && x.incident_reports.length ? `<div class="trustnote"><b>${x.incident_reports.length + 1} reports of this incident.</b> ${x.incident_reports.map(i => `<a href="#" data-jump="${i.id}">${esc(i.author_name || 'Reporter')} · ${esc(i.location_name || '')} · ${esc(i.status)}</a>`).join(' · ')}</div>` : ''}
      ${media}
      ${x.media_type === 'image' && x.media_url ? `<div class="evidence">
        <span class="mono">Photo check</span>
        <ul>
          <li>${x.exif && x.exif.taken ? `Taken <b>${esc(x.exif.taken)}</b>` : 'No capture time in the file'}${x.exif && x.exif.device ? ` on ${esc(x.exif.device)}` : ''}${x.exif && x.exif.software ? ` · edited with ${esc(x.exif.software)}` : ''}</li>
          <li>${x.exif && x.exif.gps ? `Photo GPS ${x.exif.gps.lat}, ${x.exif.gps.lng}${x.gps_distance_km !== null && x.gps_distance_km !== undefined ? ` · <b class="${x.gps_distance_km > 5 ? 'is-bad' : 'is-good'}">${x.gps_distance_km} km</b> from the reported position` : (x.exif.gps_used_for_pin ? ' · used as the pin (reporter gave no GPS)' : '')}` : 'No GPS in the photo'}</li>
          <li>${x.duplicates && x.duplicates.length ? `<b class="is-bad">Same image seen ${x.duplicates.length}× before:</b> ${x.duplicates.map(d => `<a href="${esc(d.url)}" target="_blank">${esc(d.title)}</a> (${esc(d.status)})`).join(', ')}` : 'Image not seen before on ME'}</li>
          <li>Reverse search: <a href="https://lens.google.com/uploadbyurl?url=${encodeURIComponent(x.public_media_url || '')}" target="_blank" rel="noopener">Google Lens</a> · <a href="https://tineye.com/search?url=${encodeURIComponent(x.public_media_url || '')}" target="_blank" rel="noopener">TinEye</a> · <a href="https://yandex.com/images/search?rpt=imageview&url=${encodeURIComponent(x.public_media_url || '')}" target="_blank" rel="noopener">Yandex</a> <span class="sub">(24-hour signed link to the quarantined file)</span></li>
        </ul></div>` : ''}
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
    $('#queue').innerHTML = a.length ? a.map(reviewCard).join('') : '<div class="empty"><div class="empty__glyph">—</div><h3>Nothing waiting.</h3><p>Community reports appear here after the Trust Engine screens them.</p></div>';
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
    $('#comments-list').innerHTML = a.length ? a.map(x => `<div class="review" data-id="${x.id}"><div class="meta"><b>${esc(x.author)}</b> on <a href="/story/${esc(x.slug)}" target="_blank">${esc(x.title)}</a> · ${fmt(x.created_at)}</div><p>${esc(x.body)}</p><details><summary>Moderation</summary><pre class="audit">${esc(x.moderation_json || '')}</pre></details><div class="actions"><button class="btn btn--good btn--sm" data-c="publish">Publish</button><button class="btn btn--hot btn--sm" data-c="reject">Reject</button></div></div>`).join('') : '<div class="empty"><div class="empty__glyph">—</div><h3>No flagged comments.</h3></div>';
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
      <td><select data-plan ${admin ? '' : 'disabled'}>${opts(['free', 'ME+'], x.plan)}</select></td>
      <td><input type="number" data-rep min="0" max="100" value="${x.reputation}" style="width:70px" ${admin ? '' : 'disabled'}></td>
      <td class="inline" style="gap:4px">${admin ? '<button class="btn btn--ghost btn--sm" data-save-user>Save</button>' + (x.id !== me.id ? '<button class="btn btn--hot btn--sm" data-delete-user>Delete…</button>' : '') : ''}</td></tr>`).join('')}</tbody></table></div>`;
    $('#user-add-toggle').hidden = !admin;
  }
  $('#user-add-toggle').addEventListener('click', () => { const f = $('#user-add'); f.hidden = !f.hidden; if (!f.hidden) f.display_name.focus(); });
  $('#user-add').addEventListener('submit', async e => {
    e.preventDefault(); const out = $('#user-add-result'); out.classList.remove('is-error');
    try {
      const j = await api('/api/admin/users', { method: 'POST', body: new FormData(e.target) });
      out.textContent = j.temporary_password ? `Account created. Temporary password: ${j.temporary_password} — pass it on securely; it is not shown again.` : 'Account created.';
      e.target.reset(); loadUsers(); summary();
    } catch (err) { out.classList.add('is-error'); out.textContent = err.message; }
  });
  $('#users-q').addEventListener('input', () => { clearTimeout(window._uq); window._uq = setTimeout(loadUsers, 300); });
  $('#users-table').addEventListener('click', async e => {
    if (!e.target.hasAttribute('data-save-user')) return;
    const row = e.target.closest('tr');
    try { await api('/api/admin/users/' + row.dataset.id, { method: 'POST', body: fd({ role: $('[data-role]', row).value, is_verified: $('[data-verified]', row).checked ? '1' : '0', reputation: $('[data-rep]', row).value, title: $('[data-title]', row).value, desk: $('[data-desk]', row).value, plan: $('[data-plan]', row).value }) }); toast('User saved'); } catch (err) { toast(err.message); }
  });
  $('#users-table').addEventListener('click', async e => {
    if (!e.target.hasAttribute('data-delete-user')) return;
    const row = e.target.closest('tr'); const email = $('.sub', row).textContent.trim();
    const confirmEmail = prompt(`Delete this account permanently? Their published reports stay under the byline text; sessions, follows, alerts, saved lists and adverts are removed.\n\nType the email address to confirm:`);
    if (confirmEmail === null) return;
    try { await api('/api/admin/users/' + row.dataset.id + '/delete', { method: 'POST', body: fd({ confirm: confirmEmail }) }); toast('Account deleted'); loadUsers(); summary(); } catch (err) { toast(err.message); }
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

  /* ---- section check (editor override queue) ---- */
  async function loadSections() {
    const a = await api('/api/admin/section-check');
    $('#sections-table').innerHTML = a.length ? `<div class="tablewrap"><table class="table sectioncheck"><thead><tr><th>Story</th><th>Filed as</th><th>Suggested</th><th></th></tr></thead><tbody>${a.map(x => `<tr data-id="${x.id}">
      <td><b><a href="${esc(x.url)}" target="_blank">${esc(x.title)}</a></b><span class="sub">${esc(x.source_name)} · ${esc(x.county || '')} · ${fmt(x.t)}</span></td>
      <td><span class="chip chip--cat">${esc(x.category)}</span></td>
      <td><select data-to>${opts(CATS, x.suggested_category)}</select></td>
      <td class="actions"><button class="btn btn--good btn--sm" data-refile>Re-file</button><button class="btn btn--ghost btn--sm" data-keep>Keep &amp; lock</button></td></tr>`).join('')}</tbody></table></div>` : '<div class="empty"><div class="empty__glyph">—</div><h3>Every section looks right.</h3><p>New wire stories are checked as they arrive.</p></div>';
  }
  $('#sections-table').addEventListener('click', async e => {
    const row = e.target.closest('tr[data-id]'); if (!row) return;
    try {
      if (e.target.hasAttribute('data-refile')) { await api('/api/admin/stories/' + row.dataset.id + '/refile', { method: 'POST', body: fd({ category: $('[data-to]', row).value }) }); toast('Re-filed'); }
      else if (e.target.hasAttribute('data-keep')) { await api('/api/admin/stories/' + row.dataset.id + '/refile', { method: 'POST', body: fd({ keep: '1' }) }); toast('Kept and locked'); }
      else return;
      row.remove(); summary();
    } catch (err) { toast(err.message); }
  });

  /* ---- notices ---- */
  async function loadNotices() {
    const a = await api('/api/admin/notices?status=' + encodeURIComponent($('#notices-status').value));
    $('#notices-queue').innerHTML = a.length ? a.map(n => `<article class="review" data-id="${n.id}">
      <div class="inline"><span class="status ${esc(n.status)}">${esc(n.status)}</span><span class="chip chip--cat">${esc(n.kind)}</span>${n.plan === 'promoted' ? '<span class="chip chip--plus">Promoted</span>' : ''}<span class="mono" style="margin-left:auto;color:var(--muted)">${fmt(n.created_at)}</span></div>
      <h3>${esc(n.title)}</h3>
      <div class="meta">${esc(n.town || '')}${n.county ? ', Co. ' + esc(n.county) : ''} · ${esc(n.contact_org || n.contact_name || '')}${n.verified_at ? ' · <span class="is-good">email confirmed</span>' : ' · <span class="is-bad">not confirmed yet</span>'}</div>
      ${n.funeral_at ? `<p><b>Funeral</b> ${esc(n.funeral_at)} ${esc(n.funeral_venue || '')}</p>` : ''}${n.event_at ? `<p><b>When</b> ${esc(n.event_at)} ${esc(n.venue || '')}</p>` : ''}
      ${n.reposing ? `<p><b>Reposing</b> ${esc(n.reposing)}</p>` : ''}<p>${esc(n.body || '')}</p>${n.family_message ? `<p><i>${esc(n.family_message)}</i></p>` : ''}
      <label class="form-label">Editor note (emailed to the sender)<textarea data-note rows="2">${esc(n.editorial_note || '')}</textarea></label>
      <div class="actions">${n.status !== 'published' ? '<button class="btn btn--good btn--sm" data-n="publish">Publish</button>' : '<button class="btn btn--warn btn--sm" data-n="unpublish">Unpublish</button>'}<button class="btn btn--ghost btn--sm" data-n="promote">${n.plan === 'promoted' ? 'Un-promote' : 'Promote 30 days'}</button><button class="btn btn--hot btn--sm" data-n="reject">Reject</button>${n.status === 'published' ? `<a class="btn btn--dark btn--sm" href="${esc(n.url)}" target="_blank">View</a>` : ''}</div>
    </article>`).join('') : '<div class="empty"><div class="empty__glyph">—</div><h3>Nothing waiting.</h3><p>Notices appear here once the sender confirms from their email.</p></div>';
  }
  $('#notices-status').addEventListener('change', loadNotices);
  $('#notices-queue').addEventListener('click', async e => {
    const d = e.target.dataset.n; if (!d) return; const card = e.target.closest('[data-id]');
    try { await api('/api/admin/notices/' + card.dataset.id, { method: 'POST', body: fd({ decision: d, note: $('[data-note]', card).value }) }); toast('Done'); loadNotices(); summary(); } catch (err) { toast(err.message); }
  });

  /* ---- closures ---- */
  async function loadClosures() {
    const a = await api('/api/admin/closures');
    $('#closures-table').innerHTML = a.length ? `<div class="tablewrap"><table class="table"><thead><tr><th>School</th><th>Closed</th><th>Reason</th><th>Contact</th><th>Status</th><th></th></tr></thead><tbody>${a.map(c => `<tr data-id="${c.id}">
      <td><b>${esc(c.school)}</b><span class="sub">${esc(c.town || '')} Co. ${esc(c.county)}</span></td><td>${esc(c.closed_on)}${c.reopens_on ? ' → ' + esc(c.reopens_on) : ''}</td><td>${esc(c.reason || '')}</td>
      <td>${esc(c.contact_name || '')} <span class="sub">${esc(c.contact_role || '')} · ${esc(c.contact_email)}${c.verified_at ? ' · confirmed' : ' · unconfirmed'}</span></td>
      <td><span class="status ${esc(c.status)}">${esc(c.status)}</span></td>
      <td class="actions">${c.status !== 'published' ? '<button class="btn btn--good btn--sm" data-cl="publish">Publish</button>' : '<button class="btn btn--warn btn--sm" data-cl="unpublish">Remove</button>'}<button class="btn btn--hot btn--sm" data-cl="reject">Reject</button></td></tr>`).join('')}</tbody></table></div>` : '<div class="empty"><div class="empty__glyph">—</div><h3>No closures submitted.</h3></div>';
  }
  $('#closures-table').addEventListener('click', async e => {
    const d = e.target.dataset.cl; if (!d) return;
    try { await api('/api/admin/closures/' + e.target.closest('tr').dataset.id, { method: 'POST', body: fd({ decision: d }) }); loadClosures(); summary(); } catch (err) { toast(err.message); }
  });

  /* ---- corrections & takedowns ---- */
  async function loadTrust() {
    const [t, c] = await Promise.all([api('/api/admin/takedowns'), api('/api/admin/corrections')]);
    $('#takedowns-table').innerHTML = t.length ? `<div class="tablewrap"><table class="table"><thead><tr><th>Page</th><th>Reason</th><th>From</th><th>Status</th><th></th></tr></thead><tbody>${t.map(x => `<tr data-id="${x.id}"><td><a href="${esc(x.url)}" target="_blank">${esc(x.url)}</a><span class="sub">${fmt(x.created_at)} · ${esc(x.detail || '')}</span></td><td>${esc(x.reason)}</td><td>${esc(x.contact)}</td><td><span class="status ${esc(x.status)}">${esc(x.status)}</span>${x.note ? `<span class="sub">${esc(x.note)}</span>` : ''}</td><td class="actions"><input data-tnote placeholder="Note" style="width:140px"><button class="btn btn--good btn--sm" data-t="actioned">Actioned</button><button class="btn btn--ghost btn--sm" data-t="declined">Declined</button></td></tr>`).join('')}</tbody></table></div>` : '<div class="empty"><div class="empty__glyph">—</div><h3>No removal requests.</h3></div>';
    $('#corrections-table').innerHTML = c.length ? `<div class="tablewrap"><table class="table"><thead><tr><th>Date</th><th>Story</th><th>Correction</th><th>Editor</th></tr></thead><tbody>${c.map(x => `<tr><td>${fmt(x.created_at)}</td><td>${x.slug ? `<a href="/story/${esc(x.slug)}" target="_blank">${esc(x.story_title || x.title)}</a>` : esc(x.title)}</td><td>${esc(x.summary)}</td><td>${esc(x.editor || '')}</td></tr>`).join('')}</tbody></table></div>` : '<p class="form__legal">No corrections logged.</p>';
  }
  $('#takedowns-table').addEventListener('click', async e => {
    const d = e.target.dataset.t; if (!d) return; const row = e.target.closest('tr');
    try { await api('/api/admin/takedowns/' + row.dataset.id, { method: 'POST', body: fd({ status: d, note: $('[data-tnote]', row).value }) }); loadTrust(); summary(); } catch (err) { toast(err.message); }
  });
  $('#correction-form').addEventListener('submit', async e => {
    e.preventDefault();
    try { await api('/api/admin/corrections', { method: 'POST', body: new FormData(e.target) }); toast('Correction published'); e.target.reset(); loadTrust(); } catch (err) { toast(err.message); }
  });

  /* ---- settings ---- */
  async function loadGateways() {
    const box = $('#gateways');
    try {
      const g = await api('/api/admin/gateways');
      const src = f => f.source === 'panel' ? '<span class="chip chip--ok">set here</span>' : (f.source === 'env' ? '<span class="chip">from .env</span>' : '<span class="chip chip--muted">not set</span>');
      const field = ([k, f]) => `<label><span class="gwlabel">${esc(f.label)} ${src(f)}</span>${k === 'PAYPAL_MODE'
        ? `<select name="${k}"><option value="">— keep ${esc(f.masked || 'sandbox')} —</option><option value="sandbox">sandbox</option><option value="live">live</option></select>`
        : `<input name="${k}" type="password" autocomplete="new-password" placeholder="${f.set ? esc(f.masked) + '  (leave blank to keep)' : 'Paste here'}">`}<small class="form__hint">${esc(f.hint)}${f.source === 'panel' ? ` · <a href="#" data-clear="${k}">remove</a>` : ''}</small></label>`;
      const group = name => Object.entries(g.fields).filter(([, f]) => f.group === name).map(field).join('');
      box.innerHTML = `<div class="inline" style="justify-content:space-between;flex-wrap:wrap;gap:10px"><span class="kicker">Payment gateways</span><span class="mono">Stripe: <b class="${g.stripe ? 'is-good' : 'is-bad'}">${g.stripe ? 'connected' : 'not connected'}</b> · PayPal: <b class="${g.paypal ? 'is-good' : 'is-bad'}">${g.paypal ? 'connected' : 'not connected'}</b></span></div>
        <p class="panel__note" style="margin:6px 0 12px">Paste the keys from your Stripe and PayPal dashboards here; they are encrypted before they are stored (key: <code>${esc(g.key_source)}</code>) and never shown again, only the last four characters. Nothing needs to be edited on the server. Add these webhook URLs in each dashboard: <code>${esc(g.webhooks.stripe)}</code> (events: checkout.session.completed, payment_intent.succeeded, charge.refunded, customer.subscription.*) and <code>${esc(g.webhooks.paypal)}</code> (PAYMENT.CAPTURE.COMPLETED, PAYMENT.CAPTURE.REFUNDED, BILLING.SUBSCRIPTION.*).</p>
        <form id="gateways-form" class="form">
          <div class="gwgrid"><div><h3 style="margin:0 0 8px">Stripe</h3><div class="settingsgrid">${group('stripe')}</div><div class="inline" style="margin-top:10px"><button class="btn btn--ghost btn--sm" type="button" data-test="stripe">Test Stripe connection</button></div></div>
          <div><h3 style="margin:0 0 8px">PayPal</h3><div class="settingsgrid">${group('paypal')}</div><div class="inline" style="margin-top:10px"><button class="btn btn--ghost btn--sm" type="button" data-test="paypal">Test PayPal connection</button></div></div></div>
          <div class="form__actions"><button class="btn btn--primary" type="submit">Save gateway keys</button><span class="form__hint">Only fields you fill in are changed.</span></div>
          <p class="form__result" id="gateways-result"></p>
        </form>`;
      $('#gateways-form').addEventListener('submit', async e => {
        e.preventDefault(); const out = $('#gateways-result'); out.classList.remove('is-error');
        try { const j = await api('/api/admin/gateways', { method: 'POST', body: new FormData(e.target) }); out.textContent = j.changed.length ? 'Saved: ' + j.changed.join(', ') : 'Nothing changed.'; toast('Gateway keys saved'); loadGateways(); } catch (err) { out.classList.add('is-error'); out.textContent = err.message; }
      });
      box.addEventListener('click', async e => {
        const t = e.target.closest('[data-test]'), c = e.target.closest('[data-clear]');
        const out = $('#gateways-result');
        if (t) { t.disabled = true; out.classList.remove('is-error'); out.textContent = 'Checking…'; try { const j = await api('/api/admin/gateways/test', { method: 'POST', body: fd({ gateway: t.dataset.test }) }); out.textContent = j.message; } catch (err) { out.classList.add('is-error'); out.textContent = err.message; } t.disabled = false; }
        if (c) { e.preventDefault(); if (!confirm('Remove this key from the newsroom store?')) return; try { await api('/api/admin/gateways', { method: 'POST', body: fd({ ['clear_' + c.dataset.clear]: '1' }) }); toast('Removed'); loadGateways(); } catch (err) { toast(err.message); } }
      });
    } catch (e) { box.hidden = true; }
  }
  async function loadSettings() {
    loadGateways();
    try {
      const s = await api('/api/admin/settings');
      const groups = {};
      Object.entries(s).forEach(([k, v]) => { (groups[v.group] ??= []).push([k, v]); });
      const field = ([k, v]) => `<label>${esc(v.label)}${v.type === 'select' ? `<select name="${k}">${v.options.map(o => `<option ${o === v.value ? 'selected' : ''}>${esc(o)}</option>`).join('')}</select>` : `<input name="${k}" value="${esc(v.type === 'cents' ? (Number(v.value) / 100).toFixed(2) : v.value)}" ${k === 'site_url' ? 'placeholder="https://menews.ie"' : ''}>`}${v.hint ? `<small class="form__hint">${esc(v.hint)}</small>` : ''}</label>`;
      $('#settings-grid').innerHTML = Object.entries(groups).map(([g, items]) => items.length > 8 ? `<details><summary class="mono" style="cursor:pointer;color:var(--cy);padding:8px 0">${esc(g)} (${items.length})</summary><div class="settingsgrid" style="margin-top:10px">${items.map(field).join('')}</div></details>` : items.map(field).join('')).join('');
    } catch (e) { $('#settings-grid').innerHTML = '<div class="empty"><h3>Settings require administrator access.</h3></div>'; }
  }
  $('#settings-form').addEventListener('submit', async e => {
    e.preventDefault(); const out = $('#settings-result');
    try { const j = await api('/api/admin/settings', { method: 'POST', body: new FormData(e.target) }); out.textContent = 'Saved ' + j.saved.length + ' settings.'; toast('Settings saved'); } catch (err) { out.textContent = err.message; }
  });

  async function loadAudit() {
    try {
      const a = await api('/api/admin/audit');
      $('#audit-list').innerHTML = `<div class="tablewrap"><table class="table"><thead><tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>Detail</th></tr></thead><tbody>${a.map(x => `<tr><td>${fmt(x.created_at)}</td><td>${esc(x.display_name || x.user_id || 'system')}</td><td class="mono">${esc(x.action)}</td><td>${esc(x.entity_type || '')} <span class="sub">${esc(x.entity_id || '')}</span></td><td>${esc(x.detail || '')}</td></tr>`).join('')}</tbody></table></div>`;
    } catch (e) { $('#audit-list').innerHTML = '<div class="empty"><h3>Audit log requires administrator access.</h3></div>'; }
  }

  init();
})();
