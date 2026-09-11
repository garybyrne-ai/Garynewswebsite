<section class="container pagehead pagehead--center" style="padding-top:80px;padding-bottom:80px">
  <span class="kicker">ME News Ireland</span>
  <h1 class="pagehead__title"><?= e($heading) ?></h1>
  <?php if (!empty($text)): ?><p class="pagehead__blurb" style="text-align:center"><?= e($text) ?></p><?php endif; ?>
  <a class="btn btn--primary" href="<?= e($link) ?>"><?= e($linkText) ?> <?= icon('arrow') ?></a>
</section>
