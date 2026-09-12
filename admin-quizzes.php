<?php
declare(strict_types=1);

/* Where an administrator writes the self-check quiz for a module, and looks
 * at how learners are doing on it.
 *
 * THIS IS NOT THE QCTO ASSESSMENT
 *
 * A quiz here is multiple choice, auto-graded, and exists to help someone
 * study — unlimited attempts, best score kept, exactly like the site tells a
 * learner on quiz.php. Nothing that reads a score off this page should be
 * mistaken for competence: that is Centenary's decision after the real
 * assessment, and the qualification is the QCTO's after the EISA. Write
 * questions with that in mind, the same way admin-materials.php asks
 * administrators to keep assessment material off that page entirely.
 *
 * WHY THE MODULE LIST IS NOT IN THIS FILE
 *
 * Same reasoning as admin-materials.php: the eleven modules, their codes and
 * titles live in pm-modules.js, the single source of truth for the
 * registered curriculum. This page loads that file and matches quiz data
 * against it in the browser rather than keeping a second copy of the
 * curriculum in PHP.
 *
 * WHY EACH MODULE HAS ITS OWN FORM
 *
 * admin-materials.php submits every module in one POST because each module
 * is three simple fields. A quiz can run to a dozen questions with up to six
 * choices each, so bundling all eleven modules into one submission would be
 * a genuinely large payload and a genuinely confusing "what did I just save"
 * moment if only one module's edit was intended. One form per module, saved
 * independently, keeps a save small and its result unambiguous.
 */

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/csrf.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/learner.php';
require __DIR__ . '/lib/quiz.php';
require __DIR__ . '/lib/chrome.php';

$me = require_staff();   // trainers may read this page — see require_write() below

/* Only courses whose curriculum this platform actually carries — same
   restriction admin-materials.php applies, for the same reason: nothing here
   knows the modules of a course it has no structure for. */
$courses = array_filter(learner_catalogue(),
                        fn(array $c): bool => (bool) ($c['tracked'] ?? false));

$course = (string) ($_GET['course'] ?? array_key_first($courses));
if (!isset($courses[$course])) $course = (string) array_key_first($courses);

$view = ($_GET['view'] ?? '') === 'results' ? 'results' : 'author';

$notice = '';
$errors = [];

/**
 * Reshape the posted question set into what quiz_save_questions() expects.
 *
 * Each question is q[N][id], q[N][prompt], q[N][correct] (which choice index
 * is right — a single radio group per question, so the browser itself
 * enforces "exactly one"), and q[N][c][M][id]/[text] for its choices. A
 * question or choice the browser did not submit is simply absent — removing
 * one client-side means not including it, and quiz_save_questions() already
 * treats anything stored-but-missing as removed (deactivated, never
 * deleted — see the note on quiz_questions.active in schema.mysql.sql).
 */
function admin_quizzes_reshape(array $raw): array
{
    $out = [];
    foreach ($raw as $q) {
        if (!is_array($q)) continue;
        $prompt = trim((string) ($q['prompt'] ?? ''));
        $qid    = isset($q['id']) && $q['id'] !== '' ? (int) $q['id'] : null;
        $correctIdx = (string) ($q['correct'] ?? '');

        $choices = [];
        foreach ((array) ($q['c'] ?? []) as $ci => $c) {
            if (!is_array($c)) continue;
            $cid = isset($c['id']) && $c['id'] !== '' ? (int) $c['id'] : null;
            $choices[] = [
                'id'      => $cid,
                'text'    => trim((string) ($c['text'] ?? '')),
                'correct' => (string) $ci === $correctIdx,
            ];
        }
        $out[] = ['id' => $qid, 'prompt' => $prompt, 'choices' => $choices];
    }
    return $out;
}

if (is_post()) {
    /* A trainer reaches this page but may not change it. The check is here, on
       the writing side, and not on whether the form was rendered: a hidden
       button is not a permission. */
    require_write();

    if (!csrf_valid()) {
        $errors[] = 'That form had expired — nothing was saved. Please try again.';
    } else {
        $postedCourse = post_str('course', 60);
        $module       = post_str('module', 20);

        if (!isset($courses[$postedCourse])) {
            $errors[] = 'That is not a course this page can manage.';
        } elseif (!learner_valid_code($module, 20)) {
            $errors[] = 'That module code did not look right.';
        } else {
            $course = $postedCourse;

            $passRaw = trim((string) ($_POST['pass_pct'] ?? ''));
            $passPct = $passRaw === '' ? null : max(0, min(100, (int) $passRaw));

            $quizId   = quiz_upsert($course, $module, ['pass_pct' => $passPct], (int) $me['id']);
            $counts   = quiz_save_questions($quizId, admin_quizzes_reshape((array) ($_POST['q'] ?? [])), (int) $me['id']);
            $wantPub  = !empty($_POST['published']);

            $quizNow = quiz_get($quizId);
            if ((bool) $quizNow['published'] !== $wantPub) {
                $published = quiz_publish($quizId, $wantPub, (int) $me['id']);
                if ($wantPub && !$published) {
                    $errors[] = 'Could not publish — a quiz needs at least one question first. '
                              . 'The questions below were still saved.';
                }
            }

            /* Anything that could not be saved is said out loud, before the
               reassuring part. A question the admin wrote and lost is the one
               outcome this page must never report as success — see the note
               on 'skipped' in quiz_save_questions(). */
            foreach ($counts['skipped'] as $s) {
                $errors[] = 'Question ' . $s['n'] . ' was NOT saved — ' . $s['why'] . '.'
                          . ($s['was'] !== '' ? ' (It began "' . $s['was'] . '".)' : '')
                          . ' Fix it and save again; everything else on this module was saved.';
            }

            $bits = [];
            if ($counts['added'])    $bits[] = $counts['added'] . ' question' . ($counts['added'] === 1 ? '' : 's') . ' added';
            if ($counts['updated'])  $bits[] = $counts['updated'] . ' updated';
            if ($counts['removed'])  $bits[] = $counts['removed'] . ' removed';
            if ($bits) {
                $notice = 'Saved for ' . $module . ' — ' . implode(', ', $bits) . '.';
            } elseif (!$counts['skipped']) {
                $notice = 'Saved for ' . $module . '. Nothing had changed.';
            }
        }
        csrf_rotate();
    }
}

$existing = db_optional(fn() => quiz_admin_course_data($course), []);

/* Only what an administrator is allowed to see anyway — this page is
   administrator-only, and is_correct is exactly what it needs to edit. */
$forJs = [];
foreach ($existing as $module => $data) {
    $forJs[$module] = [
        'quizId'    => (int) $data['quiz']['id'],
        'published' => (bool) $data['quiz']['published'],
        'passPct'   => $data['quiz']['pass_pct'] !== null ? (int) $data['quiz']['pass_pct'] : null,
        'questions' => array_map(static fn(array $q) => [
            'id'      => (int) $q['id'],
            'prompt'  => (string) $q['prompt'],
            'choices' => array_map(static fn(array $c) => [
                'id'      => (int) $c['id'],
                'text'    => (string) $c['choice_text'],
                'correct' => (bool) $c['is_correct'],
            ], $q['choices']),
        ], $data['questions']),
    ];
}

/* -----------------------------------------------------------------------
   Results view
   ----------------------------------------------------------------------- */

$resultsQ    = trim((string) ($_GET['q'] ?? ''));
$resultsMod  = (string) ($_GET['module'] ?? '');
$resultsPage = max(1, (int) ($_GET['page'] ?? 1));
$results     = $view === 'results'
    ? db_optional(fn() => quiz_admin_results($course, $resultsMod !== '' ? $resultsMod : null, $resultsQ, $resultsPage), null)
    : null;

if ($view === 'results') {
    audit('quiz.results_viewed', 'quizzes', null, $course . ($resultsMod !== '' ? ' ' . $resultsMod : ''));
}

if (($_GET['export'] ?? '') === 'csv' && $view === 'results') {
    $rows = db_optional(fn() => quiz_admin_results($course, $resultsMod !== '' ? $resultsMod : null, '', 1, 100000)['rows'], []);

    audit('quiz.results_exported', 'quizzes', null, $course . ' — ' . count($rows) . ' rows');

    $name = 'quiz-results-' . $course . '-' . gmdate('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $put = static fn($h, array $row) => fputcsv($h, $row, ',', '"', '');
    $put($out, ['Module', 'Name', 'Email', 'Submitted (UTC)', 'Score', 'Percent', 'Pass mark', 'Result']);
    foreach ($rows as $r) {
        $pct = (int) $r['question_count'] > 0 ? (int) round((int) $r['score_count'] / (int) $r['question_count'] * 100) : 0;
        $pass = $r['pass_pct'] !== null ? ($pct >= (int) $r['pass_pct'] ? 'Pass' : 'Below pass mark') : '';
        $put($out, [
            $r['module_code'], trim($r['first_name'] . ' ' . $r['last_name']), $r['email'],
            $r['submitted_at'], $r['score_count'] . '/' . $r['question_count'], $pct . '%',
            $r['pass_pct'] !== null ? $r['pass_pct'] . '%' : '', $pass,
        ]);
    }
    fclose($out);
    exit;
}

function when_local(string $utc): string
{
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone('Africa/Johannesburg'));
    return $d->format('d M Y, H:i');
}

/** Keep the current filter when building a link, same helper shape as admin.php's qs(). */
function qs(array $over = []): string
{
    $base = array_filter([
        'course' => $_GET['course'] ?? '', 'view' => $_GET['view'] ?? '',
        'q' => $_GET['q'] ?? '', 'module' => $_GET['module'] ?? '', 'page' => $_GET['page'] ?? '',
    ], fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query(array_merge($base, $over));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Self-check quizzes — <?= e(brand('academy')) ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= e(asset('styles.css')) ?>">
</head>
<body>
<?php chrome_nav('admin', ['active' => 'admin-quizzes', 'name' => $me['first_name']]); ?>

<section class="section-soft page-top">
  <div class="wrap">

    <div class="adm-head">
      <div>
        <span class="eyebrow">Academy administration</span>
        <h2>Self-check quizzes</h2>
      </div>
      <a class="btn btn-ghost" href="<?= e(qs(['view' => $view === 'results' ? '' : 'results', 'page' => ''])) ?>">
        <?= $view === 'results' ? 'Back to writing questions' : 'View results' ?></a>
    </div>

    <?php if (db_schema_incomplete()): ?>
      <p class="form-err" role="alert"><?= e(db_schema_notice()) ?></p>
    <?php endif; ?>
    <?php if ($notice !== ''): ?><p class="adm-notice" role="status"><?= e($notice) ?></p><?php endif; ?>
    <?php foreach ($errors as $err): ?><p class="form-err" role="alert"><?= e($err) ?></p><?php endforeach; ?>

    <div class="mat-intro">
      <p><strong>This is a self-check the academy built, not the module's summative assessment.</strong>
        A learner can take it as many times as they like and the site keeps their best score — it is
        study practice, not an exam. Write questions accordingly, and
        <strong>never use it for anything that should be marked by a person or that decides
        competence</strong> — that stays Centenary's assessment, set against the QCTO curriculum.</p>
    </div>

    <?php if (count($courses) > 1): ?>
      <form class="adm-search" method="GET">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <select name="course" onchange="this.form.submit()">
          <?php foreach ($courses as $slug => $c): ?>
            <option value="<?= e($slug) ?>"<?= $slug === $course ? ' selected' : '' ?>>
              <?= e((string) ($c['title'] ?? $slug)) ?></option>
          <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn btn-primary">Show</button></noscript>
      </form>
    <?php endif; ?>

    <?php if ($view === 'results'): ?>

      <form class="adm-search" method="GET">
        <input type="hidden" name="view" value="results">
        <input type="hidden" name="course" value="<?= e($course) ?>">
        <input type="search" name="q" value="<?= e($resultsQ) ?>" placeholder="Search name or email">
        <button type="submit" class="btn btn-primary">Search</button>
        <?php if ($resultsQ !== ''): ?><a class="adm-clear" href="<?= e(qs(['q' => '', 'page' => ''])) ?>">Clear</a><?php endif; ?>
      </form>

      <?php if ($results === null || !$results['rows']): ?>
        <p class="adm-empty">No attempts yet<?= $resultsQ !== '' ? ' matching that search' : '' ?>.</p>
      <?php else: ?>
        <div class="adm-scroll">
        <table class="adm-table">
          <thead><tr><th>Module</th><th>Learner</th><th>Submitted</th><th>Score</th><th>Result</th></tr></thead>
          <tbody>
          <?php foreach ($results['rows'] as $r): ?>
            <?php
              $qc  = (int) $r['question_count'];
              $pct = $qc > 0 ? (int) round((int) $r['score_count'] / $qc * 100) : 0;
              $pp  = $r['pass_pct'] !== null ? (int) $r['pass_pct'] : null;
            ?>
            <tr>
              <td><?= e((string) $r['module_code']) ?></td>
              <td><strong><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?></strong>
                <span class="adm-sub"><?= e((string) $r['email']) ?></span></td>
              <td class="adm-when"><?= e(when_local((string) $r['submitted_at'])) ?></td>
              <td><?= (int) $r['score_count'] ?>/<?= $qc ?> · <?= $pct ?>%</td>
              <td><?= $pp === null ? '<span class="adm-none">no pass mark set</span>'
                    : ($pct >= $pp ? '<span class="adm-enrolled">Pass</span>' : 'Below ' . $pp . '%') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php if ($results['pages'] > 1): ?>
          <div class="adm-pages">
            <?php if ($results['page'] > 1): ?><a href="<?= e(qs(['page' => $results['page'] - 1])) ?>">← Newer</a><?php endif; ?>
            <span>Page <?= $results['page'] ?> of <?= $results['pages'] ?> · <?= $results['total'] ?> attempts</span>
            <?php if ($results['page'] < $results['pages']): ?><a href="<?= e(qs(['page' => $results['page'] + 1])) ?>">Older →</a><?php endif; ?>
          </div>
        <?php endif; ?>
        <p class="mat-count"><a class="btn btn-ghost" href="<?= e(qs(['export' => 'csv'])) ?>">Download as CSV</a></p>
      <?php endif; ?>

    <?php else: ?>

      <div id="quiz-rows">
        <noscript><p class="adm-empty">This page needs JavaScript, because it reads the module list
          from the curriculum file rather than keeping a second copy of it.</p></noscript>
      </div>

    <?php endif; ?>

  </div>
</section>

<?php if ($view !== 'results'): ?>
<script src="<?= e(asset('pm-modules.js')) ?>"></script>
<script>
(function () {
  var MODS = window.PM_MODULES || [];
  /* JSON_HEX_TAG is not decoration. Without it, a question containing a
     closing script tag — a perfectly reasonable thing to write in a course
     about anything technical — ends this block early. The browser then hits a
     syntax error, the module list never renders, and the page comes up blank
     with nothing on it to explain why. It is also the difference between
     stored text and stored script. Note that this comment deliberately does
     not spell that tag out either: the parser does not care that it is inside
     a comment, and writing it here would break the page just as surely.
     Everything below that interpolates PHP into JavaScript uses the same
     flags, for the same reason. */
  var HAVE = <?= json_encode($forJs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var CSRF = <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var COURSE = <?= json_encode($course, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var rows = document.getElementById('quiz-rows');
  if (!rows || !MODS.length) return;

  var ESCMAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' };
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return ESCMAP[c]; }); }

  var MAX_CHOICES = 6, MIN_CHOICES = 2;

  function choiceRow(qIdx, c, cIdx) {
    c = c || { id: '', text: '', correct: false };
    return '<div class="qz-choice">' +
      '<input type="hidden" name="q[' + qIdx + '][c][' + cIdx + '][id]" value="' + esc(c.id) + '">' +
      '<input type="radio" name="q[' + qIdx + '][correct]" value="' + cIdx + '"' + (c.correct ? ' checked' : '') + '>' +
      '<input type="text" name="q[' + qIdx + '][c][' + cIdx + '][text]" value="' + esc(c.text) + '" ' +
        'placeholder="Answer option" maxlength="500">' +
      '<button type="button" class="qz-remove" title="Remove this option">&times;</button>' +
      '</div>';
  }

  function questionBlock(qIdx, q) {
    q = q || { id: '', prompt: '', choices: [{}, {}] };
    var choices = q.choices.map(function (c, i) { return choiceRow(qIdx, c, i); }).join('');
    return '<div class="qz-question" data-qidx="' + qIdx + '">' +
      '<input type="hidden" name="q[' + qIdx + '][id]" value="' + esc(q.id) + '">' +
      '<div class="field"><label>Question</label>' +
      '<textarea name="q[' + qIdx + '][prompt]" rows="2" placeholder="Ask something about this module">' +
        esc(q.prompt) + '</textarea></div>' +
      '<div class="qz-choices">' + choices + '</div>' +
      '<div class="qz-qactions">' +
        '<button type="button" class="btn btn-ghost qz-addchoice">Add an option</button>' +
        '<button type="button" class="btn btn-ghost qz-removeq">Remove this question</button>' +
      '</div></div>';
  }

  function renumber(container) {
    // Names carry the CURRENT dom order as their index, so a reorder or a
    // removal is just a re-render of the name attributes — the ids inside
    // (q[i][id], q[i][c][j][id]) are what quiz_save_questions() actually
    // matches against existing rows, not the index itself.
    var qs = container.querySelectorAll('.qz-question');
    qs.forEach(function (qEl, qIdx) {
      qEl.dataset.qidx = qIdx;
      qEl.querySelectorAll('input[type=hidden]').forEach(function (i) {
        if (i.name.indexOf('[id]') !== -1 && i.name.indexOf('[c][') === -1) {
          i.name = 'q[' + qIdx + '][id]';
        }
      });
      qEl.querySelectorAll('textarea').forEach(function (t) { t.name = 'q[' + qIdx + '][prompt]'; });
      var choices = qEl.querySelectorAll('.qz-choice');
      choices.forEach(function (cEl, cIdx) {
        cEl.querySelectorAll('input[type=hidden]').forEach(function (i) { i.name = 'q[' + qIdx + '][c][' + cIdx + '][id]'; });
        cEl.querySelectorAll('input[type=radio]').forEach(function (i) { i.name = 'q[' + qIdx + '][correct]'; i.value = cIdx; });
        cEl.querySelectorAll('input[type=text]').forEach(function (i) { i.name = 'q[' + qIdx + '][c][' + cIdx + '][text]'; });
      });
    });
  }

  function wireQuestion(qEl, container) {
    qEl.querySelector('.qz-removeq').addEventListener('click', function () {
      qEl.remove();
      renumber(container);
    });
    qEl.querySelector('.qz-addchoice').addEventListener('click', function () {
      var choices = qEl.querySelector('.qz-choices');
      if (choices.children.length >= MAX_CHOICES) return;
      choices.insertAdjacentHTML('beforeend', choiceRow(qEl.dataset.qidx, null, choices.children.length));
      wireChoice(choices.lastElementChild, container);
      renumber(container);
    });
    qEl.querySelectorAll('.qz-choice').forEach(function (cEl) { wireChoice(cEl, container); });
  }

  function wireChoice(cEl, container) {
    cEl.querySelector('.qz-remove').addEventListener('click', function () {
      var choices = cEl.parentElement;
      if (choices.children.length <= MIN_CHOICES) {
        alert('A question needs at least two options.');
        return;
      }
      cEl.remove();
      renumber(container);
    });
  }

  /* ONE QUIZ PER TOPIC, NOT PER MODULE (changed 8 Sep 2026)
     -------------------------------------------------------
     A learner reads a topic and then answers questions on it, so the questions
     belong to the topic. Centenary's own question bank is written that way too
     — ten questions per topic.

     This needed NO schema change, which is the reason it is done like this.
     quizzes.module_code is VARCHAR(20) and its UNIQUE key is
     (tenant_id, course_slug, module_code); a topic code such as KM-01-KT01 is
     ten characters and passes learner_valid_code() unchanged, so storing one
     there gives exactly one quiz per topic for free. The column now holds a
     curriculum code — module or topic — rather than only a module code. Adding
     a column instead would have meant ALTER TABLE, which this codebase does not
     do: install_apply_schema() re-runs every CREATE statement on each deploy
     and has to stay idempotent.

     Any quiz saved against a bare module code before today still loads and is
     still gradeable; it simply appears under no topic here. */
  var UNITS = [];
  MODS.forEach(function (m) {
    (m.topics || []).forEach(function (t) {
      UNITS.push({ id: t.code, title: t.n, module: m.id, moduleTitle: m.title, weight: t.w });
    });
  });
  if (!UNITS.length) return;

  var lastModule = null;
  rows.innerHTML = UNITS.map(function (u) {
    var have = HAVE[u.id] || { published: false, passPct: null, questions: [] };
    var n = have.questions.length;
    var status = !n ? 'no quiz yet' : (n + ' question' + (n === 1 ? '' : 's') + (have.published ? ' · published' : ' · draft'));

    var head = '';
    if (u.module !== lastModule) {
      lastModule = u.module;
      head = '<h3 class="qz-modhead">' + esc(u.module) + ' · ' + esc(u.moduleTitle) + '</h3>';
    }

    return head + '<details class="mat-mod">' +
      '<summary><span class="mat-code">' + esc(u.id) + '</span> ' + esc(u.title) +
        '<span class="mat-have' + (n ? ' on' : '') + '">' + esc(status) + '</span></summary>' +
      '<form method="POST" class="qz-form" data-module="' + esc(u.id) + '">' +
        '<input type="hidden" name="_token" value="' + esc(CSRF) + '">' +
        '<input type="hidden" name="course" value="' + esc(COURSE) + '">' +
        '<input type="hidden" name="module" value="' + esc(u.id) + '">' +
        '<div class="qz-meta">' +
          '<div class="field"><label>Pass mark (%, optional)</label>' +
            '<input type="number" name="pass_pct" min="0" max="100" value="' +
              (have.passPct === null ? '' : have.passPct) + '" placeholder="No pass mark — just show the score"></div>' +
          '<label class="mat-remove"><input type="checkbox" name="published" value="1"' +
            (have.published ? ' checked' : '') + '> Published — learners can take this</label>' +
        '</div>' +
        '<details class="qz-paste"><summary>Paste a set of questions</summary>' +
          '<p class="field-hint">Numbered questions with lettered options, the way a question ' +
            'bank is usually written. Mark the right answer with a tick or an asterisk at the ' +
            'end of its line. Pasting REPLACES what is in this form; nothing is saved until you ' +
            'press save, so you can read it over first.</p>' +
          '<textarea class="qz-paste-in" rows="7" placeholder="1. Which of these best defines a project?&#10;A. Routine day-to-day work&#10;B. A temporary effort with a start and an end  *&#10;C. A department&#10;D. A budget"></textarea>' +
          '<div class="mat-save"><button type="button" class="btn btn-ghost qz-paste-go">Load these into the form</button>' +
            '<span class="qz-paste-msg"></span></div>' +
        '</details>' +
        '<div class="qz-questions">' +
          (have.questions.length ? have.questions.map(function (q, i) { return questionBlock(i, q); }).join('')
                                  : questionBlock(0, null)) +
        '</div>' +
        '<div class="mat-save">' +
          '<button type="button" class="btn btn-ghost qz-addq">Add a question</button>' +
          '<button type="submit" class="btn btn-primary">Save this topic’s quiz</button>' +
        '</div>' +
      '</form>' +
    '</details>';
  }).join('');

  /* Read a pasted question bank.
   *
   * Deliberately fills in THIS FORM rather than posting anywhere of its own.
   * Everything then goes through the same submit, the same CSRF check and the
   * same quiz_save_questions() as a hand-typed question — including its rules
   * about needing two options and exactly one right answer, and its habit of
   * naming what it skipped instead of dropping it silently. A separate import
   * endpoint would have been a second way into the same table, with its own
   * copy of those rules to keep in step. */
  function parseBank(text) {
    var lines = String(text).replace(/\r/g, '').split('\n');
    var out = [], cur = null;

    function closeCur() {
      if (cur && cur.prompt && cur.choices.length) out.push(cur);
      cur = null;
    }
    lines.forEach(function (raw) {
      var line = raw.trim();
      if (!line) return;

      var q = line.match(/^(\d{1,3})[.)]\s+(.*)$/);
      if (q) { closeCur(); cur = { prompt: q[2].trim(), choices: [] }; return; }

      var c = line.match(/^([A-Ha-h])[.)]\s+(.*)$/);
      if (c && cur) {
        var t = c[2];
        /* The right answer is marked at the end of its own line. Accept the
           three things people actually type, and the wording Centenary's own
           bank uses. */
        var correct = /(?:✓|✔|\*|\(correct\)|correct answer)\s*$/i.test(t);
        t = t.replace(/\s*(?:✓|✔|\*|\(correct\)|(?:✓\s*)?correct answer)\s*$/i, '').trim();
        if (t) cur.choices.push({ text: t, correct: correct });
        return;
      }
      /* A wrapped question line: fold it back onto the prompt, but only before
         any options have been read, or a wrapped OPTION would land on it. */
      if (cur && !cur.choices.length) cur.prompt += ' ' + line;
      else if (cur && cur.choices.length) {
        cur.choices[cur.choices.length - 1].text += ' ' + line;
      }
    });
    closeCur();
    return out;
  }

  function wirePaste(form, qContainer) {
    var box = form.querySelector('.qz-paste-in');
    var go  = form.querySelector('.qz-paste-go');
    var msg = form.querySelector('.qz-paste-msg');
    if (!box || !go) return;

    go.addEventListener('click', function () {
      var parsed = parseBank(box.value);
      if (!parsed.length) {
        msg.textContent = 'Nothing recognised — questions need to be numbered, with lettered options under them.';
        return;
      }
      var noAnswer = parsed.filter(function (q) {
        return !q.choices.some(function (c) { return c.correct; });
      });
      var tooFew = parsed.filter(function (q) { return q.choices.length < MIN_CHOICES; });

      qContainer.innerHTML = parsed.map(function (q, i) {
        return questionBlock(i, {
          id: '', prompt: q.prompt,
          choices: q.choices.slice(0, MAX_CHOICES).map(function (c) {
            return { id: '', text: c.text, correct: c.correct };
          })
        });
      }).join('');
      qContainer.querySelectorAll('.qz-question').forEach(function (qEl) { wireQuestion(qEl, qContainer); });
      renumber(qContainer);

      var bits = [parsed.length + ' question' + (parsed.length === 1 ? '' : 's') + ' loaded'];
      if (noAnswer.length) bits.push(noAnswer.length + ' with no right answer marked — tick one before saving');
      if (tooFew.length)   bits.push(tooFew.length + ' with fewer than two options');
      msg.textContent = bits.join('. ') + '. Nothing is saved yet.';
    });
  }

  rows.querySelectorAll('.qz-form').forEach(function (form) {
    var qContainer = form.querySelector('.qz-questions');
    qContainer.querySelectorAll('.qz-question').forEach(function (qEl) { wireQuestion(qEl, qContainer); });
    wirePaste(form, qContainer);

    form.querySelector('.qz-addq').addEventListener('click', function () {
      var idx = qContainer.children.length;
      qContainer.insertAdjacentHTML('beforeend', questionBlock(idx, null));
      wireQuestion(qContainer.lastElementChild, qContainer);
      renumber(qContainer);
    });

    /* Say what is wrong HERE, before a round trip loses the typing. The server
       checks the same things again and reports anything that still gets
       through — this is the courtesy, not the guard, exactly as the URL check
       on admin-materials.php is. */
    form.addEventListener('submit', function (e) {
      var problems = [];
      qContainer.querySelectorAll('.qz-question').forEach(function (qEl, i) {
        var prompt  = (qEl.querySelector('textarea').value || '').trim();
        var texts   = [].map.call(qEl.querySelectorAll('input[type=text]'), function (t) { return (t.value || '').trim(); })
                          .filter(function (t) { return t !== ''; });
        var ticked  = !!qEl.querySelector('input[type=radio]:checked');

        if (prompt === '' && texts.length === 0) return;   // the blank row at the bottom
        if (prompt === '')          problems.push('Question ' + (i + 1) + ' has answer options but no question written.');
        else if (texts.length < MIN_CHOICES) problems.push('Question ' + (i + 1) + ' needs at least ' + MIN_CHOICES + ' answer options.');
        else if (!ticked)           problems.push('Question ' + (i + 1) + ' has no option marked as the correct one.');
      });

      if (problems.length) {
        e.preventDefault();
        alert('Nothing has been saved yet:\n\n' + problems.join('\n') +
              '\n\nFix these and press Save again.');
      }
    });
  });
})();
</script>
<?php endif; ?>
<script src="<?= e(asset('site.js')) ?>"></script>
</body></html>
