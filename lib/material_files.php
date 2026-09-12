<?php
declare(strict_types=1);

/* Course material the academy holds as an actual file, not a link.
 *
 * WHY THIS IS ITS OWN FILE, NOT A BRANCH INSIDE lib/materials.php
 *
 * The comment at the top of lib/materials.php says "we hold a LINK, never a
 * file" and gives real reasons: storage, virus scanning, document viewing,
 * video streaming and mobile apps are solved problems, and a training
 * platform that reimplements them is a training platform that falls over.
 * That is still correct for the link path, and nothing here contradicts it —
 * lib/materials.php keeps meaning exactly what it always meant. File storage
 * is a deliberate, explained departure for material that has no Drive link to
 * point at instead, so it gets its own table (material_files) and its own
 * file, the same reasoning that kept account_invites apart from
 * password_resets rather than branching one file around two purposes.
 *
 * WHERE THE BYTES ACTUALLY LIVE
 *
 * app_private_dir('material-files') — outside the web root, outside the
 * git-tracked repository entirely (tools/make-deploy-zip.php builds its file
 * list from `git ls-files`, so nothing under here is ever swept into a
 * deploy). On disk each file is named bin2hex(random_bytes(16)) with NO
 * extension at all — belt and braces on top of the directory already being
 * unreachable over HTTP: there is nothing an accidental server
 * misconfiguration could mistake for something executable.
 *
 * WHAT IS TRUSTED, AND WHAT NEVER IS
 *
 * The mime type stored and later sent to a browser is always the CANONICAL
 * value from MATERIAL_FILE_EXT, keyed by the extension the admin's upload
 * carried — never $_FILES[...]['type'] (the browser's own claim, worth
 * nothing) and never re-derived by re-sniffing the file at serve time.
 * finfo_file() is used only as a soft sanity check at upload time, and only
 * for pdf/video: Office formats (doc/docx/ppt/pptx) are ZIP containers that
 * finfo frequently misreports, so being strict there would reject real files.
 */

defined('APP_BOOTED') or exit('lib/material_files.php is not a page.');

const MATERIAL_FILE_EXT = [
    'guide'    => [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ],
    'workbook' => [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ],
    'video' => [
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
    ],
];

/* Per-kind ceiling. Not a guess at what the server will actually accept —
   see material_file_effective_upload_cap() for that — just the largest thing
   worth letting anyone try, so a mistaken multi-gigabyte selection fails fast
   with a clear message instead of grinding through an upload the server was
   always going to reject. */
const MATERIAL_FILE_MAX_BYTES = [
    'guide'    => 25 * 1024 * 1024,
    'workbook' => 25 * 1024 * 1024,
    'video'    => 300 * 1024 * 1024,
];

function material_file_extension_allowed(string $kind, string $ext): bool
{
    return isset(MATERIAL_FILE_EXT[$kind][strtolower($ext)]);
}

/** The value to STORE and later serve, never derived any other way. */
function material_file_mime_for_extension(string $kind, string $ext): ?string
{
    return MATERIAL_FILE_EXT[$kind][strtolower($ext)] ?? null;
}

function material_file_max_bytes(string $kind): int
{
    return MATERIAL_FILE_MAX_BYTES[$kind] ?? (25 * 1024 * 1024);
}

/**
 * The smaller of the two ini limits that actually govern an upload, in
 * bytes. Read live rather than assumed, because .user.ini's effect on this
 * host is exactly the sort of thing that must be verified after deploying,
 * not promised in advance — see admin-materials.php, which shows this number
 * plainly rather than letting an admin guess at it.
 */
function material_file_effective_upload_cap(): int
{
    $parse = static function (string $v): int {
        $v = trim($v);
        if ($v === '' || $v === '0') return PHP_INT_MAX;   // ini says "no limit"
        $unit = strtolower(substr($v, -1));
        $num  = (int) $v;
        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => (int) $v,
        };
    };
    return min(
        $parse((string) ini_get('upload_max_filesize')),
        $parse((string) ini_get('post_max_size'))
    );
}

/**
 * A short reason an upload was refused, for the admin who just tried it.
 * Mirrors materials_url_problem()'s honesty about what went wrong and why.
 *
 * @param array $file one element of $_FILES, e.g. $_FILES['links']['guide']['KM-01']
 */
function material_file_problem(string $kind, array $file): string
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return 'That file is larger than this server currently accepts ('
             . material_file_format_bytes(material_file_effective_upload_cap()) . ').';
    }
    if ($err !== UPLOAD_ERR_OK) return 'That upload did not complete. Please try again.';

    $name = (string) ($file['name'] ?? '');
    $ext  = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    if (!material_file_extension_allowed($kind, $ext)) {
        $allowed = implode(', ', array_keys(MATERIAL_FILE_EXT[$kind] ?? []));
        return 'That file type is not accepted here — allowed: ' . $allowed . '.';
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) return 'That file appears to be empty.';
    if ($size > material_file_max_bytes($kind)) {
        return 'That file is larger than the ' . material_file_format_bytes(material_file_max_bytes($kind))
             . ' limit for this kind of material.';
    }

    return '';
}

function material_file_format_bytes(int $bytes): string
{
    if ($bytes >= 1024 * 1024 * 1024) return round($bytes / 1024 / 1024 / 1024, 1) . ' GB';
    return round($bytes / 1024 / 1024) . ' MB';
}

/* ---------------------------------------------------------------------------
   Storage
   --------------------------------------------------------------------------- */

/** One row by id, scoped to this tenant — never trust an id from a query string. */
function material_file_get(int $id): ?array
{
    return db_one('SELECT * FROM material_files WHERE id = ? AND tenant_id = ?', [$id, tenant_id()]);
}

function material_file_for_slot(string $courseSlug, string $moduleCode, string $kind): ?array
{
    return db_one(
        'SELECT * FROM material_files WHERE tenant_id = ? AND course_slug = ? AND module_code = ? AND kind = ?',
        [tenant_id(), $courseSlug, $moduleCode, $kind]
    );
}

/**
 * Every file for one course, keyed module_code => kind => row — same shape
 * and same reasoning as materials_for_course(): one query rather than one
 * per module.
 */
function material_files_for_course(string $courseSlug): array
{
    $rows = db_all(
        'SELECT * FROM material_files WHERE tenant_id = ? AND course_slug = ? ORDER BY module_code, kind',
        [tenant_id(), $courseSlug]
    );

    $out = [];
    foreach ($rows as $r) {
        $out[(string) $r['module_code']][(string) $r['kind']] = $r;
    }
    return $out;
}

/**
 * Validate, store, and record an uploaded file for one slot.
 *
 * @param array $file one element of $_FILES, already known to have a real
 *              upload in it (caller checks material_file_problem() first)
 * @return array{ok:bool, message:string, action:?string}
 */
function material_file_set(string $courseSlug, string $moduleCode, string $kind, array $file, int $by): array
{
    $problem = material_file_problem($kind, $file);
    if ($problem !== '') return ['ok' => false, 'message' => $problem, 'action' => null];

    $ext  = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $mime = material_file_mime_for_extension($kind, $ext);
    if ($mime === null) return ['ok' => false, 'message' => 'Unrecognised file type.', 'action' => null];

    /* A soft sanity check only, and only where finfo is reliable — see this
       file's header comment on why Office formats are exempt. Logged, not
       fatal: a false positive here must never block a legitimate upload. */
    if (in_array($ext, ['pdf', 'mp4', 'webm'], true) && function_exists('finfo_open')) {
        $probe = @finfo_file(finfo_open(FILEINFO_MIME_TYPE), (string) $file['tmp_name']);
        if ($probe !== false && $probe !== $mime && !str_starts_with((string) $probe, 'video/')) {
            app_log('MATERIAL UPLOAD MIME MISMATCH — expected ' . $mime . ', finfo says ' . $probe);
        }
    }

    $dir  = app_private_dir('material-files');
    if ($dir === null) return ['ok' => false, 'message' => 'Storage is not available right now.', 'action' => null];

    $diskName = bin2hex(random_bytes(16));
    $dest     = $dir . '/' . $diskName;

    if (!@move_uploaded_file((string) $file['tmp_name'], $dest)) {
        app_log('MATERIAL UPLOAD FAILED to move to ' . $dest);
        return ['ok' => false, 'message' => 'That file could not be saved. Please try again.', 'action' => null];
    }

    $existing = material_file_for_slot($courseSlug, $moduleCode, $kind);
    $oldDisk  = $existing['disk_name'] ?? null;

    try {
        if ($existing !== null) {
            db_run('UPDATE material_files SET disk_name = ?, original_name = ?, mime_type = ?,
                        size_bytes = ?, updated_at = ?, updated_by = ?
                    WHERE id = ? AND tenant_id = ?',
                   [$diskName, mb_substr((string) $file['name'], 0, 255), $mime,
                    (int) $file['size'], now(), $by, (int) $existing['id'], tenant_id()]);
            $id     = (int) $existing['id'];
            $action = 'replaced';
        } else {
            $id = db_insert('material_files', [
                'tenant_id'     => tenant_id(),
                'course_slug'   => $courseSlug,
                'module_code'   => $moduleCode,
                'kind'          => $kind,
                'disk_name'     => $diskName,
                'original_name' => mb_substr((string) $file['name'], 0, 255),
                'mime_type'     => $mime,
                'size_bytes'    => (int) $file['size'],
                'updated_at'    => now(),
                'updated_by'    => $by,
            ]);
            $action = 'added';
        }
    } catch (Throwable $e) {
        @unlink($dest);   // the DB write failed — don't leave an orphaned upload behind
        app_log('MATERIAL UPLOAD DB WRITE FAILED: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'That could not be saved. Nothing was changed — please try again.', 'action' => null];
    }

    // Only after the DB write commits: remove the file this one replaced.
    if ($oldDisk !== null && $oldDisk !== $diskName) {
        @unlink($dir . '/' . $oldDisk);
    }

    /* Mutually exclusive with a link in the same slot — see the note on
       material_files in schema.mysql.sql. A file just won the slot, so any
       link row for it goes. */
    db_run('DELETE FROM materials WHERE tenant_id = ? AND course_slug = ? AND module_code = ? AND kind = ?',
           [tenant_id(), $courseSlug, $moduleCode, $kind]);

    audit($action === 'replaced' ? 'material.file_replaced' : 'material.file_added',
          'material_files', $id, $moduleCode . ' ' . $kind);

    return ['ok' => true, 'message' => ucfirst($action), 'action' => $action];
}

function material_file_remove(string $courseSlug, string $moduleCode, string $kind, int $by): bool
{
    $existing = material_file_for_slot($courseSlug, $moduleCode, $kind);
    if ($existing === null) return false;

    db_run('DELETE FROM material_files WHERE id = ? AND tenant_id = ?', [(int) $existing['id'], tenant_id()]);

    $dir = app_private_dir('material-files');
    if ($dir !== null) @unlink($dir . '/' . $existing['disk_name']);

    audit('material.file_removed', 'material_files', (int) $existing['id'], $moduleCode . ' ' . $kind);
    return true;
}

/* ---------------------------------------------------------------------------
   Streaming
   --------------------------------------------------------------------------- */

/**
 * Send one file's bytes to the browser, with Range/206 support for seeking.
 *
 * Called from materials.php AFTER the enrolment check has already passed —
 * this function does no authorization of its own, on purpose: it has one job.
 */
function material_file_stream(array $row): void
{
    $dir  = app_private_dir('material-files');
    $path = $dir !== null ? $dir . '/' . $row['disk_name'] : null;

    if ($path === null || !is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><meta charset="utf-8"><title>Not available</title>'
           . '<p style="font:16px/1.6 system-ui,sans-serif;max-width:34em;margin:12vh auto;padding:0 6vw">'
           . 'That file is no longer available. If you had a link to it, ask the academy.</p>';
        return;
    }

    $mime = (string) $row['mime_type'];
    $size = filesize($path);
    $name = preg_replace('/[\r\n"]/', '', (string) $row['original_name']);

    /* PDFs and video preview in-browser; Office formats don't render inline
       anywhere, so a download prompt is the honest UX rather than a browser
       trying and failing to display raw XML/ZIP bytes. */
    $inline = $mime === 'application/pdf' || str_starts_with($mime, 'video/');

    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
    header('Accept-Ranges: bytes');
    /* Private, not no-store: this is per-request-authorized bytes, not a
       secret URL. Letting the learner's OWN browser cache the ranges it
       already fetched is what makes scrubbing a video usable on shared
       hosting; a shared proxy still can't cache it for anyone else. */
    header('Cache-Control: private, max-age=3600');

    // One long download must not hold the learner's session lock and block
    // every other tab they have open.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(0);   // best-effort; shared hosting may not honour it

    $start = 0;
    $end   = $size - 1;
    $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
    $isRangeRequest = false;

    if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m)) {
        $reqStart = $m[1] === '' ? null : (int) $m[1];
        $reqEnd   = $m[2] === '' ? null : (int) $m[2];

        if ($reqStart === null && $reqEnd !== null) {
            // "last N bytes"
            $start = max(0, $size - $reqEnd);
            $end   = $size - 1;
        } elseif ($reqStart !== null) {
            $start = $reqStart;
            $end   = $reqEnd !== null ? min($reqEnd, $size - 1) : $size - 1;
        }

        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            return;
        }

        $isRangeRequest = true;
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    } else {
        http_response_code(200);
    }

    header('Content-Length: ' . ($end - $start + 1));

    /* Audited once per view, not once per range fetch — a single video can
       generate dozens of small Range requests while a learner scrubs, and
       logging every one would flood audit_log for no benefit. Access is
       still re-checked by the CALLER on every single request regardless
       (materials.php re-runs learner_is_enrolled() before this function is
       ever reached) — only the LOGGING is coalesced to the first request. */
    if (!$isRangeRequest || $start === 0) {
        audit('material.file_opened', 'material_files', (int) $row['id'],
              (string) $row['module_code'] . ' ' . (string) $row['kind']);
    }

    $fh = fopen($path, 'rb');
    if ($fh === false) { http_response_code(500); return; }
    fseek($fh, $start);

    $remaining = $end - $start + 1;
    $chunk = 1024 * 1024;
    while ($remaining > 0 && !feof($fh)) {
        if (connection_aborted()) break;
        $read = fread($fh, min($chunk, $remaining));
        if ($read === false) break;
        echo $read;
        $remaining -= strlen($read);
        flush();
    }
    fclose($fh);
}
