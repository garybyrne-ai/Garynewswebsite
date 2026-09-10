<?php /** Ad designer form + live preview. Expects $counties, $formId, $admin */ $tpls = \MeNews\Services\Ads::TEMPLATES; ?>
<section class="designer" id="ad-designer" hidden>
  <header class="designer__head"><div><span class="kicker">Ad designer</span><h2 data-designer-title>New advert</h2></div><button class="btn btn--ghost btn--sm" type="button" data-ad-close>Close</button></header>
  <div class="designer__grid">
    <form class="form designer__form" id="<?= e($formId) ?>" enctype="multipart/form-data" data-ad-form data-admin="<?= $admin ? '1' : '0' ?>">
      <input type="hidden" name="id" value="">
      <input type="hidden" name="template" value="aurora">
      <input type="hidden" name="submit" value="0">
      <div class="form__row">
        <label>Business name<input name="business_name" required maxlength="60" placeholder="e.g. Gary's Tech Hub"></label>
        <label>Landing page<input name="url" type="url" required placeholder="https://"></label>
      </div>
      <label>Headline <small class="mono" data-count="title">0/64</small><input name="title" required minlength="4" maxlength="64" placeholder="Say the one thing that matters"></label>
      <label>Supporting line <small class="mono" data-count="body">0/120</small><input name="body" maxlength="120" placeholder="What you offer, where, from how much"></label>
      <div class="form__row">
        <label>Button text<input name="cta" maxlength="22" value="Learn more"></label>
        <label>Badge (optional)<input name="badge" maxlength="18" placeholder="e.g. From €29"></label>
      </div>
      <fieldset class="designer__templates">
        <legend class="mono">Template</legend>
        <?php foreach ($tpls as $key => $t): ?><button type="button" class="tpl <?= $key === 'aurora' ? 'is-active' : '' ?>" data-template="<?= e($key) ?>" data-bg1="<?= e($t['bg1']) ?>" data-bg2="<?= e($t['bg2']) ?>" data-fg="<?= e($t['fg']) ?>" data-ac="<?= e($t['ac']) ?>" style="--bg1:<?= e($t['bg1']) ?>;--bg2:<?= e($t['bg2']) ?>;--fg:<?= e($t['fg']) ?>;--ac:<?= e($t['ac']) ?>"><i></i><?= e($t['label']) ?></button><?php endforeach; ?>
      </fieldset>
      <div class="designer__colours">
        <label>Background<input type="color" name="bg1" value="#0b2a3a"></label>
        <label>Background 2<input type="color" name="bg2" value="#12604f"></label>
        <label>Text<input type="color" name="fg" value="#eef7f4"></label>
        <label>Button<input type="color" name="ac" value="#2ef2a8"></label>
        <label>Decoration<select name="shape"><option value="orb">Orb</option><option value="grid">Grid</option><option value="stripes">Stripes</option><option value="none">None</option></select></label>
        <label>Align<select name="align"><option value="left">Left</option><option value="center">Centre</option></select></label>
      </div>
      <div class="form__row">
        <label>Logo (PNG/JPG/WebP, square works best)<input type="file" name="logo" accept="image/png,image/jpeg,image/webp"><span class="designer__file mono" data-has="logo" hidden>Logo on file · <button type="button" class="linkbtn" data-remove="logo">remove</button></span></label>
        <label>Photo (for the Photo template)<input type="file" name="image" accept="image/png,image/jpeg,image/webp"><span class="designer__file mono" data-has="image" hidden>Photo on file · <button type="button" class="linkbtn" data-remove="image">remove</button></span></label>
      </div>
      <input type="hidden" name="remove_logo" value="0"><input type="hidden" name="remove_image" value="0">
      <div class="form__row">
        <label>Target county<select name="target_county" data-county-select><option value="">All of Ireland</option><?php foreach ($counties as $c): ?><option><?= e($c) ?></option><?php endforeach; ?></select></label>
        <label>Target town (optional)<input name="target_town" list="all-locations" autocomplete="off" placeholder="Any town"></label>
      </div>
      <label>Placement<select name="placement"><option value="both">Sidebar card + banner (recommended)</option><option value="sidebar">Sidebar card only</option><option value="banner">Banner only</option></select></label>
      <div class="form__actions" style="justify-content:flex-start">
        <button class="btn btn--ghost" type="submit" data-action="save"><?= $admin ? 'Save' : 'Save draft' ?></button>
        <?php if (!$admin): ?><button class="btn btn--primary" type="submit" data-action="submit">Submit for review → free trial</button><?php else: ?><button class="btn btn--primary" type="submit" data-action="house">Save &amp; run as house ad</button><?php endif; ?>
      </div>
      <p class="form__result" data-designer-result role="status"></p>
    </form>
    <aside class="designer__preview">
      <span class="kicker">Live preview</span>
      <div class="designer__side" data-preview-sidebar></div>
      <p class="panel__note">Ads are always labelled Sponsored, open in a new tab and never appear in the kids' section.</p>
    </aside>
    <div class="designer__bannerwrap"><span class="kicker">Banner preview · home, sections &amp; articles</span><div class="designer__banner" data-preview-banner></div></div>
  </div>
</section>
