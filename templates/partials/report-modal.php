<?php
/**
 * Community report modal: photo first, prompted, no account required for a first report.
 * Expects $user (nullable) and $county (visitor's county, nullable).
 */
use MeNews\Support\Categories;
$whatsapp = \MeNews\Database::installed() ? (\MeNews\Database::setting('whatsapp_' . slugify((string)$county)) ?: \MeNews\Database::setting('whatsapp_number')) : null;
$town = \MeNews\Support\Visitor::position() ? (\MeNews\Support\Geo::nearest(\MeNews\Support\Visitor::position()['lat'], \MeNews\Support\Visitor::position()['lng'])['town'] ?? null) : null;
$where = $town ?: ($county ?: 'your area');
$prompts = ['Community' => 'Something happening now', 'Traffic' => 'A crash, closure or delay', 'Sport' => 'A club result or fixture'];
?>
<div class="modal" id="report-modal" role="dialog" aria-modal="true" aria-labelledby="report-title" hidden>
  <div class="modal__box modal__box--wide">
    <header class="modal__head"><h2 id="report-title"><?= icon('report') ?> What's happening in <?= e($where) ?> right now?</h2><button class="iconbtn" type="button" data-close aria-label="Close"><?= icon('close') ?></button></header>
    <div class="modal__body">
      <?php if ($whatsapp): ?>
        <a class="wa" href="https://wa.me/<?= e(preg_replace('/\D+/', '', $whatsapp)) ?>?text=<?= rawurlencode('Hi ME News, I want to report something in ' . $where . ':') ?>" target="_blank" rel="noopener"><?= icon('whatsapp') ?><span><b>Faster on WhatsApp?</b> Send a photo and a line to <?= e($whatsapp) ?> — it lands in the same newsroom queue.</span></a>
      <?php endif; ?>
      <form id="report-form" class="form" enctype="multipart/form-data">
        <div class="reportcats" role="group" aria-label="What kind of report?">
          <?php foreach ($prompts as $cat => $hint): ?><label class="reportcat"><input type="radio" name="category" value="<?= e($cat) ?>" <?= $cat === 'Community' ? 'checked' : '' ?>><span><?= icon(Categories::ALL[$cat]['icon']) ?><b><?= e($cat === 'Community' ? 'Something happening' : $cat) ?></b><small><?= e($hint) ?></small></span></label><?php endforeach; ?>
          <label class="reportcat reportcat--more"><input type="radio" name="category" value="Council"><span><?= icon('more') ?><b>Other</b><small>Council, event, business…</small></span></label>
        </div>
        <label class="dropzone" data-dropzone>
          <input name="media" type="file" accept="image/*,video/*" capture="environment">
          <span class="dropzone__in"><?= icon('camera', 'icon--lg') ?><b>Add a photo or video first</b><small>Tap to use your camera or choose a file. Optional, but photos get published faster.</small></span>
          <span class="dropzone__preview" hidden></span>
        </label>
        <div class="form__row">
          <label>Town / area<input name="location_name" id="report-town" list="all-locations" required placeholder="e.g. Bray" autocomplete="off" value="<?= e($town ?? '') ?>"></label>
          <label>County<select name="county" id="report-county" data-county-select><option value="">Select county</option><?php if ($county): ?><option selected><?= e($county) ?></option><?php endif; ?></select></label>
        </div>
        <label>What can you see? <small class="form__hint">One or two lines is plenty. Describe only what you personally know — no names of people involved in an incident.</small><textarea name="body" rows="3" required minlength="12" placeholder="e.g. Two cars in a collision at the Main Street junction, gardaí on the scene, traffic backed up to the church."></textarea></label>
        <label>Headline <small class="form__hint">Optional — we'll write one from your description if you leave it blank</small><input name="title" maxlength="180" placeholder="Short and factual"></label>
        <?php if (!$user): ?>
          <div class="form__row">
            <label>Your name<input name="reporter_name" required minlength="2" autocomplete="name" placeholder="Shown as the reporter"></label>
            <label>Email or mobile<input name="reporter_contact" required autocomplete="email" placeholder="So we can confirm it's you"></label>
          </div>
          <p class="form__hint">No account needed for your first report. We'll send a one-tap confirmation link; nothing is published before that and an editor's check.</p>
        <?php endif; ?>
        <input type="hidden" name="latitude" id="report-lat"><input type="hidden" name="longitude" id="report-lng"><input type="hidden" name="province" id="report-province"><input type="hidden" name="local_area" value="">
        <div class="form__actions">
          <button type="button" class="btn btn--ghost" data-gps><?= icon('gps') ?> Add my GPS</button>
          <button class="btn btn--hot" type="submit">Send to newsroom</button>
        </div>
      </form>
      <p class="form__result" id="report-result" role="status"></p>
      <p class="form__legal">Every report is screened for safety, then an editor decides. Sensitive allegations, identifiable minors and graphic events always get human review. Do not name anyone in connection with a crime unless they have been convicted. <a href="/moderation">Moderation policy</a></p>
    </div>
  </div>
</div>
