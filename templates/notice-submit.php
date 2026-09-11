<?php $k = $kind; ?>
<section class="container pagehead pagehead--narrow">
  <span class="kicker">Place a notice</span>
  <h1 class="pagehead__title">Tell your <em>county.</em></h1>
  <p class="pagehead__blurb">Death notices, in memoriam, events, jobs, planning notices, lost pets and club results. No account needed: confirm from your email, an editor checks it, and it is emailed to everyone in the county who asked for alerts. Free while ME News grows in your county.</p>
</section>
<div class="container layout">
  <div class="layout__main">
    <form class="form noticeform" id="notice-form">
      <div class="reportcats reportcats--7" role="group" aria-label="Notice type">
        <?php foreach ($kinds as $key => $m): ?><label class="reportcat"><input type="radio" name="kind" value="<?= e($key) ?>" <?= $key === $k ? 'checked' : '' ?>><span><?= icon($m['icon']) ?><b><?= e($m['label']) ?></b><small><?= e($m['who']) ?></small></span></label><?php endforeach; ?>
      </div>

      <label><span data-title-label>Full name of the deceased</span><input name="title" required maxlength="160" placeholder=""></label>
      <div class="form__row">
        <label>Town / parish<input name="town" list="all-locations" autocomplete="off" placeholder="e.g. Rathdrum"></label>
        <label>County<select name="county" required data-county-select><option value="">Select county</option><?php foreach ($counties as $c): ?><option <?= $c === $county ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></label>
      </div>

      <fieldset data-for="death memoriam" class="noticeform__group">
        <div class="form__row">
          <label>Date of death<input name="date_of_death" type="date"></label>
          <label>Address (optional)<input name="address" placeholder="e.g. Main Street, formerly of Arklow"></label>
        </div>
        <label>Reposing<textarea name="reposing" rows="2" placeholder="e.g. Reposing at Byrne's Funeral Home, Rathdrum, on Thursday from 4pm to 7pm."></textarea></label>
        <div class="form__row">
          <label>Funeral date &amp; time<input name="funeral_at" type="datetime-local"></label>
          <label>Funeral venue<input name="funeral_venue" placeholder="e.g. St Mary's Church, Rathdrum"></label>
        </div>
        <label>Burial / cremation<input name="burial" placeholder="e.g. Burial afterwards in the adjoining cemetery"></label>
        <label>Family message (optional)<textarea name="family_message" rows="2" placeholder="e.g. Family flowers only; donations, if desired, to Wicklow Hospice. House private on the morning of the funeral."></textarea></label>
      </fieldset>

      <fieldset data-for="event" class="noticeform__group">
        <div class="form__row">
          <label>Starts<input name="event_at" type="datetime-local"></label>
          <label>Ends (optional)<input name="event_end" type="datetime-local"></label>
        </div>
        <div class="form__row">
          <label>Venue<input name="venue" placeholder="e.g. Arklow Bay Hotel"></label>
          <label>Price<input name="price" placeholder="e.g. Free · €10 · €5 kids"></label>
        </div>
      </fieldset>

      <fieldset data-for="job" class="noticeform__group">
        <div class="form__row">
          <label>Employer<input name="contact_org_job" placeholder="e.g. Byrne's Hardware"></label>
          <label>Pay / hours<input name="price" placeholder="e.g. €14.50/hr, 30 hrs"></label>
        </div>
      </fieldset>

      <fieldset data-for="result" class="noticeform__group">
        <label>Competition<input name="competition" placeholder="e.g. Wicklow Junior B Football Championship"></label>
        <div class="form__row form__row--score">
          <label>Home<input name="home" placeholder="Rathdrum"></label>
          <label>Score<input name="home_score" placeholder="1-12"></label>
          <label>Score<input name="away_score" placeholder="0-09"></label>
          <label>Away<input name="away" placeholder="Avondale"></label>
        </div>
        <label>Club notes (optional)<textarea name="club_notes" rows="3" placeholder="Fixtures, lotto, training times, congratulations…"></textarea></label>
      </fieldset>

      <fieldset data-for="pet" class="noticeform__group">
        <label>Status<select name="pet_status"><option value="lost">Lost</option><option value="found">Found</option><option value="reunited">Reunited</option></select></label>
      </fieldset>

      <label><span data-body-label>Notice text</span><textarea name="body" rows="5" placeholder="Write it as you would read it out."></textarea></label>
      <label>Link (optional)<input name="url" placeholder="Tickets, the funeral home, the planning file…"></label>

      <h3 class="noticeform__h">Who is placing this?</h3>
      <div class="form__row">
        <label>Your name<input name="contact_name" required value="<?= e($user['display_name'] ?? '') ?>"></label>
        <label>Organisation (funeral director, club, venue, employer)<input name="contact_org" placeholder="Optional"></label>
      </div>
      <div class="form__row">
        <label>Email<input name="contact_email" type="email" required value="<?= e($user['email'] ?? '') ?>"></label>
        <label>Phone (never published)<input name="contact_phone" type="tel"></label>
      </div>
      <div class="form__actions"><button class="btn btn--primary" type="submit"><?= icon('check') ?> Send for publication</button></div>
      <p class="form__result" id="notice-result" role="status"></p>
      <p class="form__legal">By placing a notice you confirm you are entitled to publish it and that it names no unconvicted person in connection with a crime. Editors may shorten or decline notices. <a href="/moderation">Moderation policy</a>.</p>
    </form>
  </div>
  <aside class="side">
    <section class="panel reveal"><header class="panel__head"><span class="kicker">How it works</span></header>
      <ol class="howlist"><li><b>Fill it in</b> — two minutes, no account.</li><li><b>Confirm by email</b> — one tap from the address you gave.</li><li><b>An editor checks it</b> — usually within the hour in the daytime.</li><li><b>It goes live and out by email</b> to everyone in the county who asked for alerts.</li></ol>
    </section>
    <section class="panel reveal"><header class="panel__head"><span class="kicker">Pricing</span></header>
      <p class="panel__note" style="margin:0">Death notices, in memoriam, club results and lost pets are free. Events and jobs are free to list; a <b>promoted</b> slot at the top of the county for 30 days is available from the newsroom.</p>
    </section>
  </aside>
</div>
<?php $extraScripts = '<script>window.NOTICE_LABELS=' . json_encode([
    'death' => ['Full name of the deceased', 'Notice text (optional — the details above are usually enough)'],
    'memoriam' => ['Name of the person remembered', 'Your message'],
    'event' => ['Event title', 'What’s happening'],
    'job' => ['Job title', 'About the role'],
    'planning' => ['Notice title (e.g. Planning application, Main Street)', 'Full text of the notice'],
    'pet' => ['Pet’s name and type (e.g. Bella, black labrador)', 'Where and when, markings, how to contact you'],
    'result' => ['Headline (e.g. Rathdrum through to county semi-final)', 'Match report (optional)'],
]) . ';</script>'; ?>
