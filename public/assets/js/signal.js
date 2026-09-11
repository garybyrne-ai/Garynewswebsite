/* The Signal — voting widgets + home slider */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const api = (u, o) => window.ME.api(u, o);
  const toast = m => window.ME.toast && window.ME.toast(m);
  const LABELS = { matters: 'Matters', talking: 'Talking point', good: 'Good news', digging: 'Needs digging' };

  function burst(btn) {
    const r = btn.getBoundingClientRect();
    for (let i = 0; i < 10; i++) {
      const p = document.createElement('i'); p.className = 'burst';
      p.style.cssText = `left:${r.left + r.width / 2}px;top:${r.top + r.height / 2}px;--dx:${(Math.random() - .5) * 120}px;--dy:${(Math.random() - .9) * 120}px;--c:${getComputedStyle(btn).getPropertyValue('--c')}`;
      document.body.appendChild(p); setTimeout(() => p.remove(), 900);
    }
  }
  function applyTally(storyId, t) {
    $$(`[data-vote][data-story="${storyId}"]`).forEach(w => {
      w.dataset.total = t.total;
      Object.entries(t.counts).forEach(([k, n]) => { const c = $(`[data-count="${k}"]`, w); if (c) { if (c.textContent !== String(n)) { c.classList.remove('is-bump'); void c.offsetWidth; c.classList.add('is-bump'); } c.textContent = n; } const bar = $(`[data-mix-bar="${k}"]`, w); if (bar) bar.style.width = (t.total ? Math.round(n / t.total * 100) : 0) + '%'; });
      $$('.votebtn', w).forEach(b => { const mine = b.dataset.signal === t.mine; b.classList.toggle('is-mine', mine); b.setAttribute('aria-pressed', mine ? 'true' : 'false'); });
      const total = $('[data-vote-total]', w); if (total) total.textContent = t.total;
      const foot = $('[data-vote-foot]', w); if (foot) foot.innerHTML = (t.mine ? `You said: ${LABELS[t.mine]} · tap again to withdraw` : 'Tap a signal to vote') + (t.total ? ' · <a href="/signal">see the leaderboard →</a>' : '') + (t.local ? ' · <span style="color:var(--em)">local vote ×1.5</span>' : '');
      // sync mix bars on the card (non-widget)
      const card = w.closest('.sigcard, .sigrow, .podium__card');
      if (card) $$('.sigmix i', card).forEach(i => { const k = Object.keys(t.counts)[Array.from(i.parentNode.children).indexOf(i)]; if (k) i.style.width = (t.total ? Math.round(t.counts[k] / t.total * 100) : 0) + '%'; });
      const ring = card && $('.sigcard__ring', card); if (ring) { ring.style.setProperty('--pct', Math.min(100, t.total * 8)); const b = $('b', ring); if (b) b.textContent = t.total; }
    });
  }
  document.addEventListener('click', async e => {
    const btn = e.target.closest('.votebtn'); if (!btn) return;
    const w = btn.closest('[data-vote]'); if (!w || btn.disabled) return;
    e.preventDefault();
    const fd = new FormData(); fd.append('story_id', w.dataset.story); fd.append('signal', btn.dataset.signal);
    btn.disabled = true; btn.classList.add('is-pending');
    try {
      const t = await api('/api/signal/vote', { method: 'POST', body: fd });
      applyTally(w.dataset.story, t);
      if (t.mine) { burst(btn); toast(`${LABELS[t.mine]} — thanks for voting${t.local ? ' (local vote ×1.5)' : ''}`); } else toast('Vote withdrawn');
    } catch (err) { toast(err.message); }
    btn.disabled = false; btn.classList.remove('is-pending');
  });

  /* slider */
  $$('[data-slider]').forEach(sl => {
    const track = $('[data-track]', sl), dots = $('[data-dots]', sl), cards = $$('.sigcard', track);
    if (!cards.length) return;
    let auto = null, idx = 0;
    const perView = () => Math.max(1, Math.round(track.clientWidth / (cards[0].offsetWidth + 16)));
    const pages = () => Math.max(1, Math.ceil(cards.length / perView()));
    const go = i => { const n = pages(); idx = ((i % n) + n) % n; track.scrollTo({ left: idx * track.clientWidth, behavior: 'smooth' }); paint(); };
    const paint = () => { if (dots) dots.innerHTML = Array.from({ length: pages() }, (_, i) => `<button type="button" class="${i === idx ? 'is-active' : ''}" aria-label="Page ${i + 1}" data-dot="${i}"></button>`).join(''); };
    $$('[data-slide]', sl).forEach(b => b.addEventListener('click', () => { go(idx + Number(b.dataset.slide)); restart(); }));
    dots?.addEventListener('click', e => { const d = e.target.closest('[data-dot]'); if (d) { go(+d.dataset.dot); restart(); } });
    track.addEventListener('scroll', () => { const i = Math.round(track.scrollLeft / track.clientWidth); if (i !== idx) { idx = i; paint(); } }, { passive: true });
    const restart = () => { clearInterval(auto); if (!matchMedia('(prefers-reduced-motion: reduce)').matches) auto = setInterval(() => { if (!sl.matches(':hover')) go(idx + 1); }, 6500); };
    paint(); restart();
    window.addEventListener('resize', paint);
  });

  /* live refresh on the signal page */
  if (document.body.classList.contains('page-signal')) {
    setInterval(async () => {
      try {
        const q = new URLSearchParams(location.search);
        const j = await api('/api/signal?window=' + (q.get('window') || 'today') + '&county=' + encodeURIComponent(q.get('county') || '') + '&limit=40');
        j.items.forEach(s => applyTally(s.id, { counts: s.signal_counts, total: s.signal_total, mine: s.mine }));
      } catch (e) { }
    }, 45000);
  }
})();
