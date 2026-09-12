<?php
declare(strict_types=1);

/* In-person classes, and the register a facilitator marks.
 *
 * Added 11 Sep 2026 at Tarryn's request: the academy also teaches in a room,
 * and the room needs a register that somebody signs their name to.
 *
 * WHO DOES WHAT, AND WHY IT IS SPLIT THAT WAY
 *
 *   An ADMIN schedules a class and says who is facilitating it.
 *   A FACILITATOR marks the register for their own classes, and nothing else.
 *
 * That split is the whole point. The standing rule on this platform is that
 * trainers teach and the academy loads the material — require_write() blocks a
 * trainer from writing on every content page. Marking a register is the one
 * write a trainer SHOULD be able to make, because they are the only person in
 * the room and the register is worthless if somebody else fills it in
 * afterwards from memory. So this file carries its own, much narrower
 * permission: class_may_mark(). It grants exactly one thing, on exactly the
 * classes that person is named on, and it is not a general trainer write.
 *
 * ATTENDANCE IS NOT ENROLMENT. The register is drawn from whoever is enrolled
 * when it is opened, but a mark is a fact about a day, not about a course.
 * Un-enrolling somebody must not erase that they were in the room, which is
 * why class_attendance holds user_id directly rather than an enrolment id.
 *
 * NOT MARKED IS NOT ABSENT. A learner with no row has not been marked; a
 * learner marked absent has been. An audit reads those very differently, and
 * the register shows them differently for the same reason. Nothing in here
 * ever invents a default mark.
 */

defined('APP_BOOTED') or exit('lib/classes.php is not a page.');

/** The marks a facilitator can give. Anything else is refused, not coerced. */
const CLASS_STATUSES = ['present', 'absent', 'late', 'excused'];

/** A class's own lifecycle. 'held' is set when a register is first marked. */
const CLASS_STATES = ['scheduled', 'held', 'cancelled'];

function class_status_label(string $s): string
{
    return [
        'present' => 'Present',
        'absent'  => 'Absent',
        'late'    => 'Late',
        'excused' => 'Excused',
    ][$s] ?? $s;
}

/* ---------------------------------------------------------------------------
   Reading
   --------------------------------------------------------------------------- */

/**
 * Classes, newest sitting first.
 *
 * @param string[]|null $courses null for "every course" (an admin), a list for
 *        a trainer — an EMPTY list means nothing, exactly as trainer_slugs()
 *        defines it, and this returns no rows rather than all of them.
 * @return array<int,array<string,mixed>>
 */
function classes_list(?array $courses, int $limit = 200): array
{
    $sql = 'SELECT c.*, u.first_name AS f_first, u.last_name AS f_last,
                   (SELECT COUNT(*) FROM class_attendance a
                     WHERE a.tenant_id = c.tenant_id AND a.class_id = c.id) AS marked
              FROM classes c
              LEFT JOIN users u ON u.id = c.facilitator_id AND u.tenant_id = c.tenant_id
             WHERE c.tenant_id = ?';
    $args = [tenant_id()];

    if ($courses !== null) {
        if (!$courses) return [];                 // fail closed, like trainer_slugs()
        $sql .= ' AND c.course_slug IN (' . implode(',', array_fill(0, count($courses), '?')) . ')';
        $args = array_merge($args, $courses);
    }

    $sql .= ' ORDER BY c.held_on DESC, c.id DESC LIMIT ' . max(1, min(1000, $limit));
    return db_all($sql, $args);
}

function class_get(int $id): ?array
{
    return db_one(
        'SELECT c.*, u.first_name AS f_first, u.last_name AS f_last
           FROM classes c
           LEFT JOIN users u ON u.id = c.facilitator_id AND u.tenant_id = c.tenant_id
          WHERE c.id = ? AND c.tenant_id = ?',
        [$id, tenant_id()]
    );
}

/**
 * The register for one class: everyone enrolled on the course, with whatever
 * mark they already have.
 *
 * LEFT JOIN, so somebody unmarked appears with a null status — see the note in
 * this file's header on why that is not the same as absent.
 */
function class_register(int $classId, string $courseSlug): array
{
    return db_all(
        'SELECT u.id, u.first_name, u.last_name, u.email, u.employee_no,
                a.status, a.note, a.marked_at,
                m.first_name AS by_first, m.last_name AS by_last
           FROM enrolments e
           JOIN users u ON u.id = e.user_id AND u.tenant_id = e.tenant_id
           LEFT JOIN class_attendance a
                  ON a.class_id = ? AND a.user_id = u.id AND a.tenant_id = e.tenant_id
           LEFT JOIN users m ON m.id = a.marked_by AND m.tenant_id = e.tenant_id
          WHERE e.tenant_id = ? AND e.course_slug = ? AND e.status = ?
          ORDER BY u.last_name, u.first_name',
        [$classId, tenant_id(), $courseSlug, 'active']
    );
}

/** present/absent/late/excused counts plus how many are still unmarked. */
function class_tally(int $classId, string $courseSlug): array
{
    $out = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'unmarked' => 0, 'total' => 0];
    foreach (class_register($classId, $courseSlug) as $r) {
        $out['total']++;
        $s = (string) ($r['status'] ?? '');
        if ($s === '' || !in_array($s, CLASS_STATUSES, true)) $out['unmarked']++;
        else $out[$s]++;
    }
    return $out;
}

/* ---------------------------------------------------------------------------
   Permission — deliberately narrow. See the header.
   --------------------------------------------------------------------------- */

/**
 * May this person mark THIS class's register?
 *
 * An admin may mark any class, because an admin can already do everything and
 * somebody has to be able to fix a register when the facilitator has left.
 *
 * A trainer may mark a class only when they are named on it as the
 * facilitator. Being assigned the course is not enough: the claim a register
 * makes is "I was in the room", and the person who was in the room is the one
 * the class names. This is the single write a trainer has on the platform.
 */
function class_may_mark(array $me, array $class): bool
{
    if (($me['role'] ?? '') === 'admin') return true;
    if (($me['role'] ?? '') !== 'trainer') return false;
    return $class['facilitator_id'] !== null
        && (int) $class['facilitator_id'] === (int) $me['id'];
}

/* ---------------------------------------------------------------------------
   Writing
   --------------------------------------------------------------------------- */

/**
 * Create a class. Admin-only at the caller; nothing here assumes that, so the
 * caller must check.
 *
 * @param array<string,mixed> $in
 * @return array{0:bool,1:string,2:int}  ok, message, new id
 */
function class_create(array $in, int $by): array
{
    $title  = trim((string) ($in['title'] ?? ''));
    $course = (string) ($in['course_slug'] ?? '');
    $heldOn = trim((string) ($in['held_on'] ?? ''));

    if ($title === '')  return [false, 'Give the class a name, so the register says what it was.', 0];
    if (mb_strlen($title) > 160) return [false, 'That name is too long — 160 characters at most.', 0];
    if (!learner_course_valid($course)) return [false, 'Choose a course this class belongs to.', 0];
    if (!class_valid_date($heldOn)) return [false, 'Give the date it is held, as YYYY-MM-DD.', 0];

    $facil = isset($in['facilitator_id']) && $in['facilitator_id'] !== ''
        ? (int) $in['facilitator_id'] : null;
    if ($facil !== null && !class_is_staff_id($facil)) {
        return [false, 'That facilitator is not an admin or trainer on this academy.', 0];
    }

    $id = db_insert('classes', [
        'tenant_id'      => tenant_id(),
        'course_slug'    => $course,
        'title'          => $title,
        'venue'          => class_trim_or_null($in['venue'] ?? null, 160),
        'held_on'        => $heldOn,
        'starts_at'      => class_valid_time((string) ($in['starts_at'] ?? '')) ? (string) $in['starts_at'] : null,
        'ends_at'        => class_valid_time((string) ($in['ends_at'] ?? '')) ? (string) $in['ends_at'] : null,
        'facilitator_id' => $facil,
        'notes'          => class_trim_or_null($in['notes'] ?? null, 2000),
        'status'         => 'scheduled',
        'created_at'     => now(),
        'created_by'     => $by,
    ]);

    audit('class.created', 'classes', $id, $course . ' — ' . $title . ' on ' . $heldOn);
    return [true, 'Class added. The facilitator can mark its register from the day it runs.', $id];
}

/** Change a class's details. Same validation as creating one. */
function class_update(int $id, array $in, int $by): array
{
    $class = class_get($id);
    if ($class === null) return [false, 'That class no longer exists.', 0];

    $title  = trim((string) ($in['title'] ?? ''));
    $heldOn = trim((string) ($in['held_on'] ?? ''));
    if ($title === '') return [false, 'Give the class a name.', 0];
    if (!class_valid_date($heldOn)) return [false, 'Give the date it is held, as YYYY-MM-DD.', 0];

    $facil = isset($in['facilitator_id']) && $in['facilitator_id'] !== ''
        ? (int) $in['facilitator_id'] : null;
    if ($facil !== null && !class_is_staff_id($facil)) {
        return [false, 'That facilitator is not an admin or trainer on this academy.', 0];
    }

    $state = (string) ($in['status'] ?? $class['status']);
    if (!in_array($state, CLASS_STATES, true)) $state = (string) $class['status'];

    db_run(
        'UPDATE classes SET title = ?, venue = ?, held_on = ?, starts_at = ?, ends_at = ?,
                facilitator_id = ?, notes = ?, status = ?
          WHERE id = ? AND tenant_id = ?',
        [
            $title,
            class_trim_or_null($in['venue'] ?? null, 160),
            $heldOn,
            class_valid_time((string) ($in['starts_at'] ?? '')) ? (string) $in['starts_at'] : null,
            class_valid_time((string) ($in['ends_at'] ?? '')) ? (string) $in['ends_at'] : null,
            $facil,
            class_trim_or_null($in['notes'] ?? null, 2000),
            $state,
            $id, tenant_id(),
        ]
    );

    audit('class.updated', 'classes', $id, $title . ' on ' . $heldOn . ' — ' . $state);
    return [true, 'Class updated.', $id];
}

/**
 * Save a whole register in one go.
 *
 * ONE TRANSACTION, because a half-saved register is a lie about a room: a
 * facilitator who ticks twelve people and gets eight written has evidence that
 * four people were absent when they were not.
 *
 * Unmarked stays unmarked. A learner whose radio was left alone has no row
 * written and any existing row is left as it was — clearing a mark is done by
 * choosing a different one, never by silence.
 *
 * @param array<int,string> $marks user_id => status
 * @param array<int,string> $notes user_id => note
 * @return array{0:bool,1:string,2:int} ok, message, rows written
 */
function class_mark_register(int $classId, string $courseSlug, array $marks, array $notes, int $by): array
{
    $class = class_get($classId);
    if ($class === null) return [false, 'That class no longer exists.', 0];

    /* Only people actually on the register may be marked. A posted user_id for
       somebody not enrolled is dropped rather than written — the form cannot
       offer them, so a request carrying one is either stale or crafted. */
    $eligible = [];
    foreach (class_register($classId, $courseSlug) as $r) $eligible[(int) $r['id']] = true;

    $clean = [];
    foreach ($marks as $uid => $status) {
        $uid = (int) $uid;
        $status = (string) $status;
        if ($status === '') continue;                       // left alone
        if (!isset($eligible[$uid])) continue;              // not on this register
        if (!in_array($status, CLASS_STATUSES, true)) continue;
        $clean[$uid] = $status;
    }

    if (!$clean) return [false, 'Nothing was marked, so nothing was saved.', 0];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($clean as $uid => $status) {
            $note = class_trim_or_null($notes[$uid] ?? null, 200);
            $existing = db_value(
                'SELECT id FROM class_attendance WHERE tenant_id = ? AND class_id = ? AND user_id = ?',
                [tenant_id(), $classId, $uid]
            );
            if ($existing !== null) {
                db_run('UPDATE class_attendance SET status = ?, note = ?, marked_at = ?, marked_by = ?
                         WHERE id = ? AND tenant_id = ?',
                       [$status, $note, now(), $by, (int) $existing, tenant_id()]);
            } else {
                db_insert('class_attendance', [
                    'tenant_id' => tenant_id(), 'class_id' => $classId, 'user_id' => $uid,
                    'status' => $status, 'note' => $note, 'marked_at' => now(), 'marked_by' => $by,
                ]);
            }
        }
        /* A register that has been marked means the class happened. Only ever
           moves scheduled -> held; a cancelled class stays cancelled, because
           somebody deciding it was cancelled outranks a stray tick. */
        if ((string) $class['status'] === 'scheduled') {
            db_run('UPDATE classes SET status = ? WHERE id = ? AND tenant_id = ?',
                   ['held', $classId, tenant_id()]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        app_log('REGISTER SAVE FAILED (class ' . $classId . '): ' . $e->getMessage());
        return [false, 'That register could not be saved. Nothing was changed — try again.', 0];
    }

    audit('class.register_marked', 'classes', $classId,
          $courseSlug . ' — ' . count($clean) . ' marked');

    return [true, count($clean) . ' ' . (count($clean) === 1 ? 'learner' : 'learners') . ' marked.', count($clean)];
}

/* ---------------------------------------------------------------------------
   Small helpers
   --------------------------------------------------------------------------- */

/** Admins and trainers — the people who can be named as a facilitator. */
function class_staff_choices(): array
{
    return db_all(
        'SELECT id, first_name, last_name, role FROM users
          WHERE tenant_id = ? AND status = ? AND role IN (?, ?)
          ORDER BY last_name, first_name',
        [tenant_id(), 'active', 'admin', 'trainer']
    );
}

function class_is_staff_id(int $id): bool
{
    return db_value(
        'SELECT id FROM users WHERE id = ? AND tenant_id = ? AND role IN (?, ?)',
        [$id, tenant_id(), 'admin', 'trainer']
    ) !== null;
}

function class_valid_date(string $s): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return false;
    [$y, $m, $d] = array_map('intval', explode('-', $s));
    return checkdate($m, $d, $y);
}

function class_valid_time(string $s): bool
{
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s);
}

function class_trim_or_null($v, int $max): ?string
{
    $s = trim((string) ($v ?? ''));
    if ($s === '') return null;
    return mb_substr($s, 0, $max);
}

/** "Wed 14 Oct 2026", or the raw value if it is not a date we wrote. */
function class_when(string $heldOn, ?string $from = null, ?string $to = null): string
{
    $d = date_create($heldOn);
    $out = $d ? $d->format('D j M Y') : $heldOn;
    if ($from) $out .= ' · ' . $from . ($to ? '–' . $to : '');
    return $out;
}
