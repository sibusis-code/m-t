<?php
declare(strict_types=1);

/* Database access.
 *
 * MySQL in production; SQLite is supported so the whole application can be run
 * and tested on a laptop with no database server installed. That is not a
 * convenience — it is why this code could be finished and exercised before the
 * subdomains and the MySQL database existed at all.
 *
 * The two are kept honestly interchangeable by writing plain portable SQL:
 * no MySQL-only functions, no backtick quoting, placeholders everywhere.
 */

defined('APP_BOOTED') or exit('lib/db.php is not a page.');

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $cfg    = app_config('db') ?? [];
    $driver = $cfg['driver'] ?? 'mysql';

    if ($driver === 'sqlite') {
        $dsn  = 'sqlite:' . ($cfg['path'] ?? (dirname(APP_ROOT) . '/private/dev.sqlite'));
        $user = null;
        $pass = null;
    } else {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $cfg['host'] ?? 'localhost',
            $cfg['name'] ?? ''
        );
        $user = $cfg['user'] ?? '';
        $pass = $cfg['pass'] ?? '';
    }

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real prepared statements, not strings glued together client-side.
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // The message carries the DSN, and the DSN carries the database name
        // and host. It goes to the log, never to the page.
        app_fail('Database connection failed: ' . $e->getMessage());
    }

    if ($driver === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    return $pdo;
}

function db_driver(): string
{
    return (string) (app_config('db.driver') ?? 'mysql');
}

/* --------------------------------------------------------------------------
   Thin query helpers. Every one of these takes parameters separately; there is
   no function in this file that accepts an interpolated SQL string.
   -------------------------------------------------------------------------- */

function db_run(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->fetchAll();
}

function db_one(string $sql, array $params = []): ?array
{
    $row = db_run($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function db_value(string $sql, array $params = [])
{
    $row = db_run($sql, $params)->fetch(PDO::FETCH_NUM);
    return $row === false ? null : $row[0];
}

function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql  = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES ('
          . implode(', ', array_map(fn($c) => ':' . $c, $cols)) . ')';
    db_run($sql, $data);
    return (int) db()->lastInsertId();
}

/* --------------------------------------------------------------------------
   Surviving the gap between a deploy and its migration

   Xneelo has no shell, so a release that adds tables is two steps: upload the
   code, then run setup.php in a browser. Between those two moments the new code
   is live against the old database, and a query against a table that does not
   exist yet throws — which, uncaught, is a 500 on a page inside a client's
   domain that somebody uses every day.

   Setting the tables up first is not an option either: the schema file arrives
   WITH the deploy. So the order cannot be made safe, and the code has to be.

   These two functions are the whole answer. A query that touches a table added
   in a later release runs inside db_optional(); if the table is missing it
   returns the fallback and raises a flag the page can read, instead of taking
   the page down. Any OTHER database error still throws, because "the database
   is broken" and "this release has not been migrated yet" want opposite
   responses and quietly swallowing both would hide the first.

   Deliberately not a schema-version check running on every request. That would
   cost a query on every page view for ever, to guard a window that is open for
   about three minutes once per release. This costs nothing until it is needed.
   -------------------------------------------------------------------------- */

function db_missing_table(Throwable $e): bool
{
    // MySQL says 42S02; SQLite says HY000 and puts it in the message. Both are
    // checked because the site genuinely runs on both.
    if ((string) $e->getCode() === '42S02') return true;
    $m = $e->getMessage();
    return str_contains($m, 'no such table')          // SQLite
        || str_contains($m, "doesn't exist")          // MySQL
        || str_contains($m, 'Base table or view not found');
}

/**
 * @param callable $fn       the query
 * @param mixed    $fallback what to return if the table is not there yet
 */
function db_optional(callable $fn, $fallback = null)
{
    try {
        return $fn();
    } catch (Throwable $e) {
        if (!db_missing_table($e)) throw $e;
        $GLOBALS['app_schema_incomplete'] = true;
        app_log('SCHEMA NOT MIGRATED — ' . $e->getMessage());
        return $fallback;
    }
}

/** True once db_optional() has swallowed a missing table on this request. */
function db_schema_incomplete(): bool
{
    return !empty($GLOBALS['app_schema_incomplete']);
}

/** The one sentence every page shows when that happens. */
function db_schema_notice(): string
{
    return 'This site has been updated but its database has not been. Set "setup_token" in '
         . 'the configuration file, open /setup and run it, then empty the token again. '
         . 'Until that is done, anything to do with learner accounts is unavailable — '
         . 'registrations and progress reports are unaffected.';
}

/* --------------------------------------------------------------------------
   Tenancy

   One database, one row per company, a tenant_id on everything that holds
   personal information. The id is resolved once from the slug in the config
   file — not from the hostname, not from anything the client can influence.
   -------------------------------------------------------------------------- */

function tenant_id(): int
{
    static $id = null;
    if ($id !== null) return $id;

    $slug = (string) (app_config('tenant') ?? '');
    if ($slug === '') app_fail('No tenant set in the configuration file.');

    $found = db_value('SELECT id FROM tenants WHERE slug = ?', [$slug]);
    if ($found === null) {
        app_fail('Tenant "' . $slug . '" is not in the tenants table. Run tools/migrate.php.');
    }
    return $id = (int) $found;
}

function tenant(): array
{
    static $row = null;
    if ($row === null) {
        $row = db_one('SELECT * FROM tenants WHERE id = ?', [tenant_id()]) ?? [];
    }
    return $row;
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}
