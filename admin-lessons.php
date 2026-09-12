<?php
declare(strict_types=1);

/* Where an administrator writes the reading for each area of a topic.
 *
 * The other half of course material. admin-materials.php attaches a whole
 * document to a module; this writes the module out on the page, area by area,
 * so a learner can work through it in the browser instead of opening a
 * fifty-nine page guide and hunting for their place.
 *
 * IT DOES NOT REPLACE THE GUIDE, and the page says so. The learner guide stays
 * the authoritative document and the thing assessment is set against. What is
 * written here is the same material laid out for reading on screen — if the two
 * ever disagree, the guide is right.
 *
 * WHY THE TOPIC LIST IS NOT IN THIS FILE
 *
 * Same reasoning as admin-materials.php and admin-quizzes.php: the modules,
 * their topics and each topic's areas are the registered curriculum and live
 * in pm-modules.js, which is the single source of truth for every page that
 * shows them. This page loads that file and builds its fields from it. A
 * second copy in PHP would drift the day the curriculum was revised.
 *
 * PLAIN TEXT, NOT HTML. See the note in lib/sections.php: what is typed here
 * is stored and escaped as text, so nothing written on this page can become
 * markup on a learner's. Blank line for a new paragraph, "- " to start a
 * bullet. That is the whole of the formatting, deliberately.
 */

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/csrf.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/learner.php';
require __DIR__ . '/lib/sections.php';
require __DIR__ . '/lib/quiz.php';
require __DIR__ . '/lib/curriculum.php';
require __DIR__ . '/lib/bundle.php';
require __DIR__ . '/lib/chrome.php';

$me = require_staff();   // trainers may read this page — see require_write() below

$courses = array_filter(learner_catalogue(),
                        fn(array $c): bool => (bool) ($c['tracked'] ?? false));

$course = (string) ($_GET['course'] ?? array_key_first($courses));
if (!isset($courses[$course])) $course = (string) array_key_first($courses);

$module = (string) ($_GET['module'] ?? '');
if ($module !== '' && !learner_valid_code($module, 20)) $module = '';

$notice = '';
$errors = [];

if (is_post()) {
    /* A trainer reaches this page but may not change it. The check is here, on
       the writing side, and not on whether the form was rendered: a hidden
       button is not a permission. */
    require_write();

    /* An upload bigger than post_max_size arrives as an EMPTY $_POST and an
       empty $_FILES — PHP discards the body before this file runs. The CSRF
       token goes with it, so without this the page would report that the form
       had expired, which is a lie and sends somebody round the same loop
       trying again with the same too-big file. */
    if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors[] = 'That upload was larger than this server accepts in one request ('
                  . (string) ini_get('post_max_size') . ') and was discarded before the page saw it.';
    } elseif (!csrf_valid()) {
        $errors[] = 'That form had expired — nothing was saved. Please try again.';
    } elseif (post_str('action', 20) === 'bundle') {
        /* Loading a prepared bundle, rather than saving one module's boxes.
           Checked first because it is the one POST here that carries no module
           code — it is a whole-course load. See lib/bundle.php for why the
           content arrives as an upload instead of in the repository. */
        $postedCourse = post_str('course', 60);
        if (!isset($courses[$postedCourse])) {
            $errors[] = 'That is not a course this page can manage.';
        } else {
            $course = $postedCourse;
            $result = bundle_import(
                (array) ($_FILES['bundle'] ?? []),
                $course,
                !empty($_POST['bundle_publish']),
                (int) $me['id']
            );

            if ($result['problems']) {
                /* All of them, not the first. Somebody fixing a bundle wants
                   the list, and nothing was written, so there is no risk in
                   saying everything that is wrong with it. Capped only because
                   a badly wrong file can produce one line per topic. */
                $errors[] = 'Nothing was loaded — that bundle has '
                          . count($result['problems']) . ' problem'
                          . (count($result['problems']) === 1 ? '' : 's') . ':';
                foreach (array_slice($result['problems'], 0, 40) as $p) $errors[] = '· ' . $p;
                if (count($result['problems']) > 40) {
                    $errors[] = '· … and ' . (count($result['problems']) - 40) . ' more.';
                }
            } else {
                $r    = $result['report'];
                $bits = [];
                if ($r['reading']['added'])     $bits[] = $r['reading']['added'] . ' topics written';
                if ($r['reading']['updated'])   $bits[] = $r['reading']['updated'] . ' topics updated';
                if ($r['reading']['unchanged']) $bits[] = $r['reading']['unchanged'] . ' already the same';
                if ($r['words'])                $bits[] = number_format($r['words']) . ' words';
                /* added PLUS updated, not added alone. Reloading a corrected
                   bundle matches every question that is already stored, so
                   'added' is zero and the line read "0 questions across 10
                   quizzes" — which is true, and reads exactly like a failure to
                   somebody who has just reloaded a file to fix a mistake. */
                if ($r['quizzes']['topics']) {
                    $bits[] = ($r['quizzes']['added'] + $r['quizzes']['updated'])
                            . ' questions across ' . $r['quizzes']['topics'] . ' quizzes';
                }
                $notice = 'Bundle loaded — ' . implode(', ', $bits) . '.'
                        . (empty($_POST['bundle_publish'])
                            ? ' It is NOT visible to learners yet: nothing was published.'
                            : ' Learners can read it now.');
            }
        }
        csrf_rotate();
    } else {
        $postedCourse = post_str('course', 60);
        $postedModule = post_str('module', 20);

        if (!isset($courses[$postedCourse])) {
            $errors[] = 'That is not a course this page can manage.';
        } elseif (!learner_valid_code($postedModule, 20)) {
            $errors[] = 'That module code did not look right.';
        } else {
            $course = $postedCourse;
            $module = $postedModule;
            $counts = ['added' => 0, 'updated' => 0, 'removed' => 0, 'unchanged' => 0];

            foreach ((array) ($_POST['sec'] ?? []) as $topicCode => $areas) {
                if (!is_string($topicCode) || !learner_valid_code($topicCode, 30) || !is_array($areas)) {
                    $errors[] = 'Ignored a topic code that did not look right.';
                    continue;
                }
                foreach ($areas as $idx => $field) {
                    if (!is_numeric($idx) || !is_array($field)) continue;
                    $i = (int) $idx;
                    if ($i < 0 || $i >= SECTION_MAX_AREAS) continue;

                    $what = section_set(
                        $course, $module, $topicCode, $i,
                        mb_substr(trim((string) ($field['title'] ?? '')), 0, 255),
                        (string) ($field['body'] ?? ''),
                        !empty($field['published']),
                        (int) $me['id']
                    );
                    $counts[$what] = ($counts[$what] ?? 0) + 1;
                }
            }

            $changed = $counts['added'] + $counts['updated'] + $counts['removed'];
            if ($changed > 0) {
                $bits = [];
                if ($counts['added'])   $bits[] = $counts['added'] . ' written';
                if ($counts['updated']) $bits[] = $counts['updated'] . ' updated';
                if ($counts['removed']) $bits[] = $counts['removed'] . ' cleared';
                $notice = 'Saved for ' . $module . ' — ' . implode(', ', $bits) . '.';
            } elseif (!$errors) {
                $notice = 'Nothing had changed.';
            }
        }
        csrf_rotate();
    }
}

/* Everything already stored for this module, published or not — this is the
   editor, so a draft has to be visible here even though a learner cannot see
   it on the module page. */
$existing = $module !== ''
    ? db_optional(fn() => sections_for_module($course, $module, false), [])
    : [];

$forJs = [];
foreach ($existing as $topicCode => $areas) {
    foreach ($areas as $i => $row) {
        $forJs[$topicCode][(string) $i] = [
            'title'     => (string) $row['area_title'],
            'body'      => (string) $row['body'],
            'published' => (bool) $row['published'],
        ];
    }
}

$counts = db_optional(fn() => sections_count_for_course($course), []);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Module reading — <?= e(brand('academy')) ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= e(asset('styles.css')) ?>">
</head>
<body>
<?php chrome_nav('admin', ['active' => 'admin-lessons', 'name' => $me['first_name']]); ?>

<section class="section-soft page-top">
  <div class="wrap">

    <div class="adm-head">
      <div>
        <span class="eyebrow">Academy administration</span>
        <h2>Module reading</h2>
      </div>
    </div>

    <?php if (db_schema_incomplete()): ?>
      <p class="form-err" role="alert"><?= e(db_schema_notice()) ?></p>
    <?php endif; ?>
    <?php if ($notice !== ''): ?><p class="adm-notice" role="status"><?= e($notice) ?></p><?php endif; ?>
    <?php foreach ($errors as $err): ?><p class="form-err" role="alert"><?= e($err) ?></p><?php endforeach; ?>

    <div class="mat-intro">
      <p><strong>This is the module written out to be read on screen, one area at a time.</strong>
        It is for a learner working through a topic in the browser rather than opening the whole
        guide and finding their place. An area with nothing written here simply stays as it is on
        the module page — a heading — so you can fill these in gradually.</p>

      <p class="mat-warn"><strong>The learner guide is still the authoritative document.</strong>
        What you write here is the same material laid out for reading; it does not replace the
        guide and assessment is still set against the guide. If the two ever disagree, the guide
        is right and this should be corrected.</p>

      <p class="mat-warn"><strong>Formatting is deliberately plain.</strong> Leave a blank line to
        start a new paragraph, and begin a line with <code>- </code> to make a bullet. That is all
        there is — what you type is stored and shown as text, never as markup, which is what stops
        anything typed here becoming code on a learner's page.</p>
    </div>

    <details class="sec-bundle"<?= $notice !== '' || $errors ? ' open' : '' ?>>
      <summary>Load a prepared bundle</summary>
      <div class="sec-bundle-body">
        <p>A bundle is one <code>.json</code> file holding the reading for many topics at once, and
          the quiz questions that go with them. It is how a whole course is put on the platform —
          fifty-one topics typed into fifty-one boxes by hand is not editing, it is a data load, and
          it is how a topic ends up filed under the wrong code.</p>

        <p class="mat-warn"><strong>The file is checked against the registered curriculum before
          anything is written.</strong> Every topic code in it has to be a topic that exists, filed
          under the module it actually belongs to. If any part of the bundle is wrong,
          <strong>none</strong> of it is loaded and this page lists what is wrong — a half-loaded
          course looks finished from the module list, and the gap is only found by a learner.</p>

        <p class="mat-warn"><strong>Course material is never kept in the site's code.</strong> That
          is why this is an upload: the repository this site deploys from is public, so anything put
          there to be deployed is published to anybody who looks. The guides belong to enrolled
          learners who are signed in. Keep the bundle file on your own machine — do not put it in a
          shared folder, and do not email it.</p>

        <form method="POST" enctype="multipart/form-data" class="sec-bundle-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="bundle">
          <input type="hidden" name="course" value="<?= e($course) ?>">
          <input type="file" name="bundle" accept=".json,application/json" required>
          <label class="mat-remove"><input type="checkbox" name="bundle_publish" value="1" checked>
            Publish it as it loads, so learners can read it straight away</label>
          <button type="submit" class="btn btn-primary">Load this bundle</button>
        </form>
      </div>
    </details>

    <form class="adm-search" method="GET">
      <?php if (count($courses) > 1): ?>
        <select name="course" onchange="this.form.submit()">
          <?php foreach ($courses as $slug => $c): ?>
            <option value="<?= e($slug) ?>"<?= $slug === $course ? ' selected' : '' ?>>
              <?= e((string) ($c['title'] ?? $slug)) ?></option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <input type="hidden" name="course" value="<?= e($course) ?>">
      <?php endif; ?>
      <select name="module" id="sec-module" onchange="this.form.submit()">
        <option value="">Choose a module…</option>
      </select>
      <noscript><button type="submit" class="btn btn-primary">Show</button></noscript>
    </form>

    <?php if ($module === ''): ?>
      <p class="adm-empty">Choose a module to write its reading.</p>
    <?php else: ?>
      <form method="POST" id="sec-form">
        <?= csrf_field() ?>
        <input type="hidden" name="course" value="<?= e($course) ?>">
        <input type="hidden" name="module" value="<?= e($module) ?>">
        <div id="sec-rows">
          <noscript><p class="adm-empty">This page needs JavaScript, because it reads the topic
            list from the curriculum file rather than keeping a second copy of it.</p></noscript>
        </div>
        <div class="mat-save"><button type="submit" class="btn btn-primary">Save this module’s reading</button></div>
      </form>
    <?php endif; ?>

  </div>
</section>

<script src="<?= e(asset('pm-modules.js')) ?>"></script>
<script>
(function () {
  var MODS   = window.PM_MODULES || [];
  /* Same flags, and the same reason, as admin-quizzes.php: without JSON_HEX_TAG
     any stored text containing a closing script tag would end this block early,
     leaving the page blank with nothing on it to say why. */
  var HAVE   = <?= json_encode($forJs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var COUNTS = <?= json_encode($counts, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var MODULE = <?= json_encode($module, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  if (!MODS.length) return;

  var ESCMAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return ESCMAP[c]; }); }

  /* The module chooser, filled from the curriculum rather than from PHP. */
  var sel = document.getElementById('sec-module');
  if (sel) {
    MODS.forEach(function (m) {
      var c = COUNTS[m.id];
      var label = m.id + ' · ' + m.title;
      if (c) label += '  (' + c.published + ' of ' + c.total + ' published)';
      var o = document.createElement('option');
      o.value = m.id;
      o.textContent = label;
      if (m.id === MODULE) o.selected = true;
      sel.appendChild(o);
    });
  }

  var rows = document.getElementById('sec-rows');
  if (!rows || !MODULE) return;

  var mod = MODS.filter(function (m) { return m.id === MODULE; })[0];
  if (!mod) { rows.innerHTML = '<p class="adm-empty">That module is not in the curriculum file.</p>'; return; }

  /* ONE BOX PER TOPIC, not one per area (changed 8 Sep 2026).
     -------------------------------------------------------
     This used to render a textarea for every area the curriculum lists under a
     topic, each with the area's name pushed into a hidden title field. That was
     wrong twice over once the Learner Guides were actually read: the guides are
     written topic by topic, so material loaded from them has no area to belong
     to — and it would have been shown here under whichever area happened to be
     first, then had that area's name saved onto it as a title.

     So the areas are now shown for what they are: the syllabus this topic has
     to cover, listed above the box as a reminder while writing. The material
     itself is one piece, stored at index 0 with no title, exactly as the
     loader writes it.

     Nothing prunes: the save only touches what it is sent, so anything already
     stored at a higher index stays where it is. */
  rows.innerHTML = mod.topics.map(function (t) {
    var have = HAVE[t.code] || {};
    var v = have['0'] || { title: '', body: '', published: false };
    var nm = 'sec[' + esc(t.code) + '][0]';

    var syllabus = (t.covers || []).length
      ? '<ul class="sec-covers">' + t.covers.map(function (c) {
          return '<li>' + esc(c) + '</li>';
        }).join('') + '</ul>'
      : '';

    return '<div class="sec-topic">' + esc(t.n) +
        '<span class="sec-topic-code">' + esc(t.code) + ' · ' + t.w + '% of the module</span></div>' +
      '<div class="sec-area">' +
        (syllabus ? '<p class="sec-hint">This topic has to cover:</p>' + syllabus : '') +
        '<input type="hidden" name="' + nm + '[title]" value="' + esc(v.title || '') + '">' +
        '<textarea name="' + nm + '[body]" placeholder="What a learner reads for this topic. ' +
          'Blank line for a new paragraph, &quot;- &quot; for a bullet. Leave empty to show nothing.">' +
          esc(v.body) + '</textarea>' +
        '<label class="mat-remove"><input type="checkbox" name="' + nm + '[published]" value="1"' +
          (v.published ? ' checked' : '') + '> Learners can read this</label>' +
      '</div>';
  }).join('');
})();
</script>
<script src="<?= e(asset('site.js')) ?>"></script>
</body></html>
