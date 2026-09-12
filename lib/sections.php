<?php
declare(strict_types=1);

/* Written teaching content, one piece per AREA of a topic.
 *
 * WHAT THIS IS FOR
 *
 * Until now a module offered a learner two things: the study structure
 * (pm-modules.js — topics, what each covers, the defining idea) and a whole
 * document to open (materials.php). Nothing sat in between. This is the in
 * between: the actual reading for one area, delivered on the module page, so
 * somebody can work through a topic a section at a time instead of opening a
 * fifty-nine page guide and finding their place in it.
 *
 * It does not replace the guide. The guide stays the authoritative document
 * and is still the thing assessed against; this is the same material laid out
 * for reading on screen.
 *
 * WHY THE PROSE IS NOT IN pm-modules.js
 *
 * That file is served to anybody who loads the site, and its own header says
 * what it deliberately leaves out for exactly that reason. Teaching content
 * put there would be public the instant it was saved — the mistake the old
 * DOCS link map made. So it lives in the database and reaches a learner only
 * through lessons.php, behind the same signed-in-and-enrolled check
 * materials.php applies, and every read is auditable the same way.
 *
 * WHY CONTENT IS STORED AS PLAIN TEXT, NOT HTML
 *
 * The body is written by an administrator and rendered into a learner's page.
 * Storing HTML would mean either trusting that input (stored XSS, and the
 * admin-authored quiz prompt already showed how easily that happens) or
 * sanitising it, which needs a real sanitiser this project does not have and
 * should not hand-roll. So the body is plain text: blank lines separate
 * paragraphs, a line beginning "- " is a bullet, and everything is escaped on
 * output. That is enough for teaching prose and it cannot execute.
 */

defined('APP_BOOTED') or exit('lib/sections.php is not a page.');

/* A topic has at most this many areas — the longest `covers` list in the
   registered curriculum is nine, so this is headroom rather than a rule. */
const SECTION_MAX_AREAS = 20;

/** Every published section for a module, keyed topic_code => area_index => row. */
function sections_for_module(string $courseSlug, string $moduleCode, bool $publishedOnly = true): array
{
    $sql = 'SELECT * FROM topic_sections
             WHERE tenant_id = ? AND course_slug = ? AND module_code = ?'
         . ($publishedOnly ? ' AND published = 1' : '')
         . ' ORDER BY topic_code, area_index';

    $out = [];
    foreach (db_all($sql, [tenant_id(), $courseSlug, $moduleCode]) as $r) {
        $out[(string) $r['topic_code']][(int) $r['area_index']] = $r;
    }
    return $out;
}

/** One area, or null. */
function section_get(string $courseSlug, string $moduleCode, string $topicCode, int $areaIndex): ?array
{
    return db_one(
        'SELECT * FROM topic_sections
          WHERE tenant_id = ? AND course_slug = ? AND module_code = ? AND topic_code = ? AND area_index = ?',
        [tenant_id(), $courseSlug, $moduleCode, $topicCode, $areaIndex]
    );
}

/**
 * Create or replace the content for one area.
 *
 * @return 'added'|'updated'|'removed'|'unchanged'
 */
function section_set(string $courseSlug, string $moduleCode, string $topicCode, int $areaIndex,
                     string $areaTitle, string $body, bool $published, int $by): string
{
    $body     = trim($body);
    $existing = section_get($courseSlug, $moduleCode, $topicCode, $areaIndex);

    if ($body === '') {
        if ($existing === null) return 'unchanged';
        db_run('DELETE FROM topic_sections WHERE id = ? AND tenant_id = ?',
               [(int) $existing['id'], tenant_id()]);
        audit('section.removed', 'topic_sections', (int) $existing['id'], $topicCode . ' #' . $areaIndex);
        return 'removed';
    }

    if ($existing !== null) {
        if ((string) $existing['body'] === $body
            && (bool) $existing['published'] === $published
            && (string) $existing['area_title'] === $areaTitle) {
            return 'unchanged';
        }
        db_run('UPDATE topic_sections SET area_title = ?, body = ?, published = ?, updated_at = ?, updated_by = ?
                 WHERE id = ? AND tenant_id = ?',
               [$areaTitle, $body, $published ? 1 : 0, now(), $by, (int) $existing['id'], tenant_id()]);
        audit('section.updated', 'topic_sections', (int) $existing['id'], $topicCode . ' #' . $areaIndex);
        return 'updated';
    }

    $id = db_insert('topic_sections', [
        'tenant_id'   => tenant_id(),
        'course_slug' => $courseSlug,
        'module_code' => $moduleCode,
        'topic_code'  => $topicCode,
        'area_index'  => $areaIndex,
        'area_title'  => $areaTitle,
        'body'        => $body,
        'published'   => $published ? 1 : 0,
        'updated_at'  => now(),
        'updated_by'  => $by,
    ]);
    audit('section.added', 'topic_sections', $id, $topicCode . ' #' . $areaIndex);
    return 'added';
}

/**
 * Turn stored plain text into safe HTML.
 *
 * Blank line = new paragraph. A line starting "- " is a bullet. Everything is
 * escaped BEFORE any markup is added, so nothing an administrator types can
 * become an element — see this file's header on why the stored form is text.
 */
function section_body_html(string $body): string
{
    $body = str_replace("\r\n", "\n", $body);
    $out  = '';
    $list = false;                 // is a <ul> currently open?

    foreach (preg_split('/\n{2,}/', trim($body)) as $block) {
        $lines   = explode("\n", trim($block));
        $bullets = array_filter($lines, fn($l) => str_starts_with(trim($l), '- '));
        $isList  = $lines && count($bullets) === count($lines);

        if ($isList) {
            /* Only open a list if one is not already open. Blocks are split on
               blank lines, and a writer separating each bullet with one — which
               is what happens when material is converted out of a Word document,
               and is a perfectly natural way to type — used to produce a run of
               single-item lists instead of one list. That reads as extra spacing
               to a sighted reader and as several one-item lists to a screen
               reader, which is the part that actually matters. */
            if (!$list) { $out .= '<ul>'; $list = true; }
            foreach ($lines as $l) $out .= '<li>' . e(trim(substr(trim($l), 2))) . '</li>';
            continue;
        }

        if ($list) { $out .= '</ul>'; $list = false; }
        $out .= '<p>' . nl2br(e(implode("\n", $lines))) . '</p>';
    }
    if ($list) $out .= '</ul>';

    return $out;
}

/** How many areas of this module have published content — for the admin list. */
function sections_count_for_course(string $courseSlug): array
{
    $out = [];
    foreach (db_all(
        'SELECT module_code, COUNT(*) AS n, SUM(published) AS pub FROM topic_sections
          WHERE tenant_id = ? AND course_slug = ? GROUP BY module_code',
        [tenant_id(), $courseSlug]) as $r) {
        $out[(string) $r['module_code']] = ['total' => (int) $r['n'], 'published' => (int) $r['pub']];
    }
    return $out;
}
