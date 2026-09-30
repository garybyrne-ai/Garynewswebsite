<div class="container kidspage" data-game="county" data-puzzle='<?= e(json_encode($game, JSON_UNESCAPED_UNICODE)) ?>'>
  <div class="paper paper--puzzle">
    <?= \MeNews\View::partial('partials/paper-head', ['edition' => $edition, 'longDate' => $longDate]) ?>
    <div class="puzzle__head"><div><span class="paper__stamp">Map game</span><h1>Find the State <small>· ten rounds</small></h1></div><div class="puzzle__levels"><button class="btn btn--sm btn--ghost" type="button" data-restart>Restart</button></div></div>
    <div class="puzzle__bar"><span class="cg__prompt" data-prompt>Tap the map where you think the state is.</span><span class="mono" data-score>Round 1 of 10 · 0 pts</span></div>
    <div id="county-map" class="map map--game"></div>
    <p class="cg__feedback" data-feedback></p>
    <div class="puzzle__done" data-done hidden></div>
    <p class="paper__note">The closer your tap is to the state's centre, the more points you score. Tomorrow's ten states will be different.</p>
  </div>
</div>
