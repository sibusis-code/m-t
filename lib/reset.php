<?php
declare(strict_types=1);

/* Self-service password reset.
 *
 * WHAT A RESET FLOW IS ACTUALLY FOR, AND WHAT IT IS
 *
 * It is a way to sign in without knowing the password. That is the whole of it,
 * and it is why this file is written more carefully than its size suggests:
 * every weakness here is a way into somebody's account that does not need their
 * password at all.
 *
 * Five properties, each of which has a line of code behind it:
 *
 *   1. THE REQUEST FORM MUST NOT SAY WHETHER AN ADDRESS EXISTS. "We've sent you
 *      a link" and "no such account" are the same sentence here, or the form
 *      becomes a way to enumerate everyone the company employs — which is
 *      exactly the property lib/auth.php goes to some trouble to protect on the
 *      sign-in form, and it would be undone by this page next door.
 *
 *   2. THE TOKEN IS NEVER STORED. The database holds its SHA-256 hash. A stolen
 *      read of password_resets is then a table of useless strings rather than a
 *      working sign-in for every account in it.
 *
 *   3. SINGLE USE, AND SHORT. Used once, then dead; an hour, then dead. A reset
 *      link sits in a mailbox for years otherwise, and mailboxes get breached
 *      long after the fact.
 *
 *   4. USING ONE INVALIDATES THE OTHERS. Somebody who clicks "forgot password"
 *      three times because nothing arrived should not leave three live keys
 *      lying in their inbox.
 *
 *   5. RATE LIMITED, per address and per source. Not because the token is
 *      guessable — it is 256 bits — but because without it this endpoint is a
 *      way to have our server send unlimited mail to any address somebody
 *      names, which is a spam cannon with our domain on it.
 *
 * THE HONEST LIMITATION, STATED HERE RATHER THAN DISCOVERED LATER
 *
 * This depends on email arriving, and email from this server is not yet
 * reliable: centenarynetworks.com publishes an SPF record authorising Google,
 * not Xneelo, so mail from here is likely to be filed as spam. Until that DNS
 * record exists, the dependable route is an administrator setting a password
 * from /admin-users, and the pages say so rather than leaving somebody
 * refreshing an inbox. See lib/mail.php.
 */

defined('APP_BOOTED') or exit('lib/reset.php is not a page.');

/* An hour. Long enough to walk to a machine that has your email on it, short
   enough that a link found later is dead. */
const RESET_TTL_SECONDS = 3600;

/* Per source and per address, inside the same fifteen-minute window the
   sign-in lockout uses. Three is generous for a person and stingy for a script. */
const RESET_MAX_PER_EMAIL = 3;
const RESET_MAX_PER_IP    = 6;
const RESET_WINDOW_SECONDS = 900;

/**
 * The sentence shown for every outcome of a request: address known, address
 * unknown, account disabled, mail server refused it. One string, used once, so
 * it cannot drift into four subtly different sentences that give the game away.
 */
function reset_generic_message(): string
{
    return 'If that address has an academy account, a link to set a new password is on its way. '
         . 'It is valid for one hour, and it only works once.';
}

function reset_recent(string $column, string $value): int
{
    $since = gmdate('Y-m-d H:i:s', time() - RESET_WINDOW_SECONDS);
    return (int) db_value(
        'SELECT COUNT(*) FROM audit_log
          WHERE tenant_id = ? AND action = ? AND ' . ($column === 'ip' ? 'ip_hash' : 'detail') . ' = ?
            AND created_at > ?',
        [tenant_id(), 'password.reset_requested', $value, $since]
    );
}

/**
 * Ask for a link.
 *
 * Returns nothing a caller could use to tell the cases apart, on purpose — the
 * page prints reset_generic_message() whatever happened here. Failures that
 * matter to us go to the audit log and the file log, where the visitor cannot
 * see them.
 */
function reset_request(string $email): void
{
    $email = mb_strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;

    $ip = client_ip_hash();

    if (reset_recent('ip', $ip) >= RESET_MAX_PER_IP
        || reset_recent('email', $email) >= RESET_MAX_PER_EMAIL) {
        audit('password.reset_throttled', 'users', null, $email);
        return;
    }

    /* Logged BEFORE the account is looked up, so the rate limit counts attempts
       against addresses that do not exist as well. Counting only real accounts
       would leave the enumeration path — the one this whole file protects —
       completely unlimited. */
    audit('password.reset_requested', 'users', null, $email);

    $user = db_one('SELECT * FROM users WHERE tenant_id = ? AND email = ?', [tenant_id(), $email]);
    if ($user === null || $user['status'] !== 'active') return;

    $token = bin2hex(random_bytes(32));

    /* Any link already outstanding is retired as this one is issued. Asking
       again should replace the previous answer, not add to it.

       Both statements go through db_optional: password_resets arrives with a
       release, and on Xneelo the migration is a separate manual step afterwards
       — see the note in lib/db.php. If the table is not there yet, no link is
       issued and the page still shows the same sentence it shows everyone,
       which already points at the academy as the fallback. */
    $issued = db_optional(function () use ($user, $token, $ip) {
        db_run('UPDATE password_resets SET used_at = ?
                 WHERE tenant_id = ? AND user_id = ? AND used_at IS NULL',
               [now(), tenant_id(), (int) $user['id']]);

        db_insert('password_resets', [
            'tenant_id'  => tenant_id(),
            'user_id'    => (int) $user['id'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + RESET_TTL_SECONDS),
            'used_at'    => null,
            'ip_hash'    => $ip,
            'created_at' => now(),
        ]);
        return true;
    }, false);

    if (!$issued) {
        audit('password.reset_unavailable', 'users', (int) $user['id']);
        return;
    }

    $sent = reset_send_link($user, $token);
    audit($sent ? 'password.reset_sent' : 'password.reset_send_failed',
          'users', (int) $user['id']);
}

/** The absolute URL of the reset link, built from where this request arrived. */
function reset_link_url(string $token): string
{
    $scheme = (($_SERVER['HTTPS'] ?? '') === 'on'
               || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
    return $scheme . '://' . $host . app_base_path() . 'reset?t=' . $token;
}

function reset_send_link(array $user, string $token): bool
{
    $url  = reset_link_url($token);
    $name = trim((string) $user['first_name']) ?: 'there';

    /* Plain text, and short. A reset email that looks like marketing is a reset
       email that gets filtered, and this one is already fighting an SPF record
       that does not name our server. */
    $body = "Hello " . $name . ",\n\n"
          . "Someone asked to set a new password for your " . tenant_name() . " account.\n\n"
          . "Open this link within the next hour:\n\n"
          . $url . "\n\n"
          . "It only works once. If it has expired, ask for another from the sign-in page.\n\n"
          . "If this was not you, you can ignore this message — your password has not been\n"
          . "changed, and nobody can change it without this link. If it keeps happening,\n"
          . "tell the academy.\n\n"
          . "— " . tenant_name() . "\n";

    return mail_send((string) $user['email'], 'Set a new password for your ' . tenant_name() . ' account', $body);
}

/**
 * Look up a token from a link.
 *
 * Returns the row joined to its user, or null for every kind of no: never
 * issued, already used, expired, or belonging to an account that has since been
 * disabled. The page says the same thing for all of them.
 */
function reset_lookup(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;

    $row = db_optional(fn() => db_one(
        'SELECT r.id, r.user_id, r.expires_at, r.used_at
           FROM password_resets r
          WHERE r.tenant_id = ? AND r.token_hash = ?',
        [tenant_id(), hash('sha256', $token)]
    ));
    if ($row === null || $row['used_at'] !== null) return null;
    if (strtotime((string) $row['expires_at']) < time()) return null;

    $user = db_one('SELECT * FROM users WHERE id = ? AND tenant_id = ?',
                   [(int) $row['user_id'], tenant_id()]);
    if ($user === null || $user['status'] !== 'active') return null;

    return ['reset' => $row, 'user' => $user];
}

/**
 * Set the new password and spend the token.
 *
 * @return array{0: bool, 1: string} [ok, message]
 */
function reset_complete(array $found, string $new, string $confirm): array
{
    if (mb_strlen($new) < PASSWORD_MIN_LENGTH) {
        return [false, 'Your new password needs to be at least ' . PASSWORD_MIN_LENGTH
                     . ' characters. Longer is what makes it strong — a few ordinary '
                     . 'words together beats a short one with symbols in it.'];
    }
    if ($new !== $confirm) {
        return [false, 'The two passwords do not match.'];
    }

    $userId = (int) $found['user']['id'];

    /* The token is spent inside the same transaction that changes the password,
       so there is no instant in which the password is new and the link is still
       live. Every other outstanding link for this account goes with it. */
    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_run('UPDATE password_resets SET used_at = ? WHERE id = ? AND tenant_id = ?',
               [now(), (int) $found['reset']['id'], tenant_id()]);
        db_run('UPDATE password_resets SET used_at = ?
                 WHERE tenant_id = ? AND user_id = ? AND used_at IS NULL',
               [now(), tenant_id(), $userId]);
        db_run('UPDATE users SET password_hash = ? WHERE id = ? AND tenant_id = ?',
               [auth_hash($new), $userId, tenant_id()]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        app_log('RESET FAILED for user ' . $userId . ': ' . $e->getMessage());
        return [false, 'That could not be saved. Nothing was changed — please try the link again.'];
    }

    audit('password.reset_completed', 'users', $userId);
    return [true, 'Your password has been set.'];
}
