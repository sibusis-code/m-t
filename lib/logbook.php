<?php
declare(strict_types=1);

/* The learner's logbook, and where the paper is.
 *
 * Added 25 Sep 2026. Two things arrived together and they belong together,
 * because they are both about the portfolio of evidence:
 *
 *   THE LOGBOOK   a dated record of what the learner did — typed here, printed,
 *                 signed by their facilitator, filed in the POE.
 *   THE PAPER     which workbooks and logbooks have actually been handed in,
 *                 assessed, and handed back.
 *
 * WHAT NEITHER OF THEM IS
 *
 * Neither is a submission, and no code may present either as one. Every
 * submission on these qualifications is physical: the learner completes the
 * workbook on paper, hands it to their facilitator, and the facilitator builds
 * the file that goes for assessment, moderation and verification. What this
 * platform holds is a legible copy to print and a record of where the paper got
 * to — never the evidence itself, and never a mark.
 *
 * That is not a limitation to be designed around later. A signed hardcopy is
 * what the assessor, the moderator and the verifier work from, so a second
 * unsigned copy stored here could only ever disagree with the real one. The
 * logbook is typed because handwriting in a book on a building site gets lost
 * and cannot be read; that is the whole benefit claimed, and it is enough.
 *
 * WHY THE LEARNER OWNS THEIR LOGBOOK ROWS AND STAFF OWN THE HAND-IN ROWS
 *
 * A learner writes their own logbook — nobody else can say what they did on
 * Tuesday. Only staff record a hand-in, because the point of a hand-in is that a
 * named person took custody of the paper, and a learner ticking "handed in"
 * themselves would be evidence of nothing. The two permissions are therefore
 * different and are checked in different functions.
 */

defined('APP_BOOTED') or exit('lib/logbook.php is not a page.');

/* One learner's logbook on one course cannot sensibly run past this. Not a
   business rule — the ceiling that stops a scripted POST filling a disk, the
   same reasoning as LEARNER_PROGRESS_MAX_ROWS. */
const LOGBOOK_MAX_ROWS = 2000;

/** What goes in and out of a facilitator's hands. Constrained in PHP, per the schema note. */
const POE_KINDS = ['workbook', 'logbook'];

/* handed_in → assessed → returned. Only ever set by staff, and never inferred:
   a workbook that was handed in and lost is not "assessed", and the register's
   rule about not inventing a default mark applies here for the same reason. */
const POE_STATUSES = ['handed_in', 'assessed', 'returned'];

function poe_kind_label(string $kind): string
{
    return ['workbook' => 'Workbook', 'logbook' => 'Logbook'][$kind] ?? $kind;
}

/**
 * Two letters for a grid cell.
 *
 * The tracking page is a grid of thirty learners by six modules, so a cell has
 * room for a mark and not a sentence. The full wording is on the cell's title
 * attribute and in the key under the table — an abbreviation nobody can expand is
 * worse than no grid.
 */
function poe_short(string $status): string
{
    return ['handed_in' => 'In', 'assessed' => 'As', 'returned' => 'Rt'][$status] ?? '?';
}

function poe_status_label(string $status): string
{
    return [
        'handed_in' => 'Handed in',
        'assessed'  => 'Assessed',
        'returned'  => 'Returned to the learner',
    ][$status] ?? $status;
}

/* ---------------------------------------------------------------------------
   The logbook
   --------------------------------------------------------------------------- */

/**
 * Minutes as a person writes them.
 *
 * Stored as minutes and formatted here, never the other way round — see the note
 * above logbook_entries in schema/schema.mysql.sql on why a decimal number of
 * hours is a logbook that disagrees with itself.
 */
function logbook_hours(?int $minutes): string
{
    if ($minutes === null || $minutes <= 0) return '—';
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h === 0) return $m . ' min';
    if ($m === 0) return $h . ' hr' . ($h === 1 ? '' : 's');
    return $h . ' hr' . ($h === 1 ? '' : 's') . ' ' . $m . ' min';
}

/**
 * The stored minutes, back in the box the learner typed them into.
 *
 * Deliberately not logbook_hours(): that renders "2 hrs 30 min" for reading, and
 * putting that back in an input invites somebody to save it as-is — which parses,
 * but only because logbook_minutes_from() is generous. This is the short form the
 * placeholder asks for, so an edit that changes nothing else stores the same
 * number it started with.
 */
function logbook_hours_input(?array $entry): string
{
    $m = $entry !== null && $entry['minutes'] !== null ? (int) $entry['minutes'] : 0;
    if ($m <= 0) return '';
    $h = intdiv($m, 60);
    $r = $m % 60;
    if ($h === 0) return $r . 'm';
    return $r === 0 ? (string) $h : $h . 'h ' . $r . 'm';
}

/**
 * "2h 30m" or "2.5" or "90m" as typed by a learner, in minutes.
 *
 * Deliberately generous about the format. This field is filled in on a phone at
 * the end of a working day, and refusing "1h30" to insist on "90" would mean the
 * entry does not get written at all. Anything unreadable becomes null rather than
 * a guess: a blank is honest, an invented number is in a portfolio of evidence.
 */
function logbook_minutes_from(string $raw): ?int
{
    $s = strtolower(trim($raw));
    if ($s === '') return null;

    if (preg_match('/^(\d+)\s*h(?:ours?|rs?)?\s*(\d+)?\s*m?(?:ins?|inutes?)?$/', $s, $m)) {
        $mins = (int) $m[1] * 60 + (int) ($m[2] ?? 0);
    } elseif (preg_match('/^(\d+)\s*m(?:ins?|inutes?)?$/', $s, $m)) {
        /* The "m" itself is required and only its tail is optional, so "45m" is
           45 minutes while a bare "45" falls through to the hours branch below.
           Making the m optional here would quietly turn every bare number into
           minutes, and "3" in a logbook means three hours. */
        $mins = (int) $m[1];
    } elseif (preg_match('/^(\d+)[.,](\d+)$/', $s, $m)) {
        $mins = (int) round(((float) ($m[1] . '.' . $m[2])) * 60);
    } elseif (preg_match('/^\d+$/', $s)) {
        /* A bare number is HOURS, because that is what the label asks for and
           what somebody writing "3" in a logbook means. */
        $mins = (int) $s * 60;
    } else {
        return null;
    }
    // A SMALLINT UNSIGNED holds 65535; a day is 1440. Anything past a fortnight
    // of continuous work is a typo, and a typo must not become a stored fact.
    return $mins > 0 && $mins <= 20160 ? $mins : null;
}

/** One learner's entries for one course, newest work first. */
function logbook_entries(int $userId, string $courseSlug): array
{
    return db_all(
        'SELECT * FROM logbook_entries
          WHERE tenant_id = ? AND user_id = ? AND course_slug = ?
          ORDER BY entry_date DESC, id DESC',
        [tenant_id(), $userId, $courseSlug]
    );
}

/** Total minutes logged on one course, for the line at the foot of the print-out. */
function logbook_total_minutes(int $userId, string $courseSlug): int
{
    return (int) db_value(
        'SELECT COALESCE(SUM(minutes), 0) FROM logbook_entries
          WHERE tenant_id = ? AND user_id = ? AND course_slug = ?',
        [tenant_id(), $userId, $courseSlug]
    );
}

/** One entry, scoped to its owner — never by id alone. */
function logbook_entry_get(int $id, int $userId): ?array
{
    $row = db_one('SELECT * FROM logbook_entries WHERE id = ? AND tenant_id = ? AND user_id = ?',
                  [$id, tenant_id(), $userId]);
    return $row ?: null;
}

/**
 * Add or update one entry.
 *
 * @param array<string,mixed> $in  entry_date, activity, unit_code, hours, evidence
 * @return array{0:bool,1:string}
 */
function logbook_save(int $userId, string $courseSlug, array $in, ?int $id = null): array
{
    if (!learner_course_valid($courseSlug)) return [false, 'That is not a course on this academy.'];

    $date     = trim((string) ($in['entry_date'] ?? ''));
    $activity = trim((string) ($in['activity'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate(
        (int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)
    )) {
        return [false, 'Give the date you did the work, as YYYY-MM-DD.'];
    }
    /* A logbook records what happened, so a future date is always a mistake —
       and a mistake that reads as a claim about work not yet done. Tomorrow is
       allowed, because a phone an hour ahead of the server should not be an
       error message. */
    if ($date > gmdate('Y-m-d', time() + 86400)) {
        return [false, 'That date is in the future. A logbook records work you have already done.'];
    }
    if ($activity === '')             return [false, 'Say what you did — that is the entry.'];
    if (mb_strlen($activity) > 2000)  return [false, 'That entry is very long — 2000 characters at most.'];

    $unit = trim((string) ($in['unit_code'] ?? ''));
    if ($unit !== '' && !preg_match('/^[A-Za-z0-9 .\-]{1,30}$/', $unit)) {
        return [false, 'The unit standard or section should be a code such as SP1-02 or 114596.'];
    }

    $minutes  = logbook_minutes_from((string) ($in['hours'] ?? ''));
    $evidence = trim((string) ($in['evidence'] ?? ''));
    $evidence = $evidence === '' ? null : mb_substr($evidence, 0, 190);
    $now      = now();

    if ($id !== null) {
        if (logbook_entry_get($id, $userId) === null) return [false, 'That entry is not yours to change.'];
        db_run('UPDATE logbook_entries SET unit_code = ?, entry_date = ?, minutes = ?,
                       activity = ?, evidence = ?, updated_at = ?
                 WHERE id = ? AND tenant_id = ? AND user_id = ?',
               [$unit !== '' ? $unit : null, $date, $minutes, $activity, $evidence, $now,
                $id, tenant_id(), $userId]);
        audit('logbook.updated', 'logbook_entries', $id, $courseSlug . ' — ' . $date);
        return [true, 'Entry updated.'];
    }

    $have = (int) db_value('SELECT COUNT(*) FROM logbook_entries WHERE tenant_id = ? AND user_id = ?',
                           [tenant_id(), $userId]);
    if ($have >= LOGBOOK_MAX_ROWS) {
        app_log('LOGBOOK CAP hit — user ' . $userId);
        return [false, 'Your logbook has as many entries as this page can hold. '
                     . 'Speak to the academy.'];
    }

    $newId = db_insert('logbook_entries', [
        'tenant_id'   => tenant_id(),
        'user_id'     => $userId,
        'course_slug' => $courseSlug,
        'unit_code'   => $unit !== '' ? $unit : null,
        'entry_date'  => $date,
        'minutes'     => $minutes,
        'activity'    => $activity,
        'evidence'    => $evidence,
        'created_at'  => $now,
        'updated_at'  => $now,
    ]);
    audit('logbook.added', 'logbook_entries', $newId, $courseSlug . ' — ' . $date);
    return [true, 'Entry added to your logbook.'];
}

/**
 * Delete one of your own entries.
 *
 * A learner may delete their own rows, and that is deliberate: this is their
 * record of their own work, not an audit trail, and a typo they cannot remove
 * would end up printed into a portfolio. Once the printout is signed it is the
 * evidence, and nothing here can alter that.
 */
function logbook_delete(int $id, int $userId): array
{
    $row = logbook_entry_get($id, $userId);
    if ($row === null) return [false, 'That entry is not yours to remove.'];
    db_run('DELETE FROM logbook_entries WHERE id = ? AND tenant_id = ? AND user_id = ?',
           [$id, tenant_id(), $userId]);
    audit('logbook.deleted', 'logbook_entries', $id,
          (string) $row['course_slug'] . ' — ' . (string) $row['entry_date']);
    return [true, 'Entry removed.'];
}

/* ---------------------------------------------------------------------------
   Where the paper is
   --------------------------------------------------------------------------- */

/**
 * May this person record a hand-in on this course?
 *
 * The same narrow grant as class_may_mark() and quiz_may_grant_attempt(), for
 * the same reason: the person the paper is handed to is the facilitator in the
 * room. Recording it a week later from memory, by somebody who was not there, is
 * how a portfolio ends up saying something nobody can stand behind.
 */
function poe_may_record(string $courseSlug, ?array $u = null): bool
{
    /* The PASSED user decides, falling back to the session. An earlier version
       asked is_admin(), which reads the session and ignored the argument — so the
       answer for an explicitly named person was whatever happened to be true of
       whoever was signed in. Harmless where a page passes its own $me, wrong
       anywhere else, and impossible to test from the command line. */
    $u = $u ?? current_user();
    if ($u === null) return false;
    if ($u['role'] === 'admin') return true;
    return $u['role'] === 'trainer' && may_see_course($courseSlug, $u);
}

/** One learner's hand-ins on one course, keyed "moduleCode:kind". */
function poe_for_learner(int $userId, string $courseSlug): array
{
    $out = [];
    foreach (db_all(
        'SELECT p.*, u.first_name AS s_first, u.last_name AS s_last
           FROM poe_submissions p
           LEFT JOIN users u ON u.id = p.updated_by AND u.tenant_id = p.tenant_id
          WHERE p.tenant_id = ? AND p.user_id = ? AND p.course_slug = ?',
        [tenant_id(), $userId, $courseSlug]
    ) as $r) {
        $r['staff'] = trim((string) ($r['s_first'] ?? '') . ' ' . (string) ($r['s_last'] ?? ''));
        $out[(string) $r['module_code'] . ':' . (string) $r['kind']] = $r;
    }
    return $out;
}

/**
 * Everybody's hand-ins on one course, keyed "userId:moduleCode:kind".
 *
 * One query for the whole tracking grid. A course with thirty learners and six
 * modules is 360 possible slots, and asking per cell would be a page that takes
 * a minute to draw.
 */
function poe_for_course(string $courseSlug): array
{
    $out = [];
    foreach (db_all(
        'SELECT * FROM poe_submissions WHERE tenant_id = ? AND course_slug = ?',
        [tenant_id(), $courseSlug]
    ) as $r) {
        $out[(int) $r['user_id'] . ':' . (string) $r['module_code'] . ':' . (string) $r['kind']] = $r;
    }
    return $out;
}

/**
 * Record where a piece of paper is, or clear the record.
 *
 * An empty status DELETES the row, because the absence of a row is what "not
 * handed in" means here — writing a fourth status to mean the same thing would
 * give the grid two ways to say one fact. See the note on why there is no
 * 'issued' status in schema/schema.mysql.sql.
 *
 * @return array{0:bool,1:string}
 */
function poe_record(string $courseSlug, string $moduleCode, int $userId, string $kind,
                    string $status, string $onDate, ?string $note, int $by): array
{
    if (!in_array($kind, POE_KINDS, true))        return [false, 'That is not something learners hand in.'];
    if (!learner_course_valid($courseSlug))       return [false, 'That is not a course on this academy.'];
    if (!learner_valid_code($moduleCode, 20))     return [false, 'That module code did not look right.'];

    if ($status === '') {
        db_run('DELETE FROM poe_submissions
                 WHERE tenant_id = ? AND course_slug = ? AND module_code = ? AND user_id = ? AND kind = ?',
               [tenant_id(), $courseSlug, $moduleCode, $userId, $kind]);
        audit('poe.cleared', 'poe_submissions', $userId, $courseSlug . ' ' . $moduleCode . ' ' . $kind);
        return [true, 'Cleared — that ' . strtolower(poe_kind_label($kind)) . ' shows as not handed in.'];
    }
    if (!in_array($status, POE_STATUSES, true)) return [false, 'That is not a status this page sets.'];

    $date = trim($onDate);
    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return [false, 'Give the date as YYYY-MM-DD, or leave it blank.'];
    }
    if ($date === '') $date = gmdate('Y-m-d');

    $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 200) : null;
    $have = db_one(
        'SELECT id FROM poe_submissions
          WHERE tenant_id = ? AND course_slug = ? AND module_code = ? AND user_id = ? AND kind = ?',
        [tenant_id(), $courseSlug, $moduleCode, $userId, $kind]
    );

    if ($have) {
        db_run('UPDATE poe_submissions SET status = ?, on_date = ?, note = ?, updated_at = ?, updated_by = ?
                 WHERE id = ? AND tenant_id = ?',
               [$status, $date, $note, now(), $by, (int) $have['id'], tenant_id()]);
        audit('poe.updated', 'poe_submissions', (int) $have['id'],
              $courseSlug . ' ' . $moduleCode . ' ' . $kind . ' — ' . $status);
    } else {
        $id = db_insert('poe_submissions', [
            'tenant_id'   => tenant_id(),
            'course_slug' => $courseSlug,
            'module_code' => $moduleCode,
            'user_id'     => $userId,
            'kind'        => $kind,
            'status'      => $status,
            'on_date'     => $date,
            'note'        => $note,
            'updated_at'  => now(),
            'updated_by'  => $by,
        ]);
        audit('poe.recorded', 'poe_submissions', $id,
              $courseSlug . ' ' . $moduleCode . ' ' . $kind . ' — ' . $status);
    }
    return [true, poe_kind_label($kind) . ' for ' . $moduleCode . ' marked as '
                . strtolower(poe_status_label($status)) . '.'];
}
