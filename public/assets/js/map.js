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
    // Canonical OpenStreetMap endpoint (the {s} subdomains are deprecated). If tiles cannot be
    // reached the map still works: pins and clusters draw over the background.
    const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, crossOrigin: true, attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>' }).addTo(map);
    let tileFails = 0, tilesEverLoaded = false;
    tiles.on('tileerror', () => { if (++tileFails >= 6 && !tilesEverLoaded) el.classList.add('map--notiles'); });
    tiles.on('tileload', () => { tilesEverLoaded = true; el.classList.remove('map--notiles'); });
    L.control.zoom({ position: 'bottomright' }).addTo(map);
    if (!full) map.on('click', () => map.scrollWheelZoom.enable());

    const pinLayer = L.layerGroup().addTo(map);
    const countyLayer = L.layerGroup().addTo(map);
    let all = opts.points || [], counties = opts.counties || [], filter = { category: '', kind: '', hours: 0 };
    const CLUSTER_ZOOM = 8; // below this, counties cluster; at or above it, individual pins

    const pinIcon = (p, i) => L.divIcon({
      className: '',
      html: `<div class="me-pin ${i < 8 ? 'is-new' : ''} ${p.kind === 'community' ? 'is-community' : ''}" style="--c:${colours[p.category] || '#139a5c'}"><i></i></div>`,
      iconSize: [18, 18], iconAnchor: [9, 9], popupAnchor: [0, -10],
    });
    const popup = p => `<article class="me-pop">${p.image ? `<a class="me-pop__img" href="${esc(p.url)}"><img src="${esc(p.image)}" alt="" referrerpolicy="no-referrer" onerror="this.parentNode.remove()"></a>` : ''}<div class="me-pop__body"><span class="me-pop__cat" style="--c:${colours[p.category] || '#139a5c'}">${esc(p.category)}${p.kind === 'community' ? ' · Community report' : ''}</span><a class="me-pop__title" href="${esc(p.url)}">${esc(p.title)}</a><span class="me-pop__meta">${esc(p.location_name || p.county || '')} · ${esc(p.source_name || 'Community')} · ${esc(p.ago)}</span><a class="me-pop__more" href="${esc(p.url)}">Read →</a></div></article>`;

    const within = p => !filter.hours || (Date.now() - new Date(p.time).getTime()) <= filter.hours * 3600000;
    function render() {
      pinLayer.clearLayers(); countyLayer.clearLayers();
      const pts = all.filter(p => (!filter.category || p.category === filter.category) && (!filter.kind || p.kind === filter.kind) && within(p));
      const clustered = map.getZoom() < CLUSTER_ZOOM;
      if (clustered) {
        // County clusters: one bubble per county with a count of the filtered pins
        const by = {};
        pts.forEach(p => { if (p.county) (by[p.county] ??= { n: 0, lat: 0, lng: 0, url: '/county/' + p.county.toLowerCase().replace(/[^a-z]+/g, '-') + '/map' }).n++; });
        counties.forEach(c => { if (by[c.county]) { by[c.county].lat = c.latitude; by[c.county].lng = c.longitude; } });
        pts.forEach(p => { const b = by[p.county]; if (b && !b.lat) { b.lat = p.latitude; b.lng = p.longitude; } });
        const max = Math.max(1, ...Object.values(by).map(b => b.n));
        Object.entries(by).forEach(([county, b]) => {
          if (!b.lat) return;
          const size = 30 + Math.sqrt(b.n / max) * 34;
          L.marker([b.lat, b.lng], { icon: L.divIcon({ className: '', html: `<div class="me-cluster" style="--s:${size}px"><b>${b.n}</b><small>${esc(county)}</small></div>`, iconSize: [size, size], iconAnchor: [size / 2, size / 2] }) })
            .on('click', () => map.flyTo([b.lat, b.lng], CLUSTER_ZOOM + 1, { duration: .6 })).addTo(countyLayer);
        });
      } else {
        pts.forEach((p, i) => L.marker([p.latitude, p.longitude], { icon: pinIcon(p, i), riseOnHover: true }).bindPopup(popup(p), { maxWidth: 300, className: 'me-popup' }).addTo(pinLayer));
      }
      if (opts.count) opts.count.textContent = pts.length + (clustered ? ' stories' : ' pins');
      if (opts.list) opts.list.innerHTML = pts.slice(0, 40).map((p, i) => `<button type="button" class="maplist__item" data-i="${all.indexOf(p)}"><span class="maplist__dot" style="--c:${colours[p.category] || '#139a5c'}"></span><span><b>${esc(p.title)}</b><small>${esc(p.location_name || p.county || 'Ireland')} · ${esc(p.ago)}</small></span></button>`).join('');
      return pts;
    }
    /** Fit to whatever is drawn (pins when zoomed in, county bubbles when clustered). Never throws on an empty map. */
    function fitToMarkers(maxZoom) {
      const layers = pinLayer.getLayers().concat(countyLayer.getLayers());
      if (!layers.length) return false;
      try {
        const bounds = L.featureGroup(layers).getBounds();
        if (!bounds || !bounds.isValid()) return false;
        map.fitBounds(bounds.pad(.12), { maxZoom });
        return true;
      } catch (e) { return false; }
    }
    let pts = render();
    map.on('zoomend', () => { pts = render(); });
    if (opts.focus && opts.focus.lat) {
      map.setView([opts.focus.lat, opts.focus.lng], 9);
    } else if (opts.center && opts.center.lat) {
      L.circleMarker([opts.center.lat, opts.center.lng], { radius: 8, color: '#fff', weight: 2, fillColor: '#ff4d6d', fillOpacity: 1 }).bindTooltip('You are here', { className: 'me-tip' }).addTo(map);
      map.setView([opts.center.lat, opts.center.lng], 9);
    } else {
      fitToMarkers(full ? 8 : 7);
    }
    // The panel fades in, so tell Leaflet its real size once the layout has settled.
    setTimeout(() => map.invalidateSize(), 250);
    window.addEventListener('resize', () => map.invalidateSize());

    if (opts.list) opts.list.addEventListener('click', e => {
      const b = e.target.closest('[data-i]'); if (!b) return;
      const p = all[+b.dataset.i]; map.flyTo([p.latitude, p.longitude], 11, { duration: .8 });
      pinLayer.getLayers().forEach(m => { const ll = m.getLatLng(); if (ll.lat === p.latitude && ll.lng === p.longitude) setTimeout(() => m.openPopup(), 850); });
    });
    (opts.chips || []).forEach(chip => chip.addEventListener('click', () => {
      (opts.chips || []).forEach(c => c.classList.remove('is-active')); chip.classList.add('is-active');
      filter.category = chip.dataset.category || ''; filter.kind = chip.dataset.kind || '';
      pts = render();
      if (pts.length && !opts.focus) fitToMarkers(9);
    }));
    (opts.timeChips || []).forEach(chip => chip.addEventListener('click', () => {
      (opts.timeChips || []).forEach(c => c.classList.remove('is-active')); chip.classList.add('is-active');
      filter.hours = Number(chip.dataset.hours || 0); pts = render();
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
    let points = [], counties = [], colours = {}, center = null, focus = null;
    try { points = JSON.parse(el.dataset.points || '[]'); counties = JSON.parse(el.dataset.counties || '[]'); colours = JSON.parse(el.dataset.colours || '{}'); center = el.dataset.center ? JSON.parse(el.dataset.center) : null; focus = el.dataset.focus ? JSON.parse(el.dataset.focus) : null; } catch (e) { }
    const root = el.closest('[data-map-root]') || document;
    try {
      window.ME.initMap(el, {
        points, counties, colours, center, focus, full: el.dataset.map === 'full',
        count: root.querySelector('[data-map-count]'), list: root.querySelector('[data-map-list]'),
        chips: Array.from(root.querySelectorAll('[data-map-chip]')), timeChips: Array.from(root.querySelectorAll('[data-map-time]')), locate: root.querySelector('[data-locate]'),
      });
    } catch (err) {
      // A broken map must never take the rest of the page down with it.
      el.classList.add('map--failed');
      console.error('Map failed to start', err);
    }
  });
})();
