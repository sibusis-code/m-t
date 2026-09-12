<?php
declare(strict_types=1);

/* "An account was created for you — set your password."
 *
 * The honest upgrade the note in lib/learner.php promised: instead of an
 * administrator reading a generated password off the screen and handing it
 * over in person, "Enrol" can email a one-time link that lets the new learner
 * choose their own password. Enrolling is still entirely an administrator's
 * action — there is no public sign-up form, and nobody can get a row in
 * account_invites without an admin pressing the button on a specific
 * registration. Only the LAST step, getting the credential to the right
 * person, moves from "said out loud" to "emailed".
 *
 * WHY THIS IS NOT lib/reset.php WITH A FLAG
 *
 * Mechanically the two are the same shape — a hashed single-use token, an
 * expiry, a form that sets a password and signs you in. They are kept apart
 * anyway, and the reasons are worth stating rather than assumed:
 *
 *   1. A reset link says "someone asked to change your password", which is
 *      false and faintly alarming for an account that never had one. An
 *      invite link should say what actually happened: an account was made
 *      for you.
 *   2. reset_request() is reached by an anonymous visitor and is built around
 *      not confirming whether an address has an account — that is the whole
 *      point of lib/reset.php's opening comment. An invite is issued by a
 *      signed-in administrator who is looking straight at the registration;
 *      there is nothing to avoid confirming.
 *   3. lib/reset.php earns its safety from being small enough to read as one
 *      piece and reason about in full. Threading a second purpose through it
 *      with a branch is how a security-critical file stops being that.
 *
 * THE SAME HONEST LIMITATION AS lib/reset.php, repeated because it is easy to
 * forget when reading this file on its own: mail from this server is not yet
 * reliable — see lib/mail.php — so admin.php keeps "show the password here"
 * as the button beside this one, not a fallback bolted on afterwards.
 */

defined('APP_BOOTED') or exit('lib/invite.php is not a page.');

/* Seven days, not the reset link's one hour. A reset is acted on within
 * minutes because the person is locked out right now; an invite waits in an
 * inbox until whoever runs onboarding gets to it, which is not necessarily
 * the day it was sent. Still short enough that a link nobody used stops being
 * a live credential on its own. */
const INVITE_TTL_SECONDS = 604800;

/**
 * Create an invite for a freshly created account and try to email it.
 *
 * Called once, immediately after the account row is committed — see
 * learner_enrol_registration() in lib/learner.php. Any invite already
 * outstanding for this user is retired first, the same "asking again replaces
 * the previous answer" rule reset_request() uses, in case the button is
 * pressed twice.
 *
 * @return bool whether a link was both issued and sent. False covers two very
 *              different failures — the table is not migrated yet, or mail_send()
 *              could not deliver it — and admin.php treats both the same way:
 *              tell the administrator to use "show the password" instead for
 *              this person, right now, rather than trust an email that may
 *              never arrive.
 */
function invite_create_and_send(array $user, int $adminId, string $courseTitle,
                                string $courseSlug = ''): bool
{
    $token = bin2hex(random_bytes(32));

    /* db_optional: account_invites arrives with a release, and on Xneelo the
       migration is a separate manual step afterwards — see the note in
       lib/db.php. If the table is not there yet, no invite is issued and the
       caller falls back to showing the password on screen. */
    $issued = db_optional(function () use ($user, $token, $adminId) {
        db_run('UPDATE account_invites SET used_at = ?
                 WHERE tenant_id = ? AND user_id = ? AND used_at IS NULL',
               [now(), tenant_id(), (int) $user['id']]);

        db_insert('account_invites', [
            'tenant_id'  => tenant_id(),
            'user_id'    => (int) $user['id'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + INVITE_TTL_SECONDS),
            'used_at'    => null,
            'invited_by' => $adminId,
            'created_at' => now(),
        ]);
        return true;
    }, false);

    if (!$issued) {
        audit('learner.invite_unavailable', 'users', (int) $user['id']);
        return false;
    }

    $sent = invite_send($user, $token, $courseTitle, $courseSlug);
    audit($sent ? 'learner.invited' : 'learner.invite_send_failed',
          'users', (int) $user['id'], $courseTitle, $adminId);
    return $sent;
}

/** The absolute URL of the invite link, built the same way reset_link_url() is. */
function invite_link_url(string $token): string
{
    $scheme = (($_SERVER['HTTPS'] ?? '') === 'on'
               || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
    return $scheme . '://' . $host . app_base_path() . 'invite?t=' . $token;
}

/**
 * The invite IS the welcome letter.
 *
 * It used to be a short plain-text note carrying only the link, and the welcome
 * letter did not exist. Now that it does, a newly invited learner must not get
 * both: two emails arriving together, one confirming the enrolment and one
 * offering a password, reads like a system talking to itself, and the learner
 * has to work out which is the real one. So there is a single letter — it
 * confirms what they have been registered for AND carries the link — and
 * lib/letters.php builds it.
 *
 * @param string $courseSlug needed because letters_sent records the welcome
 *                           against the course, so re-enrolling on the same one
 *                           does not post a second copy.
 */
function invite_send(array $user, string $token, string $courseTitle, string $courseSlug = ''): bool
{
    $url = invite_link_url($token);

    /* Pages require the libraries they use; if this one did not load letters,
       fall back to the note this function used to send rather than fatal. The
       learner still gets in, which is the part that matters. */
    if (!function_exists('letter_send_welcome')) {
        app_log('INVITE — lib/letters.php was not loaded, sent the plain note instead');
        $name = trim((string) $user['first_name']) ?: 'there';
        $body = "Hello " . $name . ",\n\n"
              . "An account has been created for you on the " . tenant_name() . ", "
              . "enrolling you on " . $courseTitle . ".\n\n"
              . "Choose your own password here, within the next seven days:\n\n"
              . $url . "\n\n"
              . "It only works once. If it has expired by the time you open it, ask "
              . "the academy for a new one.\n\n"
              . "— " . tenant_name() . "\n";
        return mail_send((string) $user['email'],
            'Your ' . tenant_name() . ' account is ready', $body);
    }

    return letter_send_welcome($user, $courseSlug, $courseTitle, $url);
}

/**
 * Look up a token from an invite link. Same shape and the same reasoning as
 * reset_lookup(): every kind of no — never issued, already used, expired, the
 * account since disabled — collapses to null, and the page says one thing for
 * all of them.
 */
function invite_lookup(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;

    $row = db_optional(fn() => db_one(
        'SELECT i.id, i.user_id, i.expires_at, i.used_at
           FROM account_invites i
          WHERE i.tenant_id = ? AND i.token_hash = ?',
        [tenant_id(), hash('sha256', $token)]
    ));
    if ($row === null || $row['used_at'] !== null) return null;
    if (strtotime((string) $row['expires_at']) < time()) return null;

    $user = db_one('SELECT * FROM users WHERE id = ? AND tenant_id = ?',
                   [(int) $row['user_id'], tenant_id()]);
    if ($user === null || $user['status'] !== 'active') return null;

    return ['invite' => $row, 'user' => $user];
}

/**
 * Set the first password and spend the token. Mirrors reset_complete(): same
 * transaction shape, same "the token dies in the same beat the password
 * changes" property, and any other invite outstanding for this account is
 * retired alongside it.
 *
 * @return array{0: bool, 1: string} [ok, message]
 */
function invite_complete(array $found, string $new, string $confirm): array
{
    if (mb_strlen($new) < PASSWORD_MIN_LENGTH) {
        return [false, 'Your password needs to be at least ' . PASSWORD_MIN_LENGTH
                     . ' characters. Longer is what makes it strong — a few ordinary '
                     . 'words together beats a short one with symbols in it.'];
    }
    if ($new !== $confirm) {
        return [false, 'The two passwords do not match.'];
    }

    $userId = (int) $found['user']['id'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_run('UPDATE account_invites SET used_at = ? WHERE id = ? AND tenant_id = ?',
               [now(), (int) $found['invite']['id'], tenant_id()]);
        db_run('UPDATE account_invites SET used_at = ?
                 WHERE tenant_id = ? AND user_id = ? AND used_at IS NULL',
               [now(), tenant_id(), $userId]);
        db_run('UPDATE users SET password_hash = ? WHERE id = ? AND tenant_id = ?',
               [auth_hash($new), $userId, tenant_id()]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        app_log('INVITE COMPLETE FAILED for user ' . $userId . ': ' . $e->getMessage());
        return [false, 'That could not be saved. Nothing was changed — please try the link again.'];
    }

    audit('learner.invite_accepted', 'users', $userId);
    return [true, 'Your password has been set.'];
}
