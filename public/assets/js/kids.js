/* ME Óg — daily puzzles: crossword, word search, quiz, find the county */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const root = $('[data-game]'); if (!root) return;
  const game = root.dataset.game;
  let data = {}; try { data = JSON.parse(root.dataset.puzzle || '{}'); } catch (e) { }
  const store = { get: k => { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } }, set: (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { } } };
  const pad = n => String(n).padStart(2, '0');
  function timer(el, key) {
    let secs = (store.get(key + ':t') || 0), running = true;
    const tick = () => { if (!running) return; secs++; el.textContent = pad(Math.floor(secs / 60)) + ':' + pad(secs % 60); if (secs % 5 === 0) store.set(key + ':t', secs); };
    el.textContent = pad(Math.floor(secs / 60)) + ':' + pad(secs % 60);
    const id = setInterval(tick, 1000);
    return { stop: () => { running = false; clearInterval(id); store.set(key + ':t', secs); }, reset: () => { secs = 0; store.set(key + ':t', 0); } };
  }

  /* ---------------- crossword ---------------- */
  if (game === 'crossword') {
    const cells = {}; $$('.xw-cell[data-r]').forEach(c => cells[c.dataset.r + ',' + c.dataset.c] = c);
    const words = [...data.across.map(w => ({ ...w, dir: 'across' })), ...data.down.map(w => ({ ...w, dir: 'down' }))];
    const cellsOf = w => Array.from({ length: w.len }, (_, i) => cells[(w.dir === 'across' ? w.row : w.row + i) + ',' + (w.dir === 'across' ? w.col + i : w.col)]);
    const key = data.id;
    let state = store.get(key) || {};
    let cur = null, dir = 'across';
    const t = timer($('[data-timer]'), key);
    const letters = () => { const o = {}; Object.entries(cells).forEach(([k, c]) => { const l = $('[data-letter]', c).textContent; if (l) o[k] = l; }); return o; };
    Object.entries(state).forEach(([k, l]) => { if (cells[k]) $('[data-letter]', cells[k]).textContent = l; });
    const wordAt = (r, c, d) => words.find(w => w.dir === d && cellsOf(w).some(x => x && x.dataset.r == r && x.dataset.c == c));
    function select(cell, d) {
      if (!cell) return;
      cur = cell; if (d) dir = d;
      if (!wordAt(cur.dataset.r, cur.dataset.c, dir)) dir = dir === 'across' ? 'down' : 'across';
      $$('.xw-cell').forEach(x => x.classList.remove('is-word', 'is-active'));
      $$('.clues li').forEach(x => x.classList.remove('is-active'));
      const w = wordAt(cur.dataset.r, cur.dataset.c, dir);
      if (w) { cellsOf(w).forEach(x => x && x.classList.add('is-word')); const li = $(`[data-clue="${w.dir}-${w.n}"]`); if (li) { li.classList.add('is-active'); li.scrollIntoView({ block: 'nearest' }); } }
      cur.classList.add('is-active'); cur.focus({ preventScroll: true });
    }
    function move(step) {
      if (!cur) return;
      const r = +cur.dataset.r + (dir === 'down' ? step : 0), c = +cur.dataset.c + (dir === 'across' ? step : 0);
      const n = cells[r + ',' + c]; if (n) select(n);
    }
    function setLetter(l) { if (!cur) return; $('[data-letter]', cur).textContent = l; cur.classList.remove('is-wrong', 'is-right'); state = letters(); store.set(key, state); progress(); }
    function progress() {
      let solved = 0;
      words.forEach(w => { const ok = cellsOf(w).every((x, i) => x && $('[data-letter]', x).textContent === w.answer[i]); const li = $(`[data-clue="${w.dir}-${w.n}"]`); if (li) li.classList.toggle('is-done', ok); if (ok) solved++; });
      $('[data-progress]').textContent = `${solved} / ${words.length} solved`;
      if (solved === words.length) { $('[data-done]').hidden = false; t.stop(); } 
    }
    $$('.xw-cell[data-r]').forEach(c => c.addEventListener('click', () => select(c, c === cur ? (dir === 'across' ? 'down' : 'across') : dir)));
    $$('.clues li').forEach(li => li.addEventListener('click', () => select(cells[li.dataset.r + ',' + li.dataset.c], li.dataset.dir)));
    document.addEventListener('keydown', e => {
      if (!cur || e.metaKey || e.ctrlKey) return;
      if (/^[a-zA-Z]$/.test(e.key)) { setLetter(e.key.toUpperCase()); move(1); e.preventDefault(); }
      else if (e.key === 'Backspace') { if ($('[data-letter]', cur).textContent) setLetter(''); else { move(-1); setLetter(''); } e.preventDefault(); }
      else if (e.key === 'ArrowRight') { dir = 'across'; move(1); e.preventDefault(); } else if (e.key === 'ArrowLeft') { dir = 'across'; move(-1); e.preventDefault(); }
      else if (e.key === 'ArrowDown') { dir = 'down'; move(1); e.preventDefault(); } else if (e.key === 'ArrowUp') { dir = 'down'; move(-1); e.preventDefault(); }
      else if (e.key === ' ' || e.key === 'Tab') { select(cur, dir === 'across' ? 'down' : 'across'); e.preventDefault(); }
    });
    // Mobile: a hidden input catches virtual keyboard
    const inp = document.createElement('input'); inp.type = 'text'; inp.autocomplete = 'off'; inp.style.cssText = 'position:fixed;opacity:0;top:0;left:0;height:1px;width:1px;pointer-events:none'; document.body.appendChild(inp);
    $$('.xw-cell[data-r]').forEach(c => c.addEventListener('touchend', () => inp.focus({ preventScroll: true })));
    inp.addEventListener('input', () => { const v = inp.value.slice(-1); inp.value = ''; if (/[a-zA-Z]/.test(v)) { setLetter(v.toUpperCase()); move(1); } });
    $('[data-check]').addEventListener('click', () => { words.forEach(w => cellsOf(w).forEach((x, i) => { const l = $('[data-letter]', x).textContent; if (l) x.classList.add(l === w.answer[i] ? 'is-right' : 'is-wrong'); })); });
    $('[data-reveal-letter]').addEventListener('click', () => { if (!cur) return; const w = wordAt(cur.dataset.r, cur.dataset.c, dir); if (!w) return; const i = cellsOf(w).indexOf(cur); setLetter(w.answer[i]); cur.classList.add('is-revealed'); move(1); });
    $('[data-reveal-word]').addEventListener('click', () => { if (!cur) return; const w = wordAt(cur.dataset.r, cur.dataset.c, dir); if (!w) return; cellsOf(w).forEach((x, i) => { $('[data-letter]', x).textContent = w.answer[i]; x.classList.add('is-revealed'); }); state = letters(); store.set(key, state); progress(); });
    $('[data-clear]').addEventListener('click', () => { if (!confirm('Clear the whole grid?')) return; $$('.xw-cell[data-r]').forEach(x => { $('[data-letter]', x).textContent = ''; x.classList.remove('is-wrong', 'is-right', 'is-revealed'); }); state = {}; store.set(key, {}); t.reset(); progress(); });
    progress();
    select(cells[data.across[0].row + ',' + data.across[0].col], 'across');
  }

  /* ---------------- word search ---------------- */
  if (game === 'wordsearch') {
    const grid = $('[data-grid]'), key = data.id, t = timer($('[data-timer]'), key);
    const cellAt = (r, c) => grid.querySelector(`.ws-cell[data-r="${r}"][data-c="${c}"]`);
    let found = new Set(store.get(key) || []);
    const paint = () => { data.words.forEach(w => { if (!found.has(w.word)) return; for (let i = 0; i < w.word.length; i++) cellAt(w.row + w.dr * i, w.col + w.dc * i)?.classList.add('is-found'); $(`[data-word="${w.word}"]`).classList.add('is-found'); }); $('[data-progress]').textContent = `${found.size} / ${data.words.length} found`; if (found.size === data.words.length) { $('[data-done]').hidden = false; t.stop(); } };
    let start = null, path = [];
    const line = (a, b) => { const dr = Math.sign(b.r - a.r), dc = Math.sign(b.c - a.c); const len = Math.max(Math.abs(b.r - a.r), Math.abs(b.c - a.c)); if (!(dr === 0 || dc === 0 || Math.abs(b.r - a.r) === Math.abs(b.c - a.c))) return [a]; return Array.from({ length: len + 1 }, (_, i) => ({ r: a.r + dr * i, c: a.c + dc * i })); };
    const pos = e => { const t = document.elementFromPoint(e.clientX, e.clientY); return t && t.classList.contains('ws-cell') ? { r: +t.dataset.r, c: +t.dataset.c } : null; };
    const show = () => { $$('.ws-cell.is-sel', grid).forEach(x => x.classList.remove('is-sel')); path.forEach(p => cellAt(p.r, p.c)?.classList.add('is-sel')); };
    grid.addEventListener('pointerdown', e => { const p = pos(e); if (!p) return; start = p; path = [p]; show(); grid.setPointerCapture(e.pointerId); });
    grid.addEventListener('pointermove', e => { if (!start) return; const p = pos(e); if (!p) return; path = line(start, p); show(); });
    const finish = () => {
      if (!start) return;
      const word = path.map(p => data.grid[p.r][p.c]).join(''), rev = word.split('').reverse().join('');
      const hit = data.words.find(w => w.word === word || w.word === rev);
      if (hit) { found.add(hit.word); store.set(key, [...found]); paint(); }
      start = null; path = []; show();
    };
    grid.addEventListener('pointerup', finish); grid.addEventListener('pointercancel', finish);
    $('[data-hint]').addEventListener('click', () => { const w = data.words.find(x => !found.has(x.word)); if (w) cellAt(w.row, w.col)?.classList.add('is-hint'); });
    $('[data-clear]').addEventListener('click', () => { found = new Set(); store.set(key, []); $$('.ws-cell').forEach(x => x.classList.remove('is-found', 'is-hint')); $$('.ws__words li').forEach(x => x.classList.remove('is-found')); t.reset(); paint(); $('[data-done]').hidden = true; });
    paint();
  }

  /* ---------------- quiz ---------------- */
  if (game === 'quiz') {
    const form = $('[data-quiz]');
    form.addEventListener('submit', e => {
      e.preventDefault();
      let score = 0;
      data.questions.forEach((q, i) => {
        const fs = $(`[data-q="${i}"]`), picked = fs.querySelector('input:checked');
        $$('.quiz__opt', fs).forEach((o, k) => { o.classList.toggle('is-right', k === q.answer); o.classList.toggle('is-wrong', !!picked && +picked.value === k && k !== q.answer); });
        if (picked && +picked.value === q.answer) score++;
        $('.quiz__fact', fs).hidden = false;
      });
      const msgs = ['Tomorrow is another day!', 'Not bad — keep reading!', 'Good going!', 'Very good!', 'Excellent!', 'Full marks — maith thú!'];
      $('[data-score]').textContent = `${score} / ${data.questions.length}`;
      const done = $('[data-done]'); done.hidden = false; done.innerHTML = `<b>${score} out of ${data.questions.length}.</b> ${msgs[score]}`;
      $('[data-reset]').hidden = false; form.querySelector('[type=submit]').hidden = true;
      $$('input', form).forEach(i => i.disabled = true);
      const best = store.get('quiz:best') || {}; best[data.date] = Math.max(best[data.date] || 0, score); store.set('quiz:best', best);
      done.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    $('[data-reset]').addEventListener('click', () => { form.reset(); $$('input', form).forEach(i => i.disabled = false); $$('.quiz__opt').forEach(o => o.classList.remove('is-right', 'is-wrong')); $$('.quiz__fact').forEach(f => f.hidden = true); $('[data-done]').hidden = true; $('[data-reset]').hidden = true; form.querySelector('[type=submit]').hidden = false; $('[data-score]').textContent = ''; });
  }

  /* ---------------- find the county ---------------- */
  if (game === 'county' && window.L) {
    const L = window.L, el = $('#county-map');
    const map = L.map(el, { zoomControl: false, attributionControl: false, minZoom: 6, maxZoom: 9 }).setView([53.42, -7.9], 7);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
    const layer = L.layerGroup().addTo(map);
    const dist = (a, b) => { const R = 6371, dLat = (b[0] - a[0]) * Math.PI / 180, dLng = (b[1] - a[1]) * Math.PI / 180; const x = Math.sin(dLat / 2) ** 2 + Math.cos(a[0] * Math.PI / 180) * Math.cos(b[0] * Math.PI / 180) * Math.sin(dLng / 2) ** 2; return 2 * R * Math.asin(Math.sqrt(x)); };
    let round = 0, score = 0, locked = false;
    const prompt = () => { const r = data.rounds[round]; $('[data-prompt]').innerHTML = `Find <b>Co. ${r.county}</b> <span class="mono">(${r.province})</span>`; $('[data-score]').textContent = `Round ${round + 1} of ${data.rounds.length} · ${score} pts`; $('[data-feedback]').textContent = ''; };
    const finish = () => { const d = $('[data-done]'); d.hidden = false; d.innerHTML = `<b>${score} points out of ${data.rounds.length * 3}.</b> ${score >= 24 ? 'You know Ireland like the back of your hand!' : score >= 15 ? 'Grand job — a few more road trips and you\'ll have it.' : 'Time to get the atlas out — try again tomorrow!'}`; $('[data-prompt]').textContent = 'Game over.'; };
    map.on('click', e => {
      if (locked || round >= data.rounds.length) return;
      locked = true;
      const r = data.rounds[round], km = dist([e.latlng.lat, e.latlng.lng], [r.latitude, r.longitude]);
      const pts = km <= 40 ? 3 : (km <= 80 ? 1 : 0); score += pts;
      L.marker(e.latlng, { icon: L.divIcon({ className: '', html: `<div class="cg-pin" style="--c:${pts === 3 ? '#2e6b3a' : pts ? '#c88a12' : '#b8321f'}"></div>`, iconSize: [14, 14], iconAnchor: [7, 7] }) }).addTo(layer);
      L.marker([r.latitude, r.longitude], { icon: L.divIcon({ className: '', html: '<div class="cg-pin" style="--c:#1c1813"></div>', iconSize: [14, 14], iconAnchor: [7, 7] }) }).bindTooltip(`Co. ${r.county}`, { permanent: true, className: 'cg-label', direction: 'top', offset: [0, -6] }).addTo(layer);
      L.polyline([e.latlng, [r.latitude, r.longitude]], { color: '#1c1813', weight: 1, dashArray: '4 4' }).addTo(layer);
      $('[data-feedback]').innerHTML = `${Math.round(km)} km from the centre of Co. ${r.county} — <b>${pts === 3 ? 'spot on, +3' : pts ? 'close, +1' : 'not this time'}</b>.`;
      $('[data-score]').textContent = `Round ${round + 1} of ${data.rounds.length} · ${score} pts`;
      round++;
      setTimeout(() => { locked = false; if (round < data.rounds.length) prompt(); else finish(); }, 1400);
    });
    $('[data-restart]').addEventListener('click', () => { round = 0; score = 0; locked = false; layer.clearLayers(); $('[data-done]').hidden = true; prompt(); });
    prompt();
  }
})();
