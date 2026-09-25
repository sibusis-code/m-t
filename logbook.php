<?php
declare(strict_types=1);

/* The learner's logbook: typed here, printed, signed, filed.
 *
 * WHAT THE PRINTOUT IS FOR, AND WHY THIS PAGE EXISTS AT ALL
 *
 * The qualification needs a logbook in the portfolio of evidence, and every
 * submission is physical — the signed paper is the evidence, not this page. So
 * the only thing this page claims to improve is that a typed logbook is legible,
 * complete, and not lost when a notebook goes through a wash cycle. It prints on
 * to a sheet with a signature block, and that sheet is what goes in the file.
 *
 * Nothing here is a mark and nothing here is assessed. Every surface says so,
 * the same way lib/quiz.php's header requires of a self-check score.
 *
 * ONE COURSE AT A TIME. ?c=<slug>, defaulting to the learner's first tracked
 * enrolment, because a logbook is filed per qualification and a printout mixing
 * two of them would have to be taken apart by hand.
 *
 * PRINTING is ?print=1, which renders the same rows with the chrome and the forms
 * removed rather than a second copy of the markup — one source, so the sheet
 * cannot drift from the screen.
 */

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/csrf.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/learner.php';
require __DIR__ . '/lib/curriculum.php';
require __DIR__ . '/lib/logbook.php';
require __DIR__ . '/lib/chrome.php';

$me = require_user();

$notice = '';
$error  = '';

/* Which course this logbook is for. Only a course they are actually on: the
   slug comes from a query string, so it is checked against their enrolments
   rather than against the catalogue. */
$enrolments = db_optional(fn() => learner_enrolments((int) $me['id']), []) ?: [];
$mine = array_values(array_map(fn($e) => (string) $e['course_slug'], $enrolments));

$course = (string) ($_GET['c'] ?? $_POST['c'] ?? '');
if ($course === '' || !in_array($course, $mine, true)) {
    $tracked = array_values(array_filter($mine, 'learner_course_tracked'));
    $course  = $tracked[0] ?? ($mine[0] ?? '');
}

if ($course !== '' && is_post()) {
    if (!csrf_valid()) {
        $error = 'This page had been open a while and the form expired. Please try again.';
    } else {
        $do = (string) ($_POST['do'] ?? '');
        $res = null;
        if ($do === 'save') {
            $id  = ($_POST['id'] ?? '') !== '' ? (int) $_POST['id'] : null;
            $res = db_optional(fn() => logbook_save((int) $me['id'], $course, $_POST, $id), null);
        } elseif ($do === 'delete') {
            $res = db_optional(fn() => logbook_delete((int) ($_POST['id'] ?? 0), (int) $me['id']), null);
        }
        if ($res === null && $do !== '') {
            $error = db_schema_notice();
        } elseif (is_array($res)) {
            [$ok, $msg] = $res;
            $ok ? $notice = $msg : $error = $msg;
        }
        csrf_rotate();
    }
}

$printing = ($_GET['print'] ?? '') !== '';
$entries  = $course !== '' ? (db_optional(fn() => logbook_entries((int) $me['id'], $course), []) ?: []) : [];
$total    = $course !== '' ? (db_optional(fn() => logbook_total_minutes((int) $me['id'], $course), 0) ?: 0) : 0;
$editing  = null;
if (($_GET['edit'] ?? '') !== '') {
    $editing = db_optional(fn() => logbook_entry_get((int) $_GET['edit'], (int) $me['id']));
}

/* The sections of this course, offered as a list so a learner picks a code
   rather than typing one. Read from the curriculum file, never restated here. */
$units = $course !== '' ? (db_optional(fn() => curriculum_modules($course), []) ?: []) : [];

audit($printing ? 'logbook.printed' : 'logbook.viewed', 'logbook_entries', (int) $me['id'],
      $course . ' — ' . count($entries) . ' entr' . (count($entries) === 1 ? 'y' : 'ies'));

$courseTitle = $course !== '' ? learner_course_title($course) : '';

function lb_date(string $ymd): string
{
    $d = DateTime::createFromFormat('Y-m-d', $ymd);
    return $d ? $d->format('j M Y') : $ymd;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My logbook — <?= e(brand('academy')) ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= e(asset('styles.css')) ?>">
</head>
<body class="<?= $printing ? 'lb-printing' : '' ?>">
<?php if (!$printing) chrome_nav('learner', ['active' => 'logbook', 'admin' => $me['role'] === 'admin', 'trainer' => $me['role'] === 'trainer']); ?>

<?php if (!$printing): ?>
<section class="section-dark page-top">
  <div class="wrap">
    <div class="sec-head sec-head-wide">
      <span class="eyebrow">Portfolio of evidence</span>
      <h2 class="lede-h">Your logbook</h2>
      <p>Write down what you did and when, as you go. When your facilitator asks for your
        logbook, print this page and sign it with them — the signed sheet is what goes into
        your portfolio. Nothing here is a mark, and nothing here is assessed.</p>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="section<?= $printing ? ' lb-print' : '' ?>">
  <div class="wrap">

    <?php if (!$printing): ?>
      <?php if (db_schema_incomplete()): ?>
        <p class="form-err" role="alert"><?= e(db_schema_notice()) ?></p>
      <?php endif; ?>
      <?php if ($notice !== ''): ?><p class="adm-notice" role="status"><?= e($notice) ?></p><?php endif; ?>
      <?php if ($error  !== ''): ?><p class="form-err"   role="alert" ><?= e($error)  ?></p><?php endif; ?>
    <?php endif; ?>

    <?php if ($course === ''): ?>
      <div class="my-empty">
        <strong>You are not on a course yet.</strong>
        <p>A logbook belongs to a qualification, so there is nothing to keep one for.
          Email <a href="mailto:<?= e(brand('academy_email')) ?>"><?= e(brand('academy_email')) ?></a>
          if you were expecting to be enrolled.</p>
      </div>
    <?php else: ?>

      <?php /* The printed sheet has to say whose it is and what it is for. On screen
               the nav already says who is signed in, so this header is print-only. */ ?>
      <div class="lb-head">
        <div>
          <h3 class="my-h"><?= e($courseTitle) ?></h3>
          <p class="lb-who"><?= e(trim($me['first_name'] . ' ' . $me['last_name'])) ?>
            · <?= e(brand('academy')) ?>
            <?php if (brand_has('accred_no')): ?>· Centenary Networks, QCTO accreditation
              <?= e(brand('accred_no')) ?><?php endif; ?></p>
        </div>
        <?php if (!$printing): ?>
          <div class="lb-head-act">
            <?php if (count($mine) > 1): ?>
              <form method="GET" class="lb-pick">
                <select id="lb-course" name="c" onchange="this.form.submit()">
                  <?php foreach ($mine as $slug): ?>
                    <option value="<?= e($slug) ?>" <?= $slug === $course ? 'selected' : '' ?>>
                      <?= e(learner_course_title($slug)) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </form>
            <?php endif; ?>
            <?php if ($entries): ?>
              <a class="btn btn-ghost" href="<?= e('logbook?c=' . rawurlencode($course) . '&print=1') ?>">
                Print for signing</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <?php if (!$printing): ?>
        <div class="adm-block">
          <h3><?= $editing ? 'Edit this entry' : 'Add an entry' ?></h3>
          <form method="POST" class="form lb-form">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="save">
            <input type="hidden" name="c" value="<?= e($course) ?>">
            <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>

            <div class="lb-fields">
              <div class="field">
                <label for="lb-date">Date you did it</label>
                <input id="lb-date" type="date" name="entry_date" required
                       max="<?= e(gmdate('Y-m-d')) ?>"
                       value="<?= e((string) ($editing['entry_date'] ?? gmdate('Y-m-d'))) ?>">
              </div>
              <div class="field">
                <label for="lb-unit">Section or unit standard</label>
                <select id="lb-unit" name="unit_code">
                  <option value="">Not sure yet</option>
                  <?php /* Keyed BY module id — see curriculum_modules(). */ ?>
                  <?php foreach ($units as $mid => $m): ?>
                    <option value="<?= e((string) $mid) ?>"
                      <?= (string) ($editing['unit_code'] ?? '') === (string) $mid ? 'selected' : '' ?>>
                      <?= e((string) $mid) ?> — <?= e((string) $m['title']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="lb-hours">How long</label>
                <input id="lb-hours" type="text" name="hours" maxlength="12" placeholder="2h 30m"
                       value="<?= e($editing ? logbook_hours_input($editing) : '') ?>">
                <p class="field-hint">Hours, or "2h 30m", or "90 min".</p>
              </div>
            </div>

            <div class="field">
              <label for="lb-what">What you did</label>
              <textarea id="lb-what" name="activity" rows="3" maxlength="2000" required
                placeholder="Interviewed three shop owners about what they pay for stock, and wrote up what it told me about pricing."><?= e((string) ($editing['activity'] ?? '')) ?></textarea>
            </div>

            <div class="field">
              <label for="lb-ev">What is in your file to show for it (optional)</label>
              <input id="lb-ev" type="text" name="evidence" maxlength="190"
                     placeholder="Interview notes, pages 4–6 of my workbook"
                     value="<?= e((string) ($editing['evidence'] ?? '')) ?>">
            </div>

            <div class="cls-actions">
              <button class="btn btn-primary" type="submit"><?= $editing ? 'Save the entry' : 'Add to my logbook' ?></button>
              <?php if ($editing): ?>
                <a class="btn btn-ghost" href="<?= e('logbook?c=' . rawurlencode($course)) ?>">Cancel</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <?php if (!$entries): ?>
        <?php if (!$printing): ?>
          <div class="adm-empty">
            <strong>Nothing in your logbook yet</strong>
            <p>Add the first entry above. Little and often beats trying to remember a
               month of work on the day it is due.</p>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <div class="adm-scroll">
        <table class="adm-table lb-table">
          <thead>
            <tr>
              <th>Date</th><th>What you did</th><th>Section</th><th>Time</th>
              <?php if (!$printing): ?><th></th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($entries as $en): ?>
              <tr>
                <td class="lb-date"><?= e(lb_date((string) $en['entry_date'])) ?></td>
                <td>
                  <?= nl2br(e((string) $en['activity'])) ?>
                  <?php if ($en['evidence']): ?>
                    <span class="lb-ev">Evidence: <?= e((string) $en['evidence']) ?></span>
                  <?php endif; ?>
                </td>
                <td class="lb-unit">
                  <?php if ($en['unit_code']): ?>
                    <?= e((string) $en['unit_code']) ?>
                    <span class="lb-unit-t"><?= e(curriculum_module_title((string) $en['unit_code'], $course)) ?></span>
                  <?php else: ?>
                    <span class="adm-none">—</span>
                  <?php endif; ?>
                </td>
                <td class="lb-hrs"><?= e(logbook_hours($en['minutes'] !== null ? (int) $en['minutes'] : null)) ?></td>
                <?php if (!$printing): ?>
                  <td class="cls-act">
                    <a class="btn btn-ghost" href="<?= e('logbook?c=' . rawurlencode($course) . '&edit=' . (int) $en['id']) ?>">Edit</a>
                    <form method="POST" class="lb-del" onsubmit="return confirm('Remove this entry from your logbook?')">
                      <?= csrf_field() ?>
                      <input type="hidden" name="do" value="delete">
                      <input type="hidden" name="c" value="<?= e($course) ?>">
                      <input type="hidden" name="id" value="<?= (int) $en['id'] ?>">
                      <button class="btn btn-ghost" type="submit">Remove</button>
                    </form>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="3"><?= count($entries) ?> entr<?= count($entries) === 1 ? 'y' : 'ies' ?></td>
              <td class="lb-hrs"><strong><?= e(logbook_hours($total)) ?></strong></td>
              <?php if (!$printing): ?><td></td><?php endif; ?>
            </tr>
          </tfoot>
        </table>
        </div>

        <?php /* The signature block. The reason this page prints at all: a logbook in a
                 portfolio of evidence is worth what the two signatures on it are worth. */ ?>
        <div class="lb-sign">
          <p class="lb-sign-say">I confirm that this is a true record of the work I did.</p>
          <div class="lb-sign-row">
            <span class="lb-sign-line">Learner<br><?= e(trim($me['first_name'] . ' ' . $me['last_name'])) ?></span>
            <span class="lb-sign-line">Date</span>
          </div>
          <p class="lb-sign-say">I have seen the evidence for the entries above.</p>
          <div class="lb-sign-row">
            <span class="lb-sign-line">Facilitator</span>
            <span class="lb-sign-line">Date</span>
          </div>
          <p class="lb-foot">This logbook is a record kept by the learner. It is not an assessment
            result. <?= e(learner_assessment_route($course)) ?></p>
        </div>

        <?php if ($printing): ?>
          <p class="lb-back"><a href="<?= e('logbook?c=' . rawurlencode($course)) ?>">← Back to my logbook</a></p>
          <script>window.addEventListener('load', function () { window.print(); });</script>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>

  </div>
</section>

<?php if (!$printing) chrome_footer('slim'); ?>
</body>
</html>
