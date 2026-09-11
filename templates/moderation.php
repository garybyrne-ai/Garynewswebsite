<section class="container pagehead pagehead--narrow">
  <span class="kicker">Moderation &amp; takedowns</span>
  <h1 class="pagehead__title">Rules for what <em>gets published.</em></h1>
  <p class="pagehead__blurb">Community reporting is the point of ME News, so the rules are public, the process is real, and removal requests get answered.</p>
</section>
<div class="container prose">
  <h2>Before anything is published</h2>
  <p>Every community report and comment is screened for harmful content by the Trust Engine, then read by an editor. Reports that name a person in connection with a crime, identify a minor, show graphic injury, or make a high-impact claim always get human review before publication, and often a phone call to the reporter.</p>
  <h2>The no-naming rule</h2>
  <p>We do not publish the name of anyone in connection with a crime or serious allegation unless they have been convicted, charged in open court, or named by An Garda Síochána or a court. Irish defamation law is strict and, more importantly, so are we: a wrong name can end a life as it is lived. Reports that break this rule are edited or declined and the contributor is told why.</p>
  <h2>Corroboration</h2>
  <p>“I saw this too” lets neighbours independently confirm a report. Three confirmations from separate people earn a <b>Corroborated</b> label. Editors also check photo metadata, run a reverse image search on anything that looks familiar, and compare reports of the same incident so the desk sees one event, not five submissions.</p>
  <h2>What we remove</h2>
  <p>Content that is illegal, defamatory, a privacy intrusion, identifies a minor, infringes copyright or is shown to be inaccurate. Under the Digital Services Act we operate a notice-and-action process: anyone can flag content below, we acknowledge every request, and we act on clear cases first and review after. Coimisiún na Meán is the Irish regulator.</p>
  <h2>Contributors</h2>
  <p>Contributors build a track record: reports filed, published and corroborated. Good standing earns a verified tick and faster publication. Repeated inaccuracy, harassment or attempts to identify people lose it, and can lead to a ban.</p>

  <h2 id="takedown">Ask for a removal or correction</h2>
  <form class="form panel" id="takedown-form" style="max-width:640px">
    <label>Address of the page<input name="url" required placeholder="https://…"></label>
    <label>Reason<select name="reason" required><option value="">Choose…</option><option value="defamation">It is defamatory / untrue about a person</option><option value="privacy">It intrudes on privacy</option><option value="minor">It identifies a child</option><option value="copyright">It uses my copyright work</option><option value="illegal">It is otherwise illegal</option><option value="inaccurate">It is inaccurate (correction)</option><option value="other">Something else</option></select></label>
    <label>Details<textarea name="detail" rows="4" placeholder="What is wrong, and what should happen"></textarea></label>
    <label>Your email<input name="contact" type="email" required></label>
    <div class="form__actions"><button class="btn btn--primary" type="submit">Send request</button></div>
    <p class="form__result" id="takedown-result" role="status"></p>
  </form>
</div>
