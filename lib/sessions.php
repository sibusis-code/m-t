<?php
declare(strict_types=1);

/* Live sessions, hosted in Google Classroom.
 *
 * Added 25 Sep 2026. The decision was to teach live in Google Classroom rather
 * than build streaming into this platform, and this file is the whole of what
 * that costs us: a link per course, an optional link per session, and a way for
 * a learner to find the next one.
 *
 * WHAT THIS FILE DELIBERATELY DOES NOT DO
 *
 * It does not create Classrooms, invite anybody, read a roster, mirror a
 * recording, or know whether a meeting is running. Every one of those would mean
 * holding a second copy of something Google owns, and a second copy is the one
 * that goes stale — a learner told "no session today" by us while their
 * Classroom says otherwise is worse served than a learner given a link.
 *
 * So the platform's job here is small and honest: say where the live teaching
 * happens, say when the next sitting is, and get out of the way. Attendance is
 * still marked by the facilitator on register.php, because attendance is a fact
 * the academy has to hold for an audit whether or not Google recorded a join.
 *
 * WHY A CLASS IS "ONLINE" BECAUSE IT HAS A LINK
 *
 * There is no mode column. A class with a join link is an online session, and
 * one without is in a room. A mode flag alongside a link can disagree with it,
 * and then the page has to choose which to believe in front of a learner who is
 * already late. See the note above class_links in schema/schema.mysql.sql.
 *
 * WHY THE LINK IS NOT VALIDATED AGAINST GOOGLE
 *
 * It is checked for being an https URL of a sane length, and nothing further.
 * A Classroom link, a Meet link, a shortened link an administrator was given by
 * Centenary — all of them are legitimate, and a pattern that admits only
 * classroom.google.com would reject the real link somebody was handed on the day
 * they needed it. What matters is that it is https, so a learner's click is not
 * downgraded, and that it is stored as typed.
 */

defined('APP_BOOTED') or exit('lib/sessions.php is not a page.');

/* Where a course's live teaching lives. One kind today; constrained in PHP
   rather than by an ENUM so that adding Teams or Zoom is a code change rather
   than a migration nobody can run — see schema/schema.mysql.sql. */
const COURSE_LINK_KINDS = ['classroom'];

/** How a kind reads on a page. Learners see this, so it names the product. */
function course_link_label(string $kind): string
{
    return ['classroom' => 'Google Classroom'][$kind] ?? $kind;
}

/**
 * Is this a link we are willing to send a learner to?
 *
 * https only. Not fussiness: a live-session link is clicked from a phone on a
 * building site, and http would hand the session's contents to whatever is
 * between them and it. A link that is merely wrong sends somebody to the wrong
 * page; a link that is http can expose the class.
 */
function session_link_valid(string $url): bool
{
    if ($url === '' || mb_strlen($url) > 500) return false;
    if (!str_starts_with(strtolower($url), 'https://')) return false;
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/* ---------------------------------------------------------------------------
   The course's Classroom
   --------------------------------------------------------------------------- */

/** @return array{url:string,label:?string,updated_at:string}|null */
function course_link_get(string $courseSlug, string $kind = 'classroom'): ?array
{
    $row = db_one(
        'SELECT url, label, updated_at FROM course_links
          WHERE tenant_id = ? AND course_slug = ? AND kind = ?',
        [tenant_id(), $courseSlug, $kind]
    );
    return $row ?: null;
}

/** Every course's link, keyed by slug — one query for the admin page's list. */
function course_links_all(string $kind = 'classroom'): array
{
    $out = [];
    foreach (db_all(
        'SELECT course_slug, url, label, updated_at FROM course_links
          WHERE tenant_id = ? AND kind = ? ORDER BY course_slug',
        [tenant_id(), $kind]
    ) as $r) $out[(string) $r['course_slug']] = $r;
    return $out;
}

/**
 * Set or clear a course's Classroom link.
 *
 * An empty URL REMOVES the row rather than storing a blank, for the same reason
 * lib/materials.php deletes an emptied slot: a learner shown an empty "Join"
 * button clicks it and lands nowhere, which is worse than being told the link
 * is not up yet.
 *
 * @return array{0:bool,1:string}
 */
function course_link_set(string $courseSlug, string $kind, string $url, ?string $label, int $by): array
{
    if (!in_array($kind, COURSE_LINK_KINDS, true)) return [false, 'That is not a kind of link this page sets.'];
    if (!learner_course_valid($courseSlug))        return [false, 'That is not a course on this academy.'];

    $url = trim($url);
    if ($url === '') {
        db_run('DELETE FROM course_links WHERE tenant_id = ? AND course_slug = ? AND kind = ?',
               [tenant_id(), $courseSlug, $kind]);
        audit('course_link.removed', 'course_links', null, $courseSlug . ' ' . $kind);
        return [true, 'Removed the ' . course_link_label($kind) . ' link for that course.'];
    }
    if (!session_link_valid($url)) {
        return [false, 'That did not look like a link — it needs to start with https:// '
                     . 'and be the address you copied from ' . course_link_label($kind) . '.'];
    }

    $label = $label !== null && trim($label) !== '' ? mb_substr(trim($label), 0, 190) : null;
    $now   = now();

    if (course_link_get($courseSlug, $kind) !== null) {
        db_run('UPDATE course_links SET url = ?, label = ?, updated_at = ?, updated_by = ?
                 WHERE tenant_id = ? AND course_slug = ? AND kind = ?',
               [$url, $label, $now, $by, tenant_id(), $courseSlug, $kind]);
        audit('course_link.updated', 'course_links', null, $courseSlug . ' ' . $kind);
    } else {
        $id = db_insert('course_links', [
            'tenant_id'   => tenant_id(),
            'course_slug' => $courseSlug,
            'kind'        => $kind,
            'url'         => $url,
            'label'       => $label,
            'updated_at'  => $now,
            'updated_by'  => $by,
        ]);
        audit('course_link.added', 'course_links', $id, $courseSlug . ' ' . $kind);
    }
    return [true, course_link_label($kind) . ' link saved. Learners on that course can see it now.'];
}

/* ---------------------------------------------------------------------------
   A single session's link
   --------------------------------------------------------------------------- */

/** The join link for one class, or null if it is a class in a room. */
function class_link_get(int $classId): ?string
{
    $v = db_value('SELECT url FROM class_links WHERE tenant_id = ? AND class_id = ?',
                  [tenant_id(), $classId]);
    return $v !== null && $v !== '' ? (string) $v : null;
}

/** Join links for many classes at once, keyed by class id. */
function class_links_for(array $classIds): array
{
    $ids = array_values(array_unique(array_map('intval', $classIds)));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $out = [];
    foreach (db_all(
        'SELECT class_id, url FROM class_links WHERE tenant_id = ? AND class_id IN (' . $in . ')',
        array_merge([tenant_id()], $ids)
    ) as $r) $out[(int) $r['class_id']] = (string) $r['url'];
    return $out;
}

/**
 * Set or clear one class's join link. Empty removes it, which turns the session
 * back into one held in a room.
 *
 * @return array{0:bool,1:string}
 */
function class_link_set(int $classId, string $url, int $by): array
{
    if (class_get($classId) === null) return [false, 'That class no longer exists.'];

    $url = trim($url);
    if ($url === '') {
        db_run('DELETE FROM class_links WHERE tenant_id = ? AND class_id = ?', [tenant_id(), $classId]);
        audit('class_link.removed', 'class_links', $classId, 'now an in-person class');
        return [true, 'Removed the join link — that class now shows as being held in a room.'];
    }
    if (!session_link_valid($url)) {
        return [false, 'That did not look like a link — it needs to start with https://.'];
    }

    if (class_link_get($classId) !== null) {
        db_run('UPDATE class_links SET url = ?, updated_at = ?, updated_by = ?
                 WHERE tenant_id = ? AND class_id = ?',
               [$url, now(), $by, tenant_id(), $classId]);
        audit('class_link.updated', 'class_links', $classId, 'online session');
    } else {
        db_insert('class_links', [
            'tenant_id'  => tenant_id(),
            'class_id'   => $classId,
            'url'        => $url,
            'updated_at' => now(),
            'updated_by' => $by,
        ]);
        audit('class_link.added', 'class_links', $classId, 'online session');
    }
    return [true, 'Join link saved.'];
}

/* ---------------------------------------------------------------------------
   What a learner sees
   --------------------------------------------------------------------------- */

/**
 * The next live sessions for one learner, across every course they are on.
 *
 * FROM TODAY, NOT FROM NOW. A class that started an hour ago is the one a
 * learner is most likely to be looking for the link to — they are late, or they
 * dropped out and are getting back in. Dropping it at its start time would hide
 * exactly the session somebody is hunting for. It falls off the list at the end
 * of its day.
 *
 * Cancelled classes are included, marked, because "cancelled" is the answer to
 * "is there a class today" and silence is not.
 *
 * @return list<array<string,mixed>>  each with join_url and facilitator name
 */
function learner_sessions(int $userId, int $limit = 6): array
{
    $slugs = db_all('SELECT course_slug FROM enrolments WHERE tenant_id = ? AND user_id = ?',
                    [tenant_id(), $userId]);
    $slugs = array_values(array_map(static fn($r) => (string) $r['course_slug'], $slugs));
    if (!$slugs) return [];

    $in = implode(',', array_fill(0, count($slugs), '?'));
    $rows = db_all(
        'SELECT c.*, u.first_name AS f_first, u.last_name AS f_last
           FROM classes c
           LEFT JOIN users u ON u.id = c.facilitator_id AND u.tenant_id = c.tenant_id
          WHERE c.tenant_id = ? AND c.course_slug IN (' . $in . ') AND c.held_on >= ?
          ORDER BY c.held_on ASC, c.starts_at ASC, c.id ASC
          LIMIT ' . (int) $limit,
        array_merge([tenant_id()], $slugs, [gmdate('Y-m-d')])
    );
    if (!$rows) return [];

    $links = class_links_for(array_map(static fn($r) => (int) $r['id'], $rows));
    foreach ($rows as &$r) {
        $r['join_url']    = $links[(int) $r['id']] ?? null;
        $r['facilitator'] = trim((string) ($r['f_first'] ?? '') . ' ' . (string) ($r['f_last'] ?? ''));
    }
    return $rows;
}

/**
 * The Classroom links for the courses one learner is on, keyed by slug.
 *
 * Separate from learner_sessions() because it answers a different question and
 * survives there being no scheduled class at all — which is the normal state
 * between intakes, and exactly when a learner still wants the Classroom.
 */
function learner_course_links(int $userId, string $kind = 'classroom'): array
{
    $rows = db_all(
        'SELECT l.course_slug, l.url, l.label
           FROM course_links l
           JOIN enrolments e ON e.course_slug = l.course_slug AND e.tenant_id = l.tenant_id
          WHERE l.tenant_id = ? AND l.kind = ? AND e.user_id = ?
          ORDER BY l.course_slug',
        [tenant_id(), $kind, $userId]
    );
    $out = [];
    foreach ($rows as $r) $out[(string) $r['course_slug']] = $r;
    return $out;
}
