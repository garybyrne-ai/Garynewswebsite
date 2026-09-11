/* ME News Ireland — site runtime (no framework, no build step) */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const esc = s => String(s ?? '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));

  /* ---------- API ---------- */
  async function api(url, opt = {}) {
    opt.headers = Object.assign({ 'X-Requested-With': 'MENews', 'Accept': 'application/json' }, opt.headers || {});
    opt.credentials = 'same-origin';
    const r = await fetch(url, opt);
    let j = {};
    try { j = await r.json(); } catch (e) { /* empty body */ }
    if (!r.ok) throw new Error(j.detail || ('Request failed (' + r.status + ')'));
    return j;
  }
  window.ME = window.ME || {};
  window.ME.api = api;
  window.ME.esc = esc;

  /* ---------- toast ---------- */
  let toastTimer;
  function toast(msg) {
    const t = $('#toast'); if (!t) return;
    t.textContent = msg; t.classList.add('is-show');
    clearTimeout(toastTimer); toastTimer = setTimeout(() => t.classList.remove('is-show'), 2600);
  }
  window.ME.toast = toast;

  /* ---------- theme ---------- */
  const themeBtn = $('#theme-toggle');
  if (themeBtn) themeBtn.addEventListener('click', () => {
    const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', next);
    try { localStorage.setItem('me_theme', next); } catch (e) { }
  });

  /* ---------- text size (stored per device) ---------- */
  const sizeBtns = $$('[data-textsize]');
  if (sizeBtns.length) {
    const cur = () => document.documentElement.getAttribute('data-textsize') || '';
    const paint = () => sizeBtns.forEach(b => b.classList.toggle('is-active', b.dataset.textsize === cur()));
    sizeBtns.forEach(b => b.addEventListener('click', () => {
      const v = b.dataset.textsize;
      if (v) document.documentElement.setAttribute('data-textsize', v); else document.documentElement.removeAttribute('data-textsize');
      try { if (v) localStorage.setItem('me_textsize', v); else localStorage.removeItem('me_textsize'); } catch (e) { }
      paint();
    }));
    paint();
  }

  /* ---------- HUD clock (Irish time) ---------- */
  const clock = $('#hud-clock'), dateEl = $('#hud-date');
  if (clock) {
    const tf = new Intl.DateTimeFormat('en-IE', { timeZone: 'Europe/Dublin', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
    const df = new Intl.DateTimeFormat('en-IE', { timeZone: 'Europe/Dublin', weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
    const tick = () => { const d = new Date(); clock.textContent = tf.format(d); if (dateEl) dateEl.textContent = df.format(d) + ' · Dublin'; };
    tick(); setInterval(tick, 1000);
  }

  /* ---------- reveal on scroll ---------- */
  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver(entries => entries.forEach(en => { if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); } }), { rootMargin: '0px 0px -8% 0px', threshold: .05 });
    $$('.reveal').forEach(el => io.observe(el));
  } else document.documentElement.classList.add('no-observer');

  /* ---------- account menu ---------- */
  const accToggle = $('#account-toggle'), accMenu = $('#account-menu');
  if (accToggle) {
    accToggle.addEventListener('click', e => { e.stopPropagation(); accMenu.classList.toggle('is-open'); accToggle.setAttribute('aria-expanded', accMenu.classList.contains('is-open')); });
    document.addEventListener('click', () => accMenu.classList.remove('is-open'));
  }
  $$('[data-logout]').forEach(b => b.addEventListener('click', async () => {
    try { await api('/api/auth/logout', { method: 'POST' }); } catch (e) { }
    try { localStorage.removeItem('me_token'); } catch (e) { }
    location.href = '/';
  }));
  if (window.ME.user) {
    api('/api/me').then(me => { const b = $('#notify-badge'); if (b && me.unread) { b.hidden = false; b.textContent = me.unread; } }).catch(() => { });
  }

  /* ---------- modals ---------- */
  function openModal(id) { const m = $('#' + id); if (!m) return; m.hidden = false; document.body.style.overflow = 'hidden'; const f = m.querySelector('input:not([type=hidden]),textarea,select'); if (f) setTimeout(() => f.focus(), 50); }
  function closeModals() { $$('.modal').forEach(m => m.hidden = true); document.body.style.overflow = ''; }
  $$('.modal [data-close]').forEach(b => b.addEventListener('click', closeModals));
  $$('.modal').forEach(m => m.addEventListener('click', e => { if (e.target === m) closeModals(); }));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModals(); });
  window.ME.openModal = openModal; window.ME.closeModals = closeModals;

  /* ---------- auth ---------- */
  function showAuth(view) {
    $$('[data-auth-tab]').forEach(b => b.classList.toggle('is-active', b.dataset.authTab === view));
    $$('[data-auth-view]').forEach(f => f.hidden = f.dataset.authView !== view);
    $('#auth-title').textContent = view === 'register' ? 'Create your ME News account' : 'Sign in to ME News';
    $('#auth-result').textContent = '';
    openModal('auth-modal');
  }
  window.ME.showAuth = showAuth;
  $$('[data-open-auth]').forEach(b => b.addEventListener('click', () => showAuth(b.dataset.openAuth || 'signin')));
  $$('[data-auth-tab]').forEach(b => b.addEventListener('click', () => showAuth(b.dataset.authTab)));
  const nextUrl = () => { const p = new URLSearchParams(location.search); const n = p.get('next'); return n && n.startsWith('/') ? n : null; };
  async function authSubmit(e, url) {
    e.preventDefault();
    const out = $('#auth-result'); out.classList.remove('is-error'); out.textContent = 'One moment…';
    try {
      const j = await api(url, { method: 'POST', body: new FormData(e.target) });
      try { localStorage.setItem('me_token', j.token); } catch (err) { }
      out.textContent = 'Signed in as ' + j.user.display_name;
      location.href = nextUrl() || location.pathname + (location.hash || '');
      location.reload();
    } catch (err) { out.classList.add('is-error'); out.textContent = err.message; }
  }
  $('#signin-form')?.addEventListener('submit', e => authSubmit(e, '/api/auth/login'));
  $('#register-form')?.addEventListener('submit', e => authSubmit(e, '/api/auth/register'));
  const qp = new URLSearchParams(location.search);
  if (qp.get('auth')) showAuth(qp.get('auth') === 'register' ? 'register' : 'signin');

  /* ---------- locations & counties ---------- */
  let countiesLoaded = false;
  async function loadCounties() {
    if (countiesLoaded) return; countiesLoaded = true;
    try {
      const a = await api('/api/counties');
      const html = '<option value="">Select county</option>' + a.map(x => `<option value="${esc(x.county)}">${esc(x.county)}</option>`).join('');
      $$('[data-county-select]').forEach(s => { const v = s.value; s.innerHTML = html; s.value = v; });
    } catch (e) { }
  }
  let locTimer;
  async function loadLocations(q = '') {
    try {
      const a = await api('/api/locations?q=' + encodeURIComponent(q) + '&limit=300');
      const dl = $('#all-locations'); if (dl) dl.innerHTML = a.map(x => `<option value="${esc(x.town)}">${esc(x.county)}</option>`).join('');
      return a;
    } catch (e) { return []; }
  }
  window.ME.loadCounties = loadCounties; window.ME.loadLocations = loadLocations;
  $$('input[list="all-locations"]').forEach(inp => {
    inp.addEventListener('focus', () => { loadCounties(); if (!$('#all-locations').children.length) loadLocations(''); });
    inp.addEventListener('input', () => { clearTimeout(locTimer); locTimer = setTimeout(() => loadLocations(inp.value), 200); });
    inp.addEventListener('change', async () => {
      const a = await loadLocations(inp.value);
      const hit = a.find(x => x.town.toLowerCase() === inp.value.toLowerCase());
      const form = inp.closest('form');
      if (hit && form) { const sel = form.querySelector('[data-county-select]'); if (sel) sel.value = hit.county; const prov = form.querySelector('#report-province'); if (prov) prov.value = hit.province || ''; }
    });
  });

  /* ---------- report ---------- */
  $$('[data-open-report]').forEach(b => b.addEventListener('click', () => {
    closeDrawer(); loadCounties(); loadLocations(''); openModal('report-modal');
  }));
  $('[data-gps]')?.addEventListener('click', () => {
    if (!navigator.geolocation) return toast('Location is unavailable');
    navigator.geolocation.getCurrentPosition(p => { $('#report-lat').value = p.coords.latitude.toFixed(6); $('#report-lng').value = p.coords.longitude.toFixed(6); toast('GPS added to your report'); }, () => toast('Location permission was not granted'));
  });
  $('#report-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const out = $('#report-result'); out.classList.remove('is-error'); out.textContent = 'Uploading privately and running safety checks…';
    const btn = e.target.querySelector('[type=submit]'); btn.disabled = true;
    try {
      const j = await api('/api/report', { method: 'POST', body: new FormData(e.target) });
      out.textContent = j.message || `Thanks — your report is with the newsroom (safety ${j.safety_score}/100, confidence ${j.trust_score}/100). You'll hear back either way.`;
      e.target.reset(); const pv = $('.dropzone__preview'); if (pv) { pv.hidden = true; pv.innerHTML = ''; } setTimeout(closeModals, j.message ? 6000 : 3200);
    } catch (err) { out.classList.add('is-error'); out.textContent = err.message; }
    btn.disabled = false;
  });

  /* ---------- story page ---------- */
  const article = $('.article[data-story-id]');
  if (article) {
    const id = article.dataset.storyId;
    const bar = $('#read-progress');
    if (bar) {
      const onScroll = () => { const h = document.documentElement; const max = h.scrollHeight - h.clientHeight; bar.style.width = (max > 0 ? Math.min(100, h.scrollTop / max * 100) : 0) + '%'; };
      document.addEventListener('scroll', onScroll, { passive: true }); onScroll();
    }
    $('[data-confirm]')?.addEventListener('click', async () => {
      if (!window.ME.user) return showAuth('signin');
      try { const j = await api('/api/story/' + id + '/confirm', { method: 'POST', body: new FormData() }); $('#confirm-count').textContent = j.confirmations; toast('Thank you — confirmation recorded'); }
      catch (err) { toast(err.message); }
    });
    $('[data-share]')?.addEventListener('click', async e => {
      const data = { title: e.currentTarget.dataset.title, url: location.href };
      try { if (navigator.share) await navigator.share(data); else { await navigator.clipboard.writeText(location.href); toast('Link copied'); } } catch (err) { }
    });
    $('#comment-form')?.addEventListener('submit', async e => {
      e.preventDefault();
      if (!window.ME.user) return showAuth('signin');
      try {
        const j = await api('/api/story/' + id + '/comment', { method: 'POST', body: new FormData(e.target) });
        toast(j.status === 'published' ? 'Comment posted' : 'Comment sent for review');
        e.target.reset();
        if (j.status === 'published') {
          $('#no-comments')?.remove();
          $('#comments').innerHTML = j.comments.map(c => `<div class="comment"><span class="avatar avatar--sm" style="--h:${Number(c.accent) || 200}"><span>${esc((c.author || 'M').slice(0, 1).toUpperCase())}</span></span><div><b>${esc(c.author)}</b><time class="mono">just now</time><p>${esc(c.body)}</p></div></div>`).join('');
          $('#comment-count').textContent = j.comments.length + ' published';
        }
      } catch (err) { toast(err.message); }
    });
  }

  /* ---------- near me (geolocation) ---------- */
  const setCookie = (k, v, days) => { document.cookie = k + '=' + encodeURIComponent(v) + ';path=/;max-age=' + (days * 86400) + ';SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : ''); };
  const delCookie = k => { document.cookie = k + '=;path=/;max-age=0;SameSite=Lax'; };
  const dismissed = () => { try { return (Number(localStorage.getItem('me_loc_dismissed') || 0)) > Date.now(); } catch (e) { return false; } };
  const locbar = null;
  /* county picker: one tap on first visit, no permission needed */
  const countyModal = $('#county-modal');
  const openCounty = () => openModal('county-modal');
  $$('[data-open-county]').forEach(b => b.addEventListener('click', e => { e.preventDefault(); closeDrawer(); openCounty(); }));
  if (countyModal && !window.ME.located && !dismissed() && document.body.classList.contains('page-home') && !new URLSearchParams(location.search).get('county')) setTimeout(openCounty, 900);
  $$('[data-county]').forEach(b => b.addEventListener('click', () => { setCookie('me_county', b.dataset.county, 180); delCookie('me_loc'); toast('Leading with ' + b.dataset.county); location.reload(); }));
  $('[data-county-later]')?.addEventListener('click', () => { closeModals(); try { localStorage.setItem('me_loc_dismissed', String(Date.now() + 7 * 86400000)); } catch (e) { } });
  async function applyPosition(lat, lng) {
    setCookie('me_loc', lat.toFixed(3) + ',' + lng.toFixed(3), 30); delCookie('me_county');
    const near = $('[data-near]');
    if (near) {
      near.classList.add('is-loading');
      try {
        const j = await api(`/api/near?lat=${lat.toFixed(3)}&lng=${lng.toFixed(3)}${document.body.classList.contains('page-home') ? '&compact=1&limit=8' : '&limit=24'}`);
        const tmp = document.createElement('div'); tmp.innerHTML = j.html;
        const fresh = tmp.firstElementChild; near.replaceWith(fresh); fresh.classList.add('is-in'); bindNear();
        toast(j.place.in_ireland ? 'Local section set to ' + j.title : 'You seem to be outside Ireland — choose a county');
        if (!document.body.classList.contains('page-home')) location.reload();
      } catch (e) { near.classList.remove('is-loading'); toast(e.message); }
    } else location.reload();
  }
  function locateMe(btn) {
    if (!('geolocation' in navigator)) return toast('Location is not available in this browser');
    if (location.protocol !== 'https:' && !['localhost', '127.0.0.1'].includes(location.hostname)) return toast('Location needs a secure (https) connection');
    if (btn) { btn.disabled = true; btn.textContent = 'Locating…'; }
    navigator.geolocation.getCurrentPosition(
      pos => { closeModals(); applyPosition(pos.coords.latitude, pos.coords.longitude); },
      err => { if (btn) { btn.disabled = false; btn.textContent = 'Use my location'; } toast(err.code === 1 ? 'Location permission was not granted — you can pick a county instead' : 'Could not get your location'); },
      { enableHighAccuracy: false, timeout: 12000, maximumAge: 600000 }
    );
  }
  function bindNear() {
    $$('[data-locate-me]').forEach(b => { if (b.dataset.bound) return; b.dataset.bound = '1'; b.addEventListener('click', () => locateMe(b)); });
    $$('[data-county-pick]').forEach(sel => { if (sel.dataset.bound) return; sel.dataset.bound = '1'; sel.addEventListener('change', () => { if (!sel.value) return; setCookie('me_county', sel.value, 180); delCookie('me_loc'); location.reload(); }); });
    $$('[data-forget-location]').forEach(b => { if (b.dataset.bound) return; b.dataset.bound = '1'; b.addEventListener('click', () => { delCookie('me_loc'); delCookie('me_county'); try { localStorage.setItem('me_loc_dismissed', String(Date.now() + 7 * 86400000)); } catch (e) { } toast('Location forgotten'); location.reload(); }); });
  }
  bindNear();

  /* ---------- drawer, More menu, consent ---------- */
  const drawer = $('#drawer');
  function closeDrawer() { if (drawer) { drawer.hidden = true; document.body.style.overflow = ''; } }
  $$('[data-open-drawer]').forEach(b => b.addEventListener('click', () => { drawer.hidden = false; document.body.style.overflow = 'hidden'; }));
  $$('[data-close-drawer]').forEach(b => b.addEventListener('click', closeDrawer));
  drawer?.addEventListener('click', e => { if (e.target === drawer) closeDrawer(); });
  const more = $('[data-more]');
  if (more) {
    const btn = $('.moremenu__btn', more), panel = $('.moremenu__panel', more);
    const set = open => { panel.hidden = !open; btn.setAttribute('aria-expanded', open ? 'true' : 'false'); };
    btn.addEventListener('click', e => { e.stopPropagation(); set(panel.hidden); });
    document.addEventListener('click', e => { if (!more.contains(e.target)) set(false); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') set(false); });
  }
  const consent = $('#consent');
  if (consent) {
    let seen = false; try { seen = !!localStorage.getItem('me_consent'); } catch (e) { }
    if (!seen && !document.body.classList.contains('page-app')) consent.hidden = false;
    $$('[data-consent]').forEach(b => b.addEventListener('click', () => { try { localStorage.setItem('me_consent', b.dataset.consent); } catch (e) { } consent.hidden = true; }));
  }
  if (window.innerWidth < 761) document.body.classList.add('has-bottombar');

  /* ---------- photo-first report form ---------- */
  const dz = $('[data-dropzone]');
  if (dz) {
    const input = $('input[type=file]', dz), prev = $('.dropzone__preview', dz), intro = $('.dropzone__in', dz);
    const show = f => {
      if (!f) return; prev.hidden = false; prev.innerHTML = '';
      const url = URL.createObjectURL(f);
      const el = document.createElement(f.type.startsWith('video') ? 'video' : 'img'); el.src = url; if (el.tagName === 'VIDEO') el.controls = true;
      prev.appendChild(el); $('b', intro).textContent = f.name; $('small', intro).textContent = 'Tap to change';
    };
    input.addEventListener('change', () => show(input.files[0]));
    ['dragenter', 'dragover'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('is-over'); }));
    dz.addEventListener('drop', e => { if (e.dataTransfer.files[0]) { input.files = e.dataTransfer.files; show(e.dataTransfer.files[0]); } });
  }

  /* ---------- live wire refresh ---------- */
  const wireStatus = $('#wire-status');
  function ago(iso) { if (!iso) return ''; const s = (Date.now() - new Date(iso).getTime()) / 1000; if (s < 60) return 'just now'; if (s < 3600) return Math.floor(s / 60) + ' min ago'; return Math.floor(s / 3600) + ' hr ago'; }
  if (window.ME.wireEnabled && wireStatus && !document.body.classList.contains('page-app')) {
    setTimeout(async () => {
      try {
        wireStatus.textContent = 'Checking wire…';
        const j = await api('/api/wire/refresh', { method: 'POST' });
        wireStatus.dataset.last = j.last_refresh || wireStatus.dataset.last;
        wireStatus.textContent = 'Updated ' + ago(wireStatus.dataset.last);
        if (j.inserted > 0) { const pill = $('#new-stories'); pill.hidden = false; pill.querySelector('button').textContent = `${j.inserted} new stor${j.inserted === 1 ? 'y' : 'ies'} on the wire — refresh`; pill.querySelector('button').onclick = () => location.reload(); }
      } catch (e) { wireStatus.textContent = 'Updated ' + ago(wireStatus.dataset.last); }
    }, 2500);
    setInterval(() => { wireStatus.textContent = 'Updated ' + ago(wireStatus.dataset.last); }, 60000);
  }

})();

/* ---------- local layer: alerts sign-up, closures, notices, poll, takedown, bulletin ---------- */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const api = (u, o) => window.ME.api(u, o);
  const toast = m => window.ME.toast && window.ME.toast(m);
  const bindForm = (sel, url, resultSel, after) => $$(sel).forEach(f => f.addEventListener('submit', async e => {
    e.preventDefault();
    const out = resultSel ? $(resultSel, f) || $(resultSel) : null; const btn = f.querySelector('[type=submit]');
    if (out) { out.classList.remove('is-error'); out.textContent = 'One moment…'; }
    if (btn) btn.disabled = true;
    try { const j = await api(url, { method: 'POST', body: new FormData(f) }); if (out) out.textContent = j.message || 'Done.'; toast(j.message || 'Done'); if (after) after(j, f); }
    catch (err) { if (out) { out.classList.add('is-error'); out.textContent = err.message; } else toast(err.message); }
    if (btn) btn.disabled = false;
  }));
  $$('[data-subscribe]').forEach(f => { const c = $('[data-subscribe-county]', f); if (c && !c.value && window.ME.county) c.value = window.ME.county; if (c && c.tagName === 'INPUT' && !c.value) { c.value = ''; } });
  $$('[data-subscribe]').forEach(f => f.addEventListener('submit', e => { const c = $('[data-subscribe-county]', f); if (c && !c.value) { e.preventDefault(); e.stopImmediatePropagation(); toast('Choose your county first'); window.ME.openModal && window.ME.openModal('county-modal'); } }, true));
  bindForm('[data-subscribe]', '/api/alerts/subscribe', '[data-subscribe-result]', (j, f) => { if (j.confirmed) f.reset(); });
  bindForm('[data-closure]', '/api/alerts/closure', '[data-closure-result]', (j, f) => f.reset());
  bindForm('#takedown-form', '/api/takedown', '#takedown-result', (j, f) => f.reset());
  bindForm('#notice-form', '/api/notices', '#notice-result', (j, f) => { f.reset(); window.scrollTo({ top: 0, behavior: 'smooth' }); });

  /* notice form: show the fields for the chosen kind */
  const nf = $('#notice-form');
  if (nf) {
    const sync = () => {
      const k = (nf.querySelector('[name=kind]:checked') || {}).value || 'death';
      $$('[data-for]', nf).forEach(g => g.hidden = !g.dataset.for.split(' ').includes(k));
      const L = (window.NOTICE_LABELS || {})[k]; if (L) { $('[data-title-label]', nf).textContent = L[0]; $('[data-body-label]', nf).textContent = L[1]; }
      const org = nf.querySelector('[name=contact_org_job]'); if (org) org.addEventListener('input', () => { nf.querySelector('[name=contact_org]').value = org.value; });
    };
    $$('[name=kind]', nf).forEach(r => r.addEventListener('change', sync)); sync();
  }

  /* weekly poll */
  document.addEventListener('click', async e => {
    const b = e.target.closest('.pollopt'); if (!b || b.disabled) return;
    const w = b.closest('[data-poll]'); const fd = new FormData(); fd.append('poll_id', w.dataset.poll); fd.append('option', b.dataset.option);
    try {
      const p = await api('/api/poll/vote', { method: 'POST', body: fd });
      $$('.pollopt', w).forEach(o => { const i = Number(o.dataset.option); const pct = p.results.pct[i] || 0; o.classList.add('is-result'); o.classList.toggle('is-mine', p.mine === i); $('i', o).style.setProperty('--v', pct); $('[data-pct]', o).textContent = pct + '%'; });
      const foot = $('[data-poll-foot]', w); if (foot) foot.textContent = 'Thanks · ' + (p.results.total >= 10 ? p.results.total + ' votes so far' : 'tap another option to change') + (!window.ME.county ? ' · choose your county so it counts locally' : '');
      toast('Vote counted' + (window.ME.county ? ' for ' + window.ME.county : ''));
    } catch (err) { toast(err.message); }
  });

  /* 90-second spoken bulletin (Web Speech API) */
  let bulletinEl = null;
  $$('[data-bulletin]').forEach(b => b.addEventListener('click', async () => {
    if (!('speechSynthesis' in window)) return toast('Your browser cannot read aloud');
    if (bulletinEl) { speechSynthesis.cancel(); bulletinEl.remove(); bulletinEl = null; return; }
    try {
      const j = await api('/api/bulletin?county=' + encodeURIComponent(b.dataset.bulletin || ''));
      bulletinEl = document.createElement('div'); bulletinEl.className = 'bulletin';
      bulletinEl.innerHTML = '<span class="livedot"></span><span class="bulletin__line">Reading the ' + (j.county || 'Ireland') + ' bulletin…</span><button type="button">Stop</button>';
      document.body.appendChild(bulletinEl);
      bulletinEl.querySelector('button').onclick = () => { speechSynthesis.cancel(); bulletinEl.remove(); bulletinEl = null; };
      const voices = speechSynthesis.getVoices(); const voice = voices.find(v => /en-IE/i.test(v.lang)) || voices.find(v => /en-GB/i.test(v.lang)) || null;
      j.lines.forEach((line, i) => { const u = new SpeechSynthesisUtterance(line); u.lang = 'en-IE'; if (voice) u.voice = voice; u.rate = 1; u.onstart = () => { if (bulletinEl) bulletinEl.querySelector('.bulletin__line').textContent = line; }; if (i === j.lines.length - 1) u.onend = () => { if (bulletinEl) { bulletinEl.remove(); bulletinEl = null; } }; speechSynthesis.speak(u); });
    } catch (err) { toast(err.message); }
  }));
})();
