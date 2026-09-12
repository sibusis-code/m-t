<?php
declare(strict_types=1);

/* Learner accounts, enrolments, and progress that survives a change of browser.
 *
 * WHY ACCOUNTS EXIST AT ALL
 *
 * Progress used to live in localStorage, which was the right answer while the
 * sites were static files on GitHub Pages — there was nowhere else to put it.
 * It has three properties nobody chose:
 *
 *   - it is tied to one browser on one device, so a learner who opens the site
 *     on their phone starts again from zero;
 *   - clearing browsing data deletes months of work with no warning;
 *   - on a shared site machine, the next learner sees the previous learner's
 *     record — which is a disclosure of somebody's training performance to a
 *     colleague, not merely an inconvenience.
 *
 * An account fixes all three, and it is the same three-line answer to every
 * "can we do what GIBS does": gated material, a real dashboard, submissions
 * that are attributable. All of it reduces to the site knowing who you are.
 *
 * HOW SOMEBODY GETS AN ACCOUNT
 *
 * An administrator presses "Enrol" on a registration. There is no self-signup,
 * and that is a decision rather than an omission: the site is on a public URL,
 * so self-signup without a verified address would mean anybody who can type an
 * email address gets an account, with nothing behind the door but our own
 * material.
 *
 * The credential can reach the learner two ways — see $delivery on
 * learner_enrol_registration() below and lib/invite.php. Both are still
 * entirely admin-initiated; only the last step differs:
 *
 *   - shown once on screen, for the administrator to hand over in person; or
 *   - a one-time set-password link, emailed by lib/invite.php.
 *
 * The email route depends on mail this server cannot yet be relied on to
 * deliver — the domain's SPF record still authorises Google rather than
 * Xneelo, see lib/mail.php — so admin.php offers both and defaults to the one
 * that always works.
 *
 * WHAT IS DELIBERATELY NOT HERE
 *
 * No consent row is written when an account is created. Consent is not the
 * lawful basis for it: the learner asked to be put on a course, and giving them
 * a way to reach the material is performance of that, not a separate purpose
 * they need to agree to. Writing a consent row nobody ticked would be evidence
 * of something that did not happen, which is worse than no row. What we do
 * record is the administrator who enrolled them, in the audit log and on the
 * enrolment itself, so "why does this person have access" has a name and a date
 * against it.
 */

defined('APP_BOOTED') or exit('lib/learner.php is not a page.');

/* Progress is one row per tick. This ceiling is not a business rule — it is the
   most rows any legitimate learner could produce (11 modules × 51 topics for
   the Project Manager, with room for a second qualification) rounded up, so a
   script pointed at the endpoint fills a log rather than a disk. */
const LEARNER_PROGRESS_MAX_ROWS = 2000;

/**
 * The qualifications an SPS learner is ENROLLED on, as opposed to the courses
 * the catalogue links to.
 *
 * The short list is the point. The internationally recognised courses on the
 * catalogue page are studied on Coursera or edX, where the enrolment is between
 * the learner and that provider — recording one here would be inventing a
 * record we do not hold. What Centenary enrols people on is the Project Manager
 * qualification, the programmes we run ourselves, and a catch-all short course
 * whose title comes from whatever the learner registered for.
 *
 * Computer Technician was here until August 2026 and was removed on Kgomotso's
 * instruction. Anyone already enrolled against that slug keeps their row: this
 * list governs what a new enrolment may be filed under, and learner_course_title()
 * falls back to the registered wording for a slug it no longer recognises.
 *
 * 'tracked' says whether this site carries the module structure for the course.
 * Today exactly one does; pm-modules.js is that structure, and it is generated
 * from the registered curriculum rather than written by hand.
 */
function learner_catalogue(): array
{
    return [
        'project-management' => [
            'title'   => 'Occupational Certificate: Project Manager',
            'note'    => 'NQF 5 · 240 credits · SAQA 101869',
            'tracked' => true,
        ],
        'ai-software-development' => [
            'title'   => 'AI & Software Development',
            'note'    => 'A professional programme, not an accredited qualification',
            'tracked' => false,   // no module structure on the site yet
        ],
        /* "Skills course" is Kgomotso's own label for these, given 22 Aug 2026:
           a customised short course, built with a specialist, deliberately not
           accredited. Only the Project Manager route is the qualification. */
        'procurement' => [
            'title'   => 'Procurement Skills',
            'note'    => 'A skills course — customised, and not accredited',
            'tracked' => false,   // no module structure on the site yet
        ],
        'short-course' => [
            'title'   => null,   // whatever the registration asked for
            'note'    => 'A short course, not an accredited qualification',
            'tracked' => false,
        ],
    ];
}

function learner_course_valid(string $slug): bool
{
    return array_key_exists($slug, learner_catalogue());
}

/** The title to store on the enrolment, given the slug and what was registered for. */
function learner_course_title(string $slug, ?string $registered = null): string
{
    $cat = learner_catalogue()[$slug] ?? null;
    if ($cat === null) return trim((string) $registered) ?: 'Course to be confirmed';
    if ($cat['title'] !== null) return $cat['title'];
    return trim((string) $registered) ?: 'Course to be confirmed';
}

function learner_course_tracked(string $slug): bool
{
    return (bool) (learner_catalogue()[$slug]['tracked'] ?? false);
}

/**
 * Split a single name field into first and last.
 *
 * The registration form asks for one "Name", because asking a person to file
 * themselves into two boxes is a small rudeness and the form is the first thing
 * they see. The users table has two columns because greetings and sorting both
 * want the first name on its own. This is where the two shapes meet, and it
 * does the obvious thing: everything after the first word is the surname, and a
 * single word is a first name with no surname rather than a surname with no
 * first name — "Hello, Thabo" reads better than "Hello," on the dashboard.
 */
function learner_split_name(string $full): array
{
    $parts = preg_split('/\s+/', trim($full), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$parts) return ['Learner', ''];
    $first = array_shift($parts);
    return [mb_substr($first, 0, 80), mb_substr(implode(' ', $parts), 0, 80)];
}

/* ---------------------------------------------------------------------------
   Enrolling
   --------------------------------------------------------------------------- */

/**
 * Turn a registration into a learner account and an enrolment.
 *
 * Idempotent in the ways that matter, because the button is on a table row and
 * will be pressed twice. An existing account is reused and its password is NOT
 * reset — resetting it would lock out somebody who is already working, to fix
 * nothing. An existing enrolment is left alone.
 *
 * $delivery chooses how a NEW account's credential reaches the learner:
 *
 *   'show'   (default) — a generated password is returned once, for the
 *            administrator to read off the screen and hand over in person or
 *            by phone. Always works, whatever state the domain's DNS is in.
 *   'invite' — no password is generated or ever known to us. Instead the new
 *            row gets an unusable random hash and an email carrying a
 *            one-time set-password link — see lib/invite.php. Depends on mail
 *            actually arriving, which mail from this server cannot yet be
 *            relied on to do; the caller must check 'invite_sent' and fall
 *            back to telling the administrator to use 'show' for this person
 *            if it came back false.
 *
 * Meaningless, and ignored, when the person already has an account: an
 * existing password is never touched by enrolling them on a second course.
 *
 * @return array{
 *   ok: bool, message: string, user: ?array, password: ?string,
 *   user_created: bool, enrolment_created: bool,
 *   invited: bool, invite_sent: ?bool
 * }
 */
function learner_enrol_registration(int $regId, string $courseSlug, string $delivery = 'show'): array
{
    $fail = static fn(string $m): array => [
        'ok' => false, 'message' => $m, 'user' => null, 'password' => null,
        'user_created' => false, 'enrolment_created' => false,
        'invited' => false, 'invite_sent' => null,
    ];

    if (!learner_course_valid($courseSlug)) {
        return $fail('That is not a course anyone can be enrolled on.');
    }

    $reg = db_one('SELECT * FROM registrations WHERE id = ? AND tenant_id = ?',
                  [$regId, tenant_id()]);
    if ($reg === null) return $fail('That registration no longer exists.');

    $email = mb_strtolower(trim((string) $reg['email']));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $fail('That registration has no usable email address, so there is '
                   . 'nothing to sign in with. Correct it with the learner first.');
    }

    $title      = learner_course_title($courseSlug, $reg['course_title'] ?? null);
    $admin      = current_user();
    $viaInvite  = $delivery === 'invite';

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $user         = db_one('SELECT * FROM users WHERE tenant_id = ? AND email = ?',
                               [tenant_id(), $email]);
        $password     = null;
        $userCreated  = false;

        if ($user === null) {
            [$first, $last] = learner_split_name((string) $reg['full_name']);

            /* Two very different secrets, depending on how it will reach the
               learner. 'show' generates something a person can read off the
               screen and type. 'invite' generates 32 random bytes nobody will
               ever read or type — the account has to have SOME password hash,
               and this one exists purely to be overwritten the moment the
               invite link is used. */
            $password = $viaInvite ? null : install_readable_password();
            $hash     = $viaInvite ? auth_hash(bin2hex(random_bytes(32))) : auth_hash($password);

            $uid = db_insert('users', [
                'tenant_id'     => tenant_id(),
                'email'         => $email,
                'password_hash' => $hash,
                'first_name'    => $first,
                'last_name'     => $last,
                'employee_no'   => ($reg['employee_no'] ?? '') !== '' ? $reg['employee_no'] : null,
                'department'    => ($reg['department']  ?? '') !== '' ? $reg['department']  : null,
                'role'          => 'learner',
                'status'        => 'active',
                'created_at'    => now(),
                'last_login_at' => null,
            ]);
            $user        = db_one('SELECT * FROM users WHERE id = ?', [$uid]);
            $userCreated = true;
        }

        $uid = (int) $user['id'];

        $already = db_value(
            'SELECT id FROM enrolments WHERE tenant_id = ? AND user_id = ? AND course_slug = ?',
            [tenant_id(), $uid, $courseSlug]
        );
        $enrolCreated = false;
        $enrolId      = $already === null ? null : (int) $already;

        if ($already === null) {
            $enrolId = db_insert('enrolments', [
                'tenant_id'       => tenant_id(),
                'user_id'         => $uid,
                'course_slug'     => $courseSlug,
                'course_title'    => $title,
                'registration_id' => (int) $reg['id'],
                'status'          => 'active',
                'enrolled_at'     => now(),
                'enrolled_by'     => $admin['id'] ?? null,
                'completed_at'    => null,
            ]);
            $enrolCreated = true;
        }

        /* Tie the registration to the person it became, and move it out of the
           "new" pile. Without this the list keeps asking to be actioned. */
        db_run('UPDATE registrations SET user_id = ?, status = ? WHERE id = ? AND tenant_id = ?',
               [$uid, 'enrolled', (int) $reg['id'], tenant_id()]);

        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        app_log('ENROL FAILED (registration ' . $regId . '): ' . $ex->getMessage());
        return $fail('The enrolment could not be saved. Nothing was changed — please try again.');
    }

    // Outside the transaction: these describe something that already happened.
    if ($userCreated) {
        audit('learner.account_created', 'users', (int) $user['id'], 'from registration ' . $regId);
    }
    if ($enrolCreated) {
        // entity_id is the enrolment, not the learner — the audit table's
        // (entity, entity_id) index is what "everything that ever happened to
        // this enrolment" is looked up by, and pointing it at the user makes
        // that question unanswerable.
        audit('learner.enrolled', 'enrolments', $enrolId,
              'user ' . (int) $user['id'] . ' · ' . $courseSlug . ' — ' . $title);
    }

    /* The invite email, if that is the route asked for. Deliberately AFTER the
       audit calls above, so "an account was created" and "it was enrolled" are
       recorded even if mail fails outright — a failed send must never look
       like a failed enrolment. */
    $invited    = $userCreated && $viaInvite;
    $inviteSent = null;
    if ($invited) {
        /* One letter, not two: the invite and the welcome are the same message,
           because the learner should receive a single email that both confirms
           what they are registered for and offers the password link. See
           invite_send() and lib/letters.php. */
        $inviteSent = invite_create_and_send($user, (int) ($admin['id'] ?? 0), $title, $courseSlug);
    } elseif ($enrolCreated && function_exists('letter_send_welcome')) {
        /* The other route: the account already existed, or the administrator
           chose to read the password off the screen and hand it over. Either
           way the learner is newly enrolled on something and is owed the
           confirmation — WITHOUT a link and without a password, because in this
           branch there is no token to send and the password is not ours to
           email. Wrapped so that no email problem can fail an enrolment that
           has already been committed above. */
        try {
            letter_send_welcome($user, $courseSlug, $title, null);
        } catch (Throwable $e) {
            app_log('WELCOME LETTER FAILED (user ' . (int) $user['id'] . '): ' . $e->getMessage());
        }
    }

    if ($invited) {
        $message = $inviteSent
            ? 'Account created and enrolled on ' . $title . '. An email with a link to set '
                . 'a password is on its way to ' . $user['email'] . '.'
            : 'Account created and enrolled on ' . $title . ', but the invite email could not '
                . 'be sent. The account already exists, so re-enrolling will not generate a '
                . 'password — set one for them from the Accounts page instead.';
    } else {
        $message = $userCreated
            ? 'Account created and enrolled on ' . $title . '.'
            : ($enrolCreated
                ? 'That person already had an account — they are now also enrolled on ' . $title . '.'
                : 'They were already enrolled on ' . $title . '. Nothing was changed.');
    }

    return [
        'ok' => true, 'message' => $message, 'user' => $user, 'password' => $password,
        'user_created' => $userCreated, 'enrolment_created' => $enrolCreated,
        'invited' => $invited, 'invite_sent' => $inviteSent,
    ];
}

/** Everything this learner is on, newest first. */
function learner_enrolments(int $userId): array
{
    return db_all(
        'SELECT * FROM enrolments WHERE tenant_id = ? AND user_id = ? ORDER BY enrolled_at DESC, id DESC',
        [tenant_id(), $userId]
    );
}

/** Is this learner on this course? Everything that reads progress asks first. */
function learner_is_enrolled(int $userId, string $courseSlug): bool
{
    return db_value(
        'SELECT id FROM enrolments WHERE tenant_id = ? AND user_id = ? AND course_slug = ? AND status = ?',
        [tenant_id(), $userId, $courseSlug, 'active']
    ) !== null;
}

/* ---------------------------------------------------------------------------
   Progress

   Deliberately dumb storage. This file knows that a course has modules and a
   module has items; it does not know that there are eleven of them, or what
   they are called, or how many credits each carries. That lives in
   pm-modules.js, generated from the registered curriculum, and having a second
   copy of it in PHP would guarantee the two disagree eventually.
   --------------------------------------------------------------------------- */

function learner_valid_course_slug(string $s): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,59}$/', $s);
}

function learner_valid_code(string $s, int $max): bool
{
    return $s !== '' && mb_strlen($s) <= $max && (bool) preg_match('/^[A-Za-z0-9._-]+$/', $s);
}

/** UTC 'Y-m-d H:i:s' as the ISO string the browser side has always used. */
function learner_iso(string $sqlDatetime): string
{
    return str_replace(' ', 'T', $sqlDatetime) . 'Z';
}

/**
 * A learner's progress on one course, in the nested shape the pages expect:
 *
 *   { "KM-01": { "topics": { "KM-01-KT01": "2026-08-18T09:00:00Z" },
 *                "done":   "2026-08-20T…" | null } }
 *
 * That shape is not new — it is exactly what was in localStorage, kept
 * unchanged so the module page and the report page did not have to be rewritten
 * around a different structure, and so an import of somebody's existing browser
 * progress is a copy rather than a translation.
 */
function learner_progress_tree(int $userId, string $courseSlug): array
{
    $rows = db_all(
        'SELECT module_code, item_code, completed_at FROM learner_progress
          WHERE tenant_id = ? AND user_id = ? AND course_slug = ?
          ORDER BY module_code, item_code',
        [tenant_id(), $userId, $courseSlug]
    );

    $tree = [];
    foreach ($rows as $r) {
        $m = (string) $r['module_code'];
        if (!isset($tree[$m])) $tree[$m] = ['topics' => new stdClass(), 'done' => null];

        if ((string) $r['item_code'] === '') {
            $tree[$m]['done'] = learner_iso((string) $r['completed_at']);
        } else {
            if ($tree[$m]['topics'] instanceof stdClass) $tree[$m]['topics'] = [];
            $tree[$m]['topics'][(string) $r['item_code']] = learner_iso((string) $r['completed_at']);
        }
    }
    return $tree;
}

/**
 * Tick or un-tick one thing.
 *
 * An empty $item is the module-complete row rather than a missing value — see
 * the note on item_code in schema.mysql.sql. Un-ticking DELETES: a tick the
 * learner has taken back is not something we have a purpose for keeping.
 *
 * @return bool whether the row is now present
 */
function learner_progress_set(
    int $userId, string $courseSlug, string $module, string $item, bool $on
): bool {
    $existing = db_value(
        'SELECT id FROM learner_progress
          WHERE tenant_id = ? AND user_id = ? AND course_slug = ? AND module_code = ? AND item_code = ?',
        [tenant_id(), $userId, $courseSlug, $module, $item]
    );

    if ($on) {
        if ($existing !== null) return true;
        if (learner_progress_row_count($userId) >= LEARNER_PROGRESS_MAX_ROWS) {
            app_log('PROGRESS CAP hit for user ' . $userId);
            return false;
        }
        db_insert('learner_progress', [
            'tenant_id'    => tenant_id(),
            'user_id'      => $userId,
            'course_slug'  => $courseSlug,
            'module_code'  => $module,
            'item_code'    => $item,
            'completed_at' => now(),
        ]);
        return true;
    }

    if ($existing !== null) {
        db_run('DELETE FROM learner_progress WHERE id = ? AND tenant_id = ? AND user_id = ?',
               [(int) $existing, tenant_id(), $userId]);
    }
    return false;
}

function learner_progress_has(int $userId, string $courseSlug, string $module, string $item): bool
{
    return db_value(
        'SELECT id FROM learner_progress
          WHERE tenant_id = ? AND user_id = ? AND course_slug = ? AND module_code = ? AND item_code = ?',
        [tenant_id(), $userId, $courseSlug, $module, $item]
    ) !== null;
}

function learner_progress_row_count(int $userId): int
{
    return (int) db_value(
        'SELECT COUNT(*) FROM learner_progress WHERE tenant_id = ? AND user_id = ?',
        [tenant_id(), $userId]
    );
}

/**
 * When a tick was made, according to the browser bringing it across.
 *
 * The date matters: it is printed on the progress report a manager signs, so
 * importing somebody's year of work as if it all happened this afternoon would
 * quietly falsify the record. It is also entirely under the client's control,
 * so it is bounded rather than trusted — a date that will not parse, or is in
 * the future, or predates the site itself, becomes now(). Neither end of that
 * range is a security control; both stop an obvious mistake being written down
 * as fact.
 */
function learner_import_when($raw): string
{
    if (!is_string($raw) || $raw === '') return now();
    $t = strtotime($raw);
    if ($t === false) return now();
    if ($t > time()) return now();
    if ($t < strtotime('2026-01-01')) return now();
    return gmdate('Y-m-d H:i:s', $t);
}

/**
 * Copy a browser's saved progress into the account, once.
 *
 * This exists because the alternative is telling five people who have been
 * ticking modules off for weeks that their record starts again today. It only
 * ADDS: anything already on the account wins, so running it twice adds nothing
 * and it can never undo a tick made since.
 *
 * @param array $tree the localStorage shape, straight off the browser and
 *                    therefore not trusted: every code is validated and
 *                    anything that fails is skipped rather than stored.
 * @return int rows actually inserted
 */
function learner_progress_import(int $userId, string $courseSlug, array $tree): int
{
    $added = 0;

    /** Insert only if it is not already there, and report honestly. */
    $add = static function (string $module, string $item, $when) use ($userId, $courseSlug, &$added): bool {
        if (learner_progress_has($userId, $courseSlug, $module, $item)) return true;
        if (learner_progress_row_count($userId) >= LEARNER_PROGRESS_MAX_ROWS) return false;
        db_insert('learner_progress', [
            'tenant_id'    => tenant_id(),
            'user_id'      => $userId,
            'course_slug'  => $courseSlug,
            'module_code'  => $module,
            'item_code'    => $item,
            'completed_at' => learner_import_when($when),
        ]);
        $added++;
        return true;
    };

    foreach ($tree as $module => $entry) {
        $module = (string) $module;
        if (!learner_valid_code($module, 20) || !is_array($entry)) continue;

        $topics = $entry['topics'] ?? [];
        if (is_array($topics)) {
            foreach ($topics as $code => $when) {
                $code = (string) $code;
                if (!learner_valid_code($code, 40)) continue;
                if (!$add($module, $code, $when)) break 2;   // the ceiling
            }
        }

        if (!empty($entry['done'])) {
            $add($module, '', $entry['done']);
        }
    }

    if ($added > 0) {
        audit('learner.progress_imported', 'learner_progress', $userId,
              $courseSlug . ': ' . $added . ' items from browser storage');
    }
    return $added;
}

/**
 * Progress counts for a whole page of learners, in one query.
 *
 * Exists because privacy.php tells learners "the academy can see your ticks and
 * their dates, and uses them to know who needs help" — and for the first day
 * after learner accounts shipped, no page in the administration area read the
 * table at all. A privacy notice describing a capability nobody has is worse
 * than one that admits the gap, so the honest fix was to build the capability
 * rather than reword the sentence.
 *
 * DELIBERATELY NO PERCENTAGE. A percentage needs a denominator, the denominator
 * is the registered curriculum, and that lives in pm-modules.js where it is
 * generated from the provider's guides. Copying "51 topics" into PHP would
 * create a second source of truth that silently disagrees the first time a
 * module changes. Counts answer the question that actually gets asked — who has
 * not started, and who has stopped — without needing one.
 *
 * @return array<int, array<string, array{topics:int, modules:int, last:?string}>>
 *         user id => course slug => counts
 */
function learner_progress_counts_bulk(array $userIds): array
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$userIds) return [];

    $in   = implode(',', array_fill(0, count($userIds), '?'));
    $rows = db_optional(fn() => db_all(
        'SELECT user_id, course_slug,
                SUM(CASE WHEN item_code <> ? THEN 1 ELSE 0 END) AS topics,
                SUM(CASE WHEN item_code =  ? THEN 1 ELSE 0 END) AS modules,
                MAX(completed_at) AS last_at
           FROM learner_progress
          WHERE tenant_id = ? AND user_id IN (' . $in . ')
          GROUP BY user_id, course_slug',
        array_merge(['', '', tenant_id()], $userIds)
    ), []);

    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['user_id']][(string) $r['course_slug']] = [
            'topics'  => (int) $r['topics'],
            'modules' => (int) $r['modules'],
            'last'    => $r['last_at'] !== null ? (string) $r['last_at'] : null,
        ];
    }
    return $out;
}

/** Everything on one course, gone. The learner's own choice, from their report page. */
function learner_progress_clear(int $userId, string $courseSlug): int
{
    $n = db_run('DELETE FROM learner_progress WHERE tenant_id = ? AND user_id = ? AND course_slug = ?',
                [tenant_id(), $userId, $courseSlug])->rowCount();
    if ($n > 0) {
        audit('learner.progress_cleared', 'learner_progress', $userId, $courseSlug . ': ' . $n . ' items');
    }
    return $n;
}

/**
 * Counts for a summary line, without needing the curriculum.
 *
 * 'topics' is how many topics the learner has ticked and 'modules' how many
 * they have marked complete. Neither is expressed as a percentage here on
 * purpose: a percentage needs a denominator, the denominator is the registered
 * curriculum, and that is in pm-modules.js where it belongs.
 */
function learner_progress_counts(int $userId, string $courseSlug): array
{
    return [
        'topics' => (int) db_value(
            'SELECT COUNT(*) FROM learner_progress
              WHERE tenant_id = ? AND user_id = ? AND course_slug = ? AND item_code <> ?',
            [tenant_id(), $userId, $courseSlug, '']
        ),
        'modules' => (int) db_value(
            'SELECT COUNT(*) FROM learner_progress
              WHERE tenant_id = ? AND user_id = ? AND course_slug = ? AND item_code = ?',
            [tenant_id(), $userId, $courseSlug, '']
        ),
        'last' => db_value(
            'SELECT MAX(completed_at) FROM learner_progress
              WHERE tenant_id = ? AND user_id = ? AND course_slug = ?',
            [tenant_id(), $userId, $courseSlug]
        ),
    ];
}
