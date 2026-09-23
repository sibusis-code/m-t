<?php
declare(strict_types=1);

/* The registered curriculum, read on the server.
 *
 * pm-modules.js is the single source of truth for module codes, titles, credits
 * and topic weightings — it is generated from the provider's QCTO learner
 * guides and its own header says it must not be hand-edited. Every page that
 * shows the curriculum loads that file in the browser.
 *
 * Some things cannot wait for a browser. An email naming the module a learner
 * has just finished is written by PHP, with nobody looking at a page, so the
 * titles have to be readable from here too.
 *
 * THE ONE RULE: this file PARSES that file. It never restates it. A second copy
 * of the curriculum in PHP would be wrong within a release — someone corrects a
 * topic title in the .js, the emails keep sending the old one, and nothing
 * fails. So there is one parser, here, and lib/bundle.php calls it rather than
 * keeping a second one of its own.
 *
 * If the file cannot be read the callers get an empty array, and every one of
 * them treats that as "say less", never as "say something wrong": the letter
 * falls back to the bare module code, which is at least true.
 */

defined('APP_BOOTED') or exit('lib/curriculum.php is not a page.');

/**
 * The curriculum file for each tracked course.
 *
 * Three qualifications since 23 Sep 2026. A slug that is not here has no module
 * structure on the site, and every function below then returns nothing — which
 * the callers already treat as "say less", never as "say something wrong".
 *
 * The parser below is shape-agnostic on purpose. New Venture Creation is not a
 * QCTO qualification: its modules are the sections of a learner guide, its
 * codes are unit standards rather than "121905000-KM-01", and it registers no
 * topic weightings. None of that matters here, because all this reads is the
 * module id, the module code, the credits, and each topic's code and name.
 *
 * @return array<string,string> course slug => file in the site root
 */
function curriculum_files(): array
{
    return [
        'project-management'   => 'pm-modules.js',
        'procurement-officer'  => 'po-modules.js',
        'new-venture-creation' => 'nvc-modules.js',
    ];
}

/**
 * One course's modules, in the order the curriculum lists them.
 *
 * @return array<string, array{code:string, title:string, credits:int, topics:array<string,string>}>
 *         keyed by module id ("KM-01"); topics are topic code => topic name.
 */
function curriculum_modules(string $slug = 'project-management'): array
{
    static $cache = [];
    if (isset($cache[$slug])) return $cache[$slug];

    $file = curriculum_files()[$slug] ?? '';
    if ($file === '') return $cache[$slug] = [];

    $js = @file_get_contents(APP_ROOT . '/' . $file);
    if ($js === false || $js === '') return $cache[$slug] = [];

    /* ONLY the MODULES array. Both files end with a registry block that carries
       the QUALIFICATION's own title and credit total — read straight through,
       those land on whichever module was parsed last and quietly rename it.
       That block was added the same day this slice was, and the first run of
       the parser turned KM-11 into "Occupational Certificate: Project Manager,
       240 credits". So the text after the array is cut off before parsing. */
    $start = strpos($js, 'const MODULES = [');
    if ($start === false) return $cache[$slug] = [];
    $end = strpos($js, "\n  ];", $start);
    if ($end === false) return $cache[$slug] = [];
    $js = substr($js, $start, $end - $start);

    /* Only these five keys, and only where they appear as `key: value`. The
       `covers` arrays hold bare strings — no key in front — so they cannot be
       mistaken for a title, which is the whole reason this is anchored on the
       key rather than on the quotes. */
    if (!preg_match_all('/\b(id|title|code|n|credits)\s*:\s*(?:"([^"]*)"|(\d+))/', $js, $m, PREG_SET_ORDER)) {
        return $cache[$slug] = [];
    }

    $out     = [];
    $module  = '';
    $topic   = '';
    foreach ($m as $hit) {
        $key = $hit[1];
        $val = $hit[2] !== '' ? $hit[2] : ($hit[3] ?? '');

        if ($key === 'id') {
            $module = $val;
            $topic  = '';
            $out[$module] = ['code' => '', 'title' => '', 'credits' => 0, 'topics' => []];
            continue;
        }
        if ($module === '' || !isset($out[$module])) continue;

        switch ($key) {
            case 'code':
                /* Two different things are called `code` in that file: the
                   module's registered code ("121905000-KM-01") and a topic's
                   ("KM-01-KT01"). They are told apart by which one starts with
                   the module id, which is also how bundle_validate() decides a
                   topic is filed under the right module. */
                if (str_starts_with($val, $module . '-')) {
                    $topic = $val;
                    $out[$module]['topics'][$topic] = '';
                } elseif ($out[$module]['code'] === '') {
                    $out[$module]['code'] = $val;
                }
                break;

            case 'title':
                // The first title after the module id is the module's own.
                if ($out[$module]['title'] === '') $out[$module]['title'] = $val;
                break;

            case 'n':
                if ($topic !== '' && ($out[$module]['topics'][$topic] ?? '') === '') {
                    $out[$module]['topics'][$topic] = $val;
                }
                break;

            case 'credits':
                if ($out[$module]['credits'] === 0) $out[$module]['credits'] = (int) $val;
                break;
        }
    }

    // A module with no topics is a parse that went wrong, not a real module.
    $out = array_filter($out, fn(array $mod): bool => $mod['topics'] !== []);

    return $cache[$slug] = $out;
}

/**
 * Every topic code mapped to the module it belongs to.
 *
 * @return array<string,string> topic code => module id
 */
function curriculum_topics(string $slug = 'project-management'): array
{
    $out = [];
    foreach (curriculum_modules($slug) as $mod => $data) {
        foreach (array_keys($data['topics']) as $topic) $out[$topic] = $mod;
    }
    return $out;
}

/**
 * The module a topic belongs to, or '' if the code is not in that curriculum.
 *
 * The course matters: both qualifications have a KM-01-KT01, and answering from
 * the wrong one would file a learner's result against the wrong module.
 */
function curriculum_module_of(string $topicCode, string $slug = 'project-management'): string
{
    return curriculum_topics($slug)[$topicCode] ?? '';
}

/**
 * A module's title, falling back to the bare code.
 *
 * The fallback matters: these are used in learner-facing email, and a letter
 * that says "KM-07" is merely terse, while one that says "Unknown module" or
 * prints an empty heading looks broken to the person reading it.
 */
function curriculum_module_title(string $moduleId, string $slug = 'project-management'): string
{
    $t = curriculum_modules($slug)[$moduleId]['title'] ?? '';
    return $t !== '' ? $t : $moduleId;
}

/** A topic's name, falling back to the bare code, for the same reason. */
function curriculum_topic_title(string $topicCode, string $slug = 'project-management'): string
{
    $mod = curriculum_module_of($topicCode, $slug);
    $t   = $mod !== '' ? (curriculum_modules($slug)[$mod]['topics'][$topicCode] ?? '') : '';
    return $t !== '' ? $t : $topicCode;
}

/** The topic codes of one module, in curriculum order. */
function curriculum_module_topics(string $moduleId, string $slug = 'project-management'): array
{
    return array_keys(curriculum_modules($slug)[$moduleId]['topics'] ?? []);
}
