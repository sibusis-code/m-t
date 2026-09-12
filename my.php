<?php
declare(strict_types=1);

/* The learner's own page — the one thing an account is actually for.
 *
 * Everything on it answers "where am I up to", and nothing on it is available
 * to anyone else: the queries are scoped to the signed-in user's own id, and
 * there is no id in any URL that could be changed to somebody else's.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 *
 * It does not compute progress in PHP. The eleven modules, their topics and
 * their credit values are the registered curriculum, they live in pm-modules.js
 * because that is generated from the provider's guides, and a second copy of
 * them here would eventually disagree with the first. So the shell is rendered
 * on the server and the numbers are filled in by the same script the module
 * pages use, reading the same progress — which now comes from the database
 * rather than from this browser.
 *
 * It also does not show marks, competence, or anything that looks like an
 * assessment result. Ticking a topic is a learner saying they have studied it.
 * Being found competent is Centenary's decision after assessment and the
 * qualification is the QCTO's after the EISA, and this page says so where a
 * reasonable person might otherwise assume differently.
 */

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/csrf.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/install.php';   // install_readable_password(), via learner.php
require __DIR__ . '/lib/learner.php';
require __DIR__ . '/lib/quiz.php';
require __DIR__ . '/lib/curriculum.php';   // groups the topic quizzes by module, below
require __DIR__ . '/lib/chrome.php';

$me = require_user();

$notice = '';
$error  = '';

if (is_post()) {
    if (!csrf_valid()) {
        $error = 'This page had been open a while and the form expired. Please try again.';
    } elseif (($_POST['a'] ?? '') === 'password') {
        [$ok, $message] = auth_change_password(
            $me,
            (string) ($_POST['current'] ?? ''),
            (string) ($_POST['new'] ?? ''),
            (string) ($_POST['confirm'] ?? '')
        );
        if ($ok) {
            $notice = $message;
            // The row in memory still carries the old hash, and the rest of the
            // page renders from it. Drop the cached copy and read it back.
            current_user(true);
            $me = current_user() ?? $me;
        } else {
            $error = $message;
        }
        csrf_rotate();
    }
}

/* Survives the gap between a deploy and its migration — see lib/db.php.
   The dashboard then renders as "you are not on a course yet" with the notice
   above it, rather than as a 500. */
$enrolments = db_optional(fn() => learner_enrolments((int) $me['id']), []);

audit('learner.dashboard_viewed', 'users', (int) $me['id'],
      count($enrolments) . ' enrolment' . (count($enrolments) === 1 ? '' : 's'));

/** The slugs this page can paint a progress panel for. */
$tracked = array_values(array_filter(
    array_map(fn($e) => (string) $e['course_slug'], $enrolments),
    'learner_course_tracked'
));

function when_local(?string $utc): string
{
    if (!$utc) return '';
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone('Africa/Johannesburg'));
    return $d->format('j M Y');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My learning — <?= e(brand('academy')) ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= e(asset('styles.css')) ?>">
</head>
<body>
<?php chrome_nav('learner', ['admin' => $me['role'] === 'admin', 'trainer' => $me['role'] === 'trainer']); ?>

<section class="section-dark page-top">
  <div class="wrap">
    <div class="sec-head sec-head-wide">
      <span class="eyebrow"><?= e(brand('academy')) ?></span>
      <h2 class="lede-h">Hello, <?= e($me['first_name']) ?></h2>
      <p>Everything you have ticked off is saved to your account now, not to this browser —
        so it follows you to your phone, and it is still here after somebody clears the
        history on a shared machine.</p>
    </div>
  </div>
</section>

<section class="section">
  <div class="wrap">

    <?php if (db_schema_incomplete()): ?>
      <p class="form-err" role="alert"><?= e(db_schema_notice()) ?></p>
    <?php endif; ?>
    <?php if ($notice !== ''): ?><p class="adm-notice" role="status"><?= e($notice) ?></p><?php endif; ?>
    <?php if ($error  !== ''): ?><p class="form-err"   role="alert" ><?= e($error)  ?></p><?php endif; ?>

    <h3 class="my-h">Your courses</h3>

    <?php if (!$enrolments): ?>
      <div class="my-empty">
        <strong>You are not on a course yet.</strong>
        <p>Your account exists, but nobody has enrolled you on anything. If you were
          expecting to be on a course, email
          <a href="mailto:<?= e(brand('academy_email')) ?>"><?= e(brand('academy_email')) ?></a> —
          or have a look at <a href="courses">what the academy runs</a> and register your
          interest.</p>
      </div>
    <?php else: ?>
      <?php foreach ($enrolments as $en): ?>
        <?php $slug = (string) $en['course_slug']; ?>
        <article class="my-course" data-course="<?= e($slug) ?>">
          <div class="my-course-head">
            <div>
              <h4><?= e((string) $en['course_title']) ?></h4>
              <p class="my-meta">
                <?= e((string) (learner_catalogue()[$slug]['note'] ?? '')) ?>
                <?php if ($en['enrolled_at']): ?>
                  · Enrolled <?= e(when_local((string) $en['enrolled_at'])) ?>
                <?php endif; ?>
                <?php if ($en['status'] !== 'active'): ?>
                  · <strong><?= e(ucfirst((string) $en['status'])) ?></strong>
                <?php endif; ?>
              </p>
            </div>
          </div>

          <?php if (learner_course_tracked($slug)): ?>
            <?php /* Filled in by the inline script below, from pm-modules.js and
                     the progress now held against this account. Until that runs
                     it says so, rather than showing a confident 0%. */ ?>
            <div class="my-progress" data-progress-for="<?= e($slug) ?>">
              <p class="my-loading">Fetching where you are up to…</p>
            </div>
            <div class="my-actions">
              <a class="btn btn-primary" href="pm-schedule">My study plan</a>
              <a class="btn btn-ghost" href="pm-progress">Progress report for my manager</a>
              <a class="btn btn-ghost" href="pm-pathway">How this fits with Google</a>
            </div>

            <?php
              /* Rendered here, not fetched client-side like the progress
                 panel above — that one needs a script because it has a
                 localStorage-vs-account fallback to resolve; nobody reaches
                 this page signed out, so there is nothing to resolve here.

                 WHY THIS IS A MODULE LIST AND NOT A LIST OF QUIZZES
                 (changed 11 Sep 2026)

                 It used to print one row per quiz. That was readable while the
                 question bank was a handful of modules; the bank is now written
                 per topic, so it had become fifty-one rows of "KM-04-KT03 · 10
                 questions, not attempted yet" with no shape to it — a learner
                 could not see which MODULE they were in the middle of, which is
                 the thing they came to look at.

                 Grouping needs the curriculum, and the curriculum is
                 pm-modules.js. This page's header says progress is not computed
                 in PHP for exactly that reason — but lib/curriculum.php now
                 PARSES that same file rather than restating it, so reading it
                 here does not create the second copy that rule was protecting
                 against. There is still only one curriculum. */
              $quizSummary = db_optional(fn() => quiz_results_summary_for_user((int) $me['id'], $slug), []);
              $quizSummary = array_filter($quizSummary, fn(array $q) => $q['published'] && $q['questions'] > 0);
              $tree    = db_optional(fn() => learner_progress_tree((int) $me['id'], $slug), []);
              $modules = curriculum_modules();
            ?>
            <?php if ($quizSummary && $modules): ?>
              <div class="my-mods">
                <span class="lbl">Where you are, module by module</span>
                <?php foreach ($modules as $mid => $mod): ?>
                  <?php
                    $topics = array_keys($mod['topics']);
                    if (!$topics) continue;
                    $ticked = 0;
                    $passed = 0;
                    $withQuiz = 0;
                    foreach ($topics as $tc) {
                        if (!empty($tree[$mid]['topics'][$tc])) $ticked++;
                        if (isset($quizSummary[$tc])) {
                            $withQuiz++;
                            if (!empty($quizSummary[$tc]['passed'])) $passed++;
                        }
                    }
                    $total = count($topics);
                    $pct   = $total ? (int) round($ticked / $total * 100) : 0;
                    $state = $ticked === 0 ? 'none' : ($ticked >= $total ? 'all' : 'part');
                  ?>
                  <a class="my-mod my-mod-<?= e($state) ?>" href="<?= e('module?m=' . rawurlencode($mid)) ?>">
                    <span class="my-mod-code"><?= e($mid) ?></span>
                    <span class="my-mod-body">
                      <strong><?= e($mod['title']) ?></strong>
                      <span class="my-mod-sub">
                        <?= (int) $ticked ?> of <?= (int) $total ?> topics done<?php
                          if ($withQuiz > 0): ?> · <?= (int) $passed ?> of <?= (int) $withQuiz ?> quizzes passed<?php endif; ?>
                      </span>
                      <span class="my-mod-bar" aria-hidden="true"><i style="width:<?= (int) $pct ?>%"></i></span>
                    </span>
                    <span class="my-mod-pct"><?= (int) $pct ?>%</span>
                  </a>
                <?php endforeach; ?>
                <p class="my-mod-note">A topic ticks itself off when you pass its questions at
                  <?= (int) QUIZ_DEFAULT_PASS_PCT ?>%. You can also tick one yourself on the module page,
                  and you can retake any set of questions as often as you like — your best
                  score is the one kept.</p>
              </div>
            <?php endif; ?>
          <?php else: ?>
            <p class="my-untracked">This course does not have its modules on the site yet, so
              there is nothing here to tick off. Your materials come to you from the academy
              directly — email
              <a href="mailto:<?= e(brand('academy_email')) ?>"><?= e(brand('academy_email')) ?></a>
              if you have not had them.</p>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>

    <div class="my-grid">

      <section class="my-card">
        <h3 class="my-h">Your details</h3>
        <dl class="my-dl">
          <dt>Name</dt><dd><?= e(trim($me['first_name'] . ' ' . $me['last_name'])) ?></dd>
          <dt>Sign in with</dt><dd><?= e((string) $me['email']) ?></dd>
          <?php if ($me['employee_no']): ?>
            <dt>Employee number</dt><dd><?= e((string) $me['employee_no']) ?></dd>
          <?php endif; ?>
          <?php if ($me['department']): ?>
            <dt>Department</dt><dd><?= e((string) $me['department']) ?></dd>
          <?php endif; ?>
          <dt>Account made</dt><dd><?= e(when_local((string) $me['created_at'])) ?></dd>
        </dl>
        <p class="my-note">Something wrong here? It came from your registration form, and
          only the academy can change it — email
          <a href="mailto:<?= e(brand('academy_email')) ?>"><?= e(brand('academy_email')) ?></a>.</p>
      </section>

      <section class="my-card">
        <h3 class="my-h">Change password</h3>
        <p class="my-note">Do this now if you were given a password by someone else. Changing it
          signs you out everywhere else it was still in use. If you ever forget it, use
          <a href="forgot">the forgotten-password link</a> on the sign-in page.</p>
        <form class="form" method="POST" novalidate autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="a" value="password">
          <input type="hidden" name="username" value="<?= e((string) $me['email']) ?>"
                 autocomplete="username" hidden>
          <div class="field">
            <label for="p-cur">Your current password</label>
            <input id="p-cur" type="password" name="current" autocomplete="current-password" required>
          </div>
          <div class="field">
            <label for="p-new">New password</label>
            <input id="p-new" type="password" name="new" autocomplete="new-password"
                   minlength="<?= PASSWORD_MIN_LENGTH ?>" required>
            <p class="field-hint">At least <?= PASSWORD_MIN_LENGTH ?> characters. Three or four
              ordinary words in a row is both easier to remember and harder to guess than
              something short with symbols in it.</p>
          </div>
          <div class="field">
            <label for="p-conf">New password again</label>
            <input id="p-conf" type="password" name="confirm" autocomplete="new-password" required>
          </div>
          <button type="submit" class="btn btn-primary">Change my password</button>
        </form>
      </section>

    </div>

    <?php /* Not .sg-assure — that one is written for a dark section and its text
             colour is unreadable on white. Same idea, light-background twin. */ ?>
    <div class="my-privacy">
      <span class="lbl">What the academy can see, and what it is for</span>
      Your ticks, and the dates you made them — the same is true of any self-check quiz score
      above. The academy uses them to know who needs help and to report on the programme; they
      are your own record of what you have studied, not a mark and not an assessment result.
      Being found competent is Centenary's decision after assessment, and the qualification is
      awarded by the QCTO after the external assessment.
      What is held about you, why, and how to ask for it is set out in the
      <a href="privacy">privacy notice</a>.
    </div>

  </div>
</section>

<?php chrome_footer('slim'); ?>

<script src="<?= e(asset('pm-modules.js')) ?>"></script>
<script src="<?= e(asset('profile.js')) ?>"></script>
<script src="<?= e(asset('pm-progress.js')) ?>"></script>
<script>
/* Paint the progress panel for every tracked course on the page.
   Waits for the account's progress to arrive before drawing anything — see the
   'pmprogress:sync' event in pm-progress.js — so nobody watches their record
   jump from 0% to where they actually are. */
(function () {
  var ESC = function (s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); };
  var P = window.PM_PROGRESS, MODS = window.PM_MODULES || [];

  function paint() {
    document.querySelectorAll('[data-progress-for]').forEach(function (box) {
      if (!P || !MODS.length) {
        box.innerHTML = '<p class="my-untracked">The module list did not load. ' +
          'Refresh the page, and if it keeps happening tell the academy.</p>';
        return;
      }
      var o = P.overall(MODS);
      var next = null;
      for (var i = 0; i < MODS.length; i++) {
        var s = P.moduleStats(MODS[i]);
        if (!s.complete) { next = { m: MODS[i], s: s }; break; }
      }

      /* The big number is the topic percentage, and the sentence beside it
         counts topics too. An earlier version headlined the MODULE count next
         to a topic percentage — "0 of 11 marked complete … 2%" — which reads as
         a contradiction until you find the small print. */
      box.innerHTML =
        '<div class="prog"><div class="prog-top">' +
          '<div><span class="prog-lbl">Knowledge modules</span><strong>' +
            o.topicsDone + ' of ' + o.topicsTotal + ' topics ticked off</strong></div>' +
          '<div class="prog-pct">' + o.pct + '%</div>' +
        '</div>' +
        '<div class="prog-bar"><i style="width:' + o.pct + '%"></i></div>' +
        '<p class="prog-note">' + o.modulesComplete + ' of ' + o.modulesTotal +
          ' modules marked complete · ' + o.creditsClaimed + ' of ' + o.creditsTotal +
          ' knowledge credits covered by your own record. The practical and workplace ' +
          'credits are assessed separately, against your work at ' + <?= json_encode(brand("company_short"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> + '.</p></div>' +
        (next
          ? '<a class="my-next" href="module?m=' + encodeURIComponent(next.m.id) + '">' +
              '<span class="my-next-lbl">Carry on with</span>' +
              '<strong>' + ESC(next.m.id) + ' · ' + ESC(next.m.title) + '</strong>' +
              '<span class="my-next-sub">' + next.s.done + ' of ' + next.s.total +
                ' topics done · ' + next.m.credits + ' credits</span></a>'
          : '<p class="my-done">Every knowledge module is marked complete. Send the academy ' +
            'a dated record from the progress report page, and speak to them about your ' +
            'portfolio of evidence.</p>');
    });
  }

  window.addEventListener('pmprogress:sync', paint);
  /* If nothing is signed in, or the request fails, pm-progress.js still fires
     the event — so this runs either way rather than leaving "Fetching…" up. */
})();
</script>
<script src="<?= e(asset('site.js')) ?>"></script>
</body>
</html>
