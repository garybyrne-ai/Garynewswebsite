<section class="container pagehead pagehead--narrow">
  <span class="kicker">How ME works</span>
  <h1 class="pagehead__title">Built for trust, <em>not for clicks.</em></h1>
  <p class="pagehead__blurb">ME News Ireland combines two streams: a live wire of headlines from established Irish publishers, and community reports from the people who live where the news happens. Both pass through the same editorial labels.</p>
</section>
<div class="container prose">
  <div class="steps">
    <div class="step reveal"><span class="mono">01</span><h3>Report</h3><p>Signed-in members send text, photos or video. Everything lands in private quarantine — nothing is public yet.</p></div>
    <div class="step reveal"><span class="mono">02</span><h3>Screen</h3><p>The Trust Engine probes media, samples video frames, transcribes audio and runs safety moderation. It produces a <b>safety score</b> and a separate <b>confidence score</b>.</p></div>
    <div class="step reveal"><span class="mono">03</span><h3>Decide</h3><p>An editor publishes, holds or rejects. They alone choose the public label: Community Report, Developing, Verified or Official.</p></div>
    <div class="step reveal"><span class="mono">04</span><h3>Confirm</h3><p>Neighbours add independent confirmations and local context. Confidence rises with evidence, never with popularity.</p></div>
  </div>

  <h2 id="labels">The four labels</h2>
  <div class="labels-grid">
    <div><?= \MeNews\Ui::label('Community Report') ?><p>Reported by a member of the public. Screened for safety but not yet corroborated.</p></div>
    <div><?= \MeNews\Ui::label('Developing') ?><p>Editors are actively checking. Details may change.</p></div>
    <div><?= \MeNews\Ui::label('Verified') ?><p>Corroborated by an editor, or sourced from an established publisher with a live link back to the original.</p></div>
    <div><?= \MeNews\Ui::label('Official') ?><p>Based on a published statement from a public body such as a council, Garda Síochána or a Government department.</p></div>
  </div>

  <h2 id="sources">Our wire sources</h2>
  <p>The wire stores only the headline, standfirst, image reference and publication time. Every wire story links to the publisher, who retains all rights to the full article.</p>
  <ul class="sources">
    <?php foreach ($sources as $s): ?><li><a href="<?= e($s['home'] ?? $s['url']) ?>" target="_blank" rel="noopener"><b><?= e($s['name']) ?></b><span class="mono"><?= e($s['category']) ?><?= !empty($s['county']) ? ' · ' . e($s['county']) : '' ?></span></a></li><?php endforeach; ?>
  </ul>

  <?php if ($runs): ?>
    <h2>Recent wire activity</h2>
    <div class="tablewrap"><table class="table mono">
      <thead><tr><th>Source</th><th>Started</th><th>Fetched</th><th>New</th><th>Result</th></tr></thead>
      <tbody><?php foreach ($runs as $r): ?><tr><td><?= e($r['source_key']) ?></td><td><?= e(date_irish($r['started_at'], 'D H:i')) ?></td><td><?= (int)$r['fetched'] ?></td><td><?= (int)$r['inserted'] ?></td><td><?= $r['error'] ? '<span class="is-bad">' . e(excerpt($r['error'], 60)) . '</span>' : '<span class="is-good">ok</span>' ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>

  <h2>Principles</h2>
  <p><b>Safety is not truth.</b> The safety score asks whether content presents a harmful-content or privacy risk. The confidence score is only an evidence signal. Neither is permission to call a claim true.</p>
  <p><b>Advertising never touches editorial.</b> Local business adverts are approved separately and are always marked as sponsored.</p>
  <p><b>Your data stays yours.</b> Uploads remain private until published, sessions are stored as hashes, and you can delete a followed area or a report at any time from your dashboard.</p>
</div>
