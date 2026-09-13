<?php
declare(strict_types=1);

/* Creating the tables and the tenant rows.
 *
 * Shared by tools/migrate.php (command line, used in development) and setup.php
 * (browser, used on the server). Both exist because Xneelo's shared hosting
 * answers port 22 with "SSH-2.0-FTP Service" — an SFTP-only daemon with no
 * shell — so there is no way to run a PHP script on the server from a command
 * line. The install has to happen through a browser, and this file is the part
 * that must behave identically either way.
 */

defined('APP_BOOTED') or exit('lib/install.php is not a page.');

/** Does a table exist? Portable across MySQL and SQLite by simply asking it. */
function install_table_exists(string $table): bool
{
    try {
        db()->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Every table the schema defines, so that install_missing_tables() and
 * phpcheck.php can both say honestly whether an installation is complete.
 *
 * KEEP THIS IN STEP WITH schema/schema.*.sql. A table left off does not fail
 * loudly: install_apply_schema() creates it anyway, because it just executes the
 * schema file — but nothing then REPORTS it as missing, so a site without it
 * looks healthy while the feature that needs it is quietly broken. `materials`
 * was off this list until 8 Sep 2026 for exactly that reason; it exists on the
 * installations that have it only because they were built before it was
 * dropped. tools/migrate.php --check compares the two schema files with each
 * other; nothing was comparing either of them with this list.
 */
function install_tables(): array
{
    return ['tenants', 'users', 'registrations', 'consents', 'audit_log',
            'password_resets', 'account_invites', 'enrolments', 'learner_progress',
            'progress_reports', 'materials', 'material_files', 'quizzes',
            'quiz_questions', 'quiz_choices', 'quiz_attempts',
            'quiz_attempt_answers', 'topic_sections', 'letters_sent', 'trainer_courses',
            'classes', 'class_attendance'];
}

/** Which of the expected tables are not there yet. */
function install_missing_tables(): array
{
    return array_values(array_filter(install_tables(), fn($t) => !install_table_exists($t)));
}

/**
 * Apply the schema for the configured driver.
 *
 * Every statement is CREATE TABLE IF NOT EXISTS / CREATE INDEX IF NOT EXISTS, so
 * running this twice is not destructive and not an error. There is deliberately
 * nothing in the schema files that drops anything — an installer that can
 * destroy data is an installer somebody will eventually run on the wrong day.
 */
function install_apply_schema(): int
{
    $driver = db_driver() === 'sqlite' ? 'sqlite' : 'mysql';
    $file   = APP_ROOT . '/schema/schema.' . $driver . '.sql';

    $sql = file_get_contents($file);
    if ($sql === false) app_fail('Cannot read ' . $file);

    $sql = preg_replace('/^\s*--.*$/m', '', $sql);

    $applied = 0;
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        db()->exec($statement);
        $applied++;
    }
    return $applied;
}

/**
 * Seed the four companies.
 *
 * All four, on every installation, not just the one this site serves. The
 * tenants table describes the platform rather than the installation, so
 * standing up Fungi later is a configuration change and not a migration.
 * Existing rows are left alone.
 */
function install_seed_tenants(?string $contact = null): int
{
    $contact ??= (string) (app_config('notify_email') ?? 'kgomotso@centenarynetworks.com');

    $seed = [
        ['sps',     'SPS — Sustainable Power Solutions', 'SPS Academy'],
        ['fungi',   'Fungi Utilities (Pty) Ltd',         'Fungi Academy'],
        ['equinix', 'Equinix',                           'Equinix Academy'],
        ['maziv',   'Maziv',                             'Maziv Academy'],
        /* Tracker is the fifth, added 24 Aug 2026 while it was still being
           pitched. Seeding it costs nothing and is the whole point of the
           tenants table describing the platform rather than the installation:
           if it is sold, standing the site up is configuration, not a
           migration. If it is not, an unused row does no harm. */
        ['tracker', 'Tracker',                           'Tracker Academy'],
        /* M&T Development, the sixth, stood up 12 Sep 2026. Seeded everywhere
           for the same reason as Tracker: its row is part of the platform, so
           the M&T server's own /setup creates it and standing the site up
           needs no separate data step. The slug is "mt" — no ampersand, since
           it is compared against the 'tenant' line in a config file and has no
           business carrying a character that needs escaping. */
        ['mt',      'M&T Development',                   'M&T Academy'],
        /* Cricket South Africa, the seventh, stood up 13 Sep 2026. Seeded
           everywhere for the same reason as the rest. Not to be confused with
           the Cricket World Cup volunteer portal Centenary also built for them,
           which is a separate application with its own database. */
        ['cricketsa', 'Cricket South Africa',            'Cricket SA Academy'],
    ];

    $added = 0;
    foreach ($seed as [$slug, $name, $academy]) {
        if (db_value('SELECT id FROM tenants WHERE slug = ?', [$slug]) !== null) continue;
        db_insert('tenants', [
            'slug'          => $slug,
            'name'          => $name,
            'academy_name'  => $academy,
            'contact_email' => $contact,
            'created_at'    => now(),
        ]);
        $added++;
    }
    return $added;
}

/** How many administrators this tenant already has. */
function install_admin_count(): int
{
    if (!install_table_exists('users')) return 0;
    return (int) db_value(
        'SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role = ?',
        [tenant_id(), 'admin']
    );
}

/**
 * A password that can be read off a screen and typed correctly.
 *
 * Four blocks from a 32-character alphabet with no l/1 and no o/0 is about 80
 * bits — far past anything the fifteen-minute lockout in lib/auth.php would
 * let through, and still transcribable over a phone call.
 */
function install_readable_password(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $out = [];
    for ($block = 0; $block < 4; $block++) {
        $s = '';
        for ($i = 0; $i < 4; $i++) $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $out[] = $s;
    }
    return implode('-', $out);
}
