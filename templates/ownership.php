<section class="container pagehead pagehead--narrow">
  <span class="kicker">Ownership &amp; funding</span>
  <h1 class="pagehead__title">Who is behind <em>ME News.</em></h1>
  <p class="pagehead__blurb">No news site should ask for your trust without saying who owns it, who pays for it and who decides what gets published.</p>
</section>
<div class="container prose">
  <h2>Owner</h2>
  <p><b><?= e($company) ?></b> is owned and published by <b><?= e($owner) ?></b>, <?= e($address) ?>. It is independently owned: no political party, public body, publisher group or investor holds a stake or a seat.</p>
  <h2>How it is funded</h2>
  <p>Three ways, all visible on the site: <b>local business advertising</b> (always marked “Sponsored”, never mixed into editorial), <b>ME+ membership</b> (<?= e(\MeNews\Services\Membership::priceLabel()) ?>), and <b>promoted notices</b> from funeral directors, employers and promoters. Advertisers never see a story before publication and cannot buy coverage. Where ME News receives public funding for local journalism, for example through Coimisiún na Meán schemes for council or court reporting, the scheme and amount will be listed here.</p>
  <h2 id="editors">The desk</h2>
  <p>The people responsible for what is published. Wire headlines are curated by desk, not written by us; original reporting and community reports carry a real byline.</p>
  <ul class="sources">
    <?php foreach ($editors as $ed): ?><li><a href="<?= $ed['handle'] ? '/contributors/' . e($ed['handle']) : '#' ?>"><b><?= e($ed['display_name']) ?></b><span class="mono"><?= e($ed['title'] ?: 'Editor') ?><?= $ed['desk'] ? ' · ' . e($ed['desk']) . ' desk' : '' ?></span></a></li><?php endforeach; ?>
  </ul>
  <h2 id="contact">Contact</h2>
  <p>Editorial, corrections and complaints: <a href="mailto:<?= e($contact) ?>"><?= e($contact) ?></a>. Removal requests use the <a href="/moderation#takedown">notice-and-action form</a>. Advertising: <a href="/advertise">advertise with ME</a>.</p>
  <h2>Standards</h2>
  <p>Our <a href="/corrections">corrections policy and public log</a>, <a href="/moderation">moderation policy</a>, <a href="/about">how we label stories</a> and <a href="/privacy">privacy and cookies</a> are published in full. Machine-assisted steps, such as safety screening of uploads, are described on the how-it-works page; we do not publish machine-written articles.</p>
</div>
