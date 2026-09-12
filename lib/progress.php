<?php
declare(strict_types=1);

/* Learner progress reports.
 *
 * The same shape as lib/registration.php and for the same reason — this used to
 * post to formsubmit.co, which meant a report saying how far behind an employee
 * is left the country before anyone at Centenary read it.
 *
 * One difference worth naming: a progress report against an accredited
 * qualification is part of the learner record the QCTO obliges us to keep, so it
 * does not get the twelve-month expiry that registrations do. See the note on
 * the table in schema/schema.mysql.sql.
 */

defined('APP_BOOTED') or exit('lib/progress.php is not a page.');

const PROGRESS_RATE_LIMIT = 10;   // per hour, per source

/**
 * @return array{0: array<string,mixed>, 1: array<string,string>} [clean, errors]
 */
function progress_validate(array $in): array
{
    $clean = [
        'full_name'     => trim((string) ($in['full_name']     ?? '')),
        'email'         => trim((string) ($in['email']         ?? '')),
        'employee_no'   => trim((string) ($in['employee_no']   ?? '')),
        'line_manager'  => trim((string) ($in['line_manager']  ?? '')),
        'qualification' => trim((string) ($in['qualification'] ?? '')),
        'summary'       => trim((string) ($in['summary']       ?? '')),
        'detail'        => trim((string) ($in['detail']        ?? '')),
        'message'       => trim((string) ($in['message']       ?? '')),
        'consent'       => !empty($in['consent']),
    ];

    $errors = [];

    if ($clean['full_name'] === '') {
        $errors['full_name'] = 'Please give us your name.';
    }
    if ($clean['email'] === '') {
        $errors['email'] = 'We need an email address to reply to.';
    } elseif (!filter_var($clean['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'That does not look like an email address.';
    }
    if (!$clean['consent']) {
        $errors['consent'] = 'Please confirm you have read how we handle your information.';
    }

    foreach (['full_name' => 160, 'email' => 190, 'employee_no' => 40, 'line_manager' => 160,
              'qualification' => 190, 'summary' => 500, 'detail' => 20000,
              'message' => 4000] as $f => $max) {
        $clean[$f] = mb_substr($clean[$f], 0, $max);
    }

    return [$clean, $errors];
}

function progress_rate_limited(): bool
{
    $since = gmdate('Y-m-d H:i:s', time() - 3600);
    return (int) db_value(
        'SELECT COUNT(*) FROM progress_reports
          WHERE tenant_id = ? AND ip_hash = ? AND created_at > ?',
        [tenant_id(), client_ip_hash(), $since]
    ) >= PROGRESS_RATE_LIMIT;
}

/**
 * A progress report sent twice within a few minutes is a double click, not a
 * second month of study. Returns the existing row so the learner still sees the
 * thank-you page rather than an error for something that worked.
 */
function progress_duplicate_id(array $clean): ?int
{
    $since = gmdate('Y-m-d H:i:s', time() - 600);
    $id = db_value(
        'SELECT id FROM progress_reports
          WHERE tenant_id = ? AND email = ? AND created_at > ?
          ORDER BY id DESC',
        [tenant_id(), $clean['email'], $since]
    );
    return $id === null ? null : (int) $id;
}

function progress_store(array $clean): int
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $ip = client_ip_hash();

        $id = db_insert('progress_reports', [
            'tenant_id'     => tenant_id(),
            'user_id'       => current_user_id(),
            'full_name'     => $clean['full_name'],
            'email'         => $clean['email'],
            'employee_no'   => $clean['employee_no']   !== '' ? $clean['employee_no']   : null,
            'line_manager'  => $clean['line_manager']  !== '' ? $clean['line_manager']  : null,
            'qualification' => $clean['qualification'] !== '' ? $clean['qualification'] : null,
            'summary'       => $clean['summary']       !== '' ? $clean['summary']       : null,
            'detail'        => $clean['detail']        !== '' ? $clean['detail']        : null,
            'message'       => $clean['message']       !== '' ? $clean['message']       : null,
            'status'        => 'new',
            'ip_hash'       => $ip,
            'user_agent'    => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'created_at'    => now(),
            'purge_after'   => null,   // QCTO retention — see the schema note
        ]);

        db_insert('consents', [
            'tenant_id'       => tenant_id(),
            'registration_id' => null,
            'user_id'         => current_user_id(),
            'purpose'         => 'progress_report',
            'policy_version'  => (string) (app_config('policy_version') ?? 'unversioned'),
            'granted'         => 1,
            'granted_at'      => now(),
            'ip_hash'         => $ip,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    audit('progress.created', 'progress_reports', $id, $clean['summary'] !== '' ? $clean['summary'] : null);
    return $id;
}

function progress_notify(array $clean, int $id): void
{
    $t = tenant();
    $lines = [
        'A learner has sent in a progress report.',
        '',
        'Name         : ' . $clean['full_name'],
        'Email        : ' . $clean['email'],
        'Employee no  : ' . ($clean['employee_no'] ?: '—'),
        'Line manager : ' . ($clean['line_manager'] ?: '—'),
        'Qualification: ' . ($clean['qualification'] ?: '—'),
        '',
        'Where they are:',
        $clean['summary'] !== '' ? $clean['summary'] : '(not given)',
        '',
        'Their note:',
        $clean['message'] !== '' ? $clean['message'] : '(none)',
        '',
        '— Report #' . $id . '. The full module-by-module detail is on the report',
        'in the academy database; this email is only a notification.',
    ];

    notify(
        ($t['academy_name'] ?? 'Academy') . ' — progress report #' . $id . ' from ' . $clean['full_name'],
        implode("\n", $lines),
        $clean['email']
    );
}
