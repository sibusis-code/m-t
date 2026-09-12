<?php
declare(strict_types=1);

/* Accounts.
 *
 * WHY THIS PAGE HAD TO EXIST
 *
 * The sign-in page told people that if they forgot their password, the academy
 * would set a new one for them. That promise could not actually be kept: the
 * only way to set a password was tools/make-user.php, which is command line
 * only, and Xneelo's hosting answers port 22 with an SFTP daemon and no shell.
 * There was no way to run it. A learner who forgot their password on the live
 * site was locked out permanently, and nothing said so.
 *
 * The self-service reset in forgot.php is the proper fix, and it is built. But
 * it depends on email arriving, and mail from this server still fails the
 * domain's SPF record — so until that DNS change is made, THIS page is the
 * route that always works, and the sign-in page points at it.
 *
 * WHAT IT DELIBERATELY CANNOT DO
 *
 * No deleting, and no editing of names, emails or roles. Deleting a learner
 * whose progress and enrolment hang off them would either fail on a foreign key
 * or, worse, succeed and take a QCTO-relevant record with it. Switching an
 * account off achieves what the situation actually calls for — somebody has
 * left SPS — while keeping the record intact, and it is reversible by whoever
 * did it rather than by a restore from backup.
 *
 * No promoting anyone to administrator either. The first administrator is
 * created by the installer; any further one is a decision that should cost more
 * than a click on a list page.
 */

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/csrf.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/install.php';   // install_readable_password()
require __DIR__ . '/lib/learner.php';
require __DIR__ . '/lib/chrome.php';

$me = require_admin();

$notice = '';
$error  = '';
/* Held for this one response only — see the same pattern on admin.php. */
$fresh  = null;

if (is_post()) {
    if (!csrf_valid()) {
        $error = 'That form had expired — nothing was changed. Please try again.';
    } else {
        $id     = (int) ($_POST['id'] ?? 0);
        $action = (string) ($_POST['a'] ?? '');

        $target = $id > 0
            ? db_one('SELECT * FROM users WHERE id = ? AND tenant_id = ?', [$id, tenant_id()])
            : null;

        /* -------------------------------------------------------------------
           Creating an account.
​
           Until now the only accounts that could exist were learners created by
           pressing Enrol on a registration, plus the one administrator that
           setup.php made. There was no way to add a second administrator at
           all: tools/make-user.php is command-line only and this hosting has no
           shell, so "add Muzi so he can help" had no answer.

           It handles both roles because the same gap applies to a learner who
           never came through the registration form — someone transferring in
           mid-intake, or the person whose registration went to the old
           FormSubmit inbox and was never in the database.
           ------------------------------------------------------------------- */
        if ($action === 'create') {
            $first = post_str('first_name', 80);
            $last  = post_str('last_name', 80);
            $email = strtolower(post_str('email', 190));
            $role  = post_str('role', 10);
            if (!in_array($role, ['admin', 'trainer', 'learner'], true)) $role = 'learner';
            $empno = post_str('employee_no', 40);
            $dept  = post_str('department', 120);

            if ($first === '' || $email === '') {
                $error = 'A first name and an email address are the two things needed.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'That email address does not look right.';
            } elseif (db_one('SELECT id FROM users WHERE tenant_id = ? AND email = ?',
                             [tenant_id(), $email]) !== null) {
                /* Deliberately not "update the existing one". Reusing an email
                   silently resets a real person's password, which is a fine way
                   to lock out a learner mid-course by typing an address twice. */
                $error = 'There is already an account with that email address. '
                       . 'Search for it below — you can set a new password on it from there.';
            } else {
                $password = install_readable_password();
                $newId = db_insert('users', [
                    'tenant_id'     => tenant_id(),
                    'email'         => $email,
                    'password_hash' => auth_hash($password),
                    'first_name'    => $first,
                    'last_name'     => $last,
                    'employee_no'   => $empno !== '' ? $empno : null,
                    'department'    => $dept  !== '' ? $dept  : null,
                    'role'          => $role,
                    'status'        => 'active',
                    'created_at'    => now(),
                ]);
                audit('user.created', 'users', $newId, 'role: ' . $role);
                $notice = trim($first . ' ' . $last) . ' can now sign in'
                        . ($role === 'admin' ? ' and administer the academy.' : '.');
                $fresh = [
                    'name'     => trim($first . ' ' . $last),
                    'email'    => $email,
                    'password' => $password,
                ];
            }

        } elseif ($target === null) {
            $error = 'That account no longer exists.';

        /* -------------------------------------------------------------------
           Changing a role.

           The guard that matters: you cannot change your own. Demoting yourself
           when you are the only administrator leaves nobody who can reach these
           pages, and with no shell on this hosting there is no way back in
           short of editing the database by hand through the host's panel.
           ------------------------------------------------------------------- */
        } elseif ($action === 'role') {
            /* Three roles since 10 Sep 2026. Validated against a list rather
               than a ternary, so an unexpected value falls to 'learner' and loses
               access rather than being written into the column as-is. */
            $to = post_str('role', 10);
            if (!in_array($to, ['admin', 'trainer', 'learner'], true)) $to = 'learner';

            if ((int) $target['id'] === (int) $me['id']) {
                $error = 'You cannot change your own role. Ask another administrator to do it.';
            } elseif ($to !== 'admin' && $target['role'] === 'admin'
                      && (int) db_one('SELECT COUNT(*) c FROM users
                                       WHERE tenant_id = ? AND role = ? AND status = ?',
                                      [tenant_id(), 'admin', 'active'])['c'] <= 1) {
                /* Cannot be reached while the self-check above holds — you are
                   an administrator, so there is always at least one besides the
                   one being demoted. Kept because that reasoning is subtle, and
                   a future change to who may reach this page would break it
                   silently. */
                $error = 'That is the last administrator. Make somebody else an '
                       . 'administrator first, or there will be nobody who can.';
            } else {
                db_run('UPDATE users SET role = ? WHERE id = ? AND tenant_id = ?',
                       [$to, (int) $target['id'], tenant_id()]);
                audit('user.role_changed', 'users', (int) $target['id'], 'to: ' . $to);
                $notice = trim($target['first_name'] . ' ' . $target['last_name'])
                        . ($to === 'admin'
                            ? ' can now administer the academy. They will see the '
                              . 'administration pages next time they sign in.'
                            : ' is now an ordinary learner and can no longer reach '
                              . 'the administration pages.');
            }

        } elseif ($action === 'password') {
            $password = install_readable_password();
            auth_set_password((int) $target['id'], $password);

            /* Every session that account had, on every device, is now dead —
               auth_set_password() changes the hash and current_user() ends any
               session stamped against the old one. That is the correct
               behaviour for "I think somebody else has my password", which is
               half the reason this button gets pressed. */
            /* Any reset link they had outstanding dies with the old password.
               db_optional because this table arrives with a release and the
               migration may not have been run yet — see lib/db.php. */
            db_optional(fn() => db_run('UPDATE password_resets SET used_at = ?
                     WHERE tenant_id = ? AND user_id = ? AND used_at IS NULL',
                   [now(), tenant_id(), (int) $target['id']]));

            audit('password.set_by_admin', 'users', (int) $target['id']);
            $notice = 'A new password has been set for '
                    . trim($target['first_name'] . ' ' . $target['last_name']) . '.';
            $fresh = [
                'name'     => trim($target['first_name'] . ' ' . $target['last_name']),
                'email'    => (string) $target['email'],
                'password' => $password,
            ];

        } elseif ($action === 'status') {
            $to = ((string) ($_POST['status'] ?? '')) === 'active' ? 'active' : 'disabled';

            if ((int) $target['id'] === (int) $me['id']) {
                /* Switching off the account you are signed in as would end the
                   session on the next click and, if you are the only
                   administrator, lock everybody out of the admin pages with no
                   way back in — there is no shell on this hosting to undo it. */
                $error = 'You cannot switch off the account you are signed in with.';
            } else {
                db_run('UPDATE users SET status = ? WHERE id = ? AND tenant_id = ?',
                       [$to, (int) $target['id'], tenant_id()]);
                audit('user.status_changed', 'users', (int) $target['id'], 'to: ' . $to);
                $notice = trim($target['first_name'] . ' ' . $target['last_name'])
                        . ($to === 'active'
                            ? ' can sign in again.'
                            : ' has been switched off and can no longer sign in. '
                              . 'Their learner record is untouched.');
            }
        } elseif ($action === 'courses') {
            /* Which courses a trainer may see. Only meaningful for a trainer:
               an administrator sees every course by virtue of the role, and
               storing rows for one would create a second, quieter answer to
               "what may this person see" that could disagree with the first. */
            if ($target['role'] !== 'trainer') {
                $error = 'Courses are assigned to trainers. Change the role first.';
            } else {
                $valid  = array_keys(learner_catalogue());
                $picked = array_values(array_intersect(
                    array_map('strval', (array) ($_POST['courses'] ?? [])), $valid));

                /* Replace rather than merge: the checkboxes are the whole
                   answer, and an unticked box has to be able to remove one. */
                $ok = db_optional(static function () use ($target, $picked, $me): bool {
                    db_run('DELETE FROM trainer_courses WHERE tenant_id = ? AND user_id = ?',
                           [tenant_id(), (int) $target['id']]);
                    foreach ($picked as $slug) {
                        db_insert('trainer_courses', [
                            'tenant_id'   => tenant_id(),
                            'user_id'     => (int) $target['id'],
                            'course_slug' => $slug,
                            'created_at'  => now(),
                            'created_by'  => (int) $me['id'],
                        ]);
                    }
                    return true;
                }, false);

                if (!$ok) {
                    $error = 'Course assignments need the trainer_courses table, which is '
                           . 'not on this server yet. Run setup.php, then try again.';
                } else {
                    audit('user.courses_changed', 'users', (int) $target['id'],
                          $picked ? implode(', ', $picked) : 'none');
                    $notice = trim($target['first_name'] . ' ' . $target['last_name'])
                            . ($picked
                                ? ' now sees ' . count($picked) . ' course'
                                  . (count($picked) === 1 ? '' : 's') . '.'
                                : ' now sees no courses.');
                }
            }
        }
        csrf_rotate();
    }
}

/* ---------------------------------------------------------------------------
   The list
   --------------------------------------------------------------------------- */

$q      = trim((string) ($_GET['q'] ?? ''));
$where  = ['tenant_id = ?'];
$params = [tenant_id()];

if ($q !== '') {
    $where[] = '(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR employee_no LIKE ?)';
    $like    = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}

$rows = db_all(
    'SELECT * FROM users WHERE ' . implode(' AND ', $where)
    . " ORDER BY CASE WHEN role = 'admin' THEN 0 ELSE 1 END, last_name, first_name",
    $params
);

audit('users.viewed', 'users', null, count($rows) . ' shown');

/* Course assignments for the trainers on this page, in one query rather than
   one per row. db_optional because the deploy lands before setup.php is run:
   until the table exists every trainer shows no ticked boxes, which is exactly
   what trainer_slugs() will be telling them on their own page. */
$assigned = [];
foreach (db_optional(static fn() => db_all(
    'SELECT user_id, course_slug FROM trainer_courses WHERE tenant_id = ?',
    [tenant_id()]
), []) ?: [] as $r) {
    $assigned[(int) $r['user_id']][] = (string) $r['course_slug'];
}

/* Enrolments for everybody on the page, in one query rather than one per row. */
$enrolByUser = [];
if ($rows) {
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    foreach (db_optional(fn() => db_all(
        'SELECT user_id, course_slug, course_title, status FROM enrolments
          WHERE tenant_id = ? AND user_id IN (' . $in . ') ORDER BY enrolled_at',
        array_merge([tenant_id()], $ids)
    ), []) as $en) {
        $enrolByUser[(int) $en['user_id']][] = $en;
    }
}

/* And how far each of them has got. See the note on the function: counts, not
   percentages, because the denominator is the registered curriculum and it lives
   in pm-modules.js. */
$progressBy = $rows ? learner_progress_counts_bulk(array_map(fn($r) => (int) $r['id'], $rows)) : [];

function when_u(?string $utc): string
{
    if (!$utc) return '';
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone('Africa/Johannesburg'));
    return $d->format('d M Y, H:i');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Accounts — <?= e(brand('academy')) ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= e(asset('styles.css')) ?>">
</head>
<body>
<?php chrome_nav('admin', ['active' => 'admin-users', 'name' => $me['first_name']]); ?>

<section class="section page-top">
  <div class="wrap">

    <div class="adm-head">
      <div>
        <span class="eyebrow">Academy administration</span>
        <h2>Accounts</h2>
      </div>
    </div>

    <?php if (db_schema_incomplete()): ?>
      <p class="form-err" role="alert"><?= e(db_schema_notice()) ?></p>
    <?php endif; ?>
    <?php if ($notice !== ''): ?><p class="adm-notice" role="status"><?= e($notice) ?></p><?php endif; ?>
    <?php if ($error  !== ''): ?><p class="form-err"   role="alert" ><?= e($error)  ?></p><?php endif; ?>

    <?php if ($fresh !== null): ?>
      <div class="adm-creds" role="alert">
        <h3>New sign-in details for <?= e($fresh['name']) ?></h3>
        <p>Give these to <?= e($fresh['name']) ?> now.
          <strong>This password is not shown again</strong> — if it is lost, set another one.</p>
        <dl>
          <dt>Where</dt><dd><?= e('https://' . ($_SERVER['HTTP_HOST'] ?? 'centenarynetworks.com') . app_base_path() . 'login') ?></dd>
          <dt>Email</dt><dd><?= e($fresh['email']) ?></dd>
          <dt>Password</dt><dd class="adm-pass"><?= e($fresh['password']) ?></dd>
        </dl>
        <p class="adm-creds-note">In person or over the phone, not by email. They were signed out
          everywhere the old password was in use, and there is a <strong>Change password</strong>
          box on their dashboard for when they want one of their own.</p>
      </div>
    <?php endif; ?>

    <?php /* Closed by default. This page is read many times a day to look
             somebody up and used to add an account rarely, so the form that is
             wanted once a month should not be the first thing above the list
             that is wanted every morning. */ ?>
    <details class="adm-add">
      <summary>Add someone</summary>
      <p class="adm-add-lede">For a colleague who needs to help run the academy, or a learner who
        never came through the registration form. Everyone else arrives by pressing
        <strong>Enrol</strong> on the registrations list, which is the usual way and keeps their
        registration and their account joined up.</p>
      <form method="POST" class="form adm-add-form">
        <?= csrf_field() ?>
        <input type="hidden" name="a" value="create">
        <div class="two">
          <div class="field"><label for="n-first">First name</label>
            <input id="n-first" type="text" name="first_name" required></div>
          <div class="field"><label for="n-last">Surname</label>
            <input id="n-last" type="text" name="last_name"></div>
        </div>
        <div class="two">
          <div class="field"><label for="n-email">Work email</label>
            <input id="n-email" type="email" name="email" required
                   placeholder="they sign in with this"></div>
          <div class="field"><label for="n-role">They are</label>
            <select id="n-role" name="role">
              <option value="learner">A learner — studies, and sees only their own progress</option>
              <option value="admin">Staff — can see every learner, enrol people and add accounts</option>
            </select></div>
        </div>
        <div class="two">
          <div class="field"><label for="n-emp">Employee number</label>
            <input id="n-emp" type="text" name="employee_no" placeholder="<?= e(brand('empno_example')) ?>"></div>
          <div class="field"><label for="n-dept">Department / team</label>
            <input id="n-dept" type="text" name="department" placeholder="<?= e(brand('dept_example')) ?>"></div>
        </div>
        <button type="submit" class="btn btn-primary">Create the account</button>
        <p class="field-hint">A password is generated and shown to you once, on this page. Nothing
          is emailed — give it to them in person or over the phone.</p>
      </form>
    </details>

    <form class="adm-search" method="GET">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, email or employee number">
      <button type="submit" class="btn btn-primary">Search</button>
      <?php if ($q !== ''): ?><a class="adm-clear" href="admin-users">Clear</a><?php endif; ?>
    </form>

    <?php if (!$rows): ?>
      <p class="adm-empty"><?= $q === ''
        ? 'No accounts yet. They are created by pressing Enrol on the registrations list.'
        : 'Nothing matches that.' ?></p>
    <?php else: ?>
      <div class="adm-scroll">
      <table class="adm-table adm-users">
        <thead><tr>
          <th>Name</th><th>Signs in with</th><th>On</th><th>Last signed in</th><th>Account</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $u): ?>
          <?php $isMe = (int) $u['id'] === (int) $me['id']; ?>
          <tr<?= $u['status'] !== 'active' ? ' class="adm-off"' : '' ?>>
            <td>
              <strong><?= e(trim($u['first_name'] . ' ' . $u['last_name'])) ?></strong>
              <?php if ($u['role'] === 'admin'): ?><span class="adm-role">Administrator</span>
              <?php elseif ($u['role'] === 'trainer'): ?><span class="adm-role adm-role-tr">Trainer</span><?php endif; ?>
              <?php if ($isMe): ?><span class="adm-sub">This is you</span><?php endif; ?>
              <?php if ($u['employee_no']): ?><span class="adm-sub"><?= e((string) $u['employee_no']) ?></span><?php endif; ?>
              <?php if ($u['department']): ?><span class="adm-sub"><?= e((string) $u['department']) ?></span><?php endif; ?>
            </td>
            <td><a href="mailto:<?= e((string) $u['email']) ?>"><?= e((string) $u['email']) ?></a></td>
            <td>
              <?php $mine = $enrolByUser[(int) $u['id']] ?? []; ?>
              <?php if (!$mine): ?>
                <span class="adm-none">no courses</span>
              <?php else: foreach ($mine as $en): ?>
                <span class="adm-enrolled"><?= e((string) $en['course_title']) ?></span>
                <?php
                  $slug = (string) $en['course_slug'];
                  $c = $progressBy[(int) $u['id']][$slug] ?? null;
                ?>
                <?php if (learner_course_tracked($slug)): ?>
                  <span class="adm-prog<?= $c === null ? ' none' : '' ?>">
                    <?php if ($c === null): ?>
                      Not started
                    <?php else: ?>
                      <?= (int) $c['topics'] ?> topic<?= $c['topics'] === 1 ? '' : 's' ?> ticked ·
                      <?= (int) $c['modules'] ?> module<?= $c['modules'] === 1 ? '' : 's' ?> complete
                      <?php if ($c['last']): ?><br>Last activity <?= e(when_u($c['last'])) ?><?php endif; ?>
                    <?php endif; ?>
                  </span>
                <?php endif; ?>
              <?php endforeach; endif; ?>
            </td>
            <td class="adm-when">
              <?= $u['last_login_at']
                    ? e(when_u((string) $u['last_login_at']))
                    : '<span class="adm-none">never</span>' ?>
              <span class="adm-sub">Made <?= e(when_u((string) $u['created_at'])) ?></span>
            </td>
            <td>
              <?php if ($u['status'] !== 'active'): ?>
                <span class="adm-status-off">Switched off</span>
              <?php endif; ?>

              <form method="POST" class="adm-act"
                    onsubmit="return confirm('Set a new password for <?= e(addslashes(trim($u['first_name'] . ' ' . $u['last_name']))) ?>? They will be signed out everywhere and will need the new one.')">
                <?= csrf_field() ?>
                <input type="hidden" name="a" value="password">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <button type="submit" class="btn btn-ghost">Set a new password</button>
              </form>

              <?php if (!$isMe): ?>
                <form method="POST" class="adm-act">
                  <?= csrf_field() ?>
                  <input type="hidden" name="a" value="status">
                  <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                  <input type="hidden" name="status" value="<?= $u['status'] === 'active' ? 'disabled' : 'active' ?>">
                  <button type="submit" class="linkish adm-toggle">
                    <?= $u['status'] === 'active' ? 'Switch this account off' : 'Switch it back on' ?>
                  </button>
                </form>
                <?php /* Not offered on your own row. Demoting yourself as the only
                         administrator locks everyone out of these pages, and there is
                         no shell on this hosting to undo it. The POST handler refuses
                         it as well — a control you cannot see is not a check. */ ?>
                <?php if (!$isMe): ?>
                  <?php /* A select rather than the old two-way button: with three
                           roles a toggle cannot say where it is going. The warning
                           that used to sit in a confirm() is below the control,
                           where it is readable before the choice rather than after. */ ?>
                  <form method="POST" class="adm-act">
                    <?= csrf_field() ?>
                    <input type="hidden" name="a" value="role">
                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <label class="adm-rolepick">
                      <span>Role</span>
                      <select name="role">
                        <option value="learner"<?= $u['role'] === 'learner' ? ' selected' : '' ?>>Learner</option>
                        <option value="trainer"<?= $u['role'] === 'trainer' ? ' selected' : '' ?>>Trainer</option>
                        <option value="admin"<?= $u['role'] === 'admin'   ? ' selected' : '' ?>>Administrator</option>
                      </select>
                    </label>
                    <button type="submit" class="linkish adm-toggle">Save role</button>
                  </form>
                  <p class="adm-rolehelp">
                    A <b>trainer</b> sees the courses assigned to them and the learners on
                    those courses, and can change nothing. An <b>administrator</b> sees every
                    learner on this site, every registration, and can set passwords and add
                    accounts.
                  </p>

                  <?php if ($u['role'] === 'trainer'): ?>
                    <form method="POST" class="adm-act adm-courses">
                      <?= csrf_field() ?>
                      <input type="hidden" name="a" value="courses">
                      <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                      <span class="adm-coursehead">Courses this trainer may see</span>
                      <?php foreach (learner_catalogue() as $slug => $c): ?>
                        <label class="adm-coursebox">
                          <input type="checkbox" name="courses[]" value="<?= e($slug) ?>"
                                 <?= in_array($slug, $assigned[(int) $u['id']] ?? [], true) ? 'checked' : '' ?>>
                          <?= e((string) ($c['title'] ?? $slug)) ?>
                        </label>
                      <?php endforeach; ?>
                      <button type="submit" class="linkish adm-toggle">Save courses</button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>

    <p class="adm-foot">Setting a password here is the route that always works, and it is the one
      to use while the domain's SPF record still has to be added — until then a reset email from
      this server may be filed as spam. Hand the password over in person or on the phone, never
      in an email. Switching an account off stops the person signing in and changes nothing about
      their learner record, which the QCTO obliges us to keep. Every action on this page is logged.</p>

  </div>
</section>
<script src="<?= e(asset('site.js')) ?>"></script>
</body></html>
