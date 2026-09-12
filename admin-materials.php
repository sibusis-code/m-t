<?php
declare(strict_types=1);

/* Where an administrator pastes the links to the course material, or uploads
 * a file when there is no Drive link to point at instead.
 *
 * Most material still lives in Centenary's Google Workspace, and this page
 * records which Drive or SharePoint address belongs to which module — that
 * path is unchanged. Where the academy holds an actual file (a PDF, a
 * workbook, sometimes a video), it can be uploaded here instead; the two are
 * mutually exclusive per slot, admin's choice each save — see the note at the
 * top of lib/material_files.php for why uploaded material gets its own table
 * and its own validation rather than a branch bolted onto the link path.
 * materials.php hands either kind out to learners who are enrolled — logging
 * each one.
 *
 * WHY THE MODULE LIST IS NOT IN THIS FILE
 *
 * The eleven modules, their codes and their titles live in pm-modules.js, which
 * is the registered curriculum and the single source of truth for every page
 * that shows it. Writing them out again here in PHP would create a second copy
 * that disagrees with the first the day a module changes — the same argument
 * that kept percentages off the Accounts page. So this page loads that file and
 * builds its rows from window.PM_MODULES in the browser. PHP validates the
 * shape of what comes back and nothing more.
 *
 * The cost of that choice is honest: with JavaScript off, this page shows an
 * explanation and no form. It is a staff-only page used a handful of times per
 * intake, and the alternative is a curriculum that drifts.
 */

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/csrf.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/learner.php';
require __DIR__ . '/lib/materials.php';
require __DIR__ . '/lib/material_files.php';
require __DIR__ . '/lib/chrome.php';

$me = require_staff();   // trainers may read this page — see require_write() below

/* Only courses whose curriculum this platform actually carries. The others are
   real courses, but nothing here knows what their modules are. */
$courses = array_filter(learner_catalogue(),
                        fn(array $c): bool => (bool) ($c['tracked'] ?? false));

$course = (string) ($_GET['course'] ?? array_key_first($courses));
if (!isset($courses[$course])) $course = (string) array_key_first($courses);

$notice = '';
$errors = [];

/**
 * PHP's nested-array file-upload naming (files[MODULE][KIND]) arrives split
 * across five parallel arrays under $_FILES['files'] — 'name', 'type',
 * 'tmp_name', 'error', 'size' — each shaped [MODULE][KIND]. Reshaped here
 * into module => kind => the ordinary single-file array every other function
 * in lib/material_files.php expects.
 */
function admin_materials_reshape_files(?array $raw): array
{
    if ($raw === null || !isset($raw['name']) || !is_array($raw['name'])) return [];

    $out = [];
    foreach ($raw['name'] as $module => $kinds) {
        if (!is_string($module) || !is_array($kinds)) continue;
        foreach (array_keys($kinds) as $kind) {
            if (!is_string($kind)) continue;
            $out[$module][$kind] = [
                'name'     => $raw['name'][$module][$kind]     ?? '',
                'type'     => $raw['type'][$module][$kind]     ?? '',
                'tmp_name' => $raw['tmp_name'][$module][$kind] ?? '',
                'error'    => $raw['error'][$module][$kind]    ?? UPLOAD_ERR_NO_FILE,
                'size'     => $raw['size'][$module][$kind]     ?? 0,
            ];
        }
    }
    return $out;
}

if (is_post()) {
    /* A trainer reaches this page but may not change it. The check is here, on
       the writing side, and not on whether the form was rendered: a hidden
       button is not a permission. */
    require_write();

    /* AN UPLOAD OVER post_max_size NEVER REACHES THIS FILE INTACT.
       PHP discards the whole request body first, so $_POST and $_FILES both
       arrive empty and the CSRF token goes with them. Without this the page
       reports that the form had expired — which is a lie, and sends somebody
       round the same loop uploading the same too-big file and being told each
       time that they took too long over it.

       This is the ceiling for the WHOLE request, so several large files in one
       save can trip it even when each of them is individually under the limit;
       the message says so. The per-file caps are reported separately by
       material_file_problem(), which is only reached by a request that made it
       this far. */
    if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors[] = 'That upload was larger than this server accepts in one request ('
                  . (string) ini_get('post_max_size') . ' for everything sent together) and was'
                  . ' discarded before this page saw it. Nothing was saved. Try one file at a time.';
    } elseif (!csrf_valid()) {
        $errors[] = 'That form had expired — nothing was saved. Please try again.';
    } else {
        $posted = post_str('course', 60);
        if (!isset($courses[$posted])) {
            $errors[] = 'That is not a course this page can manage.';
        } else {
            $course = $posted;

            /* Three parallel inputs per slot — links[M][K], files[M][K],
               remove[M][K] — none of them trusted to name a well-formed
               module or kind; that is checked below regardless of source. */
            $links  = $_POST['links'] ?? [];
            $remove = $_POST['remove'] ?? [];
            $files  = admin_materials_reshape_files($_FILES['files'] ?? null);
            $counts = ['added' => 0, 'replaced' => 0, 'removed' => 0, 'unchanged' => 0];

            // The set of (module, kind) slots the form actually mentioned —
            // a slot with nothing in any of the three inputs is not visited.
            $slots = [];
            if (is_array($links)) {
                foreach ($links as $m => $ks) {
                    if (is_string($m) && is_array($ks)) foreach (array_keys($ks) as $k) $slots[$m][$k] = true;
                }
            }
            foreach ($files as $m => $ks) foreach (array_keys($ks) as $k) $slots[$m][$k] = true;

            foreach ($slots as $module => $kinds) {
                if (!learner_valid_code($module, 20)) {
                    $errors[] = 'Ignored a module code that did not look right.';
                    continue;
                }
                foreach (array_keys($kinds) as $kind) {
                    if (!materials_kind_valid($kind)) continue;

                    $wantRemove = !empty($remove[$module][$kind]);
                    $file       = $files[$module][$kind] ?? null;
                    $hasFile    = $file !== null && (int) $file['error'] !== UPLOAD_ERR_NO_FILE;
                    $url        = trim((string) ($links[$module][$kind] ?? ''));

                    if ($wantRemove) {
                        $removedLink = materials_set($course, $module, $kind, '', (int) $me['id']);
                        $removedFile = material_file_remove($course, $module, $kind, (int) $me['id']);
                        $counts['removed'] += ($removedLink === 'removed' || $removedFile) ? 1 : 0;
                        continue;
                    }

                    if ($hasFile) {
                        // A chosen file always wins the slot — see the note on
                        // this in the header comment above. Clears any link
                        // that was there, same as material_file_set() already
                        // does internally.
                        $result = material_file_set($course, $module, $kind, $file, (int) $me['id']);
                        if (!$result['ok']) {
                            $errors[] = $module . ' ' . $kind . ' — ' . $result['message'];
                        } else {
                            $counts[$result['action']] = ($counts[$result['action']] ?? 0) + 1;
                        }
                        continue;
                    }

                    if ($url !== '' && !materials_url_allowed($url)) {
                        $errors[] = $module . ' ' . $kind . ' — ' . materials_url_problem($url);
                        continue;
                    }
                    $what = materials_set($course, $module, $kind, $url, (int) $me['id']);
                    $counts[$what] = ($counts[$what] ?? 0) + 1;

                    // A link just took the slot — an existing file there,
                    // if any, no longer applies. A blank/unchanged url
                    // ('unchanged') leaves any existing file untouched.
                    if ($what === 'added' || $what === 'replaced') {
                        material_file_remove($course, $module, $kind, (int) $me['id']);
                    }
                }
            }

            $changed = $counts['added'] + $counts['replaced'] + $counts['removed'];
            if ($changed > 0) {
                $bits = [];
                if ($counts['added'])    $bits[] = $counts['added'] . ' added';
                if ($counts['replaced']) $bits[] = $counts['replaced'] . ' replaced';
                if ($counts['removed'])  $bits[] = $counts['removed'] . ' removed';
                $notice = 'Saved — ' . implode(', ', $bits) . '.';
            } elseif (!$errors) {
                $notice = 'Nothing had changed.';
            }
        }
        csrf_rotate();
    }
}

$existing     = db_optional(fn() => materials_for_course($course), []);
$existingFile = db_optional(fn() => material_files_for_course($course), []);
$filled   = 0;
foreach ($existing as $kinds) $filled += count($kinds);
foreach ($existingFile as $kinds) $filled += count($kinds);

/* Only the URLs and file names go to the browser — this page is
   administrator-only, and the administrator is the person who put them
   there. A slot is EITHER a link OR a file, never both — see the write path
   above — so this is a plain merge, not a "which one wins" decision. */
$forJs = [];
foreach ($existing as $module => $kinds) {
    foreach ($kinds as $kind => $row) {
        $forJs[$module][$kind] = ['url' => (string) $row['url'], 'file' => null];
    }
}
foreach ($existingFile as $module => $kinds) {
    foreach ($kinds as $kind => $row) {
        $forJs[$module][$kind] = ['url' => '', 'file' => [
            'name' => (string) $row['original_name'],
            'size' => (int) $row['size_bytes'],
        ]];
    }
}

$uploadCapBytes = material_file_effective_upload_cap();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Course material — <?= e(brand('academy')) ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= e(asset('styles.css')) ?>">
</head>
<body>
<?php chrome_nav('admin', ['active' => 'admin-materials', 'name' => $me['first_name']]); ?>

<section class="section-soft page-top">
  <div class="wrap">

    <div class="adm-head">
      <div>
        <span class="eyebrow">Academy administration</span>
        <h2>Course material</h2>
      </div>
    </div>

    <?php if (db_schema_incomplete()): ?>
      <p class="form-err" role="alert"><?= e(db_schema_notice()) ?></p>
    <?php endif; ?>
    <?php if ($notice !== ''): ?><p class="adm-notice" role="status"><?= e($notice) ?></p><?php endif; ?>
    <?php foreach ($errors as $err): ?><p class="form-err" role="alert"><?= e($err) ?></p><?php endforeach; ?>

    <div class="mat-intro">
      <p><strong>Most material lives in Centenary&rsquo;s Google Workspace.</strong> Paste the
        Drive or SharePoint link and this page remembers which address belongs to which module.
        Where the academy holds an actual file instead, upload it below — a slot is a link
        <em>or</em> a file, never both, and a chosen file always takes the slot. Either way, a
        learner who is signed in and enrolled sees the material on the module page; nobody else
        is given it, and every open is logged against the learner who opened it.</p>

      <p class="mat-warn"><strong>A link is only as private as its own sharing setting.</strong>
        In Drive, use <em>anyone with the link &mdash; Viewer</em> before you paste it here —
        until you do, learners will click through to a request-access screen. The link
        <em>is</em> the protection: anyone who has it can open the file. An uploaded file is
        different — the academy controls who is ever handed the address to open it at all — but
        the same rule applies to both: this page is for learner guides, workbooks and recordings
        only, and <strong>never for summative assessments, marking memos or facilitator
        guides</strong>.</p>

      <p class="mat-warn">Files can currently be up to
        <strong><?= $uploadCapBytes >= PHP_INT_MAX
              ? 'any size (no server limit set)'
              : material_file_format_bytes($uploadCapBytes) ?></strong> — set by the server,
        read fresh on this page load, not promised in advance.</p>

      <?php if (app_private_dir('material-files') === null): ?>
        <?php /* Uploads cannot work at all. Say where we looked, because the
                 alternative — the bare sentence "Storage is not available
                 right now" — is what this page said on the live server while
                 nobody could tell whether the directory was missing, unwritable
                 or refused for being web-reachable. Administrator-only page,
                 and server paths are what the person fixing it needs. */ ?>
        <div class="form-err" role="alert">
          <p><strong>File uploads are not working: this installation has nowhere private to
            put them.</strong> Links still work. Every place that was tried, in order:</p>
          <ul>
            <?php foreach (app_private_candidates() as $c): ?>
              <li><code><?= e($c['path']) ?></code> — <?= e($c['status']) ?>
                <span class="adm-sub">(<?= e($c['source']) ?>)</span></li>
            <?php endforeach; ?>
          </ul>
          <p>Create the first of those over SFTP and give it permissions <code>700</code>, then
            reload this page. Nothing else needs changing.</p>
        </div>
      <?php endif; ?>
    </div>

    <?php if (count($courses) > 1): ?>
      <form class="adm-search" method="GET">
        <select name="course" onchange="this.form.submit()">
          <?php foreach ($courses as $slug => $c): ?>
            <option value="<?= e($slug) ?>"<?= $slug === $course ? ' selected' : '' ?>>
              <?= e((string) ($c['title'] ?? $slug)) ?></option>
          <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn btn-primary">Show</button></noscript>
      </form>
    <?php endif; ?>

    <p class="mat-count"><strong><?= (int) $filled ?></strong> item<?= $filled === 1 ? '' : 's' ?>
      saved for <?= e((string) ($courses[$course]['title'] ?? $course)) ?>.</p>

    <form method="POST" id="mat-form" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="course" value="<?= e($course) ?>">
      <div id="mat-rows">
        <noscript><p class="adm-empty">This page needs JavaScript, because it reads the module list
          from the curriculum file rather than keeping a second copy of it.</p></noscript>
      </div>
      <div class="mat-save"><button type="submit" class="btn btn-primary">Save</button></div>
    </form>

  </div>
</section>

<script src="<?= e(asset('pm-modules.js')) ?>"></script>
<script>
(function () {
  var MODS  = window.PM_MODULES || [];
  /* JSON_HEX_TAG and friends: a file whose name contains a closing script tag
     would otherwise end this block early and leave the page blank with no
     explanation — see the fuller note on the same line in admin-quizzes.php,
     including why that tag is not written out literally even in a comment. */
  var HAVE  = <?= json_encode($forJs, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var KINDS = [
    ['guide',    'Learner guide', 'The notes this module is assessed on'],
    ['workbook', 'Workbook',      'Activities and self-assessments'],
    ['video',    'Recording',     'A facilitator session, if there is one']
  ];
  var rows = document.getElementById('mat-rows');
  if (!rows || !MODS.length) return;

  var ESCMAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' };
  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) { return ESCMAP[c]; });
  }

  function fmtBytes(n) {
    return n >= 1024 * 1024 ? (Math.round(n / 1024 / 1024 * 10) / 10) + ' MB'
                             : Math.round(n / 1024) + ' KB';
  }

  rows.innerHTML = MODS.map(function (m) {
    var have = HAVE[m.id] || {};
    var slots = KINDS.filter(function (k) {
      var v = have[k[0]];
      return v && (v.url || v.file);
    });
    var n = slots.length;
    var fields = KINDS.map(function (k) {
      var v      = have[k[0]] || { url: '', file: null };
      var id     = m.id + '-' + k[0];
      var status = v.file
        ? '<span class="mat-on">a file: ' + esc(v.file.name) + ' (' + fmtBytes(v.file.size) + ')</span>'
        : (v.url ? '<span class="mat-on">a link</span>' : '');
      return '<div class="field">' +
        '<label for="' + esc(id) + '-url">' + esc(k[1]) + (status ? ' ' + status : '') + '</label>' +
        '<input id="' + esc(id) + '-url" type="url" ' +
          'name="links[' + esc(m.id) + '][' + esc(k[0]) + ']" ' +
          'value="' + esc(v.url || '') + '" placeholder="' + esc(k[2]) + ' — or upload a file below">' +
        '<div class="mat-file-row">' +
          '<input id="' + esc(id) + '-file" type="file" ' +
            'name="files[' + esc(m.id) + '][' + esc(k[0]) + ']">' +
          ((v.url || v.file)
            ? '<label class="mat-remove"><input type="checkbox" name="remove[' + esc(m.id) + '][' + esc(k[0]) + ']" value="1"> Remove</label>'
            : '') +
        '</div>' +
        '</div>';
    }).join('');
    /* Modules with nothing in them start open, because those are the ones
       somebody came here to fill in. */
    return '<details class="mat-mod"' + (n ? '' : ' open') + '>' +
      '<summary><span class="mat-code">' + esc(m.id) + '</span> ' + esc(m.title) +
        '<span class="mat-have' + (n ? ' on' : '') + '">' + n + ' of 3</span></summary>' +
      '<div class="mat-fields">' + fields + '</div></details>';
  }).join('');

  /* Say so before the form is sent rather than after a round trip. The server
     checks the same thing again — this is a courtesy, not the guard. */
  var ALLOWED = /^https:\/\/([a-z0-9-]+\.)*(drive\.google\.com|docs\.google\.com|youtube\.com|youtu\.be|onedrive\.live\.com|1drv\.ms|sharepoint\.com)\//i;

  document.getElementById('mat-form').addEventListener('submit', function (e) {
    var bad = [].filter.call(this.querySelectorAll('input[type=url]'), function (i) {
      var v = i.value.trim();
      return v !== '' && !ALLOWED.test(v);
    });
    if (bad.length) {
      e.preventDefault();
      bad.forEach(function (i) { i.classList.add('has-err'); });
      bad[0].closest('details').open = true;
      bad[0].focus();
      alert(bad.length + (bad.length > 1 ? ' links do' : ' link does') +
            ' not point at Google Drive, SharePoint, OneDrive or YouTube, or does not start ' +
            'with https://. Nothing has been saved — the fields are marked.');
    }
  });
})();
</script>
<script src="<?= e(asset('site.js')) ?>"></script>
</body></html>
