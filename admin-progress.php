<?php
declare(strict_types=1);

/* Progress reports, for the academy.
 *
 * Separate from admin.php rather than a tab on it, because the two are read at
 * different times for different reasons: registrations are worked through once
 * and marked off, progress reports are looked at when someone wants to know how
 * a cohort is doing. Sharing one filter state between them would suit neither.
 *
 * Same rules as the registrations list: administrators only, every view audited
 * with the number of records reached, every export audited separately.
 */

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/csrf.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/chrome.php';

$me = require_admin();

const PER_PAGE = 25;

$q    = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

$where  = ['tenant_id = ?'];
$params = [tenant_id()];

if ($q !== '') {
    $where[] = '(full_name LIKE ? OR email LIKE ? OR employee_no LIKE ?)';
    $like    = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}

$sqlWhere = ' WHERE ' . implode(' AND ', $where);
$total    = (int) db_value('SELECT COUNT(*) FROM progress_reports' . $sqlWhere, $params);
$pages    = max(1, (int) ceil($total / PER_PAGE));
$page     = min($page, $pages);

if (($_GET['export'] ?? '') === 'csv') {
    $rows = db_all(
        'SELECT id, created_at, full_name, email, employee_no, line_manager,
                qualification, summary, message, detail
           FROM progress_reports' . $sqlWhere . ' ORDER BY id DESC',
        $params
    );
    audit('progress.exported', 'progress_reports', null, count($rows) . ' rows');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sps-progress-' . gmdate('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    // Explicit empty $escape — see the note in admin.php. A deprecation notice
    // here would be written into the spreadsheet, not into a log.
    $put = static function ($h, array $row): void { fputcsv($h, $row, ',', '"', ''); };
    $put($out, ['ID', 'Received (UTC)', 'Name', 'Email', 'Employee no', 'Line manager',
                'Qualification', 'Where they are', 'Their note', 'Module detail']);
    foreach ($rows as $r) $put($out, array_values($r));
    fclose($out);
    exit;
}

$rows = db_all(
    'SELECT * FROM progress_reports' . $sqlWhere . ' ORDER BY id DESC LIMIT ' . PER_PAGE
    . ' OFFSET ' . (($page - 1) * PER_PAGE),
    $params
);

audit('progress.viewed', 'progress_reports', null, count($rows) . ' of ' . $total . ' shown');

function qs(array $over = []): string
{
    $base = array_filter([
        'q'    => $_GET['q']    ?? '',
        'page' => $_GET['page'] ?? '',
    ], fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query(array_merge($base, $over));
}

function when(string $utc): string
{
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
<title>Progress reports — <?= e(brand('academy')) ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= e(asset('styles.css')) ?>">
</head>
<body>
<?php chrome_nav('admin', ['active' => 'admin-progress', 'name' => $me['first_name']]); ?>

<section class="section page-top">
  <div class="wrap">

    <div class="adm-head">
      <div>
        <span class="eyebrow">Academy administration</span>
        <h2>Progress reports</h2>
      </div>
      <?php if ($total): ?>
        <a class="btn btn-ghost" href="<?= e(qs(['export' => 'csv'])) ?>">Download as CSV</a>
      <?php endif; ?>
    </div>

    <form class="adm-search" method="GET">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, email or employee number">
      <button type="submit" class="btn btn-primary">Search</button>
      <?php if ($q !== ''): ?><a class="adm-clear" href="<?= e(qs(['q' => '', 'page' => 1])) ?>">Clear</a><?php endif; ?>
    </form>

    <?php if (!$rows): ?>
      <p class="adm-empty">
        <?= $total === 0 && $q === ''
              ? 'No progress reports yet. They arrive when a learner uses the "Send it in" form on the Project Manager progress page.'
              : 'Nothing matches that search.' ?>
      </p>
    <?php else: ?>
      <div class="adm-scroll">
      <table class="adm-table">
        <thead><tr>
          <th>#</th><th>Received</th><th>Learner</th><th>Where they are</th><th>Their note</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="adm-id"><?= (int) $r['id'] ?></td>
            <td class="adm-when"><?= e(when($r['created_at'])) ?></td>
            <td>
              <strong><?= e($r['full_name']) ?></strong>
              <span class="adm-sub"><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a></span>
              <?php if ($r['employee_no']): ?><span class="adm-sub"><?= e($r['employee_no']) ?></span><?php endif; ?>
              <?php if ($r['line_manager']): ?><span class="adm-sub">Manager: <?= e($r['line_manager']) ?></span><?php endif; ?>
            </td>
            <td>
              <?= $r['summary'] ? e($r['summary']) : '<span class="adm-none">not given</span>' ?>
              <?php if ($r['detail']): ?>
                <details class="adm-detail">
                  <summary>Module by module</summary>
                  <pre><?= e($r['detail']) ?></pre>
                </details>
              <?php endif; ?>
            </td>
            <td><?= $r['message'] ? e($r['message']) : '<span class="adm-none">—</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <?php if ($pages > 1): ?>
        <div class="adm-pages">
          <?php if ($page > 1): ?><a href="<?= e(qs(['page' => $page - 1])) ?>">← Newer</a><?php endif; ?>
          <span>Page <?= $page ?> of <?= $pages ?> · <?= $total ?> reports</span>
          <?php if ($page < $pages): ?><a href="<?= e(qs(['page' => $page + 1])) ?>">Older →</a><?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <p class="adm-foot">A progress report is part of a learner's record for an accredited
      qualification, so unlike a registration it is not deleted on a timer — the QCTO requires
      it to be kept. Every view and every export on this page is logged.</p>

  </div>
</section>
<script src="<?= e(asset('site.js')) ?>"></script>
</body></html>
