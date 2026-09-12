<?php
declare(strict_types=1);

/* In-person classes — scheduling them, and finding their registers.
 *
 * WHO GETS HERE
 *
 * Staff: an admin or a trainer. They see different pages, from the same file,
 * because they are asking about the same list from two directions:
 *
 *   An ADMIN sees every class and can add and edit them.
 *   A TRAINER sees only classes on the courses they teach, and can add nothing.
 *     Their way in is the register, which is the one thing they may write —
 *     see the long note on class_may_mark() in lib/classes.php for why that
 *     single exception exists and why it is scoped to classes naming them.
 *
 * The scoping uses trainer_slugs() rather than a rule of its own, so a trainer
 * sees classes for exactly the courses they see learners for, and a server
 * where trainer_courses does not exist yet shows them nothing rather than
 * everything.
 */

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/csrf.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/learner.php';
require __DIR__ . '/lib/classes.php';
require __DIR__ . '/lib/chrome.php';

$me    = require_staff();
$admin = is_admin();

$notice = '';
$error  = '';
$editing = null;

if (is_post()) {
    /* Only an admin writes on THIS page. A trainer's write lives on
       register.php and is checked there against the class itself. */
    if (!$admin) {
        http_response_code(403);
        $error = 'Your account can read this page but not change it. '
               . 'Classes are scheduled by the academy; the register is yours to mark.';
    } elseif (!csrf_valid()) {
        $error = 'That form had expired — nothing was saved. Please try again.';
    } else {
        /* Wrapped, because this page is reachable between a deploy and its
           migration and the tables may not exist yet. Reading already degrades
           through db_optional(); without this the WRITE would be a 500 the
           first time somebody filled the form in, which is exactly when the
           notice at the top of the page is telling them to run /setup. */
        $action = (string) ($_POST['action'] ?? '');
        $res = null;
        if ($action === 'create') {
            $res = db_optional(fn() => class_create($_POST, (int) $me['id']), null);
        } elseif ($action === 'update') {
            $res = db_optional(fn() => class_update((int) ($_POST['id'] ?? 0), $_POST, (int) $me['id']), null);
        }
        if ($res === null && $action !== '') {
            $error = db_schema_notice();
        } elseif (is_array($res)) {
            [$ok, $msg] = $res;
            $ok ? $notice = $msg : $error = $msg;
        }
        csrf_rotate();
    }
}

$slugs   = trainer_slugs($me);          // null for an admin, a list for a trainer
$classes = db_optional(fn() => classes_list($slugs), []) ?: [];
$staff   = $admin ? (db_optional(fn() => class_staff_choices(), []) ?: []) : [];
$courses = learner_catalogue();

if ($admin && isset($_GET['edit'])) {
    $editing = db_optional(fn() => class_get((int) $_GET['edit']));
}

audit('classes.viewed', 'classes', null, count($classes) . ' class(es)');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>In-person classes — <?= e(brand('academy')) ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= e(asset('styles.css')) ?>">
</head>
<body>
<?php chrome_nav('admin', ['active' => 'classes', 'name' => $me['first_name']]); ?>

<section class="section page-top">
  <div class="wrap">

    <div class="adm-head">
      <div>
        <span class="eyebrow">In-person</span>
        <h2><?= $admin ? 'Classes and registers' : 'My classes' ?></h2>
      </div>
    </div>

    <?php if (db_schema_incomplete()): ?>
      <p class="form-err" role="alert"><?= e(db_schema_notice()) ?></p>
    <?php endif; ?>
    <?php if ($notice !== ''): ?><p class="adm-notice" role="status"><?= e($notice) ?></p><?php endif; ?>
    <?php if ($error  !== ''): ?><p class="form-err"   role="alert" ><?= e($error)  ?></p><?php endif; ?>

    <?php if ($admin): ?>
      <div class="adm-block">
        <h3><?= $editing ? 'Edit this class' : 'Add a class' ?></h3>
        <form method="POST" class="form cls-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
          <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>

          <div class="two">
            <div class="field">
              <label for="c-title">What is it called</label>
              <input id="c-title" type="text" name="title" maxlength="160" required
                     placeholder="Procurement — day one"
                     value="<?= e((string) ($editing['title'] ?? '')) ?>">
            </div>
            <div class="field">
              <label for="c-course">Course</label>
              <select id="c-course" name="course_slug" <?= $editing ? 'disabled' : 'required' ?>>
                <option value="">Choose…</option>
                <?php foreach ($courses as $slug => $c): ?>
                  <option value="<?= e($slug) ?>" <?= ($editing['course_slug'] ?? '') === $slug ? 'selected' : '' ?>>
                    <?= e((string) ($c['title'] ?? $slug)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <?php if ($editing): ?>
                <p class="field-hint">The course cannot be changed — a register belongs to the
                  people enrolled on it.</p>
              <?php endif; ?>
            </div>
          </div>

          <div class="cls-times">
            <div class="field">
              <label for="c-date">Date</label>
              <input id="c-date" type="date" name="held_on" required value="<?= e((string) ($editing['held_on'] ?? '')) ?>">
            </div>
            <div class="field">
              <label for="c-from">From</label>
              <input id="c-from" type="time" name="starts_at" value="<?= e((string) ($editing['starts_at'] ?? '')) ?>">
            </div>
            <div class="field">
              <label for="c-to">To</label>
              <input id="c-to" type="time" name="ends_at" value="<?= e((string) ($editing['ends_at'] ?? '')) ?>">
            </div>
          </div>

          <div class="two">
            <div class="field">
              <label for="c-venue">Where</label>
              <input id="c-venue" type="text" name="venue" maxlength="160" placeholder="Centenary offices, Midrand"
                     value="<?= e((string) ($editing['venue'] ?? '')) ?>">
            </div>
            <div class="field">
              <label for="c-facil">Facilitator</label>
              <select id="c-facil" name="facilitator_id">
                <option value="">Nobody yet</option>
                <?php foreach ($staff as $s): ?>
                  <option value="<?= (int) $s['id'] ?>"
                    <?= (int) ($editing['facilitator_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>>
                    <?= e(trim($s['first_name'] . ' ' . $s['last_name'])) ?> (<?= e($s['role']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="field-hint">Only this person — and an admin — can mark the register.</p>
            </div>
          </div>

          <?php if ($editing): ?>
            <div class="field" style="max-width:260px">
              <label for="c-state">Status</label>
              <select id="c-state" name="status">
                <?php foreach (CLASS_STATES as $st): ?>
                  <option value="<?= e($st) ?>" <?= ($editing['status'] ?? '') === $st ? 'selected' : '' ?>>
                    <?= e(ucfirst($st)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>

          <div class="field">
            <label for="c-notes">Notes (optional)</label>
            <textarea id="c-notes" name="notes" rows="2" maxlength="2000"><?= e((string) ($editing['notes'] ?? '')) ?></textarea>
          </div>

          <div class="cls-actions">
            <button class="btn btn-primary" type="submit"><?= $editing ? 'Save changes' : 'Add the class' ?></button>
            <?php if ($editing): ?><a class="btn btn-ghost" href="admin-classes">Cancel</a><?php endif; ?>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <div class="adm-block">
      <h3><?= $admin ? 'Every class' : 'Classes on your courses' ?></h3>

      <?php if (!$classes): ?>
        <div class="adm-empty">
          <?php if ($admin): ?>
            <strong>No classes yet</strong>
            <p>Add one above and it appears here with its register.</p>
          <?php else: ?>
            <strong>No classes on your courses yet</strong>
            <p>When the academy schedules an in-person class on a course you teach, it shows
               up here. If you are facilitating it, you mark its register from this page.</p>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <table class="adm-table cls-table">
          <thead>
            <tr>
              <th>Class</th><th>When</th><th>Facilitator</th><th>Register</th><th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($classes as $c): ?>
              <?php
                $mayMark = class_may_mark($me, $c);
                $facil   = trim(((string) ($c['f_first'] ?? '')) . ' ' . ((string) ($c['f_last'] ?? '')));
              ?>
              <tr>
                <td>
                  <strong><?= e((string) $c['title']) ?></strong>
                  <span class="cls-sub"><?= e((string) $c['course_slug']) ?><?php
                    if ($c['venue']): ?> · <?= e((string) $c['venue']) ?><?php endif; ?></span>
                  <?php if ((string) $c['status'] !== 'scheduled'): ?>
                    <span class="cls-state cls-state-<?= e((string) $c['status']) ?>"><?= e(ucfirst((string) $c['status'])) ?></span>
                  <?php endif; ?>
                </td>
                <td><?= e(class_when((string) $c['held_on'], $c['starts_at'] ?? null, $c['ends_at'] ?? null)) ?></td>
                <td><?= $facil !== '' ? e($facil) : '<span class="cls-none">not named</span>' ?></td>
                <td><?= (int) $c['marked'] > 0
                      ? e((int) $c['marked'] . ' marked')
                      : '<span class="cls-none">not marked</span>' ?></td>
                <td class="cls-act">
                  <a class="btn btn-ghost" href="<?= e('register?class=' . (int) $c['id']) ?>">
                    <?= $mayMark ? 'Mark the register' : 'View the register' ?>
                  </a>
                  <?php if ($admin): ?>
                    <a class="btn btn-ghost" href="<?= e('admin-classes?edit=' . (int) $c['id']) ?>">Edit</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  </div>
</section>

<script src="<?= e(asset('site.js')) ?>"></script>
</body></html>
