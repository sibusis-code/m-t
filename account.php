<?php
declare(strict_types=1);

/* The small JSON endpoint the learner-facing pages talk to.
 *
 * Two jobs, and nothing else belongs here:
 *
 *   GET   — "who am I, and what have I ticked off?"  Answers for anonymous
 *           visitors too, with {"in":false}, because the marketing pages call
 *           it to decide what the nav should say.
 *   POST  — one tick on, one tick off, an import, or a clear.
 *
 * WHY AN ENDPOINT RATHER THAN RENDERING IT INTO THE PAGE
 *
 * The pages that show progress — module.html, the catalogue, the report page —
 * are static HTML, served straight off disk by Apache. Turning seventeen of
 * them into PHP to print a name into the nav would be a large change with a
 * large blast radius on a site that is live inside a client's domain. One small
 * request per tab is the cheaper trade, and it keeps the static pages working
 * exactly as they do now when nobody is signed in.
 *
 * The anonymous answer costs no database query at all: current_user() returns
 * null on a missing session before it ever asks the database anything.
 *
 * WHAT PROTECTS IT
 *
 * Every write requires the session's CSRF token. Every write is scoped to the
 * signed-in user's own id and to the tenant — there is no id in any request
 * body naming whose progress to change, so there is nothing to tamper with.
 * And a learner may only write against a course they are actually enrolled on.
 */

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/csrf.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/install.php';   // install_readable_password(), used by learner.php
require __DIR__ . '/lib/learner.php';

header('Content-Type: application/json; charset=utf-8');
// A per-user answer must never be held in a shared cache, and the nav must not
// keep saying somebody else's name after they sign out.
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

/**
 * Every exit from this file goes through here, so there is exactly one place
 * that decides what a response looks like.
 *
 * No `never` return type: phpcheck.php passes this installation at PHP 8.0, and
 * `never` needs 8.1. The server runs 8.2, but the floor is what the check says.
 *
 * @param array<string,mixed> $data
 */
function out(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$me = current_user();

/* ---------------------------------------------------------------------------
   GET — the session probe
   --------------------------------------------------------------------------- */

if (!is_post()) {
    if ($me === null) {
        out(['in' => false, 'base' => app_base_path()]);
    }

    $courses  = [];
    $progress = [];

    /* db_optional throughout: this endpoint is called by every page on the site,
       and between a deploy and its migration the enrolment tables do not exist
       yet. A 500 here would break the nav on the marketing pages, which have
       nothing to do with accounts. See the note in lib/db.php. */
    foreach (db_optional(fn() => learner_enrolments((int) $me['id']), []) as $en) {
        $slug      = (string) $en['course_slug'];
        $tracked   = learner_course_tracked($slug);
        $courses[] = [
            'slug'    => $slug,
            'title'   => (string) $en['course_title'],
            'status'  => (string) $en['status'],
            'tracked' => $tracked,
        ];
        if ($tracked) {
            // (object) so an untouched course encodes as {} and not as [].
            $progress[$slug] = (object) db_optional(
                fn() => learner_progress_tree((int) $me['id'], $slug), []
            );
        }
    }

    out([
        'in'       => true,
        'base'     => app_base_path(),
        'first'    => (string) $me['first_name'],
        'name'     => trim($me['first_name'] . ' ' . $me['last_name']),
        /* Their own details, to their own session, so the registration form can
           be filled in for them the way the local profile already does. */
        'email'    => (string) $me['email'],
        'empno'    => (string) ($me['employee_no'] ?? ''),
        'dept'     => (string) ($me['department'] ?? ''),
        'initials' => mb_strtoupper(mb_substr($me['first_name'], 0, 1) . mb_substr($me['last_name'], 0, 1)),
        'role'     => (string) $me['role'],
        'admin'    => $me['role'] === 'admin',
        'courses'  => $courses,
        'progress' => (object) $progress,
        'token'    => csrf_token(),
    ]);
}

/* ---------------------------------------------------------------------------
   POST — a write
   --------------------------------------------------------------------------- */

if ($me === null) {
    out(['ok' => false, 'error' => 'signed-out',
         'message' => 'You have been signed out. Sign in again to keep your progress.'], 401);
}

if (!csrf_valid()) {
    /* Not necessarily an attack, and much more often a stale tab: the token
       rotates whenever a form elsewhere on the site is submitted successfully.
       The browser side re-reads the token and retries once on this error, which
       is why it is named rather than described. */
    out(['ok' => false, 'error' => 'token', 'message' => 'Your session token had moved on.'], 403);
}

$uid    = (int) $me['id'];
$action = (string) ($_POST['a'] ?? '');
$course = (string) ($_POST['course'] ?? '');

if (!learner_valid_course_slug($course) || !learner_course_valid($course)) {
    out(['ok' => false, 'error' => 'course', 'message' => 'Unknown course.'], 400);
}
if (!db_optional(fn() => learner_is_enrolled($uid, $course), false)) {
    /* Worth auditing rather than merely refusing. A signed-in learner posting
       against a course they are not on is either a stale tab from a withdrawn
       enrolment or somebody having a go, and the two look identical from here. */
    audit('learner.progress_denied', 'enrolments', $uid, $course);
    out(['ok' => false, 'error' => 'not-enrolled',
         'message' => 'You are not enrolled on that course.'], 403);
}

switch ($action) {

    case 'topic':
    case 'module':
        $module = (string) ($_POST['module'] ?? '');
        $item   = $action === 'topic' ? (string) ($_POST['item'] ?? '') : '';
        $on     = ($_POST['on'] ?? '') === '1';

        if (!learner_valid_code($module, 20)) {
            out(['ok' => false, 'error' => 'module', 'message' => 'Unknown module.'], 400);
        }
        if ($action === 'topic' && !learner_valid_code($item, 40)) {
            out(['ok' => false, 'error' => 'item', 'message' => 'Unknown topic.'], 400);
        }

        $now = learner_progress_set($uid, $course, $module, $item, $on);
        out(['ok' => true, 'on' => $now]);

    case 'import':
        /* Only ever offered when the account has nothing recorded on this
           course, and it only adds — see learner_progress_import(). */
        $raw  = (string) ($_POST['payload'] ?? '');
        $tree = json_decode($raw, true);
        if (!is_array($tree)) {
            out(['ok' => false, 'error' => 'payload', 'message' => 'That did not look like saved progress.'], 400);
        }
        $added = learner_progress_import($uid, $course, $tree);
        out(['ok' => true, 'added' => $added,
             'progress' => (object) learner_progress_tree($uid, $course)]);

    case 'clear':
        $n = learner_progress_clear($uid, $course);
        out(['ok' => true, 'removed' => $n]);
}

out(['ok' => false, 'error' => 'action', 'message' => 'Nothing to do.'], 400);
