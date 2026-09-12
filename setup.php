<?php
declare(strict_types=1);

/* One-time installer.
 *
 * WHY THIS EXISTS AT ALL. Xneelo's shared hosting answers port 22 with
 * "SSH-2.0-FTP Service" — SFTP only, no shell. There is no way to run
 * tools/migrate.php on the server, so the tables have to be created through a
 * browser. A web-reachable installer is a genuine risk, so it is fenced three
 * ways:
 *
 *   1. It does nothing at all unless 'setup_token' is set in the configuration
 *      file — which lives OUTSIDE the web root and can only be written over
 *      SFTP. With no token the page is a 404, indistinguishable from a file
 *      that was never uploaded. That is the master switch.
 *   2. The token is submitted by POST, not in the URL, so it does not end up in
 *      the server's access log, in a Referer header, or in browser history.
 *   3. It only ever creates. Nothing here drops a table, empties one, or
 *      changes an existing password. Run it twice and the second run reports
 *      that there was nothing to do.
 *
 * DELETE THE TOKEN when you are finished. The page says so, loudly, until you
 * have.
 */

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/install.php';

$token = (string) (app_config('setup_token') ?? '');

/* The master switch. Not "access denied" — a 404, because an attacker who
   learns that an installer exists but is switched off has learned something. */
if ($token === '') {
    http_response_code(404);
    exit('Not found.');
}

$https = (($_SERVER['HTTPS'] ?? '') === 'on')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$debug = (bool) (app_config('debug') ?? false);

$errors = [];
$done   = [];
$newPassword = null;

if (is_post()) {
    if (!$https && !$debug) {
        $errors[] = 'Refusing to run over plain HTTP — the token would travel in the clear. '
                  . 'Use the https:// address for this page.';
    } elseif (!hash_equals($token, (string) ($_POST['token'] ?? ''))) {
        // Not logged with the attempted value: writing a guessed secret into the
        // audit table just moves the secret somewhere else.
        app_log('SETUP token mismatch from ' . substr(client_ip_hash(), 0, 12));
        $errors[] = 'That token does not match the one in the configuration file.';
        sleep(1);
    } else {
        $missing = install_missing_tables();

        $applied = install_apply_schema();
        $done[]  = $missing
            ? 'Created ' . count($missing) . ' missing table(s): ' . implode(', ', $missing) . '.'
            : 'All tables were already present — nothing to create.';

        $seeded = install_seed_tenants();
        $done[] = $seeded
            ? 'Added ' . $seeded . ' company row(s) to the tenants table.'
            : 'All four companies were already in the tenants table.';

        $done[] = 'This installation serves "' . e((string) app_config('tenant'))
                . '" (tenant id ' . tenant_id() . ').';

        // The first administrator, only if there is not one already.
        $email = strtolower(trim((string) ($_POST['admin_email'] ?? '')));
        $name  = trim((string) ($_POST['admin_name'] ?? ''));

        if (install_admin_count() > 0) {
            $done[] = 'An administrator already exists, so none was created. '
                    . 'Use the sign-in page, or reset a password from the command line.';
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
            $errors[] = 'The tables are ready, but no administrator was created — '
                      . 'a valid email address and a full name are needed for that.';
        } else {
            $parts = preg_split('/\s+/', $name, 2);
            $newPassword = install_readable_password();
            $id = db_insert('users', [
                'tenant_id'     => tenant_id(),
                'email'         => $email,
                'password_hash' => auth_hash($newPassword),
                'first_name'    => $parts[0],
                'last_name'     => $parts[1] ?? '',
                'employee_no'   => null,
                'department'    => null,
                'role'          => 'admin',
                'status'        => 'active',
                'created_at'    => now(),
                'last_login_at' => null,
            ]);
            audit('user.created', 'users', $id, 'first administrator, via setup.php');
            $done[] = 'Created the first administrator: ' . e($email) . '.';
        }

        audit('setup.ran', null, null, count($done) . ' step(s), ' . $applied . ' statements');
    }
}

$ready = install_missing_tables() === [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Set up the academy database</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="styles.css?v=20260910">
</head>
<body>
<section class="section-soft page-top">
  <div class="wrap">
    <div class="auth-card" style="max-width:560px">
      <span class="eyebrow">Installation</span>
      <h2>Set up the academy database</h2>

      <?php if ($done): ?>
        <p class="adm-notice" role="status" style="margin-bottom:8px">Done.</p>
        <ul class="setup-list">
          <?php foreach ($done as $d): ?><li><?= $d ?></li><?php endforeach; ?>
        </ul>

        <?php if ($newPassword !== null): ?>
          <div class="setup-secret">
            <strong>Administrator password</strong>
            <code><?= e($newPassword) ?></code>
            <span>Shown once and not recoverable. Write it down now, then sign in at
              <a href="login">the sign-in page</a> and keep it somewhere private.</span>
          </div>
        <?php endif; ?>

        <div class="legal-draft" style="border-color:var(--err);background:#fdecea">
          <strong>Now remove the token.</strong> Open
          <code>~/private/sps-config.php</code> over SFTP and set
          <code>'setup_token' =&gt; ''</code>. This page stays reachable until you do,
          and it will start returning a plain 404 once you have. Nothing else needs
          changing.
        </div>
      <?php endif; ?>

      <?php foreach ($errors as $err): ?>
        <p class="form-err" role="alert"><?= e($err) ?></p>
      <?php endforeach; ?>

      <?php if (!$done): ?>
        <p class="auth-lede">
          This creates the tables and the four company rows, and — the first time it is
          run — one administrator account. It only ever creates: nothing here deletes or
          overwrites anything, so running it twice is safe.
        </p>

        <?php if (!$https && !$debug): ?>
          <p class="form-err">This page is being served over plain HTTP. Load it over
            <strong>https://</strong> before entering the token.</p>
        <?php endif; ?>

        <p class="setup-state">
          Database: <strong><?= e(db_driver()) ?></strong> ·
          Tables: <strong><?= $ready ? 'all present' : implode(', ', install_missing_tables()) . ' missing' ?></strong>
        </p>

        <form class="form" method="POST" autocomplete="off">
          <div class="field">
            <label for="s-token">Setup token</label>
            <input id="s-token" type="password" name="token" required autofocus
                   placeholder="the value of setup_token in sps-config.php">
          </div>
          <div class="field">
            <label for="s-name">First administrator — full name</label>
            <input id="s-name" type="text" name="admin_name" placeholder="Kgomotso Moloantoa">
          </div>
          <div class="field">
            <label for="s-email">First administrator — email</label>
            <input id="s-email" type="email" name="admin_email" placeholder="kgomotso@centenarynetworks.com">
          </div>
          <button type="submit" class="btn btn-primary" style="width:100%">Run setup</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>
</body></html>
