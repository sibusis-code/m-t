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
 * The eleven modules, in the order the curriculum lists them.
 *
 * @return array<string, array{code:string, title:string, credits:int, topics:array<string,string>}>
 *         keyed by module id ("KM-01"); topics are topic code => topic name.
 */
function curriculum_modules(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $js = @file_get_contents(APP_ROOT . '/pm-modules.js');
    if ($js === false || $js === '') return $cache = [];

    /* Only these five keys, and only where they appear as `key: value`. The
       `covers` arrays hold bare strings — no key in front — so they cannot be
       mistaken for a title, which is the whole reason this is anchored on the
       key rather than on the quotes. */
    if (!preg_match_all('/\b(id|title|code|n|credits)\s*:\s*(?:"([^"]*)"|(\d+))/', $js, $m, PREG_SET_ORDER)) {
        return $cache = [];
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

    return $cache = $out;
}

/**
 * Every topic code mapped to the module it belongs to.
 *
 * @return array<string,string> topic code => module id
 */
function curriculum_topics(): array
{
    $out = [];
    foreach (curriculum_modules() as $mod => $data) {
        foreach (array_keys($data['topics']) as $topic) $out[$topic] = $mod;
    }
    return $out;
}

/** The module a topic belongs to, or '' if the code is not in the curriculum. */
function curriculum_module_of(string $topicCode): string
{
    return curriculum_topics()[$topicCode] ?? '';
}

/**
 * A module's title, falling back to the bare code.
 *
 * The fallback matters: these are used in learner-facing email, and a letter
 * that says "KM-07" is merely terse, while one that says "Unknown module" or
 * prints an empty heading looks broken to the person reading it.
 */
function curriculum_module_title(string $moduleId): string
{
    $t = curriculum_modules()[$moduleId]['title'] ?? '';
    return $t !== '' ? $t : $moduleId;
}

/** A topic's name, falling back to the bare code, for the same reason. */
function curriculum_topic_title(string $topicCode): string
{
    $mod = curriculum_module_of($topicCode);
    $t   = $mod !== '' ? (curriculum_modules()[$mod]['topics'][$topicCode] ?? '') : '';
    return $t !== '' ? $t : $topicCode;
}

/** The topic codes of one module, in curriculum order. */
function curriculum_module_topics(string $moduleId): array
{
    return array_keys(curriculum_modules()[$moduleId]['topics'] ?? []);
}
