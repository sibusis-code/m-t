<?php
declare(strict_types=1);

/* Course material: the links, and who is allowed to be given one.
 *
 * WHAT THIS IS
 *
 * Centenary runs Google Workspace, so the learner guides, workbooks and
 * recordings live in a Shared Drive and this holds the URL for each one. The
 * academy never stores, scans, converts, streams or serves a file. Storage,
 * document viewing, video playback and the mobile apps for both are solved
 * problems, and a training platform that reimplements them is a training
 * platform that spends its life falling over instead of teaching anybody.
 *
 * WHAT IT CANNOT DO, said plainly because the design depends on knowing it
 *
 * A Drive file shared as "anyone with the link" is protected by the link and
 * nothing else. Holding the URL is holding the file. Everything below controls
 * who is HANDED a link — you must be signed in, you must be enrolled on that
 * course, and every open is written to the audit log against your name — but
 * none of it stops a learner forwarding one to somebody else.
 *
 * That is a fair trade for a learner guide, which we want people to read. It is
 * not a fair trade for assessment material, and the summative assessments,
 * marking memos and facilitator guides from the QCTO pack must never be entered
 * here. Nothing in the code can enforce that; it is a rule for whoever is
 * pasting links, and it is why admin-materials.php says so on the page.
 */

/** The three slots a module has. A fourth would be a code change, deliberately. */
const MATERIAL_KINDS = ['guide', 'workbook', 'video'];

/**
 * Hosts we will store a link to.
 *
 * Not security — an allow list cannot make a bearer URL safe. It is there to
 * catch the ordinary mistake of pasting the wrong thing into the wrong box: a
 * local file path, an internal address no learner can reach, a http:// link
 * that would downgrade the connection. It is also what makes the redirect in
 * materials.php safe to write, because the destination is known to be one of
 * these and not whatever arrived in a query string.
 */
const MATERIAL_HOSTS = [
    'drive.google.com', 'docs.google.com', 'youtube.com', 'www.youtube.com',
    'youtu.be', 'onedrive.live.com', '1drv.ms',
];

function materials_kind_valid(string $kind): bool
{
    return in_array($kind, MATERIAL_KINDS, true);
}

/**
 * Is this a link we are willing to store?
 *
 * https only. A http:// link to a document a learner opens at a client site,
 * over whatever wifi is in the building, is not something to put on a page that
 * we told them was safe.
 */
function materials_url_allowed(string $url): bool
{
    $url = trim($url);
    if ($url === '' || mb_strlen($url) > 500) return false;

    $p = parse_url($url);
    if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || ($p['host'] ?? '') === '') {
        return false;
    }
    $host = strtolower($p['host']);

    foreach (MATERIAL_HOSTS as $allowed) {
        if ($host === $allowed) return true;
    }
    // Every Microsoft 365 tenant gets its own subdomain, so this one is a suffix
    // match rather than an exact one: centenary.sharepoint.com, and so on.
    return (bool) preg_match('/(^|\.)sharepoint\.com$/', $host);
}

/** A short reason, for the administrator who just pasted something wrong. */
function materials_url_problem(string $url): string
{
    $url = trim($url);
    if ($url === '')                          return '';
    if (mb_strlen($url) > 500)                return 'That link is too long to store.';
    $p = parse_url($url);
    if (!is_array($p) || ($p['host'] ?? '') === '') return 'That does not look like a web address.';
    if (($p['scheme'] ?? '') !== 'https')     return 'It must start with https:// — an http link is not safe to send learners to.';
    return 'That address is not on Google Drive, SharePoint, OneDrive or YouTube. '
         . 'Material has to live somewhere the academy controls.';
}

/**
 * Every link for one course, keyed module_code => kind => row.
 *
 * One query rather than one per module. Eleven modules times three kinds is
 * thirty-three round trips on a page that is opened every time somebody studies.
 */
function materials_for_course(string $courseSlug): array
{
    $rows = db_all(
        'SELECT * FROM materials WHERE tenant_id = ? AND course_slug = ?
         ORDER BY module_code, kind',
        [tenant_id(), $courseSlug]
    );

    $out = [];
    foreach ($rows as $r) {
        $out[(string) $r['module_code']][(string) $r['kind']] = $r;
    }
    return $out;
}

/** One row by id, scoped to this tenant — never trust an id from a query string. */
function materials_get(int $id): ?array
{
    return db_one('SELECT * FROM materials WHERE id = ? AND tenant_id = ?', [$id, tenant_id()]);
}

/**
 * Put a link in a slot, or clear it.
 *
 * An empty URL deletes rather than storing an empty string, so "no material
 * yet" has exactly one representation — a missing row — and the page does not
 * have to decide whether '' means anything.
 */
function materials_set(
    string $courseSlug,
    string $moduleCode,
    string $kind,
    string $url,
    int $by
): string {
    $url = trim($url);

    $existing = db_one(
        'SELECT * FROM materials
         WHERE tenant_id = ? AND course_slug = ? AND module_code = ? AND kind = ?',
        [tenant_id(), $courseSlug, $moduleCode, $kind]
    );

    if ($url === '') {
        if ($existing === null) return 'unchanged';
        db_run('DELETE FROM materials WHERE id = ? AND tenant_id = ?',
               [(int) $existing['id'], tenant_id()]);
        audit('material.removed', 'materials', (int) $existing['id'],
              $moduleCode . ' ' . $kind);
        return 'removed';
    }

    if ($existing !== null && (string) $existing['url'] === $url) return 'unchanged';

    if ($existing !== null) {
        db_run('UPDATE materials SET url = ?, updated_at = ?, updated_by = ?
                WHERE id = ? AND tenant_id = ?',
               [$url, now(), $by, (int) $existing['id'], tenant_id()]);
        audit('material.replaced', 'materials', (int) $existing['id'],
              $moduleCode . ' ' . $kind);
        return 'replaced';
    }

    $id = db_insert('materials', [
        'tenant_id'   => tenant_id(),
        'course_slug' => $courseSlug,
        'module_code' => $moduleCode,
        'kind'        => $kind,
        'url'         => $url,
        'label'       => null,
        'updated_at'  => now(),
        'updated_by'  => $by,
    ]);
    audit('material.added', 'materials', $id, $moduleCode . ' ' . $kind);
    return 'added';
}

/** How many slots are filled, for the "12 of 33" line on the admin page. */
function materials_count(string $courseSlug): int
{
    return (int) db_value(
        'SELECT COUNT(*) FROM materials WHERE tenant_id = ? AND course_slug = ?',
        [tenant_id(), $courseSlug]
    );
}

/**
 * Every slot for a course, link or file, merged into the one shape
 * materials.php hands a learner's browser.
 *
 * Two independent auto-increment sequences (materials.id, material_files.id)
 * can collide numerically, so the opaque token here carries a one-letter
 * prefix — 'l' for a link, routed through the existing redirect; 'f' for a
 * file, streamed by material_file_stream(). A slot is one or the other, never
 * both (enforced on the admin write path), so this is a plain merge rather
 * than a "which one wins" decision.
 *
 * 'native' is true only for a file-backed 'video' row — it is what tells
 * materials.js whether to render a real <video> element (a raw file stream
 * has nothing else that could play it) or the existing "opens in a new tab"
 * link card (right for a YouTube/Drive URL, which needs the destination site
 * to render it).
 *
 * Each source is wrapped in db_optional() SEPARATELY, not as one call around
 * the whole function. material_files arrived in a later release than
 * materials; on a deploy that has not been migrated yet (see the note on
 * db_optional() in lib/db.php), a missing material_files table must degrade
 * to "no file-backed material" without taking the existing, already-working
 * link material down with it.
 *
 * @return array<string, array<string, array{open:string, native:bool, name:?string}>>
 */
function materials_slots_for_course(string $courseSlug): array
{
    $out = [];

    foreach (db_optional(fn() => materials_for_course($courseSlug), []) as $module => $kinds) {
        foreach ($kinds as $kind => $row) {
            $out[$module][$kind] = [
                'open' => 'materials.php?open=l' . (int) $row['id'],
                'native' => false,
                'name' => null,
            ];
        }
    }

    // material_files_for_course() lives in lib/material_files.php, which this
    // file does not require — every caller of materials_slots_for_course()
    // already requires both, same as materials.php and admin-materials.php do.
    foreach (db_optional(fn() => material_files_for_course($courseSlug), []) as $module => $kinds) {
        foreach ($kinds as $kind => $row) {
            $out[$module][$kind] = [
                'open' => 'materials.php?open=f' . (int) $row['id'],
                'native' => $kind === 'video',
                'name' => (string) $row['original_name'],
            ];
        }
    }

    return $out;
}
