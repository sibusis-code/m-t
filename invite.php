<?php
declare(strict_types=1);

/* "An account was made for you — set your password."
 *
 * The other half of lib/invite.php, and structurally reset.php's twin: the
 * token in the link is the entire proof of identity, so it is checked on the
 * way in AND again on the way out, it travels in a hidden field on POST rather
 * than the query string, and every failure — never issued, already used, an
 * hour... a week old, the account since disabled — gives the same answer,
 * because which of those it was is our business and not a visitor's.
 *
 * What is different from reset.php, and why this is not that file with a flag:
 * see the note at the top of lib/invite.php. In short, this page is not
 * recovering a forgotten password — there never was one the learner knew —
 * it is the first proof that the person who opened the email is the person
 * the account was made for.
 */

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/mail.php';
require __DIR__ . '/lib/csrf.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/invite.php';
require __DIR__ . '/lib/chrome.php';
/* Before a single byte is printed — see the identical note in reset.php. */
app_session_start();


$token = is_post() ? (string) ($_POST['t'] ?? '') : (string) ($_GET['t'] ?? '');
$found = invite_lookup($token);
$error = '';

if ($found !== null && is_post()) {
    if (!csrf_valid()) {
        $error = 'This page had been open a while and the form expired. '
               . 'Your link still works — please fill it in again.';
    } else {
        [$ok, $message] = invite_complete(
            $found,
            (string) ($_POST['new'] ?? ''),
            (string) ($_POST['confirm'] ?? '')
        );

        if ($ok) {
            csrf_rotate();

            /* Signed straight in, same reasoning as reset.php: they have just
               proved control of the mailbox the account is named after and
               chosen their own password, so a sign-in form asking for it again
               would be ceremony. Re-read the row first — auth_sign_in() stamps
               the session from the CURRENT hash, and $found's copy predates
               the change. */
            $fresh = db_one('SELECT * FROM users WHERE id = ? AND tenant_id = ?',
                            [(int) $found['user']['id'], tenant_id()]);
            if ($fresh !== null) {
                auth_sign_in($fresh);
                redirect('my');
            }
            redirect('login');
        }
        $error = $message;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Set your password — <?= e(brand('academy')) ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= e(asset('styles.css')) ?>">
</head>
<body>
<?php chrome_nav('auth', ['tail' => 'signin']); ?>

<section class="section-soft page-top">
  <div class="wrap">
    <div class="auth-card">
      <span class="eyebrow"><?= e(brand('academy')) ?></span>

      <?php if ($found === null): ?>
        <h2>This link no longer works</h2>
        <p class="auth-lede">Invite links last seven days and can only be used once. If you
          have already set a password with it, sign in with the one you chose. Otherwise ask
          the academy for a new invite, or for a password to be set by hand.</p>
        <p style="margin-top:20px">
          <a class="btn btn-primary" href="login">Sign in</a>
          <a class="btn btn-ghost" href="contact">Contact the academy</a>
        </p>

      <?php else: ?>
        <h2>Welcome to the <?= e(brand('academy')) ?></h2>
        <p class="auth-lede">An account has been made for <strong><?= e((string) $found['user']['email']) ?></strong>.
          Choose a password and you will be signed straight in.</p>

        <form class="form" method="POST" novalidate autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="t" value="<?= e($token) ?>">
          <input type="hidden" name="username" value="<?= e((string) $found['user']['email']) ?>"
                 autocomplete="username" hidden>

          <?php if ($error !== ''): ?>
            <p class="form-err" role="alert"><?= e($error) ?></p>
          <?php endif; ?>

          <div class="field">
            <label for="i-new">Choose a password</label>
            <input id="i-new" type="password" name="new" autocomplete="new-password"
                   minlength="<?= PASSWORD_MIN_LENGTH ?>" required autofocus>
            <p class="field-hint">At least <?= PASSWORD_MIN_LENGTH ?> characters. Three or four
              ordinary words in a row is both easier to remember and harder to guess than
              something short with symbols in it.</p>
          </div>
          <div class="field">
            <label for="i-conf">Password again</label>
            <input id="i-conf" type="password" name="confirm" autocomplete="new-password" required>
          </div>
          <button type="submit" class="btn btn-primary" style="width:100%">Save it and sign me in</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php chrome_footer('slim'); ?>
<script src="<?= e(asset('site.js')) ?>"></script>
</body></html>
