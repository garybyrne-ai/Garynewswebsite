/* ME News Ireland — live map (Leaflet, self-hosted) */
(function () {
  'use strict';
  const esc = s => String(s ?? '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  const toast = m => window.ME && window.ME.toast ? window.ME.toast(m) : console.log(m);

  window.ME = window.ME || {};
  window.ME.initMap = function (el, opts = {}) {
    const L = window.L; if (!L || !el) return null;
    const colours = opts.colours || {};
    const full = !!opts.full;
    const map = L.map(el, { zoomControl: false, attributionControl: true, scrollWheelZoom: full, minZoom: 5, maxZoom: 17 }).setView([53.42, -7.9], full ? 7 : 6);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>' }).addTo(map);
    L.control.zoom({ position: 'bottomright' }).addTo(map);
    if (!full) map.on('click', () => map.scrollWheelZoom.enable());

    const pinLayer = L.layerGroup().addTo(map);
    const countyLayer = L.layerGroup().addTo(map);
    let all = opts.points || [], counties = opts.counties || [], filter = { category: '', kind: '' };

    const pinIcon = (p, i) => L.divIcon({
      className: '',
      html: `<div class="me-pin ${i < 8 ? 'is-new' : ''} ${p.kind === 'community' ? 'is-community' : ''}" style="--c:${colours[p.category] || '#47d5ff'}"><i></i></div>`,
      iconSize: [18, 18], iconAnchor: [9, 9], popupAnchor: [0, -10],
    });
    const popup = p => `<article class="me-pop">${p.image ? `<a class="me-pop__img" href="${esc(p.url)}"><img src="${esc(p.image)}" alt="" referrerpolicy="no-referrer" onerror="this.parentNode.remove()"></a>` : ''}<div class="me-pop__body"><span class="me-pop__cat" style="--c:${colours[p.category] || '#47d5ff'}">${esc(p.category)}</span><a class="me-pop__title" href="${esc(p.url)}">${esc(p.title)}</a><span class="me-pop__meta">${esc(p.location_name || p.county || '')} · ${esc(p.source_name || 'Community report')} · ${esc(p.ago)}</span></div></article>`;

    function render() {
      pinLayer.clearLayers(); countyLayer.clearLayers();
      const pts = all.filter(p => (!filter.category || p.category === filter.category) && (!filter.kind || p.kind === filter.kind));
      pts.forEach((p, i) => L.marker([p.latitude, p.longitude], { icon: pinIcon(p, i), riseOnHover: true }).bindPopup(popup(p), { maxWidth: 300, className: 'me-popup' }).addTo(pinLayer));
      if (!filter.category && !filter.kind) {
        const max = Math.max(1, ...counties.map(c => c.n));
        counties.forEach(c => L.circleMarker([c.latitude, c.longitude], { radius: 10 + Math.sqrt(c.n / max) * 26, color: 'rgba(71,213,255,.35)', weight: 1, fillColor: '#47d5ff', fillOpacity: .07, interactive: true })
          .bindTooltip(`<b>Co. ${esc(c.county)}</b> · ${c.n} stories this week`, { className: 'me-tip', direction: 'top' })
          .on('click', () => location.href = c.url).addTo(countyLayer));
      }
      if (opts.count) opts.count.textContent = pts.length + ' pins';
      if (opts.list) opts.list.innerHTML = pts.slice(0, 40).map((p, i) => `<button type="button" class="maplist__item" data-i="${all.indexOf(p)}"><span class="maplist__dot" style="--c:${colours[p.category] || '#47d5ff'}"></span><span><b>${esc(p.title)}</b><small>${esc(p.location_name || p.county || 'Ireland')} · ${esc(p.ago)}</small></span></button>`).join('');
      return pts;
    }
    let pts = render();
    if (opts.center) {
      L.circleMarker([opts.center.lat, opts.center.lng], { radius: 8, color: '#fff', weight: 2, fillColor: '#ff4d6d', fillOpacity: 1 }).bindTooltip('You are here', { className: 'me-tip' }).addTo(map);
      map.setView([opts.center.lat, opts.center.lng], 9);
    } else if (pts.length) map.fitBounds(L.featureGroup(pinLayer.getLayers()).getBounds().pad(.12), { maxZoom: full ? 8 : 7 });

    if (opts.list) opts.list.addEventListener('click', e => {
      const b = e.target.closest('[data-i]'); if (!b) return;
      const p = all[+b.dataset.i]; map.flyTo([p.latitude, p.longitude], 11, { duration: .8 });
      pinLayer.getLayers().forEach(m => { const ll = m.getLatLng(); if (ll.lat === p.latitude && ll.lng === p.longitude) setTimeout(() => m.openPopup(), 850); });
    });
    (opts.chips || []).forEach(chip => chip.addEventListener('click', () => {
      (opts.chips || []).forEach(c => c.classList.remove('is-active')); chip.classList.add('is-active');
      filter = { category: chip.dataset.category || '', kind: chip.dataset.kind || '' };
      pts = render();
      if (pts.length) map.fitBounds(L.featureGroup(pinLayer.getLayers()).getBounds().pad(.15), { maxZoom: 9 });
    }));
    if (opts.locate) opts.locate.addEventListener('click', () => {
      if (!navigator.geolocation) return toast('Location is unavailable');
      navigator.geolocation.getCurrentPosition(pos => {
        const here = [pos.coords.latitude, pos.coords.longitude];
        L.circleMarker(here, { radius: 8, color: '#fff', weight: 2, fillColor: '#ff4d6d', fillOpacity: 1 }).bindTooltip('You are here', { className: 'me-tip' }).addTo(map);
        map.flyTo(here, 11, { duration: 1 }); toast('Map centred near you');
      }, () => toast('Location permission was not granted'));
    });
    return { map, refresh: async () => { try { const r = await fetch('/api/map?limit=300', { headers: { 'X-Requested-With': 'MENews' } }); const j = await r.json(); all = j.points; counties = j.counties; render(); } catch (e) { } } };
  };

  document.querySelectorAll('[data-map]').forEach(el => {
    let points = [], counties = [], colours = {}, center = null;
    try { points = JSON.parse(el.dataset.points || '[]'); counties = JSON.parse(el.dataset.counties || '[]'); colours = JSON.parse(el.dataset.colours || '{}'); center = el.dataset.center ? JSON.parse(el.dataset.center) : null; } catch (e) { }
    const root = el.closest('[data-map-root]') || document;
    window.ME.initMap(el, {
      points, counties, colours, center, full: el.dataset.map === 'full',
      count: root.querySelector('[data-map-count]'), list: root.querySelector('[data-map-list]'),
      chips: Array.from(root.querySelectorAll('[data-map-chip]')), locate: root.querySelector('[data-locate]'),
    });
  });
})();
