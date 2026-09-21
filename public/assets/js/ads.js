/* ME Ads — shared designer used by the member dashboard and the newsroom */
(function () {
  'use strict';
  const { api, esc, toast } = window.ME;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const designer = $('#ad-designer'); if (!designer) return;
  const form = $('[data-ad-form]', designer), admin = form.dataset.admin === '1';
  const fmt = iso => iso ? new Date(iso).toLocaleDateString('en-IE', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
  let pricing = { stripe: false, paypal: false, packages: [], tiers: {} };
  const num = n => Number(n || 0).toLocaleString('en-IE');
  let previewTimer;

  async function preview() {
    const fd = new FormData(form); fd.delete('logo'); fd.delete('image');
    try { const j = await api('/api/ads/preview', { method: 'POST', body: fd }); $('[data-preview-sidebar]', designer).innerHTML = j.sidebar; $('[data-preview-banner]', designer).innerHTML = j.banner; }
    catch (e) { /* not signed in or transient */ }
  }
  const schedulePreview = () => { clearTimeout(previewTimer); previewTimer = setTimeout(preview, 250); };
  form.addEventListener('input', e => {
    const c = e.target.name && $(`[data-count="${e.target.name}"]`, form); if (c) c.textContent = `${e.target.value.length}/${e.target.maxLength}`;
    if (e.target.type === 'file') return;
    schedulePreview();
  });
  form.addEventListener('change', e => { if (e.target.type === 'file' && e.target.files[0]) toast(e.target.name === 'logo' ? 'Logo will upload when you save' : 'Photo will upload when you save'); });
  $$('[data-template]', form).forEach(b => b.addEventListener('click', () => {
    $$('[data-template]', form).forEach(x => x.classList.remove('is-active')); b.classList.add('is-active');
    form.template.value = b.dataset.template; form.bg1.value = b.dataset.bg1; form.bg2.value = b.dataset.bg2; form.fg.value = b.dataset.fg; form.ac.value = b.dataset.ac;
    schedulePreview();
  }));
  $$('[data-remove]', form).forEach(b => b.addEventListener('click', () => { form['remove_' + b.dataset.remove].value = '1'; b.closest('[data-has]').hidden = true; toast('Will be removed when you save'); }));

  function open(ad) {
    form.reset(); form.id.value = ad ? ad.id : ''; form.remove_logo.value = '0'; form.remove_image.value = '0';
    $('[data-designer-title]', designer).textContent = ad ? 'Edit: ' + ad.business_name : (admin ? 'New house ad' : 'New advert');
    if (ad) {
      ['business_name', 'url', 'title', 'body', 'cta', 'badge', 'target_county', 'target_town', 'placement'].forEach(k => { if (form[k]) form[k].value = ad[k] || ''; });
      const d = ad.design || {}; form.template.value = d.template || 'aurora';
      ['bg1', 'bg2', 'fg', 'ac', 'shape', 'align'].forEach(k => { if (d[k]) form[k].value = d[k]; });
      $$('[data-template]', form).forEach(x => x.classList.toggle('is-active', x.dataset.template === form.template.value));
      $('[data-has="logo"]', form).hidden = !ad.logo_url; $('[data-has="image"]', form).hidden = !ad.image_url;
    } else {
      $$('[data-template]', form).forEach(x => x.classList.toggle('is-active', x.dataset.template === 'aurora'));
      $('[data-has="logo"]', form).hidden = true; $('[data-has="image"]', form).hidden = true;
    }
    $$('[data-count]', form).forEach(c => { const f = form[c.dataset.count]; c.textContent = `${f.value.length}/${f.maxLength}`; });
    const box = $('[data-credit-box]', form);
    if (box) {
      const credits = (mine && mine._credits) || [];
      box.hidden = !!ad || !credits.length;
      const sel = $('[data-credit-select]', form);
      sel.innerHTML = credits.map(c => `<option value="${esc(c.id)}">${esc(c.package_name)} · ${num(c.impressions)} impressions · ${esc(c.tier_label)}</option>`).join('');
      sel.disabled = !!ad;
    }
    designer.hidden = false; designer.scrollIntoView({ behavior: 'smooth', block: 'start' }); preview();
  }
  $$('[data-ad-new]').forEach(b => b.addEventListener('click', () => open(null)));
  $('[data-ad-close]', designer).addEventListener('click', () => { designer.hidden = true; });

  form.addEventListener('submit', async e => {
    e.preventDefault();
    const action = e.submitter?.dataset.action || 'save';
    form.submit.value = action === 'submit' ? '1' : '0';
    const out = $('[data-designer-result]', form); out.classList.remove('is-error'); out.textContent = 'Saving…';
    try {
      const j = await api(admin ? '/api/admin/ads/save' : '/api/me/ads', { method: 'POST', body: new FormData(form) });
      form.id.value = j.ad.id;
      out.textContent = action === 'submit' ? 'Submitted — an editor will review it shortly. Once approved, your impressions start running automatically.' : 'Saved.';
      toast(action === 'submit' ? 'Submitted for review' : 'Advert saved');
      window.dispatchEvent(new CustomEvent('ads:changed'));
      if (action !== 'save') designer.hidden = true;
    } catch (err) { out.classList.add('is-error'); out.textContent = err.message; }
  });

  const bars = stats => `<div class="adbars" title="Impressions, last ${stats.length} days">${(() => { const m = Math.max(1, ...stats.map(s => s.impressions)); return stats.map(s => `<i style="height:${Math.max(4, s.impressions / m * 100)}%" title="${s.day}: ${s.impressions} views, ${s.clicks} clicks"></i>`).join(''); })()}</div>`;

  /* ---------- member: my ads, credits and orders ---------- */
  const mine = $('#my-ads'), creditsBox = $('#my-credits'), ordersBox = $('#my-orders');
  const orderRow = o => `<div class="notif" data-order="${esc(o.id)}"><div class="inline" style="justify-content:space-between;gap:10px;flex-wrap:wrap"><b>${esc(o.package_name)} <span class="chip">${esc(o.tier_label)}</span></b><span class="mono">${esc(o.price_label)} · ${fmt(o.paid_at || o.created_at)}</span></div>
      <div class="orderbar" title="${num(o.impressions_used)} of ${num(o.impressions)} impressions shown"><i style="--v:${o.progress}"></i></div>
      <div class="sub">${esc(o.state_label)}${o.business_name ? ' · ' + esc(o.business_name) : ''}${o.gateway === 'manual' ? ' · credited by the newsroom' : (o.gateway ? ' · paid by ' + esc(o.gateway) : '')}</div></div>`;
  async function loadMine() {
    if (!mine) return;
    const j = await api('/api/me/ads'); pricing = j.pricing;
    const credits = j.credits || [], orders = j.orders || [];
    mine._ads = j.ads; mine._credits = credits;
    $$('[data-ad-new]').forEach(b => { b.hidden = !credits.length; });
    if (creditsBox) creditsBox.innerHTML = credits.map(c => `<div class="creditcard"><div><b>${esc(c.package_name)}</b> · ${num(c.impressions)} impressions · ${esc(c.tier_label)}<small>Paid ${fmt(c.paid_at || c.created_at)} · not attached to an advert yet</small></div><div class="inline"><button class="btn btn--primary btn--sm" type="button" data-credit-design="${esc(c.id)}">Design this advert</button>${j.ads.some(a => !a.is_house) ? `<button class="btn btn--ghost btn--sm" type="button" data-credit-topup="${esc(c.id)}">Add to an existing advert</button>` : ''}</div></div>`).join('');
    mine.innerHTML = j.ads.length ? j.ads.map(ad => `<article class="adcard" data-id="${ad.id}">
      <div>${ad.preview}</div>
      <div class="adcard__meta">
        <div class="inline"><span class="adcard__state is-${esc(ad.state_tone)}">${esc(ad.state_label)}</span><span class="chip">${esc(ad.tier_label)}</span>${ad.target_county ? `<span class="chip">${esc(ad.target_town ? ad.target_town + ', ' : '')}Co. ${esc(ad.target_county)}</span>` : '<span class="chip">All Ireland</span>'}</div>
        <h3>${esc(ad.business_name)} · <span style="color:var(--muted);font-weight:500">${esc(ad.title)}</span></h3>
        ${ad.notes && ad.status === 'rejected' ? `<p class="is-warn" style="font-size:.9rem">Editor: ${esc(ad.notes)}</p>` : ''}
        <div class="adcard__stats"><span><b>${num(ad.impressions)}</b> views</span><span><b>${num(ad.clicks)}</b> clicks</span><span><b>${ad.ctr}%</b> CTR</span><span><b>${ad.impressions_left === null ? '—' : num(ad.impressions_left)}</b> impressions left</span></div>
        ${bars(ad.stats)}
        <div class="adcard__actions">
          ${ad.status === 'draft' ? `<button class="btn btn--primary btn--sm" data-act="submit">Submit for review</button>` : ''}
          ${credits.length ? `<button class="btn btn--ghost btn--sm" data-act="topup">Add a package</button>` : `<a class="btn btn--ghost btn--sm" href="/advertise?ad=${esc(ad.id)}">Top up impressions</a>`}
          <button class="btn btn--ghost btn--sm" data-act="edit">Edit</button>
          ${ad.status === 'approved' ? `<button class="btn btn--ghost btn--sm" data-act="pause">${ad.paused ? 'Resume' : 'Pause'}</button>` : ''}
          ${ad.impressions_left > 0 && ad.status === 'approved' ? '' : `<button class="btn btn--ghost btn--sm" data-act="delete">Delete</button>`}
        </div>
      </div></article>`).join('') : (credits.length
        ? `<div class="empty"><div class="empty__glyph">✓</div><h3>Your package is paid — design your advert.</h3><p>Pick a template, add your headline and target area, then submit it for review.</p><button class="btn btn--primary" type="button" data-credit-design="${esc(credits[0].id)}">Open the designer</button></div>`
        : `<div class="empty"><div class="empty__glyph">—</div><h3>No adverts yet.</h3><p>Buy a package of impressions and the designer unlocks. <a href="/advertise">See packages →</a></p></div>`);
    if (ordersBox) ordersBox.innerHTML = orders.length ? `<div class="panel"><span class="kicker">Orders</span>${orders.map(orderRow).join('')}</div>` : '';
  }
  async function attachCredit(orderId) {
    const ads = (mine._ads || []).filter(a => !a.is_house);
    if (!ads.length) return toast('Design an advert first');
    const pick = ads.length === 1 ? ads[0] : ads[parseInt(prompt(ads.map((a, i) => `${i + 1}. ${a.business_name} — ${a.title}`).join('\n') + '\n\nAdd the package to which advert? (number)', '1'), 10) - 1];
    if (!pick) return;
    const fd = new FormData(); fd.append('order_id', orderId);
    await api(`/api/me/ads/${pick.id}/attach`, { method: 'POST', body: fd }); toast('Package added to ' + pick.business_name); loadMine();
  }
  creditsBox?.addEventListener('click', async e => {
    const d = e.target.closest('[data-credit-design]'), t = e.target.closest('[data-credit-topup]');
    try {
      if (d) { open(null); const sel = $('[data-credit-select]', form); if (sel) sel.value = d.dataset.creditDesign; }
      if (t) await attachCredit(t.dataset.creditTopup);
    } catch (err) { toast(err.message); }
  });
  mine?.addEventListener('click', async e => {
    const b = e.target.closest('[data-act], [data-credit-design]'); if (!b) return;
    if (b.dataset.creditDesign) { open(null); return; }
    const card = b.closest('[data-id]'), id = card.dataset.id, ad = mine._ads.find(a => a.id === id);
    try {
      switch (b.dataset.act) {
        case 'edit': open(ad); break;
        case 'submit': await api(`/api/me/ads/${id}/submit`, { method: 'POST', body: new FormData() }); toast('Submitted for review'); loadMine(); break;
        case 'pause': { const fd = new FormData(); fd.append('paused', ad.paused ? '0' : '1'); await api(`/api/me/ads/${id}/pause`, { method: 'POST', body: fd }); loadMine(); break; }
        case 'delete': if (confirm('Delete this advert?')) { await api(`/api/me/ads/${id}/delete`, { method: 'POST', body: new FormData() }); loadMine(); } break;
        case 'topup': { const c = mine._credits[0]; const fd = new FormData(); fd.append('order_id', c.id); await api(`/api/me/ads/${id}/attach`, { method: 'POST', body: fd }); toast(`${c.package_name} added — ${num(c.impressions)} more impressions`); loadMine(); break; }
      }
    } catch (err) { toast(err.message); }
  });

  /* ---------- newsroom ---------- */
  const list = $('#ads-list'), settings = $('#ads-settings');
  async function loadAdmin() {
    if (!list) return;
    const status = $('#ads-status')?.value || '';
    const j = await api('/api/admin/ads?status=' + encodeURIComponent(status)); pricing = j.pricing;
    const isAdmin = window.NEWSROOM?.role === 'admin';
    settings.innerHTML = `<span class="kicker">Currency &amp; payment gateways</span>
      <form class="form" id="ads-settings-form" style="margin-top:10px"><div class="form__row" style="grid-template-columns:1fr auto;align-items:end;max-width:320px">
        <label>Currency<select name="currency" ${isAdmin ? '' : 'disabled'}>${['EUR', 'GBP', 'USD'].map(c => `<option ${c === pricing.currency ? 'selected' : ''}>${c}</option>`).join('')}</select></label>
        ${isAdmin ? '<button class="btn btn--primary" type="submit">Save</button>' : ''}
      </div></form>
      <p class="panel__note">Stripe: <b class="${pricing.stripe ? 'is-good' : 'is-bad'}">${pricing.stripe ? 'connected' : 'add your keys under Settings → Payment gateways'}</b> · PayPal: <b class="${pricing.paypal ? 'is-good' : 'is-bad'}">${pricing.paypal ? 'connected (' + (pricing.paypal_mode || 'sandbox') + ')' : 'add your keys under Settings → Payment gateways'}</b>. Webhooks: <code>/api/stripe/webhook</code> and <code>/api/paypal/webhook</code>. Adverts are sold as impression packages; edit the packages below.</p>`;
    $('#ads-settings-form').addEventListener('submit', async e => { e.preventDefault(); try { await api('/api/admin/ads/settings', { method: 'POST', body: new FormData(e.target) }); toast('Saved'); loadAdmin(); } catch (err) { toast(err.message); } });
    if (isAdmin) { loadPackages(); loadOrders(); } else { if (packagesBox) packagesBox.hidden = true; if (ordersAdmin) ordersAdmin.hidden = true; }
    list.innerHTML = j.ads.length ? j.ads.map(ad => `<article class="adcard" data-id="${ad.id}">
      <div>${ad.preview}</div>
      <div class="adcard__meta">
        <div class="inline"><span class="adcard__state is-${esc(ad.state_tone)}">${esc(ad.state_label)}</span>${ad.is_house ? '<span class="chip chip--plus">House</span>' : ''}<span class="chip">${esc(ad.placement)}</span>${ad.target_county ? `<span class="chip">${esc(ad.target_town ? ad.target_town + ', ' : '')}Co. ${esc(ad.target_county)}</span>` : '<span class="chip">All Ireland</span>'}<span class="mono" style="margin-left:auto;color:var(--muted)">weight ${ad.weight}</span></div>
        <h3>${esc(ad.business_name)} <span style="color:var(--muted);font-weight:500">· ${esc(ad.title)}</span></h3>
        <p class="sub" style="color:var(--muted);font-size:.8rem">${esc(ad.owner_name || '')} ${esc(ad.owner_email || '')} · <a href="${esc(ad.url)}" target="_blank" rel="noopener">${esc(ad.url)}</a> · created ${fmt(ad.created_at)}${ad.is_house ? '' : ' · ' + esc(ad.tier_label) + ' · ' + (ad.impressions_left === null ? '—' : num(ad.impressions_left)) + ' impressions left'}</p>
        ${ad.notes ? `<p class="sub is-warn">Note: ${esc(ad.notes)}</p>` : ''}
        <div class="adcard__stats"><span><b>${ad.impressions}</b> views</span><span><b>${ad.clicks}</b> clicks</span><span><b>${ad.ctr}%</b> CTR</span></div>
        <div class="adcard__actions">
          ${ad.status === 'review' || ad.status === 'draft' || ad.status === 'rejected' ? `<button class="btn btn--good btn--sm" data-act="approve">Approve</button>` : ''}
          ${ad.status !== 'rejected' ? `<button class="btn btn--hot btn--sm" data-act="reject">Reject…</button>` : ''}
          ${ad.status === 'approved' ? `<button class="btn btn--warn btn--sm" data-act="${ad.paused ? 'resume' : 'pause'}">${ad.paused ? 'Resume' : 'Pause'}</button>` : ''}
          ${isAdmin ? `<button class="btn btn--ghost btn--sm" data-act="house">${ad.is_house ? 'Unset house' : 'Make house ad'}</button>` : ''}
          <button class="btn btn--ghost btn--sm" data-act="edit">Edit</button>
          ${isAdmin ? `<button class="btn btn--ghost btn--sm" data-act="delete">Delete</button>` : ''}
        </div>
      </div></article>`).join('') : '<div class="empty"><div class="empty__glyph">—</div><h3>No adverts match.</h3></div>';
    list._ads = j.ads;
  }
  $('#ads-status')?.addEventListener('change', loadAdmin);
  list?.addEventListener('click', async e => {
    const b = e.target.closest('[data-act]'); if (!b) return;
    const id = b.closest('[data-id]').dataset.id, ad = list._ads.find(a => a.id === id), fd = new FormData();
    try {
      if (b.dataset.act === 'edit') return open(ad);
      fd.append('decision', b.dataset.act);
      if (b.dataset.act === 'reject') { const note = prompt('Reason for the advertiser (shown to them):', ad.notes || ''); if (note === null) return; fd.append('note', note); }
      if (b.dataset.act === 'house') fd.append('is_house', ad.is_house ? '0' : '1');
      if (b.dataset.act === 'delete' && !confirm('Delete this advert permanently?')) return;
      await api('/api/admin/ads/' + id, { method: 'POST', body: fd }); toast('Done'); loadAdmin(); window.dispatchEvent(new CustomEvent('ads:admin-changed'));
    } catch (err) { toast(err.message); }
  });


  /* ---------- newsroom: packages (admin) ---------- */
  const packagesBox = $('#ads-packages'), ordersAdmin = $('#ads-orders');
  const pkgRow = (p, tiers) => `<form class="pkgrow" data-pkg="${esc(p.id || '')}">
      <input type="hidden" name="id" value="${esc(p.id || '')}">
      <label>Name<input name="name" required maxlength="60" value="${esc(p.name || '')}"></label>
      <label>Price (${esc(pricing.currency || 'EUR')})<input name="price" type="number" step="0.01" min="1" required value="${p.price_cents ? (p.price_cents / 100).toFixed(2) : ''}"></label>
      <label>Impressions<input name="impressions" type="number" min="100" max="10000000" step="100" required value="${p.impressions || ''}"></label>
      <label>Tier<select name="tier">${Object.entries(tiers).map(([k, t]) => `<option value="${k}" ${k === (p.tier || 'sidebar') ? 'selected' : ''}>${esc(t.label)}</option>`).join('')}</select></label>
      <label>Tagline · badge · order<div class="inline" style="gap:6px"><input name="tagline" maxlength="80" placeholder="Tagline" value="${esc(p.tagline || '')}"><input name="badge" maxlength="30" placeholder="Badge" value="${esc(p.badge || '')}" style="max-width:110px"><input name="sort" type="number" value="${p.sort ?? 0}" style="max-width:64px"></div></label>
      <div class="pkgrow__actions"><label style="flex-direction:row;align-items:center;gap:6px;text-transform:none;letter-spacing:0">On sale <input type="checkbox" name="active" value="1" ${p.active === false ? '' : 'checked'} style="width:auto"></label><button class="btn btn--primary btn--sm" type="submit">${p.id ? 'Save' : 'Add'}</button>${p.id ? '<button class="btn btn--ghost btn--sm" type="button" data-pkg-delete>Delete</button>' : ''}</div>
      <label style="grid-column:1/-1">Features (one per line)<textarea name="features" rows="2">${esc((p.features || []).join('\n'))}</textarea></label>
    </form>`;
  async function loadPackages() {
    if (!packagesBox) return;
    const j = await api('/api/admin/ads/packages');
    packagesBox.hidden = false;
    packagesBox.innerHTML = `<span class="kicker">Ad packages — price and impressions</span><p class="panel__note" style="margin:6px 0 4px">What advertisers can buy on <a href="/advertise" target="_blank" rel="noopener">/advertise</a>. Set the price, how many times the advert is shown, and the tier (Sidebar → Site-wide → Front page). Changes apply to new purchases; paid orders keep what they bought.</p>${j.packages.map(p => pkgRow(p, j.tiers)).join('')}<details style="margin-top:10px"><summary class="mono">Add a package</summary>${pkgRow({}, j.tiers)}</details>`;
  }
  packagesBox?.addEventListener('submit', async e => {
    e.preventDefault();
    try { const j = await api('/api/admin/ads/packages', { method: 'POST', body: new FormData(e.target) }); toast(`${j.package.name} saved — ${j.package.price_label} for ${num(j.package.impressions)} impressions`); loadPackages(); } catch (err) { toast(err.message); }
  });
  packagesBox?.addEventListener('click', async e => {
    const b = e.target.closest('[data-pkg-delete]'); if (!b) return;
    const f = b.closest('[data-pkg]'); if (!confirm('Delete this package? Existing orders are unaffected.')) return;
    try { await api(`/api/admin/ads/packages/${f.dataset.pkg}/delete`, { method: 'POST', body: new FormData() }); toast('Package deleted'); loadPackages(); } catch (err) { toast(err.message); }
  });

  /* ---------- newsroom: orders (admin) ---------- */
  async function loadOrders() {
    if (!ordersAdmin) return;
    const status = ordersAdmin._status || '';
    const [j, pk] = await Promise.all([api('/api/admin/ads/orders?status=' + encodeURIComponent(status)), api('/api/admin/ads/packages')]);
    ordersAdmin.hidden = false;
    const paid = j.orders.filter(o => ['paid', 'running', 'completed'].includes(o.status)).reduce((s, o) => s + o.price_cents, 0);
    ordersAdmin.innerHTML = `<div class="inline" style="justify-content:space-between;flex-wrap:wrap;gap:10px"><span class="kicker">Orders · ${esc(pricing.currency || 'EUR')} ${(paid / 100).toFixed(2)} paid</span>
      <select id="orders-status">${[['', 'All orders'], ['pending', 'Awaiting payment'], ['paid', 'Paid, not attached'], ['running', 'Running'], ['completed', 'Completed'], ['refunded', 'Refunded']].map(([v, l]) => `<option value="${v}" ${v === status ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
      <form class="form" id="order-grant" style="margin:10px 0"><div class="form__row" style="grid-template-columns:1.4fr 1fr 1.2fr auto;align-items:end">
        <label>Grant a package (invoice / bank transfer)<input name="email" type="email" required placeholder="member@example.ie"></label>
        <label>Package<select name="package_id">${pk.packages.filter(p => p.active).map(p => `<option value="${p.id}">${esc(p.name)} · ${esc(p.price_label)} · ${num(p.impressions)}</option>`).join('')}</select></label>
        <label>Note<input name="note" maxlength="300" placeholder="e.g. invoice 1042 paid"></label>
        <button class="btn btn--primary" type="submit">Grant</button>
      </div></form>
      ${j.orders.length ? `<div class="tablewrap"><table class="table"><thead><tr><th>When</th><th>Member</th><th>Package</th><th>Paid</th><th>Advert</th><th>Progress</th><th></th></tr></thead><tbody>${j.orders.map(o => `<tr data-order="${esc(o.id)}"><td>${fmt(o.created_at)}</td><td>${esc(o.owner_name || '')}<span class="sub">${esc(o.owner_email || '')}</span></td><td>${esc(o.package_name)}<span class="sub">${esc(o.tier_label)} · ${num(o.impressions)}</span></td><td>${esc(o.price_label)}<span class="sub">${esc(o.gateway || '')}${o.payment_ref ? ' · ' + esc(o.payment_ref) : ''}</span></td><td>${o.business_name ? esc(o.business_name) : '<span class="sub">not attached</span>'}</td><td><div class="orderbar" style="min-width:90px"><i style="--v:${o.progress}"></i></div><span class="sub">${esc(o.state_label)}</span></td><td>${['paid', 'running'].includes(o.status) ? '<button class="btn btn--ghost btn--sm" data-refund>Refund…</button>' : ''}</td></tr>`).join('')}</tbody></table></div>` : '<p class="panel__note">No orders yet.</p>'}`;
    $('#orders-status').addEventListener('change', e => { ordersAdmin._status = e.target.value; loadOrders(); });
    $('#order-grant').addEventListener('submit', async e => {
      e.preventDefault(); const fd = new FormData(e.target); fd.append('action', 'grant');
      try { const r = await api('/api/admin/ads/orders', { method: 'POST', body: fd }); toast(`Granted ${r.order.package_name} to ${fd.get('email')}`); e.target.reset(); loadOrders(); } catch (err) { toast(err.message); }
    });
  }
  ordersAdmin?.addEventListener('click', async e => {
    const b = e.target.closest('[data-refund]'); if (!b) return;
    const note = prompt('Refund this order and stop its advert? Enter a note (the gateway refund itself is done in Stripe/PayPal):'); if (note === null) return;
    const fd = new FormData(); fd.append('action', 'refund'); fd.append('order_id', b.closest('[data-order]').dataset.order); fd.append('note', note);
    try { await api('/api/admin/ads/orders', { method: 'POST', body: fd }); toast('Order refunded'); loadOrders(); loadAdmin(); } catch (err) { toast(err.message); }
  });

  window.addEventListener('ads:changed', () => { loadMine(); loadAdmin(); });
  window.ME.loadAds = () => { loadMine(); loadAdmin(); };
  if (mine) loadMine();
})();
