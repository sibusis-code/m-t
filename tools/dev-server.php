<?php
/* Router for PHP's built-in server, so the site can be run on a laptop.
 *
 *   Subdomain layout (the site is the whole document root):
 *     php -S localhost:8080 -t . tools/dev-server.php
 *
 *   Folder layout (the site lives at /sps/ on a bigger site):
 *     APP_BASE=/sps php -S localhost:8080 -t . tools/dev-server.php
 *     ...then browse http://localhost:8080/sps/contact
 *
 * The second mode exists because the two layouts differ in ways that are
 * invisible until they break: rewrite substitutions, the session cookie path,
 * where the configuration file is allowed to live. Testing only the first would
 * mean finding all of that out on a live client domain.
 *
 * The built-in server does not read .htaccess, so this mirrors those rules in
 * the same order: denied paths, then a real file, then .html, then .php.
 *
 * Not a production component. It is inside tools/, which is denied to the web.
 */
declare(strict_types=1);

$root = dirname(__DIR__);

/* '' or '/sps'.
 *
 * Accepts APP_BASE with or without a leading slash, and takes only the last
 * segment of an absolute path. Git Bash on Windows rewrites an environment
 * value that starts with "/" into a Windows path — APP_BASE=/sps arrives as
 * "C:/Program Files/Git/sps" — which silently made every URL 404. Writing
 * APP_BASE=sps avoids the rewrite; this normalising handles both. */
$rawBase = trim((string) (getenv('APP_BASE') ?: ''), '/');
if ($rawBase !== '' && (str_contains($rawBase, ':') || str_contains($rawBase, '/'))) {
    $rawBase = basename(str_replace('\\', '/', $rawBase));
}
$base = $rawBase === '' ? '' : '/' . $rawBase;

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = '/' . ltrim(rawurldecode($path), '/');

/* In folder mode, anything outside our folder is somebody else's site. Saying
   so out loud is closer to the truth than serving it. */
if ($base !== '') {
    if ($path === $base) {
        header('Location: ' . $base . '/', true, 301);
        return true;
    }
    if (!str_starts_with($path, $base . '/')) {
        http_response_code(404);
        echo 'Outside this application. In folder mode only ' . htmlspecialchars($base)
           . '/ is served — the rest of the host belongs to the parent site.';
        return true;
    }
    $path = substr($path, strlen($base));
}

// Mirror the deny rule — server code is not a document, locally either.
// Getting this wrong here would hide the mistake until deployment.
if (preg_match('#^/(lib|schema|tools|_drafts|private)(/|$)#', $path)) {
    http_response_code(404);
    echo 'Not found.';
    return true;
}

/* The one .htaccess rule that matches a FILENAME rather than a directory name.
   The rule above only covers directory names, so this is not already handled,
   and a local check that reports .user.ini as readable when the live server
   404s it is worse than no check at all. */
if ($path === '/.user.ini') {
    http_response_code(404);
    echo 'Not found.';
    return true;
}

if ($path === '/' || substr($path, -1) === '/') {
    $path .= 'index.html';
}

$file = $root . $path;

/** Set the CGI variables the application reads, with the folder prefix intact. */
$serve = static function (string $file, string $path) use ($base): void {
    $_SERVER['SCRIPT_NAME']     = $base . $path;
    $_SERVER['SCRIPT_FILENAME'] = $file;
    $_SERVER['DOCUMENT_ROOT']   = $base === '' ? dirname($file) : dirname(dirname($file));
};

/* A real static file.
 *
 * In subdomain mode we can hand it back to the built-in server. In folder mode
 * we cannot: returning false makes it serve the ORIGINAL request URI, which
 * still has /sps/ on the front, against a document root that does not contain a
 * sps directory — so every stylesheet and image 404s. Serve it here instead. */
if (is_file($file) && !str_ends_with($file, '.php')) {
    if ($base === '') return false;

    static $types = [
        'css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2',
        'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'json' => 'application/json',
        'html' => 'text/html', 'txt' => 'text/plain',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    readfile($file);
    return true;
}
if (is_file($file)) {
    $serve($file, $path);
    require $file;
    return true;
}

foreach (['.html', '.php'] as $ext) {
    if (!is_file($file . $ext)) continue;
    $serve($file . $ext, $path . $ext);
    if ($ext === '.php') {
        require $file . $ext;
        return true;
    }
    header('Content-Type: text/html; charset=utf-8');
    readfile($file . $ext);
    return true;
}

http_response_code(404);
echo 'Not found: ' . htmlspecialchars($base . $path);
return true;
