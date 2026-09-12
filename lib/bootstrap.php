<?php
declare(strict_types=1);

/* Shared start-up for every PHP entry point. Include this first, always.
 *
 * Targets PHP 8.0 syntax deliberately. The local machine runs 8.5 but Xneelo
 * shared hosting decides its own version, and finding out that a language
 * feature is unavailable after deploying is a bad way to find out. */

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    exit('This application requires PHP 8.0 or newer.');
}

define('APP_ROOT', dirname(__DIR__));
define('APP_BOOTED', true);

/* Errors are never shown to a visitor unless the config says debug, and the
 * config has not been read yet — so the safe setting goes on first and is
 * relaxed later, rather than the other way round. A fatal inside a database
 * call would otherwise print the connection string to the page.
 *
 * Set here rather than in .htaccess. php_flag only exists when PHP runs as an
 * Apache module; under FastCGI or FPM those directives are unknown and can make
 * the server refuse every .php request while static files serve perfectly. */
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

/* ---------------------------------------------------------------------------
   Configuration
   --------------------------------------------------------------------------- */

/** Normalise a path for comparison: real path where possible, forward slashes. */
function path_norm(string $p): string
{
    $r = realpath($p);
    if ($r !== false) return str_replace('\\', '/', $r);

    /* The path does not exist YET — a log file about to be created on first
       write, for instance. realpath() gives up entirely on a missing leaf and
       returns false, and the old fallback then compared the raw string with its
       ".." segments still in it. "…/sps/lib/../../private/mail.log" is outside
       …/sps, but as a plain string it starts with "…/sps/", so path_inside()
       answered "inside the web root" and the caller refused a perfectly safe
       path. Resolve the deepest part that does exist and rebuild from there. */
    $parent = realpath(dirname($p));
    if ($parent !== false) {
        return str_replace('\\', '/', rtrim($parent, '\\/') . '/' . basename($p));
    }
    return str_replace('\\', '/', $p);
}

/**
 * Is $path inside $dir? Used to keep the configuration out of the web root.
 *
 * The trailing slash on $dir matters: without it, "/var/www/public_htmlX"
 * counts as inside "/var/www/public_html".
 */
function path_inside(string $path, string $dir): bool
{
    $path = path_norm($path);
    $dir  = rtrim(path_norm($dir), '/') . '/';
    return $dir !== '/' && str_starts_with($path, $dir);
}

/**
 * Find and load the config file.
 *
 * It walks UP from the application directory looking for private/sps-config.php,
 * and REFUSES any candidate that turns out to be inside the web root. That
 * refusal is the whole point of the function, and it is why this is not just a
 * hardcoded path.
 *
 * The two layouts this has to serve put the web root in different places:
 *
 *   subdomain   ~/sps.centenarynetworks.com/   <- web root IS the app
 *               ~/private/sps-config.php       <- one level up, outside. Fine.
 *
 *   folder      ~/public_html/sps/             <- app
 *               ~/public_html/private/         <- one level up, INSIDE the web
 *                                                 root. Anyone could fetch
 *                                                 /private/sps-config.php.
 *               ~/private/sps-config.php       <- two levels up, outside. Fine.
 *
 * A fixed "one level up" rule is correct for the first and hands out the
 * database password in the second. So the level is not fixed; the test is
 * whether the file is reachable over HTTP.
 */
function app_config(?string $key = null)
{
    static $cfg = null;

    if ($cfg === null) {
        $docroot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');

        $candidates = [];
        if ($env = getenv('SPS_CONFIG')) $candidates[] = $env;

        /* The account's home directory, checked BEFORE walking up from the site.
         *
         * On this hosting public_html is a symlink: the site resolves to
         * /usr/www/users/<account>/spsacademy while the directory an SFTP client
         * shows on login — and therefore the obvious place to put a config file
         * — is /usr/home/<account>. Walking up from the site never passes
         * through it, so a file sitting in plain view was invisible to the
         * application, and the directory immediately above the site turned out
         * to be the document root itself.
         *
         * The home directory is inside open_basedir and outside the document
         * root, which makes it the correct place on this host rather than
         * merely a convenient one. */
        /* WHICH FILENAME, and why this is not cosmetic.
         *
         * Four academies share one Xneelo account and one home directory. The
         * configuration file is what tells an installation which tenant it is,
         * so if two installations can find the same file, the second one
         * silently becomes the first. Fungi at public_html/fungiacademy/ would
         * have walked up, found ~/sps-config.php, and served SPS's
         * registrations, learners and progress under Fungi's branding — with no
         * error, because from the code's point of view nothing went wrong.
         *
         * So the name is derived from the directory the application is
         * installed in: public_html/fungiacademy/ looks for
         * fungiacademy-config.php and will not accept anything else.
         *
         * The one exception is the SPS installation that already exists, whose
         * file was placed by hand and is called sps-config.php. That fallback
         * is allowed ONLY when the directory is recognisably the SPS one — not
         * as a general last resort, because a general last resort is exactly
         * the cross-tenant bug this is here to prevent. A missing Fungi config
         * has to fail loudly rather than quietly load somebody else's.
         */
        $appDir = basename(APP_ROOT);
        $names  = [];
        if ((bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,39}$/', $appDir)) {
            $names[] = $appDir . '-config.php';
        }
        if ($appDir === 'sps' || $appDir === 'spsacademy') {
            $names[] = 'sps-config.php';
        }
        if (!$names) $names[] = 'academy-config.php';   // unnameable directory

        $home = (string) (getenv('HOME') ?: '');
        if ($home === '' && function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
            $pw = @posix_getpwuid(posix_getuid());
            $home = (string) ($pw['dir'] ?? '');
        }
        if ($home !== '') {
            foreach ($names as $n) {
                $candidates[] = rtrim($home, '/') . '/private/' . $n;
                $candidates[] = rtrim($home, '/') . '/' . $n;
            }
        }

        /* Walk up from the app directory. Four levels is far more than either
           layout needs and still cannot escape a hosting account.

           Both a private/ subdirectory and the directory itself are checked.
           On Xneelo the SFTP login lands in the home directory, and the obvious
           place to drop the file is right there — ~/sps-config.php — which is
           just as safely outside the web root as ~/private/sps-config.php.
           Insisting on the tidier of the two only produces a site that says it
           cannot find its configuration while the file sits in plain view one
           directory up. The test that matters is the DOCUMENT_ROOT one below,
           not which folder it is in. */
        $dir = APP_ROOT;
        for ($i = 0; $i < 4; $i++) {
            $dir = dirname($dir);
            if ($dir === '' || $dir === '.' || $dir === dirname($dir)) break;
            foreach ($names as $n) {
                $candidates[] = $dir . '/private/' . $n;
                $candidates[] = $dir . '/' . $n;
            }
        }

        $candidates[] = APP_ROOT . '/lib/config.local.php';  // development only, gitignored

        $found = null;
        foreach ($candidates as $path) {
            if (!is_file($path)) continue;

            /* The development fallback lives inside the application on purpose —
               it is gitignored, denied by .htaccess, and only ever points at a
               local SQLite file. Everything else must be out of reach. */
            $isDevFallback = path_norm($path) === path_norm(APP_ROOT . '/lib/config.local.php');

            if (!$isDevFallback && $docroot !== '' && path_inside($path, $docroot)) {
                app_log('REFUSED config inside the web root: ' . $path);
                continue;
            }
            $found = $path;
            break;
        }

        if ($found === null) {
            /* Name the file THIS installation is looking for. The generic
               message sent somebody to create sps-config.php for the Fungi
               site, which is the one file Fungi must never load. */
            app_fail('No configuration file found. Copy lib/config.sample.php to '
                   . '~/private/' . $names[0] . ' and fill it in. This installation '
                   . 'is in a directory called "' . $appDir . '", and that is where '
                   . 'the filename comes from.');
        }

        $cfg = require $found;
        if (!is_array($cfg)) {
            app_fail('The configuration file did not return an array.');
        }
        $cfg['_path'] = $found;

        // Now — and only now — the safe default set at the top of this file can
        // be relaxed, because we finally know whether this is a development box.
        if (!empty($cfg['debug'])) @ini_set('display_errors', '1');
    }

    if ($key === null) return $cfg;

    // Dotted lookup: app_config('db.host')
    $node = $cfg;
    foreach (explode('.', $key) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) return null;
        $node = $node[$part];
    }
    return $node;
}

/* ---------------------------------------------------------------------------
   Errors
   --------------------------------------------------------------------------- */

/**
 * Stop with a message the visitor can read and nothing they cannot.
 *
 * The detail goes to the log; the page gets a sentence. A database error must
 * never render the connection string, which is exactly what an uncaught PDO
 * exception does by default.
 */
function app_fail(string $detail, int $status = 500): void
{
    /* Re-entry guard, and it is not theoretical.
     *
     * When no configuration file was found, app_config() called app_fail(), and
     * app_fail() asked app_config_safe('debug') whether to show details — which
     * called app_config(), which found no file, which called app_fail(). The
     * try/catch in app_config_safe caught nothing, because app_fail throws
     * nothing. The result was infinite recursion: the page never responded, and
     * curl reported no HTTP status at all rather than an error anyone could
     * read. The most likely reason to reach this function is a missing config,
     * so the one path that must never loop was the one that did. */
    static $failing = false;
    if ($failing) {
        http_response_code(500);
        if (PHP_SAPI === 'cli') { fwrite(STDERR, $detail . "\n"); exit(1); }
        exit('The site is not configured correctly and cannot start.');
    }
    $failing = true;

    app_log('ERROR ' . $detail);

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $detail . "\n");
        exit(1);
    }

    http_response_code($status);
    $debug = (bool) (app_config_safe('debug') ?? false);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Something went wrong</title>'
       . '<div style="font:16px/1.6 system-ui,sans-serif;max-width:34rem;margin:18vh auto;padding:0 1.5rem">'
       . '<h1 style="font-size:1.3rem">Something went wrong at our end.</h1>'
       . '<p>Nothing you did caused this. Please try again in a moment — and if it '
       . 'keeps happening, email <a href="mailto:kgomotso@centenarynetworks.com">'
       . 'kgomotso@centenarynetworks.com</a> and say what you were doing.</p>'
       . ($debug ? '<pre style="white-space:pre-wrap;background:#f4f4f4;padding:1rem;'
                 . 'border-radius:6px;font-size:13px">' . htmlspecialchars($detail) . '</pre>' : '')
       . '</div>';
    exit;
}

/** app_config() itself can fail, so the error handler needs a version that cannot. */
function app_config_safe(string $key)
{
    try { return app_config($key); } catch (Throwable $e) { return null; }
}

/** The account's home directory, however this host is willing to reveal it. */
function app_home_dir(): string
{
    $home = (string) (getenv('HOME') ?: '');
    if ($home === '' && function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
        $pw = @posix_getpwuid(posix_getuid());
        $home = (string) ($pw['dir'] ?? '');
    }
    /* Last resort: the directory the configuration file was found in. That
       file is placed by hand, outside the web root, by whoever installed the
       site — so wherever it lives is by definition a directory this account
       owns and the web server cannot serve. On a host that hides HOME from
       PHP-FPM this is the only honest clue we have. */
    if ($home === '') {
        $cfg = app_config_safe('_path');
        if (is_string($cfg) && $cfg !== '') {
            $dir = dirname($cfg);
            if (basename($dir) === 'private') $dir = dirname($dir);
            $home = $dir;
        }
    }
    return $home === '' ? '' : rtrim(str_replace('\\', '/', $home), '/');
}

/**
 * Every place a private directory could reasonably live, best first, each
 * with what is actually true of it right now.
 *
 * Split out from app_private_dir() so that a page reporting "storage is not
 * available" can say WHERE it looked and WHY each place was no good. The
 * first version of this feature failed on the live server with nothing but
 * that sentence, and no shell to go and look with — which turned a
 * five-minute fix into three deploys of guessing. A resolver that cannot
 * explain itself is a resolver you debug blind.
 *
 * @return array<int, array{path:string, source:string, status:string, usable:bool}>
 */
function app_private_candidates(): array
{
    $docroot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
    $paths   = [];

    $home = app_home_dir();
    if ($home !== '') $paths[] = [$home . '/private', 'the account home directory'];

    /* Walking up from the application. On this host public_html is a symlink
       to /usr/www/users/<account>/spsacademy, so this walk does NOT pass
       through /usr/home/<account> — which is exactly why the home directory
       is checked first above, and the same reason app_config() checks it
       first. Kept as a fallback because it is right for the subdomain
       layout, where the web root IS the application. */
    $dir = APP_ROOT;
    for ($i = 0; $i < 4; $i++) {
        $dir = dirname($dir);
        if ($dir === '' || $dir === '.' || $dir === dirname($dir)) break;
        $paths[] = [str_replace('\\', '/', $dir) . '/private', 'above the application'];
    }

    /* Last, and only last: the home directory itself. It is outside the web
       root and writable, so it works — but it scatters material-files/ and
       logs/ across the account's top level, which is untidy enough that it
       must never win while a private/ directory exists or could be made.
       Hence its position at the bottom of this list rather than beside the
       first entry, where it would quietly pre-empt creating ~/private. */
    if ($home !== '') $paths[] = [$home, 'the account home directory itself (last resort)', true];

    $out = [];
    foreach ($paths as $p) {
        [$path, $source] = $p;
        $fallback = $p[2] ?? false;

        $status = 'usable';
        $usable = true;

        if ($docroot !== '' && path_inside($path, $docroot)) {
            $status = 'REFUSED — it is inside the web root, so anyone could fetch what we put there';
            $usable = false;
        } elseif (!is_dir($path)) {
            $status = is_dir(dirname($path)) ? 'does not exist yet (could be created)' : 'parent directory does not exist';
            $usable = false;
        } elseif (!is_writable($path)) {
            $status = 'exists but is not writable by the web server';
            $usable = false;
        }

        $out[] = ['path' => $path, 'source' => $source, 'status' => $status,
                  'usable' => $usable, 'fallback' => (bool) $fallback];
    }
    return $out;
}

/**
 * A writable directory that is NOT reachable over HTTP.
 *
 * Same reasoning as the config search, for the same reason: a log of what went
 * wrong on a registration page quotes the request, and the request contains
 * somebody's name and email address. Serving that at /private/logs/app.log
 * would be a worse leak than the bug being logged.
 *
 * IT CREATES ~/private IF IT HAS TO. The deploy note has always said to make
 * that directory by hand, and on this installation it plainly never happened —
 * the configuration file sits directly in the home directory instead, which
 * app_config() accepts and says so. Requiring a directory nobody was told
 * twice to create, on a host with no shell to create it from, is a
 * requirement that fails quietly and blames the code. Creating it is safe:
 * the only candidate we will create is one already proven to be outside the
 * document root, and it is made 0700.
 */
function app_private_dir(string $sub = ''): ?string
{
    static $base = null;

    if ($base === null) {
        $base       = false;
        $candidates = app_private_candidates();

        /* Three passes, and the order between them is the whole point. A
           real private/ directory beats making one; making one beats
           scattering our directories across the top of somebody's home
           folder. The last-resort candidate IS "usable" — it exists and it
           is writable — so a single first-usable-wins loop would take it and
           never create ~/private at all. */

        // 1. An existing, writable, out-of-reach private/ directory.
        foreach ($candidates as $c) {
            if ($c['usable'] && !$c['fallback']) { $base = $c['path']; break; }
        }

        // 2. Create the best one that is merely absent.
        if ($base === false) {
            foreach ($candidates as $c) {
                if ($c['fallback']) continue;
                if ($c['status'] !== 'does not exist yet (could be created)') continue;
                if (@mkdir($c['path'], 0700, true) && is_dir($c['path'])) {
                    /* Assign BEFORE logging: app_log() calls straight back
                       into this function for its own directory, and would
                       otherwise see $base still false and write the "we made
                       it" line into the system temp directory instead of the
                       directory it is announcing. */
                    $base = $c['path'];
                    app_log('CREATED private directory at ' . $c['path']);
                    break;
                }
            }
        }

        // 3. Only now, the home directory itself.
        if ($base === false) {
            foreach ($candidates as $c) {
                if ($c['usable'] && $c['fallback']) {
                    $base = $c['path'];
                    app_log('FALLING BACK to ' . $c['path'] . ' — could not create a private directory');
                    break;
                }
            }
        }
    }

    if ($base === false) return null;
    if ($sub === '') return $base;

    $path = $base . '/' . $sub;
    if (!is_dir($path)) @mkdir($path, 0700, true);
    return is_dir($path) ? $path : null;
}

function app_log(string $line): void
{
    $dir = app_private_dir('logs') ?? sys_get_temp_dir();
    @file_put_contents(
        $dir . '/app-' . date('Y-m') . '.log',
        '[' . date('c') . '] ' . $line . "\n",
        FILE_APPEND | LOCK_EX
    );
}

set_exception_handler(function (Throwable $e): void {
    app_fail(get_class($e) . ': ' . $e->getMessage()
           . ' @ ' . $e->getFile() . ':' . $e->getLine());
});

/* ---------------------------------------------------------------------------
   Request helpers
   --------------------------------------------------------------------------- */

/**
 * A stable, non-reversible stand-in for the visitor's IP address.
 *
 * See the note on 'ip_pepper' in the config template: we keep the ability to
 * compare, and drop the ability to identify.
 */
function client_ip_hash(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    // Shared hosting sits behind a proxy; the left-most entry is the client.
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
    }
    $pepper = (string) (app_config('ip_pepper') ?? '');
    if ($pepper === '') {
        // Better to refuse than to store something reversible by rainbow table.
        app_fail('ip_pepper is not set in the configuration file.');
    }
    return hash_hmac('sha256', $ip, $pepper);
}

/**
 * The URL path this installation is mounted at: "/" or "/sps/".
 *
 * Derived rather than configured, so the folder can be renamed — or the site
 * moved to a subdomain of its own — without editing anything.
 */
function app_base_path(): string
{
    static $base = null;
    if ($base !== null) return $base;

    $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    if ($dir === '' || $dir === '.' || $dir === '/') return $base = '/';
    return $base = rtrim($dir, '/') . '/';
}

/**
 * Start a session with cookie flags set BEFORE the cookie is issued.
 *
 * Called explicitly rather than on every request: an anonymous visitor reading
 * the course catalogue should not be given a cookie they never needed.
 *
 * The path and the name both matter more in the folder layout than they would
 * on a subdomain. With four academies living at /sps/, /fungi/, /maziv/ and
 * /equinix/ on ONE hostname, a cookie set at path "/" is sent to all four —
 * one company's session cookie travelling to another company's site. Scoping
 * the cookie to this installation's own path, and giving each tenant its own
 * session name, keeps them apart.
 *
 * Worth being honest about the limit: a cookie path is not a security boundary.
 * Same host means same origin, and the browser will not defend these four from
 * each other the way it would defend four subdomains. That is the real cost of
 * the folder layout, and it is why this is a stop on the way to subdomains
 * rather than the destination.
 */
function app_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    /* A session cannot be started once anything has been printed, because the
       cookie is a header. PHP says so in a warning, and on a live site warnings
       are switched off — so the failure is silent, and what it breaks is not
       obvious: csrf_token() mints a token, cannot store it, and the next POST
       is rejected as expired. A form that never submits, with nothing in the
       log to say why.

       It bit pm-progress.php exactly this way. Worse, it did so only because
       that page is long enough to overflow PHP's output buffer — contact.php
       has the identical shape and got away with it because it is shorter. That
       makes it a bug that appears when someone adds a paragraph, and appears on
       the server rather than on the laptop, since the buffer size is a server
       setting. So it is recorded here, loudly, where the failure happens. */
    if (headers_sent($file, $line)) {
        app_log(sprintf(
            'SESSION TOO LATE — output began at %s:%d, so no session cookie can be set. '
            . 'Any CSRF token on this page is worthless and its form cannot be submitted. '
            . 'Call app_session_start() before the page prints anything.',
            $file, $line
        ));
        return;
    }

    $https = (($_SERVER['HTTPS'] ?? '') === 'on')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => app_base_path(),
        'secure'   => $https,
        'httponly' => true,      // not readable from JavaScript
        'samesite' => 'Lax',     // survives normal navigation, blocks cross-site POST
    ]);

    $slug = (string) (app_config_safe('tenant') ?? 'acad');
    session_name('acad_' . (preg_replace('/[^a-z0-9]/', '', strtolower($slug)) ?: 'x'));
    session_start();
}

/**
 * Who is signed in, or null.
 *
 * Lives here rather than in lib/auth.php because lib/audit.php needs it, and
 * audit is loaded on pages that have nothing to do with signing in. It only
 * reads the session, so it costs nothing where nobody is signed in.
 */
function current_user_id(): ?int
{
    if (session_status() !== PHP_SESSION_ACTIVE) return null;
    return isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Trimmed string from POST, capped so a hostile client cannot post a megabyte. */
function post_str(string $key, int $max = 500): string
{
    $v = $_POST[$key] ?? '';
    if (!is_string($v)) return '';
    $v = trim($v);
    return mb_substr($v, 0, $max);
}

function redirect(string $to): void
{
    header('Location: ' . $to, true, 303);
    exit;
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
