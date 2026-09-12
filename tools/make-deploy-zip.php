<?php
declare(strict_types=1);

/* Build a zip containing exactly what should go on the server, and nothing else.
 *
 *     php tools/make-deploy-zip.php
 *
 * WHY NOT JUST ZIP THE FOLDER. Two reasons, and both are the kind that are only
 * noticed afterwards:
 *
 *   1. This directory contains things that must never be on a public web
 *      server — _drafts/ holds client emails, lib/config.local.php holds the IP
 *      hashing key, and .git/ holds every version of everything. A zip of the
 *      folder uploads all of it. Nothing in .gitignore protects you here,
 *      because a zip is not git.
 *
 *   2. The site does not work without its .htaccess files, and they are exactly
 *      the files a hand-made zip is most likely to leave behind — a leading dot
 *      hides them in Explorer, and some archive tools skip them by default.
 *      Losing the root one breaks every link on the site AND removes the rule
 *      that stops /lib/ being browsable. That combination is much worse than an
 *      obviously broken site, because it looks like it half works.
 *
 * The archive holds files at the TOP LEVEL, not inside an sps/ folder, so
 * extracting it inside spsacademy/ gives spsacademy/index.html — not
 * spsacademy/sps/index.html.
 *
 * It refuses to write the file if any of its own checks fail.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!extension_loaded('zip')) { fwrite(STDERR, "PHP's zip extension is not available.\n"); exit(1); }

$root = dirname(__DIR__);
$out  = dirname($root) . '/sps-deploy-' . date('Y-m-d-Hi') . '.zip';

/* Directories that never ship, matched on the first path segment.
 *
 * tools/ is in this list on purpose. Every script in it is command-line only,
 * and Xneelo answers port 22 with "SSH-2.0-FTP Service" — SFTP, no shell — so
 * none of it could be run on the server even if it were there. Uploading code
 * that cannot run, to a public web server, is all risk and no benefit. It is
 * also why setup.php exists.
 *
 * schema/ DOES ship, and must: setup.php reads schema/schema.mysql.sql to
 * create the tables. It is denied to the web by its own .htaccess. */
$skipDirs = [
    '.git', '.github', '.vscode', '.claude', '.idea',
    '_drafts', 'private', 'node_modules', 'tools',
    'Website-inspiration-latout',
    'SPS-AI-Micro-Learning-Onepager.files',
];

/* Files that never ship. The config is the important one — it carries the IP
   pepper, and on the server the real config lives outside the web root.
   The git housekeeping files are only noise on a web server, but .gitignore in
   particular advertises that lib/config.local.php exists, which is a small hint
   nobody needs to be given. */
$skipFiles = [
    'lib/config.local.php',
    'lib/config.sample.php',   // a template, read here and filled in by hand — the
                               // server never loads it, so it has no business there
    '.gitignore',
    '.gitattributes',
];

/* Working documents rather than site content. Checked against the whole
   relative path, so resources/*.pdf (which the course pages link to) still
   ships — only the loose one-pagers at the top level are dropped. */
$skipPattern = '#^(.*\.(md|zip|docx|htm)|SPS-AI-Micro-Learning-Onepager\..*)$#i';

/* Every .htaccess that ships is load-bearing, and the archive is not valid
   without all of them. tools/.htaccess is not listed because tools/ does not
   ship at all — there is nothing left in it to deny. */
$required = ['.htaccess', 'lib/.htaccess', 'schema/.htaccess'];

/* Files the server genuinely cannot work without, checked for the same reason:
   a silently incomplete archive is worse than an obviously broken one. */
$requiredAlso = [
    'index.html', 'styles.css', 'contact.php', 'setup.php',
    'lib/bootstrap.php', 'lib/db.php', 'lib/install.php',
    // Learner accounts. account.php is the one that would be missed silently:
    // without it every page still renders, the nav simply never learns anybody
    // is signed in and progress quietly falls back to browser storage.
    'login.php', 'my.php', 'account.php', 'lib/auth.php', 'lib/learner.php',
    'profile.js', 'pm-progress.js',
    // Getting back in. forgot/reset are the self-service half and admin-users
    // is the half that works while the domain's SPF record is still missing;
    // shipping one without the other leaves somebody locked out.
    'forgot.php', 'reset.php', 'admin-users.php', 'lib/reset.php',
    // The invite route: "Enrol" can email a one-time set-password link instead
    // of showing the password on screen. Same reasoning as the pair above —
    // shipping invite.php without lib/invite.php (or the reverse) leaves a
    // link in somebody's inbox that 404s when they open it.
    'invite.php', 'lib/invite.php', 'lib/mail.php',
    // File-backed materials and self-check quizzes. materials.php and
    // admin-materials.php already ship via the wildcard git-tracked list, but
    // both are dead without the lib/ files their new code paths call into —
    // and .user.ini is what makes an upload of any real size possible at all
    // under Xneelo's FastCGI/PHP-FPM, see the file itself.
    'lib/material_files.php', 'lib/quiz.php', 'quiz.php', 'admin-quizzes.php',
    'quiz-widget.js', '.user.ini',
    // Reading a module on the page, area by area. Same pairing rule as above:
    // lessons.php without lib/sections.php is a page that cannot answer, and
    // lessons.js without lessons.php quietly renders nothing at all — which
    // looks exactly like "no content written yet" and would not be noticed.
    'lib/sections.php', 'lessons.php', 'admin-lessons.php', 'lessons.js',
    // The bundle loader is how a course's reading and questions reach a site at
    // all, since the content itself is never in this repository — see the header
    // of lib/bundle.php. admin-lessons.php hard-requires it, so shipping the
    // page without it is a white screen on the content page.
    'lib/bundle.php',
    // The curriculum reader, which lib/bundle.php require_once's and the letters
    // read module and topic titles from. Missing, every bundle upload is refused
    // with "not a topic in the curriculum" — the file is fine, the site is not.
    'lib/curriculum.php',
    // The learner's welcome letter and module report. lib/invite.php calls into
    // this for the enrolment email; without it a new learner still gets the old
    // plain-text note, so the failure is quiet rather than broken — which is
    // exactly why it is listed here rather than left to chance.
    'lib/letters.php',
    'schema/schema.mysql.sql',
];

/* The file list comes from git, not from the filesystem.
 *
 * A filesystem walk ships whatever happens to be lying in the directory. That
 * is not hypothetical: a debugging session left r.html and r2.html here —
 * saved copies of server responses — and both went into an archive destined
 * for a public web server before anyone noticed. Version control already knows
 * the difference between "part of the site" and "something I saved once", so
 * it is asked.
 *
 * Anything untracked is reported rather than silently dropped, because the
 * other way this goes wrong is a genuinely new page that was never added. */
exec('git -C ' . escapeshellarg($root) . ' ls-files --cached --exclude-standard 2>&1', $tracked, $gitStatus);

if ($gitStatus !== 0) {
    fwrite(STDERR, "git is not available here, so the archive cannot be built safely.\n"
                 . "Without it there is no way to tell site files from stray ones.\n");
    exit(1);
}

$files = [];
foreach ($tracked as $rel) {
    $rel = trim(str_replace('\\', '/', $rel));
    if ($rel === '' || !is_file($root . '/' . $rel)) continue;

    $first = explode('/', $rel)[0];
    if (in_array($first, $skipDirs, true)) continue;
    if (in_array($rel, $skipFiles, true)) continue;
    if (preg_match($skipPattern, $rel)) continue;

    $files[] = $rel;
}
sort($files);

/* Untracked files that look like site content — a page someone wrote and
   forgot to commit would otherwise be missing from the deploy without a word. */
exec('git -C ' . escapeshellarg($root) . ' ls-files --others --exclude-standard 2>&1', $untracked);
$notable = array_values(array_filter($untracked, function ($f) use ($skipDirs) {
    $f = trim(str_replace('\\', '/', $f));
    if ($f === '') return false;
    if (in_array(explode('/', $f)[0], $skipDirs, true)) return false;
    return (bool) preg_match('#\.(html|php|css|js|svg|png|jpg|pdf)$#i', $f);
}));

/* ---------------------------------------------------------------------------
   Checks, before anything is written
   --------------------------------------------------------------------------- */

$problems = [];

foreach (array_merge($required, $requiredAlso) as $r) {
    if (!in_array($r, $files, true)) $problems[] = 'missing ' . $r;
}
foreach ($files as $f) {
    if (basename($f) === 'config.local.php') $problems[] = 'config.local.php would have shipped';
    if (str_starts_with($f, '_drafts/'))     $problems[] = 'a draft would have shipped: ' . $f;
}

/* The development pepper must not appear anywhere in the archive. This catches
   the case where it has been pasted into some other file while debugging. */
$devConfig = $root . '/lib/config.local.php';
if (is_file($devConfig)) {
    $cfg = @include $devConfig;
    $pepper = is_array($cfg) ? (string) ($cfg['ip_pepper'] ?? '') : '';
    if (strlen($pepper) > 16) {
        foreach ($files as $f) {
            $full = $root . '/' . $f;
            if (filesize($full) > 2_000_000) continue;
            if (!preg_match('#\.(php|html|js|css|json|txt|sql|htaccess)$#i', $f)
                && basename($f) !== '.htaccess') continue;
            if (str_contains((string) file_get_contents($full), $pepper)) {
                $problems[] = 'the development pepper appears in ' . $f;
            }
        }
    }
}

if ($problems) {
    fwrite(STDERR, "REFUSING to build the archive:\n");
    foreach ($problems as $p) fwrite(STDERR, '  - ' . $p . "\n");
    exit(1);
}

/* ---------------------------------------------------------------------------
   Write
   --------------------------------------------------------------------------- */

$zip = new ZipArchive();
if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Could not create $out\n");
    exit(1);
}
foreach ($files as $f) $zip->addFile($root . '/' . $f, $f);
$zip->close();

$bytes = filesize($out);
printf("\n  built    %s\n", str_replace('\\', '/', $out));
printf("  files    %d  (from git, not from the directory)\n", count($files));
printf("  size     %.1f MB\n", $bytes / 1048576);

if ($notable) {
    echo "\n  NOT included — these look like site files but are not committed:\n";
    foreach ($notable as $f) echo "    " . trim($f) . "\n";
    echo "  Commit them if they belong on the site, or delete them if they do not.\n";
}

echo "\n  included, and easy to lose:\n";
foreach ($required as $r) echo "    $r\n";

echo "\n  deliberately left out:\n";
echo "    tools/                 command-line only; Xneelo has no shell\n";
echo "    lib/config.sample.php  a template, filled in by hand and never loaded\n";
echo "    _drafts/               client emails\n";
echo "    .git/                  every version of everything\n";
echo "    *.md, the Word one-pagers, .gitignore\n";

echo "\n  Extract this INSIDE the spsacademy folder — the files sit at the top\n";
echo "  level of the archive, so you should end up with spsacademy/index.html\n";
echo "  and not spsacademy/sps/index.html.\n\n";
