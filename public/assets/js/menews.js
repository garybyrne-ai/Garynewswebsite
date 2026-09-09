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
    if (!window.ME.user) { showAuth('signin'); toast('Sign in before reporting'); return; }
    loadCounties(); loadLocations(''); openModal('report-modal');
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
      out.textContent = `Submitted. Status: ${j.status}. Safety ${j.safety_score}/100 · confidence ${j.trust_score}/100. The newsroom can now review it.`;
      e.target.reset(); setTimeout(closeModals, 2600);
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
        if (j.inserted > 0) { const pill = $('#new-stories'); pill.hidden = false; pill.querySelector('button').textContent = `◌ ${j.inserted} new stor${j.inserted === 1 ? 'y' : 'ies'} on the wire — refresh`; pill.querySelector('button').onclick = () => location.reload(); }
      } catch (e) { wireStatus.textContent = 'Updated ' + ago(wireStatus.dataset.last); }
    }, 2500);
    setInterval(() => { wireStatus.textContent = 'Updated ' + ago(wireStatus.dataset.last); }, 60000);
  }

  /* ---------- map (Leaflet loaded on demand) ---------- */
  const mapEl = $('#map');
  if (mapEl) {
    const css = document.createElement('link'); css.rel = 'stylesheet'; css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'; document.head.appendChild(css);
    const s = document.createElement('script'); s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    s.onload = () => {
      const L = window.L; if (!L) return;
      const map = L.map(mapEl, { zoomControl: false, attributionControl: true }).setView([53.4, -7.9], 6);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
      L.control.zoom({ position: 'bottomright' }).addTo(map);
      let pts = [];
      try { pts = JSON.parse(mapEl.dataset.points || '[]'); } catch (e) { }
      const icon = L.divIcon({ className: '', html: '<div class="me-pin"></div>', iconSize: [14, 14], iconAnchor: [7, 7] });
      const group = L.featureGroup(pts.map(p => L.marker([p.latitude, p.longitude], { icon }).bindPopup(`<b><a href="${esc(p.url)}">${esc(p.title)}</a></b><br>${esc(p.location_name || '')} · ${esc(p.category)}`)));
      group.addTo(map);
      if (pts.length) map.fitBounds(group.getBounds().pad(.35));
      $('[data-locate]')?.addEventListener('click', () => {
        if (!navigator.geolocation) return toast('Location is unavailable');
        navigator.geolocation.getCurrentPosition(p => { map.setView([p.coords.latitude, p.coords.longitude], 11); toast('Map centred near you'); }, () => toast('Location permission was not granted'));
      });
    };
    document.head.appendChild(s);
  }
})();
