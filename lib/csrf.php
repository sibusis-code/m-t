<?php
declare(strict_types=1);

/* Cross-site request forgery tokens.
 *
 * Worth being clear about what this is and is not doing on the registration
 * form, because it is easy to add for the wrong reason. CSRF protects an
 * AUTHENTICATED action from being triggered by another site riding the
 * visitor's session. A public "register your interest" form has no session to
 * ride, so the token is not what stops abuse there — the honeypot and the rate
 * limit are.
 *
 * It is here because the token is what makes the form single-use, which stops a
 * double-submitted page creating two identical registrations, and because every
 * page added after login lands — the dashboard, progress, evidence upload — will
 * need it for the real reason. Building it once, now, beats retrofitting it.
 *
 * The session cookie this requires is strictly necessary for the security of a
 * function the visitor asked for. It carries no identifier and does no
 * tracking, which is why it does not need consent of its own.
 */

defined('APP_BOOTED') or exit('lib/csrf.php is not a page.');

function csrf_token(): string
{
    app_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    app_session_start();
    $sent = $_POST['_token'] ?? '';
    $held = $_SESSION['csrf'] ?? '';
    // hash_equals, not ===, so the comparison does not leak the token's prefix
    // through how long it takes to fail.
    return is_string($sent) && $held !== '' && hash_equals($held, $sent);
}

/** Burn the token after a successful write so a refresh cannot replay it. */
function csrf_rotate(): void
{
    app_session_start();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
