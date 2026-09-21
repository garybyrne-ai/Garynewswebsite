<section class="container pagehead">
  <span class="kicker">Syndication</span>
  <h1 class="pagehead__title">Feeds for readers <em>and aggregators.</em></h1>
  <p class="pagehead__blurb">Every section and county has its own feed in RSS 2.0, Atom 1.0 and JSON Feed 1.1. Wire headlines link straight to their publishers; our own reporting carries full text, author and image. Google News, Apple News, Flipboard, Feedly, NewsNow, Inoreader and any other reader can subscribe without scraping.</p>
</section>
<div class="container" style="padding-bottom:50px">
  <div class="plans" style="padding-top:0;grid-template-columns:repeat(2,minmax(0,520px))">
    <div class="plan reveal">
      <span class="kicker">For Google News &amp; Apple News</span>
      <ul>
        <li><b>News sitemap</b> — <a href="/news-sitemap.xml"><?= e(absolute_url('/news-sitemap.xml')) ?></a> (our original reporting from the last 48 hours, also listed in robots.txt)</li>
        <li><b>Original reporting feed</b> — <a href="/feed/community.xml"><?= e(absolute_url('/feed/community.xml')) ?></a></li>
        <li><b>Publisher record</b> — NewsMediaOrganization structured data on the home page; NewsArticle on every report with author, section, keywords, location and publisher logo</li>
        <li><b>Sitemap</b> — <a href="/sitemap.xml"><?= e(absolute_url('/sitemap.xml')) ?></a></li>
      </ul>
      <p class="form__legal">Submit the site in Google Publisher Center and Apple News Publisher with the news sitemap and the original-reporting feed. Wire headlines are excluded on purpose: they canonicalise to their publishers.</p>
    </div>
    <div class="plan reveal">
      <span class="kicker">Formats</span>
      <ul>
        <li>RSS 2.0 — <code>.xml</code> (Atom, Dublin Core, Media RSS and content:encoded extensions)</li>
        <li>Atom 1.0 — <code>.atom</code></li>
        <li>JSON Feed 1.1 — <code>.json</code></li>
        <li>OpenSearch — <a href="/opensearch.xml">/opensearch.xml</a></li>
        <li>Add <code>?limit=100</code> for up to 100 items; feeds are cached for five minutes and allow cross-origin reads.</li>
      </ul>
    </div>
  </div>
  <div class="tablewrap"><table class="table">
    <thead><tr><th>Feed</th><th>RSS</th><th>Atom</th><th>JSON</th></tr></thead>
    <tbody>
    <?php foreach ($feeds as $key => $f): $base = substr($f['path'], 0, -4); ?>
      <tr><td><b><?= e(str_replace('ME News Ireland — ', '', $f['title'])) ?></b><span class="sub"><?= e($f['blurb'] ?? '') ?></span></td><td><a href="<?= e($f['path']) ?>"><?= e($f['path']) ?></a></td><td><a href="<?= e($base) ?>.atom">.atom</a></td><td><a href="<?= e($base) ?>.json">.json</a></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
