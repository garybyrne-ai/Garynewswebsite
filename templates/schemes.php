<section class="container pagehead pagehead--narrow">
  <span class="kicker">Government schemes</span>
  <h1 class="pagehead__title">Find the <em>scheme you qualify for.</em></h1>
  <p class="pagehead__blurb">Major central government schemes, with a link straight to the official portal for each. Eligibility rules are set by the government and can depend on your state, income and other details we don't have — always check and apply on the official site, not here.</p>
</section>
<div class="container">
  <div class="grid grid--3">
    <?php foreach ($schemes as $s): ?>
      <article class="package reveal">
        <span class="kicker"><?= e($s['category']) ?></span>
        <h2><?= e($s['name']) ?></h2>
        <p class="package__tag"><?= e($s['summary']) ?></p>
        <a class="btn btn--ghost btn--block" href="<?= e($s['url']) ?>" target="_blank" rel="noopener">Official site ↗</a>
      </article>
    <?php endforeach; ?>
  </div>
  <p class="panel__note" style="margin:24px 0">This is a directory, not an eligibility checker or an application service — Bharat Wire is not affiliated with any of these schemes. Beware of anyone charging a fee to "process" a government scheme application; they are all free to apply for directly.</p>
</div>
