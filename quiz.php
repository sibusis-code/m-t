<?php
declare(strict_types=1);

/* Taking a self-check quiz, and seeing the result.
 *
 * THIS IS NOT THE QCTO ASSESSMENT — see lib/quiz.php's header comment for the
 * full reasoning. Every surface below repeats the same sentence rather than
 * paraphrasing it differently each time, on purpose.
 *
 * Three shapes, mirroring materials.php's own branching:
 *
 *   GET  ?course=<slug>              — every module's quiz status for this
 *                                       learner, as JSON. Consumed by
 *                                       quiz-widget.js on module.html.
 *   GET  ?course=<slug>&module=<code> — the quiz itself, to take.
 *   POST (same URL)                   — grade it and show the result, in the
 *                                       same response.
 *
 * WHY GRADING CANNOT BE FAKED FROM THE BROWSER
 *
 * The GET above renders choice TEXT ONLY — quiz_questions_with_choices()'s
 * is_correct never reaches the HTML, an inline script, or a data attribute.
 * The POST re-checks enrolment and published status fresh (never trusts
 * anything about the earlier GET) and asks quiz_grade_and_record() to re-read
 * the current answer key from the database; the only thing trusted from the
 * client is which choice id was picked for which question id.
 */

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/csrf.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/learner.php';
require __DIR__ . '/lib/quiz.php';
require __DIR__ . '/lib/chrome.php';
require __DIR__ . '/lib/mail.php';
require __DIR__ . '/lib/curriculum.php';
require __DIR__ . '/lib/letters.php';

app_session_start();

const QUIZ_DISCLAIMER = 'This is a self-check the academy built to help you study — it does not '
    . 'count towards being found competent. That is Centenary’s decision after the real '
    . 'assessment, and the qualification is awarded by the QCTO after the EISA.';

/** @param array<string,mixed> $data */
function qout(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$course = (string) ($_GET['course'] ?? $_POST['course'] ?? '');
$module = (string) ($_GET['module'] ?? $_POST['module'] ?? '');

/* ---------------------------------------------------------------------------
   ?course=<slug>, no module — the JSON summary for the module page
   --------------------------------------------------------------------------- */

if ($module === '' && !is_post()) {
    $me = current_user();
    if ($me === null) qout(['in' => false]);

    if ($course === '' || !learner_valid_course_slug($course)) {
        qout(['in' => true, 'error' => 'course', 'message' => 'No such course.'], 400);
    }
    if (!db_optional(fn() => learner_is_enrolled((int) $me['id'], $course), false)) {
        qout(['in' => true, 'enrolled' => false, 'quizzes' => (object) []], 403);
    }

    $summary = db_optional(fn() => quiz_results_summary_for_user((int) $me['id'], $course), []);
    $out = [];
    foreach ($summary as $mod => $s) {
        if (!$s['published'] || $s['questions'] === 0) continue;   // nothing a learner could take
        $out[$mod] = [
            'available' => true,
            'questions' => $s['questions'],
            'best_pct'  => $s['best']['pct'] ?? null,
            'attempts'  => $s['best']['attempts'] ?? 0,
        ];
    }
    qout(['in' => true, 'enrolled' => true, 'course' => $course, 'quizzes' => (object) $out]);
}

/* ---------------------------------------------------------------------------
   ?course=<slug>&module=<code> — take it, or see the result of taking it
   --------------------------------------------------------------------------- */

$me = require_user();

if ($course === '' || !learner_valid_course_slug($course) || $module === '' || !learner_valid_code($module, 20)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><title>Not found</title>'
       . '<p style="font:16px/1.6 system-ui,sans-serif;max-width:34em;margin:12vh auto;padding:0 6vw">'
       . 'That is not a module this site has a self-check for.</p>';
    exit;
}

$enrolled = db_optional(fn() => learner_is_enrolled((int) $me['id'], $course), false);
$quiz     = $enrolled ? db_optional(fn() => quiz_for_module($course, $module)) : null;
$questions = ($quiz !== null && (bool) $quiz['published'])
    ? db_optional(fn() => quiz_questions_with_choices((int) $quiz['id']), [])
    : [];
$available = $enrolled && $quiz !== null && (bool) $quiz['published'] && count($questions) > 0;

/**
 * Tick the topic off when the learner reaches the pass mark.
 *
 * ONLY EVER TICKS, NEVER UN-TICKS. Somebody who passed on Tuesday and then
 * retook the same questions for practice on Friday and scored 40% has not
 * un-learnt the topic, and taking the tick away would punish the practising
 * this page exists to encourage. The tick means "reached the bar at least
 * once", which is also what quiz_results_summary_for_user() reports, since it
 * reads the BEST attempt rather than the last.
 *
 * Written here rather than left to the browser so that it is true even if the
 * fetch that follows never lands — a learner who passes and closes the laptop
 * has still passed. The widget re-reads progress afterwards rather than
 * assuming.
 */
function quiz_tick_topic_on_pass(array $me, string $course, string $code, array $result): bool
{
    if (($result['pass'] ?? null) !== true) return false;

    /* A topic quiz is keyed by its topic code (KM-01-KT01) and belongs to a
       module (KM-01); a module-level quiz is keyed by the module itself and
       takes the empty item_code that means "module complete" — see the note on
       item_code in schema.mysql.sql. */
    $module = curriculum_module_of($code);
    $item   = ($module !== '' && $module !== $code) ? $code : '';
    if ($module === '') { $module = $code; $item = ''; }

    $already = db_optional(fn() => learner_progress_has((int) $me['id'], $course, $module, $item), false);
    if ($already) return false;

    $set = db_optional(fn() => learner_progress_set((int) $me['id'], $course, $module, $item, true), false);
    if ($set) {
        audit('quiz.passed_topic', 'learner_progress', (int) $me['id'],
              $course . ' ' . $code . ' — ' . $result['pct'] . '% (bar ' . $result['pass_pct'] . '%)');
    }
    return $set;
}

$result = null;
$error  = '';

/* The module page takes these inline now, so both halves of the exchange have
   a JSON shape. The HTML page below is kept and still works: it is what a
   learner gets with JavaScript off, and what the emailed links point at. */
$asJson = (($_GET['as'] ?? $_POST['as'] ?? '') === 'json');

if ($asJson && !is_post()) {
    if (!$available) {
        qout(['in' => true, 'available' => false,
              'enrolled' => $enrolled,
              'message' => $enrolled
                  ? 'There is no self-check for this topic yet.'
                  : 'You are not on the course this topic belongs to.'], $enrolled ? 200 : 403);
    }
    $best = db_optional(fn() => quiz_best_result((int) $me['id'], (int) $quiz['id']));
    qout([
        'in' => true, 'available' => true, 'enrolled' => true,
        'course' => $course, 'module' => $module,
        'pass_pct' => quiz_pass_pct($quiz),
        /* Choice TEXT ONLY — is_correct never reaches the browser before the
           answers are posted. Same rule as the HTML page; see the header. */
        'questions' => array_map(static fn(array $q) => [
            'id'      => (int) $q['id'],
            'prompt'  => (string) $q['prompt'],
            'choices' => array_map(
                static fn(array $c) => ['id' => (int) $c['id'], 'text' => (string) $c['choice_text']],
                $q['choices']
            ),
        ], array_values($questions)),
        'best' => $best ? ['pct' => (int) $best['pct'], 'attempts' => (int) $best['attempts']] : null,
        'disclaimer' => QUIZ_DISCLAIMER,
    ]);
}

if ($asJson && is_post()) {
    if (!$available) {
        qout(['in' => true, 'available' => false, 'message' => 'This self-check is not available right now.'], 409);
    }
    if (!csrf_valid()) {
        qout(['in' => true, 'error' => 'token',
              'message' => 'This page had been open a while. Reload it and try again.'], 403);
    }

    $answers = [];
    foreach ((array) ($_POST['a'] ?? []) as $qid => $cid) {
        if (is_numeric($qid) && is_numeric($cid)) $answers[(int) $qid] = (int) $cid;
    }
    $graded = quiz_grade_and_record((int) $quiz['id'], (int) $me['id'], $answers);
    $ticked = quiz_tick_topic_on_pass($me, $course, $module, $graded);
    csrf_rotate();

    letter_module_completed($me, $course, $module, learner_course_title($course));

    /* The answer key goes back ONLY in the response to a graded submission,
       never in the GET above. Without it a learner who got one wrong has no
       way to learn which, and re-reading is the whole point of the retry. */
    $key = [];
    foreach ($questions as $q) $key[(int) $q['id']] = quiz_choice_correct_id($q);

    qout([
        'in' => true, 'available' => true, 'graded' => true,
        'pct' => $graded['pct'], 'pass' => $graded['pass'], 'pass_pct' => $graded['pass_pct'],
        'score_count' => $graded['score_count'], 'question_count' => $graded['question_count'],
        'ticked' => $ticked,
        'token' => csrf_token(),        // the rotated one, so a retry can post
        'breakdown' => array_map(static fn(array $b) => [
            'question_id' => $b['question_id'],
            'choice_id'   => $b['choice_id'],
            'correct'     => $b['correct'],
            'correct_id'  => $key[$b['question_id']] ?? null,
        ], $graded['breakdown']),
    ]);
}

if (is_post()) {
    if (!$available) {
        $error = 'This self-check is not available right now.';
    } elseif (!csrf_valid()) {
        $error = 'This page had been open a while and the form expired. Please try again.';
    } else {
        $answers = [];
        foreach ((array) ($_POST['a'] ?? []) as $qid => $cid) {
            if (is_numeric($qid) && is_numeric($cid)) $answers[(int) $qid] = (int) $cid;
        }
        $result = quiz_grade_and_record((int) $quiz['id'], (int) $me['id'], $answers);
        /* The same tick the inline widget gets. Without this line a learner
           with JavaScript off could pass and still show the topic untouched. */
        quiz_tick_topic_on_pass($me, $course, $module, $result);
        csrf_rotate();

        /* If that was the last topic quiz outstanding in this module, post the
           learner their module report — once, however many times they retake
           anything in it afterwards. letter_module_completed() decides; it
           swallows its own failures, because the learner is waiting for a score
           and an email problem must never become an error page. */
        letter_module_completed($me, $course, $module,
                                learner_course_title($course));
    }
}

/* Choice TEXT ONLY reaches this array — see the header comment on why. */
$forDisplay = array_map(static fn(array $q) => [
    'id'      => (int) $q['id'],
    'prompt'  => (string) $q['prompt'],
    'choices' => array_map(static fn(array $c) => ['id' => (int) $c['id'], 'text' => (string) $c['choice_text']], $q['choices']),
], $questions);

/* For the results view: which choice was picked and whether it was right,
   without ever having shown is_correct before the POST above ran. */
$breakdownByQ = [];
if ($result !== null) {
    foreach ($result['breakdown'] as $b) $breakdownByQ[$b['question_id']] = $b;
}
function quiz_choice_correct_id(array $q): ?int
{
    foreach ($q['choices'] as $c) if (!empty($c['is_correct'])) return (int) $c['id'];
    return null;
}

// Same 403 convention materials.php uses for "signed in, but not the right person".
if (!$available && $result === null) http_response_code(!$enrolled ? 403 : 200);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Self-check: <?= e($module) ?> — <?= e(brand('academy')) ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= e(asset('styles.css')) ?>">
</head>
<body>
<?php chrome_nav('learner', ['admin' => $me['role'] === 'admin', 'trainer' => $me['role'] === 'trainer']); ?>

<section class="section-soft page-top">
  <div class="wrap" style="max-width:760px">

    <span class="eyebrow"><?= e($module) ?></span>
    <h2 class="lede-h">Check your knowledge</h2>
    <p class="qz-disclaimer"><?= e(QUIZ_DISCLAIMER) ?></p>

    <?php if ($error !== ''): ?><p class="form-err" role="alert"><?= e($error) ?></p><?php endif; ?>

    <?php if (!$available && $result === null): ?>

      <p class="adm-empty"><?= !$enrolled
            ? 'You are not on the course this module belongs to. If that is wrong, ask the academy.'
            : 'There is no self-check for this module yet.' ?></p>
      <p style="margin-top:16px"><a class="btn btn-ghost" href="<?= e('module?m=' . rawurlencode($module)) ?>">Back to the module</a></p>

    <?php elseif ($result !== null): ?>

      <div class="qz-result <?= $result['pass'] === false ? 'qz-result-fail' : ($result['pass'] === true ? 'qz-result-pass' : '') ?>">
        <div class="qz-score"><?= (int) $result['pct'] ?>%</div>
        <p><?= (int) $result['score_count'] ?> of <?= (int) $result['question_count'] ?> correct.
          <?php if ($result['pass'] !== null): ?>
            <?= $result['pass'] ? '<strong>That meets the pass mark.</strong>' : '<strong>That is below the pass mark.</strong>' ?>
          <?php endif; ?></p>
        <p class="qz-disclaimer"><?= e(QUIZ_DISCLAIMER) ?></p>
      </div>

      <?php /* $questions (not $forDisplay) from here on — grading has already
               happened server-side above, so using is_correct to choose a CSS
               class in this template is not a leak; it never reaches the
               browser before the POST that already scored the attempt. */ ?>
      <div class="qz-review">
        <?php foreach ($questions as $q): ?>
          <?php
            $b = $breakdownByQ[(int) $q['id']] ?? null;
            $correctId = quiz_choice_correct_id($q);
          ?>
          <div class="qz-question qz-static">
            <p class="qz-prompt"><?= e((string) $q['prompt']) ?></p>
            <?php foreach ($q['choices'] as $c): ?>
              <?php
                $cid     = (int) $c['id'];
                $picked  = $b !== null && $b['choice_id'] === $cid;
                $isRight = $cid === $correctId;
                $cls = $isRight ? 'qz-right' : ($picked ? 'qz-wrong' : '');
              ?>
              <div class="qz-choice-static <?= e($cls) ?>"><?= e((string) $c['choice_text']) ?><?= $picked ? ' — your answer' : '' ?></div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="mat-save">
        <a class="btn btn-primary" href="<?= e('quiz?course=' . rawurlencode($course) . '&module=' . rawurlencode($module)) ?>">Try again</a>
        <a class="btn btn-ghost" href="<?= e('module?m=' . rawurlencode($module)) ?>">Back to the module</a>
      </div>

    <?php else: ?>

      <form method="POST" class="qz-take">
        <?= csrf_field() ?>
        <input type="hidden" name="course" value="<?= e($course) ?>">
        <input type="hidden" name="module" value="<?= e($module) ?>">
        <?php foreach ($forDisplay as $q): ?>
          <div class="qz-question">
            <p class="qz-prompt"><?= e($q['prompt']) ?></p>
            <?php foreach ($q['choices'] as $ci => $c): ?>
              <label class="qz-choice-pick">
                <input type="radio" name="a[<?= (int) $q['id'] ?>]" value="<?= (int) $c['id'] ?>" required>
                <?= e($c['text']) ?>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        <div class="mat-save"><button type="submit" class="btn btn-primary">Submit</button></div>
      </form>

    <?php endif; ?>

  </div>
</section>

<?php chrome_footer('slim'); ?>
<script src="<?= e(asset('site.js')) ?>"></script>
</body></html>
