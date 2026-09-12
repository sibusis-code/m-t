<?php
declare(strict_types=1);

/* Loading a whole course's prepared content in one go.
 *
 * WHY THIS EXISTS
 *
 * The Project Manager qualification's eleven knowledge modules hold fifty-one
 * topics and about a hundred and fifty thousand words of Learner Guide
 * material. admin-lessons.php can write any one of them, and for ordinary
 * editing that is the right tool — but typing fifty-one topics into fifty-one
 * boxes, module by module, is not an editing job, it is a data load, and doing
 * it by hand on a live site is how a topic ends up under the wrong code.
 *
 * WHY NOT SHIP THE CONTENT IN THE REPOSITORY
 *
 * Because the repository is PUBLIC (github.com/kgomotso-Bolide/sps.academy)
 * and the deploy runs from a checkout of it. Anything committed in order to be
 * deployed is, in the same movement, published to anybody who cares to look.
 *
 * The Learner Guides are Centenary's accredited QCTO course material. They are
 * meant to be readable by an enrolled learner who is signed in — which is
 * exactly what topic_sections and lessons.php provide — and not by the open
 * internet. So the content cannot travel the way code travels. It is prepared
 * as a file, uploaded through this page by a signed-in administrator, and
 * stored in the database. The file itself never enters git and never sits in
 * the web root.
 *
 * That is the whole reason this is an upload and not, say, a seed script in
 * tools/ — a seed script would have to carry the content, and carrying it is
 * the thing that must not happen.
 *
 * WHAT A BUNDLE MAY CONTAIN
 *
 * Reading material for topics, quiz questions for topics, or both. Nothing
 * else — in particular a bundle cannot create courses, modules or topics. The
 * registered curriculum lives in pm-modules.js and a bundle can only fill in
 * content against codes that already exist there; see bundle_validate().
 *
 * ALL OR NOTHING
 *
 * Validation runs over the entire bundle before a single row is written. A
 * bundle with one malformed topic writes nothing at all, and says which topic.
 * A half-loaded course is worse than an unloaded one: it looks finished from
 * the module list, and the gap is only found by a learner.
 */

defined('APP_BOOTED') or exit('lib/bundle.php is not a page.');

/* THE ONE PLACE A LIBRARY HERE REQUIRES ANOTHER, and it is deliberate.
 *
 * Everywhere else in this codebase a page lists the libraries it needs and the
 * libraries themselves require nothing. That works because a forgotten require
 * is a fatal error on the first call — loud, immediate, and fixed in a minute.
 *
 * This one is different. bundle_curriculum_topics() reads the curriculum, and
 * if it comes back empty every topic in an uploaded bundle is reported as "not
 * a topic in the curriculum" and the whole course load is refused. That is not
 * a crash; it is a page confidently telling an administrator their file is
 * wrong when the file is fine. Somebody would spend an afternoon on it.
 *
 * So this file makes sure of its own dependency rather than trusting fifty-one
 * topic codes to a line somebody has to remember to add. require_once, so a
 * page that also lists it explicitly is unaffected. */
require_once __DIR__ . '/curriculum.php';

/* The format string a bundle must carry. It is versioned so that a file
   prepared for a later shape is refused with an explanation rather than
   half-understood by an older site — the four academies do not all update on
   the same day. */
const BUNDLE_FORMAT = 'centenary-course-bundle/1';

/* Ceilings. None of these are business rules; they are the point past which a
   file stops looking like prepared course content and starts looking like
   something went wrong. The eleven-module Project Manager bundle is about
   1 MB, 51 topics, and no topic body over 12,000 words. */
const BUNDLE_MAX_BYTES     = 12 * 1024 * 1024;
const BUNDLE_MAX_MODULES   = 60;
const BUNDLE_MAX_TOPICS    = 500;
const BUNDLE_MAX_BODY      = 400_000;   // characters in one topic's reading
const BUNDLE_MAX_QUESTIONS = 100;       // per topic quiz

/**
 * Every topic code the registered curriculum defines, as code => module code.
 *
 * READ OUT OF pm-modules.js, not kept here. That file is the single source of
 * truth for the modules, their topics and their weightings, and every page that
 * shows them reads it — in the browser, because until now nothing in PHP needed
 * it. A bundle has to be checked against the curriculum on the server, where
 * the browser's copy cannot be trusted, so this reads the same file rather than
 * introducing the second copy that would drift the day the curriculum is
 * revised.
 *
 * The reading itself now lives in lib/curriculum.php, because the letters sent
 * to learners need the module and topic TITLES from the same file and two
 * parsers of one curriculum is the drift this comment was written to prevent.
 * This stays as the name the bundle code and its tests already use.
 *
 * Returns an empty array if the file cannot be read, which makes every bundle
 * fail validation — the safe direction, and bundle_import() says so plainly
 * rather than reporting fifty-one topics as unknown.
 */
function bundle_curriculum_topics(): array
{
    return curriculum_topics();
}

/**
 * Is this upload usable at all? Mirrors material_file_problem(): returns '' if
 * it is fine, or a sentence for a human if it is not.
 */
function bundle_upload_problem(array $file): string
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return 'That file is larger than this server currently accepts.';
    }
    if ($err === UPLOAD_ERR_NO_FILE) return 'No file was chosen.';
    if ($err !== UPLOAD_ERR_OK)      return 'That upload did not complete. Please try again.';

    $ext = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext !== 'json') return 'A bundle is a .json file — that one is a .' . ($ext ?: 'file') . '.';

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0)                 return 'That file is empty.';
    if ($size > BUNDLE_MAX_BYTES)   return 'That file is larger than the '
                                         . (int) (BUNDLE_MAX_BYTES / 1048576) . ' MB limit for a bundle.';

    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        return 'That upload could not be read.';
    }
    return '';
}

/**
 * Read and parse the uploaded file.
 *
 * @return array{bundle: ?array, error: string}
 */
function bundle_read(array $file): array
{
    $raw = @file_get_contents((string) $file['tmp_name']);
    if ($raw === false || $raw === '') {
        return ['bundle' => null, 'error' => 'That file could not be read.'];
    }

    /* A UTF-8 byte order mark is what a Windows editor leaves behind, and
       json_decode() refuses it with a syntax error that reads as though the
       file itself is broken. */
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['bundle' => null, 'error' => 'That file is not valid JSON (' . json_last_error_msg() . ').'];
    }
    if ((string) ($data['format'] ?? '') !== BUNDLE_FORMAT) {
        return ['bundle' => null, 'error' => 'That file is not a course bundle this site understands.'
                                           . ' It should say "format": "' . BUNDLE_FORMAT . '".'];
    }
    return ['bundle' => $data, 'error' => ''];
}

/**
 * Check the whole bundle against the curriculum and the ceilings.
 *
 * $topicCodes is every topic code the curriculum defines, keyed code =>
 * module code. It comes from the caller rather than from here because the
 * curriculum lives in pm-modules.js — a JavaScript file this page already
 * reads for its module chooser — and a second copy in PHP would drift the day
 * the curriculum was revised. See the same note in admin-lessons.php.
 *
 * @return string[] every problem found, empty if the bundle is good
 */
function bundle_validate(array $bundle, string $course, array $topicCodes): array
{
    $problems = [];

    if ((string) ($bundle['course'] ?? '') !== $course) {
        $problems[] = 'This bundle was prepared for "' . (string) ($bundle['course'] ?? '(nothing)')
                    . '", but you are loading it into "' . $course . '".';
        return $problems;   // nothing below is meaningful if it is the wrong course
    }

    $reading = $bundle['reading'] ?? [];
    $quizzes = $bundle['quizzes'] ?? [];
    if (!is_array($reading)) $problems[] = 'The "reading" section is not a list of modules.';
    if (!is_array($quizzes)) $problems[] = 'The "quizzes" section is not a list of topics.';
    if ($problems) return $problems;

    if (!$reading && !$quizzes) {
        $problems[] = 'That bundle has neither reading nor quiz questions in it.';
        return $problems;
    }

    /* ---- reading ---- */
    if (count($reading) > BUNDLE_MAX_MODULES) {
        $problems[] = 'That bundle has ' . count($reading) . ' modules in it, which is more than this'
                    . ' page will load at once.';
        return $problems;
    }

    $topicsSeen = 0;
    foreach ($reading as $moduleCode => $topics) {
        if (!is_string($moduleCode) || !learner_valid_code($moduleCode, 20)) {
            $problems[] = 'A module code in the bundle did not look like a module code.';
            continue;
        }
        if (!is_array($topics)) {
            $problems[] = $moduleCode . ': its topics are not a list.';
            continue;
        }
        foreach ($topics as $topicCode => $t) {
            $topicsSeen++;
            if (!is_string($topicCode) || !learner_valid_code($topicCode, 30)) {
                $problems[] = $moduleCode . ': a topic code did not look like a topic code.';
                continue;
            }
            /* The topic must belong to the module it is filed under, and both
               must be in the registered curriculum. Without this a bundle
               could quietly file KM-05's material under KM-01, where it would
               render perfectly and simply be the wrong module's content. */
            if (!isset($topicCodes[$topicCode])) {
                $problems[] = $topicCode . ' is not a topic in the curriculum.';
                continue;
            }
            if ($topicCodes[$topicCode] !== $moduleCode) {
                $problems[] = $topicCode . ' belongs to ' . $topicCodes[$topicCode]
                            . ', but the bundle files it under ' . $moduleCode . '.';
                continue;
            }
            if (!is_array($t)) { $problems[] = $topicCode . ': its content is not readable.'; continue; }

            $body = trim((string) ($t['body'] ?? ''));
            if ($body === '') {
                $problems[] = $topicCode . ': there is no reading written for it.';
            } elseif (mb_strlen($body) > BUNDLE_MAX_BODY) {
                $problems[] = $topicCode . ': its reading is longer than this page will load ('
                            . number_format(mb_strlen($body)) . ' characters).';
            }
            if (mb_strlen((string) ($t['title'] ?? '')) > 255) {
                $problems[] = $topicCode . ': its title is too long.';
            }
        }
    }
    if ($topicsSeen > BUNDLE_MAX_TOPICS) {
        $problems[] = 'That bundle has ' . $topicsSeen . ' topics in it, which is more than this page'
                    . ' will load at once.';
    }

    /* ---- quizzes ----
       Keyed by the code the quiz hangs off, which for a per-topic quiz is the
       TOPIC code — quizzes.module_code holds either, see lib/quiz.php. */
    foreach ($quizzes as $code => $q) {
        if (!is_string($code) || !learner_valid_code($code, 20)) {
            $problems[] = 'A quiz in the bundle is filed under something that is not a code.';
            continue;
        }
        if (!isset($topicCodes[$code])) {
            $problems[] = $code . ': there is no such topic to hang a quiz on.';
            continue;
        }
        if (!is_array($q)) { $problems[] = $code . ': its quiz is not readable.'; continue; }

        $qs = $q['questions'] ?? null;
        if (!is_array($qs) || !$qs) { $problems[] = $code . ': its quiz has no questions.'; continue; }
        if (count($qs) > BUNDLE_MAX_QUESTIONS) {
            $problems[] = $code . ': ' . count($qs) . ' questions is more than this page will load.';
            continue;
        }

        foreach (array_values($qs) as $i => $question) {
            $n = $code . ' question ' . ($i + 1);
            if (!is_array($question)) { $problems[] = $n . ': it is not readable.'; continue; }
            if (trim((string) ($question['prompt'] ?? '')) === '') {
                $problems[] = $n . ': it has no question written.';
            }
            $choices = $question['choices'] ?? null;
            if (!is_array($choices)) { $problems[] = $n . ': it has no answer options.'; continue; }

            $filled  = array_values(array_filter($choices,
                fn($c) => is_array($c) && trim((string) ($c['text'] ?? '')) !== ''));
            $correct = array_filter($filled, fn($c) => !empty($c['correct']));

            if (count($filled) < QUIZ_MIN_CHOICES) {
                $problems[] = $n . ': it needs at least ' . QUIZ_MIN_CHOICES . ' answer options.';
            } elseif (count($filled) > QUIZ_MAX_CHOICES) {
                $problems[] = $n . ': it has more than ' . QUIZ_MAX_CHOICES . ' answer options.';
            }
            /* Exactly one, not at least one. The learner-facing form is radio
               buttons, so a question with two right answers cannot be answered
               correctly and a learner would simply lose the mark. Better to
               refuse the file than to publish an unanswerable question. */
            if (count($correct) !== 1) {
                $problems[] = $n . ': ' . (count($correct) === 0
                    ? 'no option is marked as the correct one.'
                    : count($correct) . ' options are marked correct, and only one may be.');
            }

            /* THE ANSWER KEY MUST NOT BE VISIBLE IN THE OPTION TEXT.
               Question banks arrive with the right answer ticked in the
               document — "Ten   [tick] Correct answer" — and whatever converts
               that into a bundle has to take the marking off. On 8 Sep 2026 a
               converter took off the trailing words and left the tick, so all
               forty questions of KM-01 shipped to a live site showing learners
               which answer was right.

               Nothing checked. The conversion was correct about WHICH option
               was right, so every count and every test agreed with it; the only
               thing wrong was a character a learner could see. So the check
               belongs here, on the file, where it applies to every bundle
               anybody ever makes rather than to the one converter that got it
               wrong. */
            foreach ($filled as $ci => $c) {
                if (($why = bundle_answer_marker((string) $c['text'])) !== '') {
                    $problems[] = $n . ' option ' . ($ci + 1) . ': ' . $why
                                . ' Take the marking off — a learner sees this text.';
                }
            }
        }
    }

    return $problems;
}

/**
 * Does this answer option still carry the marking from the question bank?
 *
 * Returns '' if it is clean, or a sentence naming what was found.
 *
 * TICKS AND CROSSES ARE FLAGGED WHEREVER THEY APPEAR. There is no legitimate
 * reason for one in a multiple-choice option, so position does not matter.
 *
 * WORDS ARE FLAGGED ONLY AT THE END, and the bare word "correct" is not
 * flagged at all: KM-01-KT03 has the perfectly good option "To identify and
 * correct performance deviations", and a checker that fails on that is a
 * checker somebody switches off.
 */
function bundle_answer_marker(string $text): string
{
    $t = trim($text);

    /* U+2713 check, U+2714 heavy check, U+2705 white heavy check, U+2717/U+2718
       ballot X, U+274C cross mark, U+2611 ballot box with check. */
    if (preg_match('/[\x{2713}\x{2714}\x{2705}\x{2717}\x{2718}\x{274C}\x{2611}]/u', $t)) {
        return 'it still has a tick or cross in it.';
    }
    if (preg_match('/(?:correct(?:\s+answer)?|answer\s*key|\(correct\)|\[correct\])\s*[.)\]]?$/iu', $t)) {
        return 'it ends with the words that marked it as the answer.';
    }
    if (preg_match('/[*]\s*$/u', $t)) {
        return 'it ends with an asterisk, which is how the bank marks the answer.';
    }
    return '';
}

/**
 * The form of a question or an option used to recognise it again on a reload.
 *
 * Case and run-of-whitespace differences only — nothing that could make two
 * genuinely different questions collide. A rewritten question is a new
 * question, which is correct: the old one is deactivated and any attempt
 * against it stays readable.
 */
function bundle_key(string $text): string
{
    return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
}

/**
 * Write a validated bundle.
 *
 * Every write goes through section_set() and quiz_save_questions() — the same
 * functions the editing pages call, so the same auditing, the same escaping on
 * output and the same deactivate-rather-than-delete handling of dropped quiz
 * choices apply. Nothing here writes a table directly.
 *
 * @return array a report for the operator
 */
function bundle_apply(array $bundle, string $course, bool $publish, int $by): array
{
    $report = [
        'reading' => ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0],
        'quizzes' => ['topics' => 0, 'added' => 0, 'updated' => 0, 'removed' => 0, 'published' => 0],
        'words'   => 0,
    ];

    foreach ((array) ($bundle['reading'] ?? []) as $moduleCode => $topics) {
        foreach ((array) $topics as $topicCode => $t) {
            $body = trim((string) ($t['body'] ?? ''));
            /* Area index 0 with no title unless the bundle gives one: the
               guides are written topic by topic, so there is nothing to head.
               See the long note in admin-lessons.php on why this is not one
               box per area any more. */
            $what = section_set(
                $course, (string) $moduleCode, (string) $topicCode, 0,
                mb_substr(trim((string) ($t['title'] ?? '')), 0, 255),
                $body, $publish, $by
            );
            $report['reading'][$what] = ($report['reading'][$what] ?? 0) + 1;
            $report['words'] += str_word_count($body);
        }
    }

    foreach ((array) ($bundle['quizzes'] ?? []) as $code => $q) {
        $passPct = isset($q['pass_pct']) && $q['pass_pct'] !== null ? (int) $q['pass_pct'] : null;
        $quizId  = quiz_upsert($course, (string) $code, ['pass_pct' => $passPct], $by);

        /* Reshaped into what quiz_save_questions() expects.
           ------------------------------------------------
           MATCHED TO WHAT IS ALREADY STORED, by the text of the question and
           of each option. A bundle has no database ids in it — it is a file
           somebody prepared, not an edit to particular rows — so without this
           every reload would insert forty new questions and deactivate the
           forty that were there.

           That is safe (nothing is deleted, and an earlier learner's attempt
           still points at a readable question) but it is not harmless:
           reloading a bundle is exactly what happens when a typo is spotted
           and the file rebuilt, and after five rounds of that a ten-question
           quiz is dragging fifty dead rows. Matching on the text means a
           reload updates what is there and only genuinely new questions are
           inserted.

           Matching on text rather than position is deliberate: inserting one
           question at the top of a topic's list should not renumber and
           rewrite every question below it. */
        $storedByPrompt = [];
        foreach (quiz_questions_with_choices($quizId) as $sq) {
            $storedByPrompt[bundle_key((string) $sq['prompt'])] = $sq;
        }

        $posted = [];
        foreach (array_values((array) $q['questions']) as $question) {
            $prompt = trim((string) $question['prompt']);
            $stored = $storedByPrompt[bundle_key($prompt)] ?? null;

            $storedChoiceId = [];
            foreach ((array) ($stored['choices'] ?? []) as $sc) {
                $storedChoiceId[bundle_key((string) $sc['choice_text'])] = (int) $sc['id'];
            }

            $choices = [];
            foreach ((array) $question['choices'] as $c) {
                $text = trim((string) ($c['text'] ?? ''));
                if ($text === '') continue;
                $choice = ['text' => $text, 'correct' => !empty($c['correct'])];
                if (isset($storedChoiceId[bundle_key($text)])) {
                    $choice['id'] = $storedChoiceId[bundle_key($text)];
                }
                $choices[] = $choice;
            }

            $row = ['prompt' => $prompt, 'choices' => $choices];
            if ($stored !== null) $row['id'] = (int) $stored['id'];
            $posted[] = $row;
        }

        $counts = quiz_save_questions($quizId, $posted, $by);
        $report['quizzes']['topics']++;
        $report['quizzes']['added']   += $counts['added'];
        $report['quizzes']['updated'] += $counts['updated'];
        $report['quizzes']['removed'] += $counts['removed'];

        /* A quiz follows the same choice the operator made for the reading —
           loading a course as drafts and then having to publish fifty-one
           things by hand is the failure this whole page exists to avoid. */
        if ($publish && quiz_publish($quizId, true, $by)) $report['quizzes']['published']++;
    }

    audit('bundle.loaded', 'topic_sections', null,
          $course . ': ' . $report['reading']['added'] . ' written, '
        . $report['reading']['updated'] . ' updated, '
        . $report['quizzes']['topics'] . ' quizzes');

    return $report;
}

/**
 * The whole thing, in the order it has to happen: is the upload usable, is the
 * file a bundle, does it agree with the curriculum, and only then write it.
 *
 * Kept here rather than in the page so that it can be tested without HTTP, and
 * so the four academies get the same behaviour when this file is synced.
 *
 * @return array{problems: string[], report: ?array}
 */
function bundle_import(array $file, string $course, bool $publish, int $by): array
{
    $problem = bundle_upload_problem($file);
    if ($problem !== '') return ['problems' => [$problem], 'report' => null];

    ['bundle' => $bundle, 'error' => $err] = bundle_read($file);
    if ($bundle === null) return ['problems' => [$err], 'report' => null];

    $topics = bundle_curriculum_topics();
    if (!$topics) {
        return ['problems' => ['The curriculum file (pm-modules.js) could not be read, so there is'
                             . ' nothing to check this bundle against. Nothing was loaded.'],
                'report'   => null];
    }

    $problems = bundle_validate($bundle, $course, $topics);
    if ($problems) return ['problems' => $problems, 'report' => null];

    return ['problems' => [], 'report' => bundle_apply($bundle, $course, $publish, $by)];
}
