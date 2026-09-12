<?php
declare(strict_types=1);

/* What a trainer sees: their own courses, and the learners on them.
 *
 * WHY THIS IS ITS OWN PAGE AND NOT A FILTER ON admin-progress.php
 *
 * They answer different questions off different tables. admin-progress.php
 * lists progress_reports — the form a learner fills in about their own study —
 * and its only course field is `qualification`, free text, set by exactly one
 * page to one long string about the Project Manager qualification. There is no
 * reliable way to ask it "which of these belong to the Procurement course",
 * because nothing in it is a course.
 *
 * enrolments and learner_progress ARE keyed by course_slug, which is the same
 * key trainer_courses uses. So the scoped question — "who is on MY course, and
 * how far have they got" — is answerable here and is not answerable there.
 * Bolting a filter onto the other page would have looked like it worked and
 * been wrong for every course except one.
 *
 * WHAT AN ADMINISTRATOR SEES HERE
 *
 * Everything, because trainer_slugs() returns null for an admin and the query
 * drops its course filter. The page is still primarily the trainer's; an admin
 * has richer views elsewhere.
 *
 * FAIL CLOSED. A trainer with no rows in trainer_courses sees no courses at
 * all, and so does a trainer on a server where the table has not been created
 * yet — see the note on trainer_slugs() in lib/auth.php. An empty page is the
 * right answer to "we have not said what you teach"; the alternative failure,
 * showing everything, hands a partner company somebody else's cohort.
 */

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/csrf.php';   // chrome_signout() builds a form and needs csrf_field()
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/chrome.php';

$me = require_staff();

/* null for an administrator (no restriction), a list for a trainer, and an
   EMPTY list is a real answer meaning "nothing". Read the null check first. */
$slugs = trainer_slugs($me);
$all   = ($slugs === null);

/* Course titles live in the front end, not the database — course.html holds
   them. Rather than duplicate that list here and let the two drift, the page
   shows the slug and lets the admin pages supply the nice name where they
   already do. A slug is not pretty but it is never wrong. */
$courses = [];

if ($all) {
    $rows = db_optional(static fn() => db_all(
        'SELECT course_slug, COUNT(DISTINCT user_id) AS learners
           FROM enrolments WHERE tenant_id = ? AND status = ?
          GROUP BY course_slug ORDER BY course_slug',
        [tenant_id(), 'active']
    ), []);
    foreach ($rows ?: [] as $r) $courses[(string) $r['course_slug']] = (int) $r['learners'];
} else {
    foreach ($slugs as $s) $courses[$s] = 0;
    if ($slugs) {
        $in   = implode(',', array_fill(0, count($slugs), '?'));
        $rows = db_optional(static fn() => db_all(
            'SELECT course_slug, COUNT(DISTINCT user_id) AS learners
               FROM enrolments
              WHERE tenant_id = ? AND status = ? AND course_slug IN (' . $in . ')
              GROUP BY course_slug',
            array_merge([tenant_id(), 'active'], $slugs)
        ), []);
        foreach ($rows ?: [] as $r) $courses[(string) $r['course_slug']] = (int) $r['learners'];
    }
}

/* The learners themselves, with a count of the items each has completed.
   LEFT JOIN so somebody enrolled who has not started yet still appears — those
   are the ones a trainer most wants to see. */
$learners = [];
if ($courses) {
    $keys = array_keys($courses);
    $in   = implode(',', array_fill(0, count($keys), '?'));
    $learners = db_optional(static fn() => db_all(
        'SELECT e.course_slug, u.id, u.first_name, u.last_name, u.email,
                e.status, e.enrolled_at,
                (SELECT COUNT(*) FROM learner_progress p
                  WHERE p.tenant_id = e.tenant_id AND p.user_id = e.user_id
                    AND p.course_slug = e.course_slug) AS done
           FROM enrolments e
           JOIN users u ON u.id = e.user_id AND u.tenant_id = e.tenant_id
          WHERE e.tenant_id = ? AND e.course_slug IN (' . $in . ')
          ORDER BY e.course_slug, u.last_name, u.first_name',
        array_merge([tenant_id()], $keys)
    ), []) ?: [];
}

/* Audited like every other view of learner data, with the number of records
   reached, so "who looked at this cohort" is answerable afterwards. */
audit('trainer.viewed', 'enrolments', null,
      count($learners) . ' learners across ' . count($courses) . ' course(s)');

$byCourse = [];
foreach ($learners as $r) $byCourse[(string) $r['course_slug']][] = $r;

function tr_when(?string $ts): string
{
    if (!$ts) return '—';
    $d = date_create($ts, new DateTimeZone('UTC'));
    if (!$d) return '—';
    $d->setTimezone(new DateTimeZone('Africa/Johannesburg'));
    return $d->format('d M Y');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My learners — <?= e(brand('academy')) ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= e(asset('styles.css')) ?>">
</head>
<body>
<?php chrome_nav('admin', ['active' => 'trainer', 'name' => $me['first_name']]); ?>

<section class="section page-top">
  <div class="wrap">

    <div class="adm-head">
      <div>
        <span class="eyebrow"><?= $all ? 'Academy administration' : 'Teaching' ?></span>
        <h2><?= $all ? 'Learners by course' : 'My learners' ?></h2>
      </div>
    </div>

    <?php if (db_schema_incomplete()): ?>
      <p class="form-err" role="alert"><?= e(db_schema_notice()) ?></p>
    <?php endif; ?>

    <?php if (!$courses): ?>
      <div class="adm-empty">
        <?php if ($all): ?>
          <strong>Nobody is enrolled on a course yet</strong>
          <p>Enrolments appear here as soon as learners are on a course.</p>
        <?php else: ?>
          <strong>No courses have been assigned to you yet</strong>
          <p>An administrator assigns the courses you teach, on the Accounts page.
             Until then there is nothing for this page to show — it is not that
             your learners are missing, it is that we have not recorded which
             courses are yours.</p>
        <?php endif; ?>
      </div>
    <?php else: ?>

      <?php foreach ($courses as $slug => $count): ?>
        <div class="adm-block">
          <h3><?= e($slug) ?></h3>
          <p class="adm-sub"><?= (int) $count ?> learner<?= $count === 1 ? '' : 's' ?> enrolled</p>

          <?php $rows = $byCourse[$slug] ?? []; ?>
          <?php if (!$rows): ?>
            <p class="adm-sub">Nobody is enrolled on this course yet.</p>
          <?php else: ?>
            <table class="adm-table">
              <thead>
                <tr><th>Learner</th><th>Email</th><th>Enrolled</th><th>Items completed</th><th>Status</th></tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $r): ?>
                  <tr>
                    <td><?= e(trim(((string) $r['first_name']) . ' ' . ((string) $r['last_name']))) ?></td>
                    <td><?= e((string) $r['email']) ?></td>
                    <td><?= e(tr_when($r['enrolled_at'] ?? null)) ?></td>
                    <td><?= (int) $r['done'] ?></td>
                    <td><?= e((string) $r['status']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

    <?php endif; ?>

    <p class="adm-sub" style="margin-top:26px">
      This page is read-only. Course material, the reading and the self-check
      questions are under <a href="admin-materials">Material</a>,
      <a href="admin-lessons">Reading</a> and <a href="admin-quizzes">Quizzes</a> —
      <?= $all ? 'yours to edit.' : 'yours to read. Send anything that needs changing to the academy and it will be loaded for you.' ?>
    </p>

  </div>
</section>

<script src="<?= e(asset('site.js')) ?>"></script>
</body></html>
