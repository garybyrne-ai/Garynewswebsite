/* ME Ads — shared designer used by the member dashboard and the newsroom */
(function () {
  'use strict';
  const { api, esc, toast } = window.ME;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const designer = $('#ad-designer'); if (!designer) return;
  const form = $('[data-ad-form]', designer), admin = form.dataset.admin === '1';
  const fmt = iso => iso ? new Date(iso).toLocaleDateString('en-IE', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
  let pricing = { price_label: '€25/month', trial_days: 7, stripe: false, paypal: false };
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
      out.textContent = action === 'submit' ? 'Submitted — an editor will review it shortly. Once approved, your free trial starts automatically.' : 'Saved.';
      toast(action === 'submit' ? 'Submitted for review' : 'Advert saved');
      window.dispatchEvent(new CustomEvent('ads:changed'));
      if (action !== 'save') designer.hidden = true;
    } catch (err) { out.classList.add('is-error'); out.textContent = err.message; }
  });

  const bars = stats => `<div class="adbars" title="Impressions, last ${stats.length} days">${(() => { const m = Math.max(1, ...stats.map(s => s.impressions)); return stats.map(s => `<i style="height:${Math.max(4, s.impressions / m * 100)}%" title="${s.day}: ${s.impressions} views, ${s.clicks} clicks"></i>`).join(''); })()}</div>`;

  /* ---------- member: my ads ---------- */
  const mine = $('#my-ads');
  async function loadMine() {
    if (!mine) return;
    const j = await api('/api/me/ads'); pricing = j.pricing;
    $$('[data-price-label]').forEach(x => x.textContent = pricing.price_label); $$('[data-trial-days]').forEach(x => x.textContent = pricing.trial_days);
    mine.innerHTML = j.ads.length ? j.ads.map(ad => `<article class="adcard" data-id="${ad.id}">
      <div>${ad.preview}</div>
      <div class="adcard__meta">
        <div class="inline"><span class="adcard__state is-${esc(ad.state_tone)}">${esc(ad.state_label)}</span>${ad.target_county ? `<span class="chip">${esc(ad.target_town ? ad.target_town + ', ' : '')}Co. ${esc(ad.target_county)}</span>` : '<span class="chip">All Ireland</span>'}</div>
        <h3>${esc(ad.business_name)} · <span style="color:var(--muted);font-weight:500">${esc(ad.title)}</span></h3>
        ${ad.notes && ad.status === 'rejected' ? `<p class="is-warn" style="font-size:.9rem">Editor: ${esc(ad.notes)}</p>` : ''}
        <div class="adcard__stats"><span><b>${ad.impressions}</b> views</span><span><b>${ad.clicks}</b> clicks</span><span><b>${ad.ctr}%</b> CTR</span><span><b>${ad.gateway ? esc(ad.gateway) : '—'}</b> billing</span></div>
        ${bars(ad.stats)}
        <div class="adcard__actions">
          ${ad.status === 'draft' ? `<button class="btn btn--primary btn--sm" data-act="submit">Submit for review</button>` : ''}
          ${ad.can_subscribe && pricing.stripe ? `<button class="btn btn--primary btn--sm" data-act="stripe">Subscribe by card · ${esc(pricing.price_label)}</button>` : ''}
          ${ad.can_subscribe && pricing.paypal ? `<button class="btn btn--ghost btn--sm" data-act="paypal">Subscribe with PayPal</button>` : ''}
          ${ad.can_subscribe && !pricing.stripe && !pricing.paypal ? `<span class="mono" style="color:var(--muted)">Online payment coming soon — contact the newsroom to keep this running</span>` : ''}
          <button class="btn btn--ghost btn--sm" data-act="edit">Edit</button>
          ${ad.status === 'approved' ? `<button class="btn btn--ghost btn--sm" data-act="pause">${ad.paused ? 'Resume' : 'Pause'}</button>` : ''}
          ${!['active', 'past_due'].includes(ad.plan_status) ? `<button class="btn btn--ghost btn--sm" data-act="delete">Delete</button>` : ''}
        </div>
      </div></article>`).join('') : `<div class="empty"><div class="empty__glyph">—</div><h3>No adverts yet.</h3><p>Design one in a few minutes. It runs free for ${pricing.trial_days} days after approval, then ${esc(pricing.price_label)}.</p></div>`;
    mine._ads = j.ads;
  }
  mine?.addEventListener('click', async e => {
    const b = e.target.closest('[data-act]'); if (!b) return;
    const card = b.closest('[data-id]'), id = card.dataset.id, ad = mine._ads.find(a => a.id === id);
    try {
      switch (b.dataset.act) {
        case 'edit': open(ad); break;
        case 'submit': await api(`/api/me/ads/${id}/submit`, { method: 'POST', body: new FormData() }); toast('Submitted for review'); loadMine(); break;
        case 'pause': { const fd = new FormData(); fd.append('paused', ad.paused ? '0' : '1'); await api(`/api/me/ads/${id}/pause`, { method: 'POST', body: fd }); loadMine(); break; }
        case 'delete': if (confirm('Delete this advert?')) { await api(`/api/me/ads/${id}/delete`, { method: 'POST', body: new FormData() }); loadMine(); } break;
        case 'stripe': b.disabled = true; { const j = await api(`/api/me/ads/${id}/checkout/stripe`, { method: 'POST', body: new FormData() }); location.href = j.url; } break;
        case 'paypal': b.disabled = true; { const j = await api(`/api/me/ads/${id}/checkout/paypal`, { method: 'POST', body: new FormData() }); location.href = j.url; } break;
      }
    } catch (err) { toast(err.message); b.disabled = false; }
  });

  /* ---------- newsroom ---------- */
  const list = $('#ads-list'), settings = $('#ads-settings');
  async function loadAdmin() {
    if (!list) return;
    const status = $('#ads-status')?.value || '';
    const j = await api('/api/admin/ads?status=' + encodeURIComponent(status)); pricing = j.pricing;
    const isAdmin = window.NEWSROOM?.role === 'admin';
    settings.innerHTML = `<span class="kicker">Pricing &amp; gateways</span>
      <form class="form" id="ads-settings-form" style="margin-top:10px"><div class="form__row" style="grid-template-columns:1fr 1fr 1fr auto;align-items:end">
        <label>Monthly price<input name="price" type="number" step="0.01" min="1" value="${(pricing.price_cents / 100).toFixed(2)}" ${isAdmin ? '' : 'disabled'}></label>
        <label>Currency<select name="currency" ${isAdmin ? '' : 'disabled'}>${['EUR', 'GBP', 'USD'].map(c => `<option ${c === pricing.currency ? 'selected' : ''}>${c}</option>`).join('')}</select></label>
        <label>Free trial (days)<input name="trial_days" type="number" min="0" max="60" value="${pricing.trial_days}" ${isAdmin ? '' : 'disabled'}></label>
        ${isAdmin ? '<button class="btn btn--primary" type="submit">Save</button>' : ''}
      </div></form>
      <p class="panel__note">Currently <b>${esc(pricing.price_label)}</b> after a <b>${pricing.trial_days}-day</b> free trial. Stripe: <b class="${pricing.stripe ? 'is-good' : 'is-bad'}">${pricing.stripe ? 'connected' : 'add STRIPE_SECRET_KEY + STRIPE_WEBHOOK_SECRET to .env'}</b> · PayPal: <b class="${pricing.paypal ? 'is-good' : 'is-bad'}">${pricing.paypal ? 'connected (' + (pricing.paypal_mode || 'sandbox') + ')' : 'add PAYPAL_CLIENT_ID, PAYPAL_CLIENT_SECRET, PAYPAL_WEBHOOK_ID to .env'}</b>. Webhooks: <code>/api/stripe/webhook</code> and <code>/api/paypal/webhook</code>.</p>`;
    $('#ads-settings-form').addEventListener('submit', async e => { e.preventDefault(); try { await api('/api/admin/ads/settings', { method: 'POST', body: new FormData(e.target) }); toast('Pricing saved'); loadAdmin(); } catch (err) { toast(err.message); } });
    list.innerHTML = j.ads.length ? j.ads.map(ad => `<article class="adcard" data-id="${ad.id}">
      <div>${ad.preview}</div>
      <div class="adcard__meta">
        <div class="inline"><span class="adcard__state is-${esc(ad.state_tone)}">${esc(ad.state_label)}</span>${ad.is_house ? '<span class="chip chip--plus">House</span>' : ''}<span class="chip">${esc(ad.placement)}</span>${ad.target_county ? `<span class="chip">${esc(ad.target_town ? ad.target_town + ', ' : '')}Co. ${esc(ad.target_county)}</span>` : '<span class="chip">All Ireland</span>'}<span class="mono" style="margin-left:auto;color:var(--muted)">weight ${ad.weight}</span></div>
        <h3>${esc(ad.business_name)} <span style="color:var(--muted);font-weight:500">· ${esc(ad.title)}</span></h3>
        <p class="sub" style="color:var(--muted);font-size:.8rem">${esc(ad.owner_name || '')} ${esc(ad.owner_email || '')} · <a href="${esc(ad.url)}" target="_blank" rel="noopener">${esc(ad.url)}</a> · created ${fmt(ad.created_at)}${ad.trial_ends_at ? ' · trial ends ' + fmt(ad.trial_ends_at) : ''}${ad.current_period_end ? ' · paid until ' + fmt(ad.current_period_end) : ''}${ad.gateway ? ' · ' + esc(ad.gateway) : ''}</p>
        ${ad.notes ? `<p class="sub is-warn">Note: ${esc(ad.notes)}</p>` : ''}
        <div class="adcard__stats"><span><b>${ad.impressions}</b> views</span><span><b>${ad.clicks}</b> clicks</span><span><b>${ad.ctr}%</b> CTR</span></div>
        <div class="adcard__actions">
          ${ad.status === 'review' || ad.status === 'draft' || ad.status === 'rejected' ? `<button class="btn btn--good btn--sm" data-act="approve">Approve</button>` : ''}
          ${ad.status !== 'rejected' ? `<button class="btn btn--hot btn--sm" data-act="reject">Reject…</button>` : ''}
          ${ad.status === 'approved' ? `<button class="btn btn--warn btn--sm" data-act="${ad.paused ? 'resume' : 'pause'}">${ad.paused ? 'Resume' : 'Pause'}</button>` : ''}
          ${isAdmin && !ad.is_house ? `<button class="btn btn--ghost btn--sm" data-act="activate">Mark paid…</button>` : ''}
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
      if (b.dataset.act === 'activate') { const m = prompt('Months to activate (e.g. 1). Add "paid" to record as a paid period, otherwise it is complimentary:', '1'); if (m === null) return; fd.append('months', parseInt(m, 10) || 1); fd.append('paid', /paid/i.test(m) ? '1' : '0'); }
      if (b.dataset.act === 'house') fd.append('is_house', ad.is_house ? '0' : '1');
      if (b.dataset.act === 'delete' && !confirm('Delete this advert permanently?')) return;
      await api('/api/admin/ads/' + id, { method: 'POST', body: fd }); toast('Done'); loadAdmin(); window.dispatchEvent(new CustomEvent('ads:admin-changed'));
    } catch (err) { toast(err.message); }
  });

  window.addEventListener('ads:changed', () => { loadMine(); loadAdmin(); });
  window.ME.loadAds = () => { loadMine(); loadAdmin(); };
  if (mine) loadMine();
})();
