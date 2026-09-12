<?php
declare(strict_types=1);

/* Setting a new password from a link.
 *
 * The token in the link is the entire proof of identity, so this page checks it
 * on the way in AND again on the way out. Checking only on GET would let a form
 * be submitted long after the link expired, or against a token that was spent
 * by another tab in between.
 *
 * The token travels in a hidden field on POST rather than in the query string,
 * so a mistyped password does not re-post it into the address bar and into the
 * browser history of whichever machine the learner happens to be on.
 *
 * Every failure gives the same answer: the link no longer works. Whether it was
 * never real, already used, an hour old, or belongs to an account that has since
 * been switched off is our business, not a visitor's.
 */

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/mail.php';
require __DIR__ . '/lib/csrf.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/reset.php';
require __DIR__ . '/lib/chrome.php';
/* Before a single byte is printed. The session cookie is a header, so a page
   that prints first and starts its session later gets no session at all — and
   the CSRF token in the form below it is then unbacked, so the form can never
   be submitted. See the note in app_session_start(). */
app_session_start();


$token = is_post() ? (string) ($_POST['t'] ?? '') : (string) ($_GET['t'] ?? '');
$found = reset_lookup($token);
$error = '';

if ($found !== null && is_post()) {
    if (!csrf_valid()) {
        $error = 'This page had been open a while and the form expired. '
               . 'Your link still works — please fill it in again.';
    } else {
        [$ok, $message] = reset_complete(
            $found,
            (string) ($_POST['new'] ?? ''),
            (string) ($_POST['confirm'] ?? '')
        );

        if ($ok) {
            csrf_rotate();

            /* Signed straight in. They have just proved control of the mailbox
               the account is named after and chosen the password themselves, so
               sending them to a sign-in form to type it again is ceremony.

               Re-read the row first: auth_sign_in() stamps the session with a
               fingerprint of the password hash, and the copy in $found is the
               one from before it changed. */
            $fresh = db_one('SELECT * FROM users WHERE id = ? AND tenant_id = ?',
                            [(int) $found['user']['id'], tenant_id()]);
            if ($fresh !== null) {
                auth_sign_in($fresh);
                redirect($fresh['role'] === 'admin' ? 'admin' : 'my');
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
<title>Set a new password — <?= e(brand('academy')) ?></title>
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
        <p class="auth-lede">Reset links last an hour and can only be used once. If you have
          already set a password with it, sign in with the new one. Otherwise ask for a
          fresh link.</p>
        <p style="margin-top:20px">
          <a class="btn btn-primary" href="forgot">Send me a new link</a>
          <a class="btn btn-ghost" href="login">Sign in</a>
        </p>

      <?php else: ?>
        <h2>Set a new password</h2>
        <p class="auth-lede">For <strong><?= e((string) $found['user']['email']) ?></strong>.
          Once you save it you will be signed in, and you will be signed out anywhere else
          the old password was still in use.</p>

        <form class="form" method="POST" novalidate autocomplete="off">
          <?= csrf_field() ?>
          <input type="hidden" name="t" value="<?= e($token) ?>">
          <input type="hidden" name="username" value="<?= e((string) $found['user']['email']) ?>"
                 autocomplete="username" hidden>

          <?php if ($error !== ''): ?>
            <p class="form-err" role="alert"><?= e($error) ?></p>
          <?php endif; ?>

          <div class="field">
            <label for="r-new">New password</label>
            <input id="r-new" type="password" name="new" autocomplete="new-password"
                   minlength="<?= PASSWORD_MIN_LENGTH ?>" required autofocus>
            <p class="field-hint">At least <?= PASSWORD_MIN_LENGTH ?> characters. Three or four
              ordinary words in a row is both easier to remember and harder to guess than
              something short with symbols in it.</p>
          </div>
          <div class="field">
            <label for="r-conf">New password again</label>
            <input id="r-conf" type="password" name="confirm" autocomplete="new-password" required>
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
