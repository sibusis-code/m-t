<?php
declare(strict_types=1);

/* The written content for a module's topics, as JSON, for the module page.
 *
 *   GET ?course=<slug>&module=<code>
 *
 * Same shape and same rules as materials.php's ?course= branch: signed out
 * gets {"in":false} and the page simply carries on without it; signed in but
 * not enrolled gets 403; an enrolled learner gets the published sections for
 * that module.
 *
 * WHY THE HTML IS BUILT HERE AND NOT IN THE BROWSER
 *
 * The stored body is plain text (see lib/sections.php) and has to be escaped
 * before it can be shown. Doing that conversion server-side means the escaping
 * lives in one place, next to the rule that made the content text in the first
 * place, rather than being re-implemented in JavaScript where a later edit
 * could quietly turn it into innerHTML of raw input.
 */

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/learner.php';
require __DIR__ . '/lib/sections.php';

app_session_start();

/** @param array<string,mixed> $data */
function lout(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$me = current_user();
if ($me === null) lout(['in' => false]);

$course = (string) ($_GET['course'] ?? '');
$module = (string) ($_GET['module'] ?? '');

if ($course === '' || !learner_valid_course_slug($course)
    || $module === '' || !learner_valid_code($module, 20)) {
    lout(['in' => true, 'error' => 'request', 'message' => 'No such module.'], 400);
}

if (!db_optional(fn() => learner_is_enrolled((int) $me['id'], $course), false)) {
    lout(['in' => true, 'enrolled' => false, 'sections' => (object) []], 403);
}

$found = db_optional(fn() => sections_for_module($course, $module), []);

$out = [];
foreach ($found as $topicCode => $areas) {
    foreach ($areas as $i => $row) {
        $out[$topicCode][(string) $i] = [
            'title' => (string) $row['area_title'],
            /* Counted from the stored plain text, not from the rendered HTML —
               tag names would otherwise be counted as words. The page shows a
               reading time built from this, so it has to be the real figure. */
            'words' => str_word_count((string) $row['body']),
            'html'  => section_body_html((string) $row['body']),
        ];
    }
}

/* One line per module opened, not one per section — the same reasoning as the
   coalesced logging in material_file_stream(). "Did this learner read the
   module" is the question worth answering; every individual paragraph is not. */
if ($out) {
    audit('section.read', 'topic_sections', null, $course . ' ' . $module);
}

lout([
    'in'       => true,
    'enrolled' => true,
    'course'   => $course,
    'module'   => $module,
    'sections' => (object) $out,
]);
