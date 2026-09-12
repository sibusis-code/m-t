<?php
declare(strict_types=1);

/* Course registrations: validate, then store.
 *
 * Kept out of the page so it can be exercised directly by tools/selftest.php
 * without a browser, and so the rules are in one readable list rather than
 * spread through markup.
 */

defined('APP_BOOTED') or exit('lib/registration.php is not a page.');

/* How long an unconverted registration is kept.
 *
 * POPIA s14: no longer than is necessary for the purpose. The purpose here is
 * "HR contacts this person about an intake". Twelve months covers the next
 * intake and the one after it, and is short enough to be defensible in writing.
 * Registrations that become enrolments are a different matter — the QCTO
 * obliges us to retain those records — so that basis attaches to the enrolment,
 * not to this row. */
const REGISTRATION_RETENTION_MONTHS = 12;

/** Refuse more than this many registrations per hour from one source. */
const REGISTRATION_RATE_LIMIT = 8;

/**
 * @return array{0: array<string,mixed>, 1: array<string,string>} [clean, errors]
 */
function registration_validate(array $in): array
{
    $clean = [
        'full_name'    => trim((string) ($in['full_name']    ?? '')),
        'email'        => trim((string) ($in['email']        ?? '')),
        'phone'        => trim((string) ($in['phone']        ?? '')),
        'employee_no'  => trim((string) ($in['employee_no']  ?? '')),
        'department'   => trim((string) ($in['department']   ?? '')),
        'line_manager' => trim((string) ($in['line_manager'] ?? '')),
        'course_title' => trim((string) ($in['course_title'] ?? '')),
        'message'      => trim((string) ($in['message']      ?? '')),
        'consent'      => !empty($in['consent']),
    ];

    $errors = [];

    if ($clean['full_name'] === '') {
        $errors['full_name'] = 'Please give us your name.';
    } elseif (mb_strlen($clean['full_name']) > 160) {
        $errors['full_name'] = 'That name is longer than we can store.';
    }

    if ($clean['email'] === '') {
        $errors['email'] = 'We need an email address to reply to.';
    } elseif (!filter_var($clean['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'That does not look like an email address.';
    } elseif (mb_strlen($clean['email']) > 190) {
        $errors['email'] = 'That email address is longer than we can store.';
    }

    // A phone number is optional, but if one is given it should be a number.
    if ($clean['phone'] !== '' && !preg_match('/^[0-9+()\s\-]{6,40}$/', $clean['phone'])) {
        $errors['phone'] = 'Please use digits, spaces, + and - only.';
    }

    /* The consent tick is a hard requirement, not a nicety. Without a record of
       what this person agreed to, we are holding their information on a basis we
       cannot evidence — which is the thing POPIA is actually about. */
    if (!$clean['consent']) {
        $errors['consent'] = 'Please confirm you have read how we handle your information.';
    }

    foreach (['phone' => 40, 'employee_no' => 40, 'department' => 120,
              'line_manager' => 160, 'course_title' => 190, 'message' => 4000] as $f => $max) {
        $clean[$f] = mb_substr($clean[$f], 0, $max);
    }

    return [$clean, $errors];
}

/** True when this source has submitted too many registrations in the last hour. */
function registration_rate_limited(): bool
{
    $since = gmdate('Y-m-d H:i:s', time() - 3600);
    $n = (int) db_value(
        'SELECT COUNT(*) FROM registrations
          WHERE tenant_id = ? AND ip_hash = ? AND created_at > ?',
        [tenant_id(), client_ip_hash(), $since]
    );
    return $n >= REGISTRATION_RATE_LIMIT;
}

/**
 * True when this looks like the same form sent twice — a double-click, or a
 * refresh on the POST. Returns the existing row's id so the visitor still gets
 * the thank-you page rather than an error for something that worked.
 */
function registration_duplicate_id(array $clean): ?int
{
    $since = gmdate('Y-m-d H:i:s', time() - 600);
    $id = db_value(
        'SELECT id FROM registrations
          WHERE tenant_id = ? AND email = ? AND created_at > ?
          ORDER BY id DESC',
        [tenant_id(), $clean['email'], $since]
    );
    return $id === null ? null : (int) $id;
}

/**
 * Write the registration and its consent record as one unit.
 *
 * In a transaction because a registration without its consent row is exactly
 * the state we must never be in: personal information held with no evidence of
 * the basis for holding it.
 */
function registration_store(array $clean, string $source = 'web'): int
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $ip = client_ip_hash();

        $id = db_insert('registrations', [
            'tenant_id'    => tenant_id(),
            'user_id'      => current_user_id(),
            'course_slug'  => null,
            'course_title' => $clean['course_title'] !== '' ? $clean['course_title'] : null,
            'full_name'    => $clean['full_name'],
            'email'        => $clean['email'],
            'phone'        => $clean['phone']        !== '' ? $clean['phone']        : null,
            'employee_no'  => $clean['employee_no']  !== '' ? $clean['employee_no']  : null,
            'department'   => $clean['department']   !== '' ? $clean['department']   : null,
            'line_manager' => $clean['line_manager'] !== '' ? $clean['line_manager'] : null,
            'message'      => $clean['message']      !== '' ? $clean['message']      : null,
            'status'       => 'new',
            'source'       => $source,
            'ip_hash'      => $ip,
            'user_agent'   => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'created_at'   => now(),
            'purge_after'  => gmdate('Y-m-d', strtotime('+' . REGISTRATION_RETENTION_MONTHS . ' months')),
        ]);

        db_insert('consents', [
            'tenant_id'       => tenant_id(),
            'registration_id' => $id,
            'user_id'         => current_user_id(),
            'purpose'         => 'course_registration',
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

    // Outside the transaction: the audit row describes something that has
    // already happened, and must not be able to roll it back.
    audit('registration.created', 'registrations', $id,
          'course: ' . ($clean['course_title'] !== '' ? $clean['course_title'] : 'not specified'));

    return $id;
}

/** The notification body. No more information than HR needs to act on it. */
function registration_notify(array $clean, int $id): void
{
    $t    = tenant();
    $lines = [
        'A new course registration has come in through ' . ($t['academy_name'] ?? 'the academy') . '.',
        '',
        'Name           : ' . $clean['full_name'],
        'Email          : ' . $clean['email'],
        'Phone          : ' . ($clean['phone'] ?: '—'),
        'Employee no    : ' . ($clean['employee_no'] ?: '—'),
        'Department     : ' . ($clean['department'] ?: '—'),
        'Line manager   : ' . ($clean['line_manager'] ?: '—'),
        'Course         : ' . ($clean['course_title'] ?: 'not specified'),
        '',
        'Message:',
        $clean['message'] !== '' ? $clean['message'] : '(none)',
        '',
        '— Registration #' . $id . '. This email is a notification; the record is',
        'held in the academy database and can be viewed there.',
    ];

    notify(
        ($t['academy_name'] ?? 'Academy') . ' — new registration #' . $id,
        implode("\n", $lines),
        $clean['email']
    );
}
