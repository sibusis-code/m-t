<?php
declare(strict_types=1);

/* A self-check quiz, one per module — multiple choice, auto-graded, unlimited
 * attempts with the best kept.
 *
 * WHAT THIS IS NOT
 *
 * Not the QCTO's assessment, and nothing built on this file may read as
 * though it were. Being found competent is Centenary's decision after the
 * real assessment, and the qualification is the QCTO's after the EISA — the
 * same sentence quiz.php, admin-quizzes.php and my.php all repeat verbatim
 * next to any score this file produces. A quiz here is the academy's own
 * self-check, built to help someone study, and unlimited attempts is part of
 * what keeps it reading that way: there is no incentive to see it as high
 * stakes when retaking it costs nothing.
 *
 * WHY GRADING NEVER TRUSTS THE BROWSER
 *
 * quiz_questions_with_choices() carries is_correct and is for admin/grading
 * use only — the page that shows a learner a quiz to take must never pass
 * that array to the browser. quiz_grade_and_record() re-reads the current
 * answer key from the database at the moment of grading; the only thing it
 * trusts from the client is which choice id was picked for which question.
 *
 * WHY THERE IS NO STORED SCORE_PCT AND NO STORED "BEST" COLUMN
 *
 * A percentage is score_count/question_count, computed here, not stored —
 * carrying forward the same reasoning lib/learner.php already applies to
 * progress ("DELIBERATELY NO PERCENTAGE"). "Best score kept" therefore means
 * comparing PERCENTAGES across every attempt, in PHP, never MAX(score_count)
 * in SQL: question_count is a snapshot taken at attempt time, so two attempts
 * by the same learner can legitimately have different denominators if an
 * admin edited the quiz in between, and comparing raw counts across those
 * would quietly favour whichever attempt happened to face fewer questions.
 */

defined('APP_BOOTED') or exit('lib/quiz.php is not a page.');

const QUIZ_MIN_CHOICES = 2;
const QUIZ_MAX_CHOICES = 6;

/* Not a business rule — the most attempts any real learner would plausibly
   rack up practising, rounded well up, so a script pointed at the endpoint
   fills a log rather than a disk. Same reasoning as LEARNER_PROGRESS_MAX_ROWS. */
const QUIZ_ATTEMPT_MAX_PER_QUIZ = 200;

/* The mark a learner has to reach for a topic to count as done — Kgomotso's
   figure, 11 Sep 2026.
 *
 * WHY THIS IS A DEFAULT IN CODE RATHER THAN A NUMBER WRITTEN INTO EVERY ROW
 *
 * quizzes.pass_pct stays nullable and an explicit value still wins, so a quiz
 * that needs a different bar can have one. But null used to mean "show a score
 * and say nothing about passing", and every one of the 51 topic quizzes now
 * live was loaded with null. Writing 80 into all of them would have meant a
 * data migration per site, on five servers, for a number that is the same
 * everywhere — and any topic loaded afterwards would have come back null again
 * and quietly had no pass mark. A default reads the same on every site on the
 * day it deploys and cannot be forgotten for new content.
 *
 * The cost is that null now means 80 rather than "no opinion", so an admin who
 * genuinely wants a scored-but-ungated quiz has to say so. Nobody had asked for
 * one; being gated is the thing that was asked for. */
const QUIZ_DEFAULT_PASS_PCT = 80;

/**
 * The bar for one quiz: its own pass_pct when set, otherwise the academy
 * default above. One place, so the take page, the grader, the summary and the
 * module page can never disagree about what passing means.
 *
 * @param array<string,mixed> $quiz
 */
function quiz_pass_pct(array $quiz): int
{
    $own = $quiz['pass_pct'] ?? null;
    return $own !== null ? (int) $own : QUIZ_DEFAULT_PASS_PCT;
}

/* ---------------------------------------------------------------------------
   Reading
   --------------------------------------------------------------------------- */

function quiz_for_module(string $courseSlug, string $moduleCode): ?array
{
    return db_one(
        'SELECT * FROM quizzes WHERE tenant_id = ? AND course_slug = ? AND module_code = ?',
        [tenant_id(), $courseSlug, $moduleCode]
    );
}

/** Tenant-scoped, like every other *_get() in this codebase — never trust a bare id. */
function quiz_get(int $id): ?array
{
    return db_one('SELECT * FROM quizzes WHERE id = ? AND tenant_id = ?', [$id, tenant_id()]);
}

/**
 * Every question and its choices, ordered, WITH is_correct.
 *
 * Admin/grading use only. The page that shows a learner a quiz to take must
 * build its own view of this that strips is_correct before anything reaches
 * the browser — see quiz.php.
 */
function quiz_questions_with_choices(int $quizId): array
{
    $questions = db_all(
        'SELECT * FROM quiz_questions WHERE tenant_id = ? AND quiz_id = ? AND active = 1
         ORDER BY sort_order, id',
        [tenant_id(), $quizId]
    );
    if (!$questions) return [];

    $ids = array_map(fn($q) => (int) $q['id'], $questions);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $choices = db_all(
        'SELECT * FROM quiz_choices WHERE tenant_id = ? AND question_id IN (' . $in . ') AND active = 1
         ORDER BY question_id, sort_order, id',
        array_merge([tenant_id()], $ids)
    );

    $byQuestion = [];
    foreach ($choices as $c) $byQuestion[(int) $c['question_id']][] = $c;

    foreach ($questions as &$q) {
        $q['choices'] = $byQuestion[(int) $q['id']] ?? [];
    }
    unset($q);

    return $questions;
}

/** How many questions a quiz has right now — the live count, not a snapshot. */
function quiz_question_count(int $quizId): int
{
    return (int) db_value(
        'SELECT COUNT(*) FROM quiz_questions WHERE tenant_id = ? AND quiz_id = ? AND active = 1',
        [tenant_id(), $quizId]
    );
}

/**
 * Every quiz and its (active) questions/choices for a whole course, keyed by
 * module code — what admin-quizzes.php renders against. A staff page opened
 * a handful of times per intake, against at most a handful of quizzes, so
 * this is a plain loop rather than something worth a bulk join.
 *
 * @return array<string, array{quiz: array, questions: array}>
 */
function quiz_admin_course_data(string $courseSlug): array
{
    $quizzes = db_all('SELECT * FROM quizzes WHERE tenant_id = ? AND course_slug = ?',
                      [tenant_id(), $courseSlug]);

    $out = [];
    foreach ($quizzes as $q) {
        $out[(string) $q['module_code']] = [
            'quiz'      => $q,
            'questions' => quiz_questions_with_choices((int) $q['id']),
        ];
    }
    return $out;
}

/* ---------------------------------------------------------------------------
   Authoring
   --------------------------------------------------------------------------- */

/**
 * Create or update the quiz row for a module. Does not touch questions —
 * quiz_save_questions() is the separate, whole-set replace.
 *
 * @param array{pass_pct: ?int} $fields
 */
function quiz_upsert(string $courseSlug, string $moduleCode, array $fields, int $by): int
{
    $passPct = $fields['pass_pct'] ?? null;
    if ($passPct !== null) $passPct = max(0, min(100, (int) $passPct));

    $existing = quiz_for_module($courseSlug, $moduleCode);

    if ($existing === null) {
        $id = db_insert('quizzes', [
            'tenant_id'   => tenant_id(),
            'course_slug' => $courseSlug,
            'module_code' => $moduleCode,
            'pass_pct'    => $passPct,
            'published'   => 0,
            'created_at'  => now(),
            'updated_at'  => now(),
            'updated_by'  => $by,
        ]);
        audit('quiz.created', 'quizzes', $id, $courseSlug . ' ' . $moduleCode);
        return $id;
    }

    db_run('UPDATE quizzes SET pass_pct = ?, updated_at = ?, updated_by = ? WHERE id = ? AND tenant_id = ?',
           [$passPct, now(), $by, (int) $existing['id'], tenant_id()]);
    return (int) $existing['id'];
}

/**
 * Replace the whole question set for a quiz in one transaction.
 *
 * Small question counts per module make a full replace simpler and safer than
 * granular per-question AJAX: one diff to reason about, no partial-save races
 * between two admins, and a bad save can't leave half the old set and half
 * the new set mixed together.
 *
 * $posted shape (already validated by the caller — see admin-quizzes.php):
 *   [ ['id' => ?int, 'prompt' => string,
 *      'choices' => [ ['id' => ?int, 'text' => string, 'correct' => bool], ... ] ], ... ]
 * A null 'id' means a new question/choice. Anything stored but absent from
 * $posted is deleted.
 *
 * A question that cannot be saved is REPORTED, not silently dropped. An
 * earlier version simply `continue`d past anything incomplete, so an admin
 * who wrote a question and forgot to tick which answer was correct — much the
 * easiest mistake to make on this form — got "Saved. Nothing had changed."
 * and lost the lot. Losing somebody's writing is bad; telling them it went
 * fine while doing it is worse. The caller renders 'skipped' as an error.
 *
 * @return array{added:int, updated:int, removed:int, skipped: array<int, array{n:int, why:string}>}
 */
function quiz_save_questions(int $quizId, array $posted, int $by): array
{
    $counts = ['added' => 0, 'updated' => 0, 'removed' => 0, 'skipped' => []];

    $existingQ = db_all('SELECT id FROM quiz_questions WHERE tenant_id = ? AND quiz_id = ? AND active = 1',
                        [tenant_id(), $quizId]);
    $keepQIds  = [];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($posted as $order => $q) {
            $prompt = trim((string) ($q['prompt'] ?? ''));

            $choicesIn = array_values(array_filter(
                (array) ($q['choices'] ?? []),
                fn($c) => trim((string) ($c['text'] ?? '')) !== ''
            ));

            /* An entirely empty block is the blank row the form always leaves
               at the bottom — not something anybody wrote, so it is ignored
               without comment. Anything half-filled IS somebody's writing. */
            if ($prompt === '' && !$choicesIn) continue;

            $why = '';
            if ($prompt === '') {
                $why = 'it has answer options but no question written above them';
            } elseif (count($choicesIn) < QUIZ_MIN_CHOICES) {
                $why = 'it needs at least ' . QUIZ_MIN_CHOICES . ' answer options filled in';
            } elseif (count($choicesIn) > QUIZ_MAX_CHOICES) {
                $why = 'it has more than ' . QUIZ_MAX_CHOICES . ' answer options';
            } elseif (!array_filter($choicesIn, fn($c) => !empty($c['correct']))) {
                $why = 'no option is marked as the correct one';
            }
            if ($why !== '') {
                $counts['skipped'][] = [
                    'n'   => (int) $order + 1,
                    'why' => $why,
                    'was' => mb_substr($prompt !== '' ? $prompt : trim((string) $choicesIn[0]['text']), 0, 60),
                ];
                /* If this question is ALREADY stored, keep it. Everything not
                   in $keepQIds is deactivated at the end of the loop, so
                   without this line a broken edit to a saved question would
                   delete the good version that was already there — turning a
                   refused edit into data loss. */
                $skippedId = (int) ($q['id'] ?? 0);
                if ($skippedId > 0) $keepQIds[] = $skippedId;
                continue;
            }

            $qid = (int) ($q['id'] ?? 0);
            if ($qid > 0 && db_value(
                    'SELECT id FROM quiz_questions WHERE id = ? AND tenant_id = ? AND quiz_id = ? AND active = 1',
                    [$qid, tenant_id(), $quizId]) !== null) {
                db_run('UPDATE quiz_questions SET prompt = ?, sort_order = ?, updated_at = ?
                        WHERE id = ? AND tenant_id = ?',
                       [$prompt, $order, now(), $qid, tenant_id()]);
                $counts['updated']++;
            } else {
                $qid = db_insert('quiz_questions', [
                    'tenant_id'  => tenant_id(), 'quiz_id' => $quizId, 'prompt' => $prompt,
                    'sort_order' => $order, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $counts['added']++;
            }
            $keepQIds[] = $qid;

            $existingC = db_all('SELECT id FROM quiz_choices WHERE tenant_id = ? AND question_id = ? AND active = 1',
                                [tenant_id(), $qid]);
            $keepCIds  = [];

            foreach ($choicesIn as $cOrder => $c) {
                $text    = trim((string) $c['text']);
                $correct = !empty($c['correct']) ? 1 : 0;
                $cid     = (int) ($c['id'] ?? 0);

                if ($cid > 0 && db_value(
                        'SELECT id FROM quiz_choices WHERE id = ? AND tenant_id = ? AND question_id = ? AND active = 1',
                        [$cid, tenant_id(), $qid]) !== null) {
                    db_run('UPDATE quiz_choices SET choice_text = ?, is_correct = ?, sort_order = ?
                            WHERE id = ? AND tenant_id = ?',
                           [$text, $correct, $cOrder, $cid, tenant_id()]);
                } else {
                    $cid = db_insert('quiz_choices', [
                        'tenant_id' => tenant_id(), 'question_id' => $qid, 'choice_text' => $text,
                        'is_correct' => $correct, 'sort_order' => $cOrder,
                    ]);
                }
                $keepCIds[] = $cid;
            }

            /* A dropped choice is switched off, never deleted — see the note
               on quiz_choices.active in schema.mysql.sql: a past attempt may
               have picked exactly this row, and quiz_attempt_answers.choice_id
               must keep pointing at something readable. */
            $dropC = array_diff(array_map(fn($r) => (int) $r['id'], $existingC), $keepCIds);
            if ($dropC) {
                $in = implode(',', array_fill(0, count($dropC), '?'));
                db_run('UPDATE quiz_choices SET active = 0 WHERE tenant_id = ? AND id IN (' . $in . ')',
                       array_merge([tenant_id()], array_values($dropC)));
            }
        }

        /* A dropped question is switched off too, and its still-active
           choices go with it (a deactivated question has no business still
           offering live choices, even though nothing queries them directly
           once the question itself is inactive) — same reasoning as above. */
        $dropQ = array_diff(array_map(fn($r) => (int) $r['id'], $existingQ), $keepQIds);
        if ($dropQ) {
            $in = implode(',', array_fill(0, count($dropQ), '?'));
            db_run('UPDATE quiz_choices SET active = 0 WHERE tenant_id = ? AND question_id IN (' . $in . ')',
                   array_merge([tenant_id()], array_values($dropQ)));
            db_run('UPDATE quiz_questions SET active = 0, updated_at = ? WHERE tenant_id = ? AND id IN (' . $in . ')',
                   array_merge([now(), tenant_id()], array_values($dropQ)));
            $counts['removed'] = count($dropQ);
        }

        db_run('UPDATE quizzes SET updated_at = ?, updated_by = ? WHERE id = ? AND tenant_id = ?',
               [now(), $by, $quizId, tenant_id()]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        app_log('QUIZ SAVE FAILED (quiz ' . $quizId . '): ' . $e->getMessage());
        throw $e;
    }

    audit('quiz.questions_saved', 'quizzes', $quizId,
          $counts['added'] . ' added, ' . $counts['updated'] . ' updated, ' . $counts['removed'] . ' removed');
    return $counts;
}

/** Refuses to publish a quiz with no questions — nothing to self-check against. */
function quiz_publish(int $quizId, bool $on, int $by): bool
{
    if ($on && quiz_question_count($quizId) === 0) return false;

    db_run('UPDATE quizzes SET published = ?, updated_at = ?, updated_by = ? WHERE id = ? AND tenant_id = ?',
           [$on ? 1 : 0, now(), $by, $quizId, tenant_id()]);
    audit($on ? 'quiz.published' : 'quiz.unpublished', 'quizzes', $quizId);
    return true;
}

/* ---------------------------------------------------------------------------
   Taking it
   --------------------------------------------------------------------------- */

/**
 * Grade one attempt and record it. Re-reads the current answer key — the
 * caller passes only which choice id was picked for which question, never
 * whether it was right.
 *
 * @param array<int,int> $posted question_id => choice_id
 * @return array{score_count:int, question_count:int, pct:int, pass:?bool,
 *               breakdown: array<int,array{question_id:int,choice_id:?int,correct:bool}>}
 */
function quiz_grade_and_record(int $quizId, int $userId, array $posted): array
{
    $quiz      = quiz_get($quizId);
    $questions = quiz_questions_with_choices($quizId);

    $scoreCount = 0;
    $breakdown  = [];
    $answerRows = [];

    foreach ($questions as $q) {
        $qid       = (int) $q['id'];
        $pickedId  = isset($posted[$qid]) ? (int) $posted[$qid] : 0;
        $picked    = null;
        $isCorrect = false;

        foreach ($q['choices'] as $c) {
            if ((int) $c['id'] === $pickedId) {
                $picked    = $c;
                $isCorrect = (bool) $c['is_correct'];
                break;
            }
        }
        // A choice id that doesn't belong to this question (tampered POST,
        // or a stale form) counts as unanswered, not as whatever it happened
        // to collide with.
        if ($picked === null) { $pickedId = 0; $isCorrect = false; }

        if ($isCorrect) $scoreCount++;
        $breakdown[]  = ['question_id' => $qid, 'choice_id' => $pickedId ?: null, 'correct' => $isCorrect];
        $answerRows[] = ['question_id' => $qid, 'choice_id' => $pickedId ?: null, 'is_correct' => $isCorrect ? 1 : 0];
    }

    $questionCount = count($questions);
    $existingAttempts = (int) db_value(
        'SELECT COUNT(*) FROM quiz_attempts WHERE tenant_id = ? AND quiz_id = ? AND user_id = ?',
        [tenant_id(), $quizId, $userId]
    );
    if ($existingAttempts >= QUIZ_ATTEMPT_MAX_PER_QUIZ) {
        app_log('QUIZ ATTEMPT CAP hit — quiz ' . $quizId . ' user ' . $userId);
        // Grade it for display, but don't write another row past the ceiling.
        $pct = $questionCount > 0 ? (int) round($scoreCount / $questionCount * 100) : 0;
        return [
            'score_count' => $scoreCount, 'question_count' => $questionCount, 'pct' => $pct,
            'pass' => $pct >= quiz_pass_pct($quiz), 'pass_pct' => quiz_pass_pct($quiz),
            'breakdown' => $breakdown,
        ];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $attemptId = db_insert('quiz_attempts', [
            'tenant_id' => tenant_id(), 'quiz_id' => $quizId, 'user_id' => $userId,
            'started_at' => now(), 'submitted_at' => now(),
            'score_count' => $scoreCount, 'question_count' => $questionCount,
        ]);
        foreach ($answerRows as $a) {
            db_insert('quiz_attempt_answers', array_merge(
                ['tenant_id' => tenant_id(), 'attempt_id' => $attemptId], $a
            ));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        app_log('QUIZ ATTEMPT FAILED (quiz ' . $quizId . ' user ' . $userId . '): ' . $e->getMessage());
        throw $e;
    }

    audit('quiz.attempted', 'quiz_attempts', $attemptId,
          $quiz['course_slug'] . ' ' . $quiz['module_code'] . ' — ' . $scoreCount . '/' . $questionCount);

    $pct = $questionCount > 0 ? (int) round($scoreCount / $questionCount * 100) : 0;
    return [
        'score_count' => $scoreCount, 'question_count' => $questionCount, 'pct' => $pct,
        'pass' => $pct >= quiz_pass_pct($quiz), 'pass_pct' => quiz_pass_pct($quiz),
        'breakdown' => $breakdown,
    ];
}

/* ---------------------------------------------------------------------------
   Reading results
   --------------------------------------------------------------------------- */

/**
 * The best attempt for one learner on one quiz, by PERCENTAGE — never by raw
 * score_count, for the reason in this file's header comment.
 *
 * @return array{score_count:int, question_count:int, pct:int, attempts:int, last_at:string}|null
 */
function quiz_best_result(int $userId, int $quizId): ?array
{
    $rows = db_all(
        'SELECT score_count, question_count, submitted_at FROM quiz_attempts
          WHERE tenant_id = ? AND quiz_id = ? AND user_id = ?',
        [tenant_id(), $quizId, $userId]
    );
    if (!$rows) return null;

    $best = null;
    $lastAt = null;
    foreach ($rows as $r) {
        $qc  = (int) $r['question_count'];
        $pct = $qc > 0 ? $r['score_count'] / $qc * 100 : 0;
        if ($best === null || $pct > $best['pct_raw']) {
            $best = ['score_count' => (int) $r['score_count'], 'question_count' => $qc, 'pct_raw' => $pct];
        }
        if ($lastAt === null || (string) $r['submitted_at'] > $lastAt) $lastAt = (string) $r['submitted_at'];
    }

    return [
        'score_count'    => $best['score_count'],
        'question_count' => $best['question_count'],
        'pct'            => (int) round($best['pct_raw']),
        'attempts'       => count($rows),
        'last_at'        => (string) $lastAt,
    ];
}

/**
 * Every tracked module's quiz status for one learner on one course, one
 * query rather than one per module — same reasoning as
 * learner_progress_counts_bulk() in lib/learner.php.
 *
 * @return array<string, array{published:bool, questions:int, best:?array}>
 */
function quiz_results_summary_for_user(int $userId, string $courseSlug): array
{
    $quizzes = db_all(
        'SELECT q.*, (SELECT COUNT(*) FROM quiz_questions qq
                       WHERE qq.tenant_id = q.tenant_id AND qq.quiz_id = q.id AND qq.active = 1) AS question_count
           FROM quizzes q WHERE q.tenant_id = ? AND q.course_slug = ?',
        [tenant_id(), $courseSlug]
    );
    if (!$quizzes) return [];

    $quizIds = array_map(fn($q) => (int) $q['id'], $quizzes);
    $in = implode(',', array_fill(0, count($quizIds), '?'));
    $attempts = db_all(
        'SELECT quiz_id, score_count, question_count, submitted_at FROM quiz_attempts
          WHERE tenant_id = ? AND user_id = ? AND quiz_id IN (' . $in . ')',
        array_merge([tenant_id(), $userId], $quizIds)
    );

    $byQuiz = [];
    foreach ($attempts as $a) $byQuiz[(int) $a['quiz_id']][] = $a;

    $out = [];
    foreach ($quizzes as $q) {
        $qid = (int) $q['id'];
        $best = null;
        if (!empty($byQuiz[$qid])) {
            $b = null;
            foreach ($byQuiz[$qid] as $a) {
                $qc  = (int) $a['question_count'];
                $pct = $qc > 0 ? $a['score_count'] / $qc * 100 : 0;
                if ($b === null || $pct > $b['pct_raw']) {
                    $b = ['score_count' => (int) $a['score_count'], 'question_count' => $qc, 'pct_raw' => $pct];
                }
            }
            $best = [
                'score_count' => $b['score_count'], 'question_count' => $b['question_count'],
                'pct' => (int) round($b['pct_raw']), 'attempts' => count($byQuiz[$qid]),
            ];
        }
        $out[(string) $q['module_code']] = [
            'published' => (bool) $q['published'],
            'questions' => (int) $q['question_count'],
            /* The effective bar, not the raw column — a null here used to mean
               "no pass mark" and now means the academy default. Callers show
               this number to learners, so it has to be the one they are graded
               against. See quiz_pass_pct(). */
            'pass_pct'  => quiz_pass_pct($q),
            'best'      => $best,
            'passed'    => $best !== null && $best['pct'] >= quiz_pass_pct($q),
        ];
    }
    return $out;
}

/**
 * Admin results list for one quiz (or every quiz on a course), paginated —
 * same list+search+paginate shape as admin-progress.php.
 *
 * @return array{rows: array, total: int}
 */
function quiz_admin_results(string $courseSlug, ?string $moduleCode, string $q, int $page, int $perPage = 25): array
{
    $where  = ['a.tenant_id = ?', 'z.course_slug = ?'];
    $params = [tenant_id(), $courseSlug];

    if ($moduleCode !== null && $moduleCode !== '') {
        $where[] = 'z.module_code = ?';
        $params[] = $moduleCode;
    }
    if ($q !== '') {
        $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }

    $sqlWhere = ' WHERE ' . implode(' AND ', $where);
    $total = (int) db_value(
        'SELECT COUNT(*) FROM quiz_attempts a
           JOIN quizzes z ON z.id = a.quiz_id AND z.tenant_id = a.tenant_id
           JOIN users u   ON u.id = a.user_id AND u.tenant_id = a.tenant_id' . $sqlWhere,
        $params
    );
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min(max(1, $page), $pages);

    $rows = db_all(
        'SELECT a.*, z.module_code, z.pass_pct, u.first_name, u.last_name, u.email
           FROM quiz_attempts a
           JOIN quizzes z ON z.id = a.quiz_id AND z.tenant_id = a.tenant_id
           JOIN users u   ON u.id = a.user_id AND u.tenant_id = a.tenant_id'
        . $sqlWhere . ' ORDER BY a.submitted_at DESC, a.id DESC LIMIT ' . $perPage
        . ' OFFSET ' . (($page - 1) * $perPage),
        $params
    );

    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
}
