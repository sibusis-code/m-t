<?php
declare(strict_types=1);

/* The registrations list.
 *
 * This page is not a nice-to-have. Moving registrations out of an inbox and
 * into a database takes something away from whoever was reading that inbox, and
 * until this exists they have strictly less than they had before. Everything
 * here is in service of one question — who has registered, and what has been
 * done about them — and nothing else is on the page.
 *
 * Every view is audited with the number of records reached, and every export is
 * audited separately. Under POPIA the interesting question after an incident is
 * not "was there a login" but "what was read", and a list page is where bulk
 * reading actually happens.
 */

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/csrf.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/install.php';   // install_readable_password()
require __DIR__ . '/lib/learner.php';
require __DIR__ . '/lib/mail.php';
require __DIR__ . '/lib/chrome.php';     // brand(), which the letters read
require __DIR__ . '/lib/curriculum.php';
require __DIR__ . '/lib/letters.php';
require __DIR__ . '/lib/invite.php';

$me = require_admin();

const STATUSES  = ['new', 'contacted', 'enrolled', 'declined'];
const PER_PAGE  = 25;

/* ---------------------------------------------------------------------------
   Actions
   --------------------------------------------------------------------------- */

$notice = '';
/* A new account's password, held only for the length of this one response. It
   is never stored anywhere readable, never written to the audit log, and never
   shown again — which is why the panel that displays it says so plainly. */
$fresh  = null;

if (is_post()) {
    if (!csrf_valid()) {
        $notice = 'That form had expired — nothing was changed. Please try again.';
    } else {
        $id     = (int) ($_POST['id'] ?? 0);
        $action = (string) ($_POST['a'] ?? 'status');

        if ($action === 'enrol' && $id > 0) {
            $delivery = ($_POST['delivery'] ?? 'show') === 'invite' ? 'invite' : 'show';
            $result   = learner_enrol_registration($id, (string) ($_POST['course'] ?? ''), $delivery);
            $notice   = $result['message'];
            if ($result['ok'] && $result['password'] !== null) {
                $fresh = [
                    'name'     => trim($result['user']['first_name'] . ' ' . $result['user']['last_name']),
                    'email'    => (string) $result['user']['email'],
                    'password' => $result['password'],
                ];
            }
        } else {
            $status = (string) ($_POST['status'] ?? '');

            if ($id > 0 && in_array($status, STATUSES, true)) {
                // Scoped to the tenant in the WHERE clause, not just in the lookup:
                // an id from another company must not be updatable by guessing it.
                $n = db_run(
                    'UPDATE registrations SET status = ? WHERE id = ? AND tenant_id = ?',
                    [$status, $id, tenant_id()]
                )->rowCount();

                if ($n > 0) {
                    audit('registration.status_changed', 'registrations', $id, 'to: ' . $status);
                    $notice = 'Registration #' . $id . ' marked "' . $status . '".';
                }
            }
        }
        csrf_rotate();
    }
}

/* ---------------------------------------------------------------------------
   Filtering
   --------------------------------------------------------------------------- */

$q      = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$page   = max(1, (int) ($_GET['page'] ?? 1));

$where  = ['tenant_id = ?'];
$params = [tenant_id()];

if ($q !== '') {
    $where[]  = '(full_name LIKE ? OR email LIKE ? OR employee_no LIKE ? OR course_title LIKE ?)';
    $like     = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if (in_array($status, STATUSES, true)) {
    $where[]  = 'status = ?';
    $params[] = $status;
}

$sqlWhere = ' WHERE ' . implode(' AND ', $where);
$total    = (int) db_value('SELECT COUNT(*) FROM registrations' . $sqlWhere, $params);
$pages    = max(1, (int) ceil($total / PER_PAGE));
$page     = min($page, $pages);

/* ---------------------------------------------------------------------------
   Export

   Handled before any output so the headers are still ours to set. The export
   ignores pagination on purpose — a spreadsheet of the first 25 rows is a trap.
   --------------------------------------------------------------------------- */

if (($_GET['export'] ?? '') === 'csv') {
    $rows = db_all(
        'SELECT id, created_at, full_name, email, phone, employee_no, department,
                line_manager, course_title, status, message
           FROM registrations' . $sqlWhere . ' ORDER BY id DESC',
        $params
    );

    audit('registrations.exported', 'registrations', null,
          count($rows) . ' rows, filter: ' . ($q !== '' ? 'q=' . $q . ' ' : '')
          . ($status !== '' ? 'status=' . $status : 'all'));

    $name = 'sps-registrations-' . gmdate('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');

    $out = fopen('php://output', 'w');
    // Excel reads a UTF-8 CSV as the local codepage unless it sees a BOM, which
    // is how "Renée" becomes "RenÃ©e" in a file that is perfectly correct.
    fwrite($out, "\xEF\xBB\xBF");

    /* The fifth argument — $escape — is passed explicitly and empty on purpose.
       PHP's historical default is a backslash escape that is not part of the CSV
       format and that Excel does not understand, and from PHP 8.4 leaving it out
       raises a deprecation notice. On a page that is streaming a file, a notice
       is not a log line: it is written into the download, and the spreadsheet
       arrives with PHP warnings in the first cells. */
    $put = static function ($handle, array $row): void {
        fputcsv($handle, $row, ',', '"', '');
    };

    $put($out, ['ID', 'Received (UTC)', 'Name', 'Email', 'Phone', 'Employee no',
                'Department', 'Line manager', 'Course', 'Status', 'Message']);
    foreach ($rows as $r) $put($out, array_values($r));
    fclose($out);
    exit;
}

$rows = db_all(
    'SELECT * FROM registrations' . $sqlWhere . ' ORDER BY id DESC LIMIT ' . PER_PAGE
    . ' OFFSET ' . (($page - 1) * PER_PAGE),
    $params
);

audit('registrations.viewed', 'registrations', null,
      count($rows) . ' of ' . $total . ' shown');

$counts = [];
foreach (db_all('SELECT status, COUNT(*) AS n FROM registrations WHERE tenant_id = ? GROUP BY status',
                [tenant_id()]) as $r) {
    $counts[$r['status']] = (int) $r['n'];
}

/* What each already-linked learner is enrolled on.
 *
 * One query for the whole page rather than one per row. Twenty-five extra
 * queries would not be noticed on this table today, and would be noticed on the
 * day somebody exports a year of registrations. */
$enrolByUser = [];
$userIds = array_values(array_unique(array_filter(array_map(
    fn($r) => (int) ($r['user_id'] ?? 0), $rows
))));
if ($userIds) {
    $in = implode(',', array_fill(0, count($userIds), '?'));
    /* db_optional: this table arrives with a release, and the release lands
       before the migration is run. A missing table must not take down the page
       Kgomotso reads every day — see the note in lib/db.php. */
    foreach (db_optional(fn() => db_all(
        'SELECT user_id, course_slug, course_title, status FROM enrolments
          WHERE tenant_id = ? AND user_id IN (' . $in . ') ORDER BY enrolled_at',
        array_merge([tenant_id()], $userIds)
    ), []) as $en) {
        $enrolByUser[(int) $en['user_id']][] = $en;
    }
}

/**
 * Which course to preselect in the Enrol box.
 *
 * The registration carries free text — whatever was typed into the form, or
 * whatever the Skills Gap tool put there — so this is a guess, and it is only a
 * guess: the administrator sees the selection and can change it before pressing
 * anything. Falls back to the short-course entry, which keeps the learner's own
 * wording rather than filing them under a qualification nobody chose.
 */
function suggest_course(?string $registeredTitle): string
{
    $t = mb_strtolower(trim((string) $registeredTitle));
    if ($t === '') return 'short-course';
    foreach (learner_catalogue() as $slug => $c) {
        if ($c['title'] === null) continue;
        // Compare on the distinctive half — "Occupational Certificate:" is on
        // both of them and matches everything.
        $key = mb_strtolower(trim(substr((string) $c['title'], strpos((string) $c['title'], ':') !== false
            ? (int) strpos((string) $c['title'], ':') + 1 : 0)));
        if ($key !== '' && str_contains($t, $key)) return $slug;
    }
    return 'short-course';
}

/** Keep the current filter when building a link. */
function qs(array $over = []): string
{
    $base = array_filter([
        'q'      => $_GET['q']      ?? '',
        'status' => $_GET['status'] ?? '',
        'page'   => $_GET['page']   ?? '',
    ], fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query(array_merge($base, $over));
}

function when(string $utc): string
{
    // Stored in UTC, read in Johannesburg. Two hours is not a rounding error
    // when someone is checking whether a registration came in before a meeting.
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
<title>Registrations — <?= e(brand('academy')) ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= e(asset('styles.css')) ?>">
</head>
<body>
<?php chrome_nav('admin', ['active' => 'admin', 'name' => $me['first_name']]); ?>

<section class="section page-top">
  <div class="wrap">

    <div class="adm-head">
      <div>
        <span class="eyebrow">Academy administration</span>
        <h2>Registrations</h2>
      </div>
      <a class="btn btn-ghost" href="<?= e(qs(['export' => 'csv'])) ?>">Download as CSV</a>
    </div>

    <?php if (db_schema_incomplete()): ?>
      <p class="form-err" role="alert"><?= e(db_schema_notice()) ?></p>
    <?php endif; ?>

    <?php if ($notice !== ''): ?>
      <p class="adm-notice" role="status"><?= e($notice) ?></p>
    <?php endif; ?>

    <?php if ($fresh !== null): ?>
      <?php /* Shown once, on this response only. There is no way to get it back
               — the database holds a hash, not a password — and the panel says
               so rather than letting somebody discover it tomorrow. */ ?>
      <div class="adm-creds" role="alert">
        <h3>New sign-in details for <?= e($fresh['name']) ?></h3>
        <p>Write these down or give them to <?= e($fresh['name']) ?> now.
          <strong>This password is not shown again</strong> — if it is lost, the
          account has to be given a new one.</p>
        <dl>
          <dt>Where</dt><dd><?= e('https://' . ($_SERVER['HTTP_HOST'] ?? 'centenarynetworks.com') . app_base_path() . 'login') ?></dd>
          <dt>Email</dt><dd><?= e($fresh['email']) ?></dd>
          <dt>Password</dt><dd class="adm-pass"><?= e($fresh['password']) ?></dd>
        </dl>
        <p class="adm-creds-note">Give it to them in person or over the phone rather than by
          email — email is the one channel we cannot vouch for until the domain's SPF record
          is set. Ask them to change it once they are in: there is a <strong>Change
          password</strong> box on their dashboard. If it is ever forgotten, they have to
          come back to you, because there is no self-service reset yet.</p>
      </div>
    <?php endif; ?>

    <div class="adm-tabs">
      <a href="<?= e(qs(['status' => '', 'page' => 1])) ?>"<?= $status === '' ? ' class="on"' : '' ?>>
        All <span><?= array_sum($counts) ?></span></a>
      <?php foreach (STATUSES as $s): ?>
        <a href="<?= e(qs(['status' => $s, 'page' => 1])) ?>"<?= $status === $s ? ' class="on"' : '' ?>>
          <?= e(ucfirst($s)) ?> <span><?= (int) ($counts[$s] ?? 0) ?></span></a>
      <?php endforeach; ?>
    </div>

    <form class="adm-search" method="GET">
      <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, email, employee number or course">
      <button type="submit" class="btn btn-primary">Search</button>
      <?php if ($q !== ''): ?><a class="adm-clear" href="<?= e(qs(['q' => '', 'page' => 1])) ?>">Clear</a><?php endif; ?>
    </form>

    <?php if (!$rows): ?>
      <p class="adm-empty">
        <?= $total === 0 && $q === '' && $status === ''
              ? 'No registrations yet. They will appear here the moment someone sends the form on the Contact page.'
              : 'Nothing matches that filter.' ?>
      </p>
    <?php else: ?>
      <div class="adm-scroll">
      <table class="adm-table">
        <thead><tr>
          <th>#</th><th>Received</th><th>Name</th><th>Contact</th>
          <th>Course</th><th>Status</th><th>Academy account</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="adm-id"><?= (int) $r['id'] ?></td>
            <td class="adm-when"><?= e(when($r['created_at'])) ?></td>
            <td>
              <strong><?= e($r['full_name']) ?></strong>
              <?php if ($r['employee_no']): ?><span class="adm-sub"><?= e($r['employee_no']) ?></span><?php endif; ?>
              <?php if ($r['department']): ?><span class="adm-sub"><?= e($r['department']) ?></span><?php endif; ?>
              <?php if ($r['line_manager']): ?><span class="adm-sub">Manager: <?= e($r['line_manager']) ?></span><?php endif; ?>
            </td>
            <td>
              <a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a>
              <?php if ($r['phone']): ?><span class="adm-sub"><a href="tel:<?= e($r['phone']) ?>"><?= e($r['phone']) ?></a></span><?php endif; ?>
            </td>
            <td>
              <?= $r['course_title'] ? e($r['course_title']) : '<span class="adm-none">not specified</span>' ?>
              <?php if ($r['message']): ?><span class="adm-msg"><?= e($r['message']) ?></span><?php endif; ?>
            </td>
            <td>
              <form method="POST" class="adm-status">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <select name="status" onchange="this.form.submit()" aria-label="Status of registration <?= (int) $r['id'] ?>">
                  <?php foreach (STATUSES as $s): ?>
                    <option value="<?= e($s) ?>"<?= $r['status'] === $s ? ' selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                  <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="btn btn-ghost">Save</button></noscript>
              </form>
              <span class="adm-sub">Delete after <?= e((string) $r['purge_after']) ?></span>
            </td>
            <td>
              <?php $mine = $enrolByUser[(int) ($r['user_id'] ?? 0)] ?? []; ?>
              <?php if ($mine): ?>
                <?php foreach ($mine as $en): ?>
                  <span class="adm-enrolled"><?= e((string) $en['course_title']) ?></span>
                <?php endforeach; ?>
              <?php endif; ?>

              <?php if (db_schema_incomplete()): ?>
                <span class="adm-none">unavailable until /setup is run</span>
              <?php else: ?>
              <?php /* Still offered when they already have an account: the same
                       person can be enrolled on a second qualification, and the
                       enrolment function reuses the account rather than making
                       another one or resetting the password. */ ?>
              <details class="adm-enrol">
                <summary><?= $mine ? 'Enrol on another course' : 'Enrol this person' ?></summary>
                <form method="POST">
                  <?= csrf_field() ?>
                  <input type="hidden" name="a" value="enrol">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <label class="adm-enrol-lbl" for="crs-<?= (int) $r['id'] ?>">Course</label>
                  <select id="crs-<?= (int) $r['id'] ?>" name="course">
                    <?php $sug = suggest_course($r['course_title'] ?? null); ?>
                    <?php foreach (learner_catalogue() as $slug => $c): ?>
                      <option value="<?= e($slug) ?>"<?= $slug === $sug ? ' selected' : '' ?>>
                        <?= e($c['title'] ?? ('Short course — ' . (($r['course_title'] ?? '') !== ''
                              ? (string) $r['course_title'] : 'title to be confirmed'))) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <?php if (!$mine): ?>
                    <?php /* Only meaningful when a NEW account is about to be made —
                             enrolling someone already signed up never touches their
                             password, so there is nothing here to choose between. */ ?>
                    <fieldset class="adm-delivery">
                      <legend>How should they get their sign-in?</legend>
                      <label>
                        <input type="radio" name="delivery" value="show" checked>
                        Show the password here — hand it over in person or by phone
                      </label>
                      <label>
                        <input type="radio" name="delivery" value="invite">
                        Email them a link to set their own password
                      </label>
                      <p class="adm-sub">The email route depends on mail the domain's SPF
                        record does not yet authorise this server to send — it may be filed
                        as spam, or not arrive. If sending fails outright, the notice above
                        the table will say so; the account still exists, so set a password
                        for them from the <a href="admin-users">Accounts page</a> rather than
                        pressing Enrol again.</p>
                    </fieldset>
                  <?php endif; ?>
                  <button type="submit" class="btn btn-primary">
                    <?= $mine ? 'Enrol' : 'Create account and enrol' ?></button>
                  <?php if ($mine): ?>
                    <span class="adm-sub">Adds this course to their existing account. Their
                      password is not affected.</span>
                  <?php endif; ?>
                </form>
              </details>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <?php if ($pages > 1): ?>
        <div class="adm-pages">
          <?php if ($page > 1): ?><a href="<?= e(qs(['page' => $page - 1])) ?>">← Newer</a><?php endif; ?>
          <span>Page <?= $page ?> of <?= $pages ?> · <?= $total ?> registrations</span>
          <?php if ($page < $pages): ?><a href="<?= e(qs(['page' => $page + 1])) ?>">Older →</a><?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <p class="adm-foot">These are people's personal details. Take out of here only what you
      need for the job in front of you, and delete any spreadsheet you export when you are
      finished with it — a copy on a laptop is outside everything this system does to protect
      it. Every view and every export on this page is logged.</p>

  </div>
</section>
<script src="<?= e(asset('site.js')) ?>"></script>
</body></html>
