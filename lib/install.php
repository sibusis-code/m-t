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
            'classes', 'class_attendance',
            'course_links', 'class_links', 'logbook_entries',
            'poe_submissions', 'quiz_attempt_grants'];
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

    $applied = 0;
    foreach (install_sql_statements($sql) as $statement) {
        /* The file's own contract, checked rather than trusted. Everything in
           it creates; nothing drops, alters or writes rows. A fragment that is
           not a CREATE means the splitter or the file is wrong, and stopping
           here with the fragment in hand beats handing the database something
           nobody wrote. */
        if (!preg_match('/^CREATE\b/i', $statement)) {
            app_fail('Refusing to run a schema statement that is not a CREATE: '
                   . substr(preg_replace('/\s+/', ' ', $statement), 0, 120));
        }
        db()->exec($statement);
        $applied++;
    }
    return $applied;
}

/**
 * Split a schema file into statements.
 *
 * WHY THIS IS NOT explode(';').
 *
 * It was, over a file stripped of whole-line `--` comments — and a TRAILING
 * comment was left in place, semicolon and all:
 *
 *     kind VARCHAR(20) NOT NULL,   -- 'classroom' today; see COURSE_LINK_KINDS
 *
 * The split landed inside that comment and cut the CREATE in half, so MySQL was
 * handed `... NOT NULL, -- 'classroom' today` and then `see COURSE_LINK_KINDS
 * url VARCHAR(500) ...`. Both are syntax errors, setup.php died on the first
 * one, and the browser got a 500 with nothing to go on. Three of the tables
 * added on 28 Sep 2026 carried such a comment; SQLite's schema did not, so
 * every local run passed and only the live MySQL install failed.
 *
 * This is the third time a semicolon inside human text has broken a naive scan
 * in this codebase (M&amp;T's entity in the workflow guards, twice). So this
 * walks the file rather than pattern-matching it: quoted strings and both
 * comment styles are consumed whole, and only a `;` at top level ends a
 * statement. A comment may now say whatever it likes.
 *
 * @return string[] statements, trimmed, comments removed, no empties
 */
function install_sql_statements(string $sql): array
{
    $out = [];
    $buf = '';
    $n   = strlen($sql);
    $i   = 0;

    while ($i < $n) {
        $c   = $sql[$i];
        $two = substr($sql, $i, 2);

        /* `--` starts a comment only when whitespace or the end of the line
           follows it, which is MySQL's own rule and leaves `a--b` as
           arithmetic rather than swallowing the rest of the line. */
        if ($two === '--' && ($i + 2 >= $n || ctype_space($sql[$i + 2]))) {
            $j    = strpos($sql, "\n", $i);
            $i    = $j === false ? $n : $j + 1;
            $buf .= "\n";
            continue;
        }
        if ($two === '/*') {
            $j    = strpos($sql, '*/', $i + 2);
            $i    = $j === false ? $n : $j + 2;
            $buf .= ' ';
            continue;
        }
        /* Quoted text is copied through untouched — a `;` or a `--` inside a
           string literal or a backticked identifier is data, not syntax. */
        if ($c === "'" || $c === '"' || $c === '`') {
            $q    = $c;
            $buf .= $c;
            $i++;
            while ($i < $n) {
                $ch = $sql[$i];
                if ($ch === '\\' && $q !== '`' && $i + 1 < $n) {   // \' and \"
                    $buf .= substr($sql, $i, 2);
                    $i   += 2;
                    continue;
                }
                $buf .= $ch;
                $i++;
                if ($ch === $q) {
                    if ($i < $n && $sql[$i] === $q) {   // '' and `` double up
                        $buf .= $q;
                        $i++;
                        continue;
                    }
                    break;
                }
            }
            continue;
        }
        if ($c === ';') {
            $out[] = $buf;
            $buf   = '';
            $i++;
            continue;
        }
        $buf .= $c;
        $i++;
    }
    $out[] = $buf;

    return array_values(array_filter(array_map('trim', $out), fn($s) => $s !== ''));
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
        /* Inhance Supply Chain Solutions, the eighth, started 25 Sep 2026 after
           Kgomotso introduced them as a white-label prospect. Seeded everywhere
           for the same reason as Tracker: the tenants table describes the
           platform, not the installation, so if it is sold, standing the site up
           is configuration rather than a migration. */
        ['inhance', 'Inhance Supply Chain Solutions',    'Inhance Academy'],
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
