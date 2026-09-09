<section class="container errorpage">
  <div class="errorpage__code mono"><?= (int)$status ?></div>
  <h1><?= $status === 404 ? 'Nothing at this frequency.' : e($message) ?></h1>
  <p><?= $status === 404 ? 'The page you were looking for has moved, expired or never existed.' : '' ?></p>
  <div class="errorpage__actions"><a class="btn btn--primary" href="/">Back to Around Me</a><a class="btn btn--ghost" href="/search">Search stories</a></div>
</section>
