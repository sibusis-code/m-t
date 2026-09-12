<?php
declare(strict_types=1);

/* One class's register — the thing a facilitator marks in the room.
 *
 * THIS IS THE ONE PAGE A TRAINER MAY WRITE ON.
 *
 * Everywhere else, a trainer reads and the academy writes: require_write()
 * refuses their POSTs on every content page, deliberately, because they do not
 * own the material. A register is the opposite kind of document. It is a claim
 * about who was in a room, and the only person who can honestly make it is the
 * person who was standing in front of them. Somebody else filling it in
 * afterwards from a WhatsApp message is exactly the failure a register exists
 * to prevent.
 *
 * So the permission here is not is_trainer(). It is class_may_mark(), which
 * asks a narrower question — is this person named as the facilitator OF THIS
 * CLASS — and is checked again on the POST against the class as it is in the
 * database now, never against anything the form said. See lib/classes.php.
 *
 * WHAT THE PAGE WILL NOT DO
 *
 *   It does not default anybody to present. An unmarked learner shows as
 *   "not marked", which is a different fact from "absent" and is the one the
 *   register is actually able to support until somebody says otherwise.
 *
 *   It does not touch enrolment. Marking somebody absent all term is a
 *   conversation for the academy, not a thing this page acts on.
 */

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/csrf.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/learner.php';
require __DIR__ . '/lib/classes.php';
require __DIR__ . '/lib/chrome.php';

$me = require_staff();

$classId = (int) ($_GET['class'] ?? $_POST['class'] ?? 0);
$class   = $classId > 0 ? db_optional(fn() => class_get($classId)) : null;

if ($class === null) {
    /* Two different reasons land here and they need different answers. If the
       tables are not there yet, the class may well exist and this server has
       simply not been migrated — telling somebody "that class does not exist"
       would send them looking for a data problem that is not there. */
    $migrating = db_schema_incomplete();
    http_response_code($migrating ? 503 : 404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><title>'
       . ($migrating ? 'Not ready yet' : 'Not found') . '</title>'
       . '<p style="font:16px/1.6 system-ui,sans-serif;max-width:34em;margin:12vh auto;padding:0 6vw">'
       . e($migrating ? db_schema_notice() : 'That class does not exist on this academy.')
       . ' <a href="admin-classes">Back to the classes</a>.</p>';
    exit;
}

/* A trainer may only reach registers for courses they teach at all — the same
   scoping as everywhere else — and may only MARK the ones naming them. Those
   are two different questions and both are asked. */
$slugs = trainer_slugs($me);
if ($slugs !== null && !in_array((string) $class['course_slug'], $slugs, true)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><title>Not yours</title>'
       . '<p style="font:16px/1.6 system-ui,sans-serif;max-width:34em;margin:12vh auto;padding:0 6vw">'
       . 'That class is on a course you do not teach. <a href="admin-classes">Your classes</a>.</p>';
    exit;
}

$mayMark = class_may_mark($me, $class);
$notice  = '';
$error   = '';

if (is_post()) {
    if (!$mayMark) {
        http_response_code(403);
        $error = 'You can read this register but not mark it. It is marked by '
               . (trim(((string) ($class['f_first'] ?? '')) . ' ' . ((string) ($class['f_last'] ?? ''))) ?: 'the named facilitator')
               . ', or by the academy.';
    } elseif (!csrf_valid()) {
        $error = 'This page had been open a while and the form expired — nothing was saved. '
               . 'Your marks are still on screen; press save again.';
    } else {
        $marks = [];
        foreach ((array) ($_POST['mark'] ?? []) as $uid => $status) {
            if (is_numeric($uid)) $marks[(int) $uid] = (string) $status;
        }
        $notes = [];
        foreach ((array) ($_POST['note'] ?? []) as $uid => $n) {
            if (is_numeric($uid)) $notes[(int) $uid] = (string) $n;
        }

        /* Wrapped for the same reason as admin-classes.php: reachable before
           the migration, and a half-written register must never be the way we
           find that out. */
        $res = db_optional(fn() => class_mark_register(
            (int) $class['id'], (string) $class['course_slug'], $marks, $notes, (int) $me['id']
        ), null);

        if ($res === null) {
            $error = db_schema_notice();
        } else {
            [$ok, $msg] = $res;
            $ok ? $notice = $msg : $error = $msg;
        }
        csrf_rotate();
        $class = db_optional(fn() => class_get($classId)) ?? $class;   // status may have moved
    }
}

$rows  = db_optional(fn() => class_register((int) $class['id'], (string) $class['course_slug']), []) ?: [];
$tally = db_optional(fn() => class_tally((int) $class['id'], (string) $class['course_slug']), []) ?: [];

audit('class.register_viewed', 'classes', (int) $class['id'],
      (string) $class['course_slug'] . ' — ' . count($rows) . ' on the register');

function reg_when(?string $ts): string
{
    if (!$ts) return '';
    $d = date_create($ts, new DateTimeZone('UTC'));
    if (!$d) return '';
    $d->setTimezone(new DateTimeZone('Africa/Johannesburg'));
    return $d->format('j M, H:i');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register: <?= e((string) $class['title']) ?> — <?= e(brand('academy')) ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= e(asset('styles.css')) ?>">
</head>
<body>
<?php chrome_nav('admin', ['active' => 'classes', 'name' => $me['first_name']]); ?>

<section class="section page-top">
  <div class="wrap">

    <div class="adm-head">
      <div>
        <span class="eyebrow"><?= e(class_when((string) $class['held_on'], $class['starts_at'] ?? null, $class['ends_at'] ?? null)) ?></span>
        <h2><?= e((string) $class['title']) ?></h2>
        <p class="adm-sub">
          <?= e((string) $class['course_slug']) ?>
          <?php if ($class['venue']): ?> · <?= e((string) $class['venue']) ?><?php endif; ?>
          <?php $facil = trim(((string) ($class['f_first'] ?? '')) . ' ' . ((string) ($class['f_last'] ?? ''))); ?>
          <?php if ($facil !== ''): ?> · Facilitated by <?= e($facil) ?><?php endif; ?>
        </p>
      </div>
      <a class="btn btn-ghost" href="admin-classes">All classes</a>
    </div>

    <?php if ($notice !== ''): ?><p class="adm-notice" role="status"><?= e($notice) ?></p><?php endif; ?>
    <?php if ($error  !== ''): ?><p class="form-err"   role="alert" ><?= e($error)  ?></p><?php endif; ?>

    <?php if ($tally): ?>
      <div class="reg-tally">
        <span class="reg-chip reg-present"><strong><?= (int) ($tally['present'] ?? 0) ?></strong> present</span>
        <span class="reg-chip reg-late"><strong><?= (int) ($tally['late'] ?? 0) ?></strong> late</span>
        <span class="reg-chip reg-absent"><strong><?= (int) ($tally['absent'] ?? 0) ?></strong> absent</span>
        <span class="reg-chip reg-excused"><strong><?= (int) ($tally['excused'] ?? 0) ?></strong> excused</span>
        <span class="reg-chip reg-unmarked"><strong><?= (int) ($tally['unmarked'] ?? 0) ?></strong> not marked</span>
      </div>
    <?php endif; ?>

    <?php if (!$rows): ?>
      <div class="adm-empty">
        <strong>Nobody is enrolled on this course yet</strong>
        <p>A register lists whoever is enrolled on <?= e((string) $class['course_slug']) ?>.
           Enrol the learners first and they appear here.</p>
      </div>
    <?php else: ?>

      <?php if (!$mayMark): ?>
        <p class="adm-sub" style="margin-bottom:14px">
          This register is read-only for you. It is marked by
          <?= $facil !== '' ? e($facil) : 'the facilitator named on the class' ?>, or by the academy —
          see who marked each line in the last column.
        </p>
      <?php endif; ?>

      <form method="POST" class="reg-form">
        <?= csrf_field() ?>
        <input type="hidden" name="class" value="<?= (int) $class['id'] ?>">

        <table class="adm-table reg-table">
          <thead>
            <tr>
              <th>Learner</th>
              <?php foreach (CLASS_STATUSES as $s): ?>
                <th class="reg-h"><?= e(class_status_label($s)) ?></th>
              <?php endforeach; ?>
              <th>Note</th>
              <th>Marked</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <?php
                $uid  = (int) $r['id'];
                $cur  = (string) ($r['status'] ?? '');
                $by   = trim(((string) ($r['by_first'] ?? '')) . ' ' . ((string) ($r['by_last'] ?? '')));
              ?>
              <tr class="<?= $cur === '' ? 'reg-row-unmarked' : '' ?>">
                <td>
                  <strong><?= e(trim(((string) $r['first_name']) . ' ' . ((string) $r['last_name']))) ?></strong>
                  <span class="cls-sub"><?= e((string) $r['email']) ?><?php
                    if ($r['employee_no']): ?> · <?= e((string) $r['employee_no']) ?><?php endif; ?></span>
                </td>
                <?php foreach (CLASS_STATUSES as $s): ?>
                  <td class="reg-pick">
                    <label class="reg-radio">
                      <input type="radio" name="mark[<?= $uid ?>]" value="<?= e($s) ?>"
                             <?= $cur === $s ? 'checked' : '' ?>
                             <?= $mayMark ? '' : 'disabled' ?>>
                      <span class="reg-dot reg-dot-<?= e($s) ?>" aria-hidden="true"></span>
                      <span class="reg-sr"><?= e(class_status_label($s)) ?></span>
                    </label>
                  </td>
                <?php endforeach; ?>
                <td>
                  <input type="text" class="reg-note" name="note[<?= $uid ?>]" maxlength="200"
                         value="<?= e((string) ($r['note'] ?? '')) ?>"
                         placeholder="<?= $mayMark ? 'optional' : '' ?>"
                         <?= $mayMark ? '' : 'readonly' ?>>
                </td>
                <td class="reg-by">
                  <?php if ($cur !== ''): ?>
                    <?= e(reg_when($r['marked_at'] ?? null)) ?>
                    <?php if ($by !== ''): ?><span class="cls-sub"><?= e($by) ?></span><?php endif; ?>
                  <?php else: ?>
                    <span class="cls-none">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <?php if ($mayMark): ?>
          <div class="reg-save">
            <button class="btn btn-primary" type="submit">Save the register</button>
            <span class="adm-sub">Anyone you leave unmarked stays unmarked — that is not the
              same as absent, and this page will not decide it for you.</span>
          </div>
        <?php endif; ?>
      </form>

    <?php endif; ?>

  </div>
</section>

<script src="<?= e(asset('site.js')) ?>"></script>
</body></html>
