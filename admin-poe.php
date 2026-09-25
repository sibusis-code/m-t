<?php
declare(strict_types=1);

/* Where the paper is: who has handed in which workbook and logbook.
 *
 * Every submission on these qualifications is physical. The learner completes the
 * workbook on paper, hands it to their facilitator, and the facilitator builds the
 * portfolio of evidence that goes for assessment, moderation and verification.
 * Nothing about that changes here, and this page holds no submissions — it answers
 * the question nobody could answer before: whose file is short what.
 *
 * WHO WRITES HERE
 *
 * An admin, and a FACILITATOR on their own courses — see poe_may_record() for why
 * that is the third narrow write a trainer has, alongside marking a register and
 * opening one more try at a self-check. The person the paper was handed to is the
 * only person who can honestly say it was handed in.
 *
 * WHY A GRID
 *
 * A facilitator standing over a pile of workbooks is asking "who is missing", and
 * that is a question about a column. One row per learner, one cell per module,
 * every cell a status — so the gaps are the thing you see first. The alternative,
 * a list of submissions, answers "what came in" instead, which is the question
 * nobody had.
 *
 * NOTHING IS INVENTED. An empty cell means not handed in. The register's rule —
 * see lib/classes.php — that "not marked" and "absent" are different facts about a
 * person applies here for the same reason, so no cell is ever given a default.
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

$me = require_staff();

/* Only courses whose curriculum this platform carries — the grid's columns are
   its modules, and there are none for a course with no structure. Then narrowed
   to what this person may see at all, which for a trainer is their own courses. */
$courses = array_filter(
    learner_catalogue(),
    fn(array $c, string $slug): bool => !empty($c['tracked']) && may_see_course($slug, $me),
    ARRAY_FILTER_USE_BOTH
);

$notice = '';
$errors = [];

$course = (string) ($_GET['course'] ?? $_POST['course'] ?? '');
if (!isset($courses[$course])) $course = (string) (array_key_first($courses) ?? '');

if (is_post() && $course !== '') {
    /* The permission is checked here, on the write, and against the course that
       was posted — not against the one the page happened to be showing. */
    if (!poe_may_record($course, $me)) {
        audit('write.denied', 'poe_submissions', null, $course);
        http_response_code(403);
        exit('Your account cannot record hand-ins on that course.');
    }
    if (!csrf_valid()) {
        $errors[] = 'That form had expired — nothing was saved. Please try again.';
    } else {
        $res = db_optional(fn() => poe_record(
            $course,
            (string) ($_POST['module_code'] ?? ''),
            (int) ($_POST['user_id'] ?? 0),
            (string) ($_POST['kind'] ?? ''),
            (string) ($_POST['status'] ?? ''),
            (string) ($_POST['on_date'] ?? ''),
            (string) ($_POST['note'] ?? '') !== '' ? (string) $_POST['note'] : null,
            (int) $me['id']
        ), null);

        if ($res === null) {
            $errors[] = db_schema_notice();
        } else {
            [$ok, $msg] = $res;
            $ok ? $notice = $msg : $errors[] = $msg;
        }
        csrf_rotate();
    }
}

$modules  = $course !== '' ? (db_optional(fn() => curriculum_modules($course), []) ?: []) : [];
$learners = $course !== ''
    ? (db_optional(fn() => db_all(
        'SELECT u.id, u.first_name, u.last_name, u.email
           FROM users u
           JOIN enrolments e ON e.user_id = u.id AND e.tenant_id = u.tenant_id
          WHERE u.tenant_id = ? AND e.course_slug = ? AND u.role = ?
          ORDER BY u.last_name, u.first_name',
        [tenant_id(), $course, 'learner']
      ), []) ?: [])
    : [];
$have = $course !== '' ? (db_optional(fn() => poe_for_course($course), []) ?: []) : [];
$mayWrite = $course !== '' && poe_may_record($course, $me);

/* One cell, opened for editing: ?cell=<userId>:<moduleCode>:<kind>. A form per
   cell would be four hundred forms on a page; one at a time is also how a
   facilitator actually works through a pile. */
$openCell = (string) ($_GET['cell'] ?? '');

audit('poe.viewed', 'poe_submissions', null, $course . ' — ' . count($learners) . ' learner(s)');

/**
 * One cell's form, opened in place.
 *
 * A form per cell would be four hundred forms on a page for thirty learners, so
 * exactly one is open at a time — which is also how somebody actually works
 * through a pile of workbooks. A function rather than an included partial
 * because an include in the web root is a file the server can be asked for
 * directly, and this markup is not a page.
 */
function poe_cell_form(string $cell, string $course, int $userId, string $moduleCode,
                       string $kind, ?array $row): void
{
    $status = (string) ($row['status'] ?? 'handed_in');
    $date   = (string) ($row['on_date'] ?? gmdate('Y-m-d'));
    ?>
    <form method="POST" class="poe-form">
      <?= csrf_field() ?>
      <input type="hidden" name="course" value="<?= e($course) ?>">
      <input type="hidden" name="user_id" value="<?= $userId ?>">
      <input type="hidden" name="module_code" value="<?= e($moduleCode) ?>">
      <input type="hidden" name="kind" value="<?= e($kind) ?>">
      <select name="status" aria-label="Where this is">
        <option value="">Not handed in</option>
        <?php foreach (POE_STATUSES as $st): ?>
          <option value="<?= e($st) ?>" <?= $st === $status ? 'selected' : '' ?>>
            <?= e(poe_status_label($st)) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <input type="date" name="on_date" value="<?= e($date) ?>" aria-label="On what date">
      <input type="text" name="note" maxlength="200" placeholder="Note"
             value="<?= e((string) ($row['note'] ?? '')) ?>" aria-label="Note">
      <div class="poe-form-act">
        <button class="btn btn-primary" type="submit">Save</button>
        <a class="btn btn-ghost" href="<?= e(poe_qs(['cell' => ''])) ?>">Cancel</a>
      </div>
    </form>
    <?php
}
function poe_qs(array $over = []): string
{
    $q = array_merge(['course' => $_GET['course'] ?? '', 'cell' => $_GET['cell'] ?? ''], $over);
    $q = array_filter($q, fn($v) => (string) $v !== '');
    return 'admin-poe' . ($q ? '?' . http_build_query($q) : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Portfolios of evidence — <?= e(brand('academy')) ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= e(asset('styles.css')) ?>">
</head>
<body>
<?php chrome_nav('admin', ['active' => 'poe', 'name' => $me['first_name']]); ?>

<section class="section page-top">
  <div class="wrap">

    <div class="adm-head">
      <div>
        <span class="eyebrow">On paper</span>
        <h2>Portfolios of evidence</h2>
      </div>
    </div>

    <div class="mat-intro">
      <p>Workbooks and logbooks are handed in on paper and filed into each learner's portfolio
         of evidence. This page is the record of where that paper is — nothing is submitted or
         assessed here. An empty cell means not handed in.</p>
      <?php if (!$mayWrite && $course !== ''): ?>
        <p>Your account can read this page. Hand-ins are recorded by the facilitator the paper
           was given to, or by the academy.</p>
      <?php endif; ?>
    </div>

    <?php if (db_schema_incomplete()): ?>
      <p class="form-err" role="alert"><?= e(db_schema_notice()) ?></p>
    <?php endif; ?>
    <?php if ($notice !== ''): ?><p class="adm-notice" role="status"><?= e($notice) ?></p><?php endif; ?>
    <?php foreach ($errors as $er): ?><p class="form-err" role="alert"><?= e($er) ?></p><?php endforeach; ?>

    <?php if (!$courses): ?>
      <div class="adm-empty">
        <strong>No courses to show</strong>
        <p>This page lists the qualifications whose modules the site carries. If you are a
           trainer, it shows the ones you are assigned to.</p>
      </div>
    <?php else: ?>

      <?php if (count($courses) > 1): ?>
        <div class="adm-tabs">
          <?php foreach ($courses as $slug => $c): ?>
            <a class="adm-tab<?= $slug === $course ? ' is-on' : '' ?>"
               href="<?= e(poe_qs(['course' => $slug, 'cell' => ''])) ?>">
              <?= e((string) ($c['title'] ?? $slug)) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!$learners): ?>
        <div class="adm-empty">
          <strong>Nobody is enrolled on this course yet</strong>
          <p>The grid fills in as learners are enrolled.</p>
        </div>
      <?php else: ?>

        <div class="adm-scroll">
        <table class="adm-table poe-grid">
          <thead>
            <tr>
              <th>Learner</th>
              <?php /* curriculum_modules() is keyed BY module id, with the title inside —
                       there is no 'id' field on the row. */ ?>
              <?php foreach ($modules as $mid => $m): ?>
                <th title="<?= e((string) $m['title']) ?>"><?= e((string) $mid) ?></th>
              <?php endforeach; ?>
              <th>Logbook</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($learners as $l): ?>
              <tr>
                <td>
                  <strong><?= e(trim($l['first_name'] . ' ' . $l['last_name'])) ?></strong>
                  <span class="adm-sub"><?= e((string) $l['email']) ?></span>
                </td>

                <?php foreach ($modules as $mid => $m): ?>
                  <?php
                    $cell = (int) $l['id'] . ':' . (string) $mid . ':workbook';
                    $row  = $have[$cell] ?? null;
                  ?>
                  <td class="poe-cell<?= $row ? ' poe-' . e((string) $row['status']) : '' ?>">
                    <?php if ($openCell === $cell && $mayWrite): ?>
                      <?php poe_cell_form($cell, $course, (int) $l['id'], (string) $mid, 'workbook', $row); ?>
                    <?php elseif ($row): ?>
                      <a class="poe-mark" href="<?= e($mayWrite ? poe_qs(['cell' => $cell]) : poe_qs()) ?>"
                         title="<?= e(poe_status_label((string) $row['status'])
                                      . ($row['on_date'] ? ' — ' . (string) $row['on_date'] : '')
                                      . ($row['note'] ? ' — ' . (string) $row['note'] : '')) ?>">
                        <?= e(poe_short((string) $row['status'])) ?>
                      </a>
                    <?php elseif ($mayWrite): ?>
                      <a class="poe-empty" href="<?= e(poe_qs(['cell' => $cell])) ?>"
                         title="Not handed in — click to record it">+</a>
                    <?php else: ?>
                      <span class="poe-empty">—</span>
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>

                <?php
                  /* The logbook is one per course, not one per module, so it is
                     filed against the course itself. LOGBOOK is not a module code
                     any curriculum uses, which is what makes it safe to key on. */
                  $cell = (int) $l['id'] . ':LOGBOOK:logbook';
                  $row  = $have[$cell] ?? null;
                ?>
                <td class="poe-cell poe-cell-log<?= $row ? ' poe-' . e((string) $row['status']) : '' ?>">
                  <?php if ($openCell === $cell && $mayWrite): ?>
                    <?php poe_cell_form($cell, $course, (int) $l['id'], 'LOGBOOK', 'logbook', $row); ?>
                  <?php elseif ($row): ?>
                    <a class="poe-mark" href="<?= e(poe_qs(['cell' => $cell])) ?>"
                       title="<?= e(poe_status_label((string) $row['status'])
                                    . ($row['on_date'] ? ' — ' . (string) $row['on_date'] : '')) ?>">
                      <?= e(poe_short((string) $row['status'])) ?>
                    </a>
                  <?php elseif ($mayWrite): ?>
                    <a class="poe-empty" href="<?= e(poe_qs(['cell' => $cell])) ?>" title="Not handed in">+</a>
                  <?php else: ?>
                    <span class="poe-empty">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>

        <p class="poe-key">
          <span class="poe-mark poe-handed_in">In</span> handed in ·
          <span class="poe-mark poe-assessed">As</span> assessed ·
          <span class="poe-mark poe-returned">Rt</span> returned to the learner ·
          <span class="poe-empty">+</span> not handed in
        </p>
      <?php endif; ?>
    <?php endif; ?>

  </div>
</section>

<?php chrome_footer('slim'); ?>
</body>
</html>
