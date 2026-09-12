<?php
/* A deliberately tiny preflight check.
 *
 * Its whole job is to answer one question before anything else is investigated:
 * does PHP run on this server, and can it reach its configuration and database?
 * It depends on nothing — not lib/, not the configuration file, not the
 * database — so if this page works and the rest of the site does not, the fault
 * is ours; and if this page does not work either, the fault is the hosting and
 * no amount of editing our code will help.
 *
 * WHEN IT IS VISIBLE, and why it is not simply deleted after an install:
 *
 *   - No configuration found anywhere -> it runs. The site cannot work in that
 *     state, so there is nothing to protect and everything to diagnose.
 *   - Configuration found, setup_token set -> it runs. You are installing.
 *   - Configuration found, setup_token empty -> 404, exactly like setup.php.
 *
 * The alternative was "remember to delete it", which works until the fourth
 * academy is deployed by somebody in a hurry. What it prints — server paths,
 * open_basedir, PHP version, which credentials are filled in — is ordinary
 * reconnaissance material, so on a working site it is not available at all.
 *
 * Deliberately NOT phpinfo(). That publishes paths, module lists and
 * environment variables wholesale.
 */
declare(strict_types=1);

$appRoot = __DIR__;
$docroot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');

/* The account's home directory. On this hosting public_html is a SYMLINK, so
   the directory an SFTP client shows on login and the directory PHP resolves
   are not the same place — which is how a configuration file ends up sitting in
   plain view somewhere the application will never look. */
$home = (string) (getenv('HOME') ?: '');
if ($home === '' && function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
    $pw = @posix_getpwuid(posix_getuid());
    $home = (string) ($pw['dir'] ?? '');
}

/* The same candidate list as lib/bootstrap.php, in the same order, and it must
   stay that way — a preflight check that disagrees with the thing it is
   checking is worse than no check. */
/* The filename is derived from the directory this installation sits in, so
   that four academies sharing one hosting account cannot load each other's
   configuration — see the long note in lib/bootstrap.php. sps-config.php is
   accepted only for the SPS directory, because that installation already
   exists with a file of that name. */
$appDir = basename($appRoot);
$names  = [];
if ((bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,39}$/', $appDir)) {
    $names[] = $appDir . '-config.php';
}
if ($appDir === 'sps' || $appDir === 'spsacademy') {
    $names[] = 'sps-config.php';
}
if (!$names) $names[] = 'academy-config.php';

$candidates = [];
if ($home !== '') {
    foreach ($names as $n) {
        $candidates[] = rtrim($home, '/') . '/private/' . $n;
        $candidates[] = rtrim($home, '/') . '/' . $n;
    }
}
$dir = $appRoot;
for ($i = 0; $i < 4; $i++) {
    $dir = dirname($dir);
    if ($dir === '' || $dir === '.' || $dir === dirname($dir)) break;
    foreach ($names as $n) {
        $candidates[] = $dir . '/private/' . $n;
        $candidates[] = $dir . '/' . $n;
    }
}

$inWebRoot = static function (string $path) use ($docroot): bool {
    if ($docroot === '') return false;
    return str_starts_with(
        str_replace('\\', '/', (string) (realpath($path) ?: $path)),
        rtrim(str_replace('\\', '/', (string) (realpath($docroot) ?: $docroot)), '/') . '/'
    );
};

$found = null;
foreach ($candidates as $c) {
    if (is_file($c) && !$inWebRoot($c)) { $found = $c; break; }
}

/* The gate. Worked out before a single byte is printed. */
$cfg = $found !== null ? @include $found : null;
if (is_array($cfg) && (string) ($cfg['setup_token'] ?? '') === '') {
    http_response_code(404);
    exit('Not found.');
}

header('Content-Type: text/plain; charset=utf-8');

$need = ['pdo', 'pdo_mysql', 'mbstring', 'session', 'json', 'fileinfo'];

echo "PHP is running.\n\n";
printf("  version        %s\n", PHP_VERSION);
printf("  interface      %s\n", PHP_SAPI);
printf("  version is ok  %s\n", PHP_VERSION_ID >= 80000 ? 'yes' : 'NO — this site needs PHP 8.0 or newer');

echo "\n  extensions:\n";
$missing  = [];   // PHP extensions
$problems = [];   // anything else that would stop the site working
foreach ($need as $e) {
    $ok = extension_loaded($e);
    if (!$ok) $missing[] = $e;
    printf("    %-12s %s\n", $e, $ok ? 'yes' : 'MISSING');
}

echo "\n  paths as PHP resolves them:\n";
printf("    this file          %s\n", $appRoot);
printf("    document root      %s\n", $docroot !== '' ? $docroot : '(not set)');
printf("    account home       %s\n", $home !== '' ? $home : '(cannot determine)');
printf("    open_basedir       %s\n", ini_get('open_basedir') ?: '(not set)');

echo "\n  configuration:\n";
foreach ($candidates as $c) {
    printf("    %-56s %s\n", $c,
        !is_file($c) ? 'not there'
            : ($inWebRoot($c) ? 'FOUND but INSIDE the web root — refused'
                              : 'found, and outside the web root'));
}
printf("    %s\n", $found ? 'A usable configuration file was found.'
                          : 'No usable configuration file. See DEPLOY-XNEELO.md step 4.');

if (is_array($cfg)) {
    $db = $cfg['db'] ?? [];
    echo "\n  database:\n";
    printf("    driver           %s\n", $db['driver'] ?? '(not set)');
    printf("    database name    %s\n", ($db['name'] ?? '') !== '' ? 'set' : 'NOT SET');
    printf("    user             %s\n", ($db['user'] ?? '') !== '' ? 'set' : 'NOT SET');
    printf("    password         %s\n", ($db['pass'] ?? '') !== '' ? 'set' : 'NOT SET');
    printf("    ip_pepper        %s\n", strlen((string) ($cfg['ip_pepper'] ?? '')) >= 32
        ? 'set' : 'NOT SET or too short — the site will refuse to store anything');

    /* The exception message is never printed: it carries the DSN, and therefore
       the host and database name. The failure is classified instead. */
    try {
        $dsn = ($db['driver'] ?? 'mysql') === 'sqlite'
            ? 'sqlite:' . ($db['path'] ?? '')
            : sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $db['host'] ?? 'localhost', $db['name'] ?? '');
        $pdo = new PDO($dsn, $db['user'] ?? null, $db['pass'] ?? null,
                       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        echo "    connection       CONNECTED\n";
        /* READ FROM THE SCHEMA FILE, not from a list kept here.
         *
         * This page used to carry its own copy, and that copy stopped at the
         * nine tables that existed when it was written. It then reported
         * "tables 9 of 9 present — Nothing is missing" on an installation
         * missing eight of them, which is worse than reporting nothing at all:
         * somebody runs /setup, reads this page, and reasonably concludes the
         * migration worked.
         *
         * Taken from schema/schema.mysql.sql rather than lib/install.php
         * because this page is deliberately standalone — it has to be able to
         * diagnose an installation whose bootstrap or database config is the
         * very thing that is broken, so it cannot require the application. The
         * schema file is the same file /setup executes, so the two cannot
         * drift. */
        $expected  = [];
        $schemaTxt = @file_get_contents(__DIR__ . '/schema/schema.mysql.sql');
        if ($schemaTxt !== false
            && preg_match_all('/CREATE TABLE IF NOT EXISTS\s+([A-Za-z0-9_]+)/i', $schemaTxt, $sm)) {
            $expected = array_values(array_unique($sm[1]));
        }
        if (!$expected) {
            echo "    tables           CANNOT TELL — schema/schema.mysql.sql is missing or"
               . " unreadable, so there is nothing to compare the database against\n";
            $problems[] = 'schema/schema.mysql.sql could not be read, so the table check did not run';
        } else {
            $tables = [];
            foreach ($expected as $t) {
                try { $pdo->query('SELECT 1 FROM ' . $t . ' LIMIT 1'); $tables[] = $t; } catch (Throwable $e) {}
            }
            /* $missingTables, not $missing: $missing is the PHP-extension list
               the closing summary reads, and reusing the name here made this
               page announce that nothing was missing whenever the tables were
               all present, however many extensions were not. */
            $missingTables = array_values(array_diff($expected, $tables));
            printf("    tables           %s\n", $tables
                ? count($tables) . ' of ' . count($expected) . ' present'
                  . ($missingTables ? ' — MISSING: ' . implode(', ', $missingTables) . '; run /setup' : '')
                : 'none yet — run /setup');
            if ($missingTables) $problems[] = count($missingTables) . ' database table(s) missing';
        }
    } catch (Throwable $e) {
        $m = $e->getMessage();
        echo "    connection       FAILED — " . (
              str_contains($m, 'Access denied')    ? 'the username or password is wrong'
            : (str_contains($m, 'Unknown database') ? 'that database does not exist'
            : (str_contains($m, 'No such file') || str_contains($m, 'refused')
                                                    ? 'no database server answered at that host'
                                                    : 'see the site log for the full message'))) . "\n";
    }
}

echo "\n  files:\n";
foreach (['.htaccess', 'lib/bootstrap.php', 'schema/schema.mysql.sql', 'setup.php'] as $f) {
    printf("    %-26s %s\n", $f, is_file($appRoot . '/' . $f) ? 'present' : 'MISSING');
}

echo "\n";
/* Both lists, not just the extensions one. This said "Nothing is missing"
   whenever the extensions were all present — including on an installation with
   eight database tables absent, which is how a migration came to be reported as
   successful when it had not been run. */
if ($missing) {
    echo "Missing extensions must be enabled before the site will work.\n";
}
foreach ($problems as $p) {
    echo "PROBLEM: " . $p . " — run /setup\n";
}
if (!$missing && !$problems) {
    echo "Nothing is missing. Empty setup_token and this page becomes a 404.\n";
}
