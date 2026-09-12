<?php
/* The Project Manager progress report — and, since 17 Aug 2026, the thing that
 * receives it.
 *
 * This page used to POST to formsubmit.co. What a learner sends here is more
 * revealing than a registration: it is a dated, module-by-module account of how
 * far behind they are, attached to their name and employee number. That was
 * leaving South Africa to a third party on no written basis.
 *
 * It keeps its URL — the rewrite in .htaccess serves this file at /pm-progress
 * — so nothing that links to it has to change. Everything below the handler is
 * the original page: the inline script that paints the progress table is
 * untouched, and still sets #pgPayload and #pgSummaryField by ID.
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/mail.php';
require __DIR__ . '/lib/csrf.php';
require __DIR__ . '/lib/progress.php';
require __DIR__ . '/lib/chrome.php';
/* Before a single byte is printed. The session cookie is a header, so a page
   that prints first and starts its session later gets no session at all — and
   the CSRF token in the form below it is then unbacked, so the form can never
   be submitted. See the note in app_session_start(). */
app_session_start();


$errors = [];
$old    = [];

if (is_post()) {
    if (post_str('_honey') !== '') {
        audit('progress.honeypot', 'progress_reports', null, 'silently discarded');
        redirect('thanks');
    }

    [$clean, $errors] = progress_validate($_POST);

    if (!csrf_valid()) {
        $errors['_form'] = 'This page had been open a while and the form expired. '
                         . 'Nothing was lost — please send it again.';
    }

    if (!$errors) {
        if (progress_rate_limited()) {
            $errors['_form'] = 'That is a lot of reports from one connection in a short time. '
                             . 'Please wait a few minutes, or email us directly.';
            audit('progress.rate_limited');
        } else {
            $existing = progress_duplicate_id($clean);
            if ($existing !== null) {
                audit('progress.duplicate_ignored', 'progress_reports', $existing);
                csrf_rotate();
                redirect('thanks');
            }
            $id = progress_store($clean);
            progress_notify($clean, $id);
            csrf_rotate();
            redirect('thanks');
        }
    }

    $old = $clean;
}

/* Same three helpers as contact.php. Duplicated rather than shared because the
   next structural job is extracting the page chrome into partials, and these
   move into it then — putting them somewhere shared now would mean moving them
   twice. */
function err(array $errors, string $field): string
{
    return isset($errors[$field]) ? '<p class="field-err">' . e($errors[$field]) . '</p>' : '';
}
function bad(array $errors, string $field): string
{
    return isset($errors[$field]) ? ' has-err' : '';
}
function old(array $old, string $field): string
{
    return e((string) ($old[$field] ?? ''));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My progress — Project Manager NQF 5 — <?= e(brand('academy')) ?></title>
<meta name="description" content="Track your way through the eleven knowledge modules of the Occupational Certificate: Project Manager, print a report for your manager to sign, and send a dated record to the academy.">
<link rel="stylesheet" href="<?= e(asset('styles.css')) ?>">
</head>
<body>
<?php chrome_nav('site'); ?>

<section class="section-dark page-top">
  <div class="wrap">
    <div class="sec-head sec-head-wide reveal">
      <span class="eyebrow">Project Manager NQF 5</span>
      <h2 class="lede-h">Where you are, and something you can hand to your manager</h2>
      <p>Tick topics off as you work through them and this keeps count. <strong>Sign in and it is saved to your account</strong>, so it follows you to your phone and survives a shared machine being cleared. When you have finished a module, print the report for your manager to sign and send the academy a dated record.</p>
    </div>
    <?php /* Two different true answers, and the page must give the right one:
             a signed-in learner's ticks are on the server, an anonymous one's
             are in their browser. The wording is swapped by the script at the
             foot of this page once the session is known, and the markup below
             is the anonymous case because that is what somebody with JavaScript
             disabled is guaranteed to be. */ ?>
    <div class="sg-assure reveal" id="pgWhere">
      <span class="lbl">Where this is stored</span>
      <span id="pgWhereText">In this browser, on this device, and nowhere else — the same place your profile lives. Nothing is uploaded until you press send. On a shared machine, clear it from <a href="profile" style="color:var(--accent-green);font-weight:700">your profile</a> when you are done.</span>
    </div>
  </div>
</section>

<section>
  <div class="wrap">
    <div id="pgUnavailable" hidden>
      <div class="sg-caveat" style="max-width:820px;margin:0 auto"><strong>This browser will not let the site save anything.</strong> That is usually private browsing or a locked-down work profile. You can still read every module — the progress ticks just will not stick between visits. Ask IT, or use a different browser, if you want progress to be remembered.</div>
    </div>

    <div id="pgMain">
      <div class="stats-row reveal" id="pgStats"></div>

      <div class="scrollx reveal" style="margin-top:30px">
        <table class="ptable" id="pgTable"></table>
      </div>

      <div class="sg-card reveal" style="max-width:900px;margin:36px auto 0">
        <div class="sg-stepnum">Send it in</div>
        <h3>Put your progress on file</h3>
        <p class="sg-intro">This sends the academy a dated summary of where you are, in your own words. It goes to Centenary Networks, the accredited provider. If you are signed in they can already see the topics you have ticked — what this adds is a statement you stand behind, and the printable copy your manager signs. Send it whenever you finish a module, or sooner if you are stuck.</p>

        <form class="form" id="pgForm" action="pm-progress" method="POST" novalidate>
          <?= csrf_field() ?>
          <input type="text" name="_honey" tabindex="-1" autocomplete="off" style="display:none">
          <input type="hidden" name="qualification" value="Occupational Certificate: Project Manager — SAQA 101869, NQF 5">
          <input type="hidden" name="detail" id="pgPayload">
          <input type="hidden" name="summary" id="pgSummaryField">

          <?php if (isset($errors['_form'])): ?>
            <p class="form-err" role="alert"><?= e($errors['_form']) ?></p>
          <?php elseif ($errors): ?>
            <p class="form-err" role="alert">There is something to fix below before this can be sent.</p>
          <?php endif; ?>
          <div class="two">
            <div class="field<?= bad($errors,'full_name') ?>"><label for="p-name">Full name</label>
              <input id="p-name" type="text" name="full_name" value="<?= old($old,'full_name') ?>" placeholder="Your name" required>
              <?= err($errors,'full_name') ?></div>
            <div class="field"><label for="p-emp">Employee number</label>
              <input id="p-emp" type="text" name="employee_no" value="<?= old($old,'employee_no') ?>" placeholder="<?= e(brand('empno_example')) ?>"></div>
          </div>
          <div class="two">
            <div class="field<?= bad($errors,'email') ?>"><label for="p-email">Work email</label>
              <input id="p-email" type="email" name="email" value="<?= old($old,'email') ?>" placeholder="you@company.co.za" required>
              <?= err($errors,'email') ?></div>
            <div class="field"><label for="p-mgr">Line manager</label>
              <input id="p-mgr" type="text" name="line_manager" value="<?= old($old,'line_manager') ?>" placeholder="Manager's name"></div>
          </div>
          <div class="field"><label for="p-msg">Anything we should know?</label>
            <textarea id="p-msg" name="message" rows="3" placeholder="Falling behind, shift changes, something blocking you — say so here rather than going quiet"><?= old($old,'message') ?></textarea></div>

          <div class="field field-consent<?= bad($errors,'consent') ?>">
            <label class="check">
              <input type="checkbox" name="consent" value="1"<?= !empty($old['consent']) ? ' checked' : '' ?> required>
              <span>I agree that <?= e(brand('company_short')) ?> and Centenary Networks may keep this progress report as
                part of my learner record for this qualification. I have read the
                <a href="privacy" target="_blank" rel="noopener">privacy notice</a>.</span>
            </label>
            <?= err($errors,'consent') ?>
          </div>

          <button type="submit" class="btn btn-primary" style="width:100%">Send my progress</button>
        </form>
      </div>

      <div class="sg-actions reveal" style="justify-content:center;margin-top:26px">
        <button class="btn btn-ghost-dark" id="pgPrint">Print report for my manager</button>
        <a class="btn btn-ghost-dark" href="pm-schedule">Study plan &amp; calendar</a>
        <button class="btn btn-ghost-dark" id="pgReset">Reset my progress</button>
      </div>

      <!-- print-only: the countersignature is what turns a self-report into
           something a portfolio of evidence can actually use -->
      <div class="signblock" id="pgSign">
        <h3>Manager confirmation</h3>
        <p>The learner named above has completed the modules marked, and the time recorded is consistent with what I have observed.</p>
        <div class="sigrow">
          <div><span>Learner signature</span><i></i></div>
          <div><span>Date</span><i></i></div>
        </div>
        <div class="sigrow">
          <div><span>Manager name and signature</span><i></i></div>
          <div><span>Date</span><i></i></div>
        </div>
      </div>
    </div>

    <p class="sg-caveat reveal" style="max-width:860px;margin-top:32px"><strong>What this report is, exactly.</strong> A record of your own study, kept by you. It is not an assessment result and it does not award credits. Module competence is decided by Centenary Networks after assessment, and the qualification itself is awarded by the QCTO once you pass the external integrated summative assessment. What this does is make progress visible early enough to do something about — which is the whole problem with a self-paced programme.</p>
  </div>
</section>

<?php chrome_footer('site', ['employees' => true, 'extra' => 'Progress recorded on this page is self-reported by the learner: it is saved to their academy account once they are signed in, and in their own browser until then. It is not an assessment result and confers no credits. Module competence is determined by Centenary Networks through assessment, and the qualification is awarded by the QCTO following the external integrated summative assessment.']); ?>

<script src="<?= e(asset('pm-modules.js')) ?>"></script>
<script src="<?= e(asset('profile.js')) ?>"></script>
<script src="<?= e(asset('pm-progress.js')) ?>"></script>
<script>
(function(){
  const P=window.PM_PROGRESS, MODS=window.PM_MODULES;
  if(!P||!MODS) return;
  const ESC=s=>String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const $=id=>document.getElementById(id);

  if(!P.available()){
    $('pgUnavailable').hidden=false;
    $('pgMain').hidden=true;
    return;
  }

  function paint(){
    const o=P.overall(MODS);

    $('pgStats').innerHTML=[
      [o.pct+'%','of topics ticked off'],
      [o.topicsDone+' / '+o.topicsTotal,'topics'],
      [o.modulesComplete+' / '+o.modulesTotal,'modules marked complete'],
      [o.creditsClaimed+' / '+o.creditsTotal,'credits covered by your own record']
    ].map(s=>'<div class="stat"><div class="n">'+ESC(String(s[0]))+'</div><div class="l">'+s[1]+'</div></div>').join('');

    $('pgTable').innerHTML=
      '<thead><tr><th>#</th><th>Module</th><th class="num">Credits</th><th class="num">Topics</th>'+
      '<th class="num">Progress</th><th>Status</th></tr></thead><tbody>'+
      MODS.map(function(m,i){
        const s=P.moduleStats(m);
        const status=s.complete
          ? '<span class="pill done">Complete '+s.completedAt.slice(0,10)+'</span>'
          : s.done ? '<span class="pill going">In progress</span>'
                   : '<span class="pill">Not started</span>';
        return '<tr><td class="num">'+(i+1)+'</td>'+
          '<td><a href="module?m='+m.id+'"><strong>'+ESC(m.id)+'</strong> '+ESC(m.title)+'</a></td>'+
          '<td class="num">'+m.credits+'</td>'+
          '<td class="num">'+s.done+'/'+s.total+'</td>'+
          '<td class="num" style="min-width:120px"><div class="prog-bar sm"><i style="width:'+s.pct+'%"></i></div></td>'+
          '<td>'+status+'</td></tr>';
      }).join('')+'</tbody>';

    /* Fill the hidden fields so the email carries the detail, not just a % */
    const rep=P.report(MODS);
    $('pgPayload').value=rep.text;
    $('pgSummaryField').value=
      o.modulesComplete+' of '+o.modulesTotal+' modules complete · '+
      o.topicsDone+' of '+o.topicsTotal+' topics ('+o.pct+'%) · '+
      o.creditsClaimed+' of '+o.creditsTotal+' credits · self-reported '+
      new Date().toISOString().slice(0,10);
  }

  $('pgPrint').addEventListener('click',()=>window.print());
  $('pgReset').addEventListener('click',function(){
    var onAccount=P.mode()==='account';
    if(!confirm(onAccount
      ? 'Clear your recorded progress on all eleven modules? This deletes it from your '+
        'academy account, on every device, and cannot be undone.'
      : 'Clear your recorded progress on all eleven modules? This cannot be undone.')) return;
    P.clear(); paint();
  });

  /* Which of the two true answers to give about where this is kept. Both are
     honest; giving the wrong one is not, and "in this browser and nowhere else"
     stops being true the moment somebody signs in. */
  window.addEventListener('pmprogress:sync',function(){
    paint();
    var t=$('pgWhereText'); if(!t) return;
    if(P.mode()==='account'){
      t.innerHTML='On your academy account, held by Centenary Networks on a server in '+
        'Johannesburg — so it follows you to any device and survives this browser being '+
        'cleared. The academy can see your ticks and their dates. What is held, why, and '+
        'how to ask for it is in the <a href="privacy" style="color:var(--accent-green);'+
        'font-weight:700">privacy notice</a>.';
    }
  });

  /* Progress is usually ticked off on the module pages, in another tab. Two
     things follow from that, and both matter because the submitted record is
     the artefact the provider reports from:
       1. repaint whenever this page comes back into view, so the screen is not
          showing yesterday's numbers;
       2. rebuild the payload at the moment of submit, which is the only time it
          is guaranteed current. Filling it at paint time alone would let a
          learner send a stale record without ever noticing. */
  ['visibilitychange','pageshow','focus'].forEach(function(ev){
    (ev==='visibilitychange'?document:window).addEventListener(ev,function(){
      if(ev==='visibilitychange'&&document.hidden) return;
      paint();
    });
  });
  $('pgForm').addEventListener('submit',function(){ paint(); });

  paint();
})();
</script>
<script src="<?= e(asset('site.js')) ?>"></script>
<script src="<?= e(asset('assistant.js')) ?>"></script>
</body>
</html>
