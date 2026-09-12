<?php
declare(strict_types=1);

/* Signing in.
 *
 * Two properties this file is built around, both easy to lose by accident:
 *
 * 1. It must not tell an anonymous visitor whether an email address exists.
 *    "No such user" and "wrong password" are the same message, and an unknown
 *    address still gets a password check run against a dummy hash so that the
 *    two paths take a similar amount of time. Otherwise the login form becomes
 *    a way to enumerate everyone the company employs.
 *
 * 2. It must survive a password list being run at it. Attempts are counted per
 *    source and per address, from the audit table, and both have to be clear
 *    before a check is even attempted.
 *
 * There is no password-reset flow yet. Accounts are created by
 * tools/make-user.php from the command line, which is honest for the number of
 * people involved today and is noted as a gap rather than pretended away.
 */

defined('APP_BOOTED') or exit('lib/auth.php is not a page.');

const LOGIN_MAX_ATTEMPTS = 6;      // per source, and per address
const LOGIN_WINDOW_SECONDS = 900;  // 15 minutes

/**
 * A hash of a password nobody has, used to spend roughly the same time on an
 * unknown address as on a known one. Generated once at the cost of one hash.
 */
function auth_dummy_hash(): string
{
    static $h = null;
    return $h ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
}

function auth_hash(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

/** How many failed attempts match this column value inside the window. */
function auth_recent_failures(string $column, string $value): int
{
    $since = gmdate('Y-m-d H:i:s', time() - LOGIN_WINDOW_SECONDS);
    return (int) db_value(
        'SELECT COUNT(*) FROM audit_log
          WHERE tenant_id = ? AND action = ? AND ' . ($column === 'ip' ? 'ip_hash' : 'detail') . ' = ?
            AND created_at > ?',
        [tenant_id(), 'login.failed', $value, $since]
    );
}

function auth_locked_out(string $email): bool
{
    return auth_recent_failures('ip', client_ip_hash()) >= LOGIN_MAX_ATTEMPTS
        || auth_recent_failures('email', mb_strtolower($email)) >= LOGIN_MAX_ATTEMPTS;
}

/**
 * Attempt a sign-in.
 *
 * @return array{0: bool, 1: string} [ok, message-for-the-visitor]
 */
function auth_attempt(string $email, string $password): array
{
    $email = mb_strtolower(trim($email));

    /* The same sentence for every failure below. A visitor who mistyped their
       password and a stranger probing for valid addresses learn exactly the
       same thing from it, which is nothing. */
    $generic = 'That email address and password do not match. Please try again.';

    if ($email === '' || $password === '') {
        return [false, $generic];
    }

    if (auth_locked_out($email)) {
        audit('login.throttled', 'users', null, $email);
        return [false, 'Too many attempts. Please wait fifteen minutes and try again, '
                     . 'or email kgomotso@centenarynetworks.com if you are stuck.'];
    }

    $user = db_one(
        'SELECT * FROM users WHERE tenant_id = ? AND email = ?',
        [tenant_id(), $email]
    );

    // Runs either way — see the note on auth_dummy_hash().
    $hash = $user['password_hash'] ?? auth_dummy_hash();
    $ok   = password_verify($password, $hash);

    if (!$user || !$ok) {
        audit('login.failed', 'users', $user['id'] ?? null, $email);
        return [false, $generic];
    }

    if (($user['status'] ?? '') !== 'active') {
        audit('login.disabled', 'users', (int) $user['id'], $email);
        return [false, 'This account is not active. Please email '
                     . 'kgomotso@centenarynetworks.com.'];
    }

    /* If PHP's default algorithm has moved on since this hash was made, take the
       one moment we legitimately have the plain password and bring it forward.

       $user is updated in place as well as in the database, and that is not
       tidiness. auth_sign_in() below stamps the session with a fingerprint of
       this hash, and current_user() ends any session whose stamp no longer
       matches. Leaving the stale hash here would stamp the session with a value
       that was already wrong, and the visitor would be signed out on their very
       next click — a rehash, which nobody asked for and nobody can see, would
       lock people out of a correct password. */
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $hash = auth_hash($password);
        db_run('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, (int) $user['id']]);
        $user['password_hash'] = $hash;
    }

    auth_sign_in($user);
    return [true, ''];
}

/**
 * A short fingerprint of the stored password hash.
 *
 * Kept in the session so that changing a password can end every OTHER session
 * on every other device, which is what a person means when they change it
 * because something felt wrong. Comparing the fingerprint is enough: the hash
 * changes on a reset, on a self-service change, and on an administrator setting
 * a new one, and those are exactly the three moments other sessions should die.
 *
 * A fingerprint rather than the hash itself, so the session store never holds
 * anything that could be attacked offline. Truncated because the only job is to
 * differ when the hash differs.
 */
function auth_password_stamp(string $hash): string
{
    return substr(hash('sha256', $hash), 0, 16);
}

function auth_sign_in(array $user): void
{
    app_session_start();
    // A brand new session id, so a session fixed before sign-in is worthless.
    session_regenerate_id(true);

    $_SESSION['uid']  = (int) $user['id'];
    $_SESSION['role'] = (string) $user['role'];
    $_SESSION['pwv']  = auth_password_stamp((string) $user['password_hash']);
    current_user(true);   // the cached "nobody" from a moment ago is now wrong

    db_run('UPDATE users SET last_login_at = ? WHERE id = ?', [now(), (int) $user['id']]);
    audit('login.ok', 'users', (int) $user['id']);
}

function auth_sign_out(): void
{
    app_session_start();
    $uid = current_user_id();
    if ($uid !== null) audit('logout', 'users', $uid);

    current_user(true);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
                  $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * The signed-in user, looked up once per request.
 *
 * $forget exists because the answer changes mid-request exactly twice — at
 * sign-in and at sign-out — and the cached "nobody is signed in" from before a
 * successful sign-in is worse than no cache at all. It sent the first
 * administrator who logged in to the public homepage instead of the
 * registrations list, because the role check ran against a stale null.
 */
function current_user(bool $forget = false): ?array
{
    static $user = null;
    static $looked = false;

    if ($forget) { $user = null; $looked = false; return null; }
    if ($looked) return $user;
    $looked = true;

    app_session_start();
    $id = current_user_id();
    if ($id === null) return null;

    $user = db_one('SELECT * FROM users WHERE id = ? AND tenant_id = ?', [$id, tenant_id()]);

    /* Belt and braces: a session can outlive the account it names. If the user
       was deleted, disabled, or belongs to another tenant, the session is not
       merely ignored — it is ended. */
    if (!$user || $user['status'] !== 'active') {
        auth_sign_out();
        return $user = null;
    }

    /* And it can outlive the password it was issued against. This is what makes
       "change your password" mean something on a device you have lost.

       A session with no stamp is ADOPTED rather than rejected: sessions created
       before this check existed are legitimate, and signing out everybody who
       was already logged in at the moment of a deploy would be a self-inflicted
       incident. That leaves those particular sessions surviving a reset, once,
       which is the honest cost of not doing it. */
    $stamp = auth_password_stamp((string) $user['password_hash']);
    if (!isset($_SESSION['pwv'])) {
        $_SESSION['pwv'] = $stamp;
    } elseif (!hash_equals((string) $_SESSION['pwv'], $stamp)) {
        audit('session.ended_by_password_change', 'users', (int) $user['id']);
        auth_sign_out();
        return $user = null;
    }

    return $user;
}

/* The shortest password this site will accept.
 *
 * Ten, not sixteen, and not a rule about capitals and punctuation. The
 * generated passwords are nineteen characters of readable nonsense, so this
 * floor only ever applies to a password somebody chose for themselves — and the
 * evidence is consistent that composition rules push people towards
 * "Password1!" rather than towards anything stronger. Length plus the
 * fifteen-minute lockout above is what actually holds. */
const PASSWORD_MIN_LENGTH = 10;

/**
 * Change your own password.
 *
 * The current password is required even though the caller is already signed in.
 * That is not belt-and-braces about the session — it is what stops a borrowed
 * unlocked laptop from becoming a permanent takeover of somebody's account.
 *
 * @return array{0: bool, 1: string} [ok, message]
 */
function auth_change_password(array $user, string $current, string $new, string $confirm): array
{
    if (!password_verify($current, (string) $user['password_hash'])) {
        audit('password.change_failed', 'users', (int) $user['id'], 'current password wrong');
        return [false, 'Your current password is not right. Nothing has been changed.'];
    }
    if (mb_strlen($new) < PASSWORD_MIN_LENGTH) {
        return [false, 'Your new password needs to be at least ' . PASSWORD_MIN_LENGTH
                     . ' characters. Longer is what makes it strong — a few ordinary '
                     . 'words together beats a short one with symbols in it.'];
    }
    if ($new !== $confirm) {
        return [false, 'The two new passwords do not match.'];
    }
    if ($new === $current) {
        return [false, 'That is the password you already have.'];
    }

    auth_set_password((int) $user['id'], $new);

    audit('password.changed', 'users', (int) $user['id']);
    return [true, 'Your password has been changed on every device. '
                . 'Use the new one next time you sign in.'];
}

/**
 * Write a new password and keep THIS session alive while ending the others.
 *
 * The two halves are inseparable, which is why they are one function rather
 * than two lines every caller has to remember: the new hash ends every session
 * carrying the old fingerprint, and the session doing the changing must be
 * re-stamped or it ends itself. Getting that wrong signs the user out at the
 * moment they succeed, which reads as failure.
 *
 * Callers: the change-password box on /my, the reset link, and an
 * administrator setting one from /admin-users.
 */
function auth_set_password(int $userId, string $plain): void
{
    $hash = auth_hash($plain);
    db_run('UPDATE users SET password_hash = ? WHERE id = ? AND tenant_id = ?',
           [$hash, $userId, tenant_id()]);

    app_session_start();
    if (current_user_id() === $userId) {
        // A new session id as well: a password change is one of the two moments
        // one should not survive — see the note in auth_sign_in().
        session_regenerate_id(true);
        $_SESSION['pwv'] = auth_password_stamp($hash);
    }
    current_user(true);   // the cached row still carries the old hash
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

/**
 * Send anyone who is not signed in to the sign-in page.
 *
 * Any role: this is the gate on the learner's own pages, where an administrator
 * is simply a signed-in person who also happens to have the admin pages.
 */
function require_user(string $fallback = '/my'): array
{
    $u = current_user();
    if ($u === null) {
        redirect('login?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? $fallback));
    }
    return $u;
}


/* ---------------------------------------------------------------------------
   THE THREE ROLES
   ---------------
   'admin'    runs the academy: registrations, accounts, material, the lot.
   'trainer'  teaches on it. May LOOK at the courses they are assigned and at
              the learners on them, and may change nothing at all.
   'learner'  everybody else.

   Added 10 Sep 2026. Before it there were two roles, and the only way to let a
   specialist see how their own learners were doing was to make them an
   administrator — which would also have handed a partner company every
   registration, every learner account on the academy, and the power to promote
   anyone else. That is the gap this closes.

   ANYTHING UNRECOGNISED IS A LEARNER. The column is a VARCHAR, not an enum, so
   a typo is possible; a typo must lose access rather than grant it.

   WRITING IS STILL ADMIN-ONLY. require_admin() has not moved an inch — every
   page that could change something still calls it. Trainers reach the reading
   pages through require_staff() below, and each of those pages asks
   staff_may_write() before it acts on a POST. Hiding a button is not a
   permission; the check has to be on the writing side.
   --------------------------------------------------------------------------- */

/** True for a trainer account. Trainers may look; they may not touch. */
function is_trainer(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'trainer';
}

/** Admin or trainer — anyone with a reason to see the academy's own pages. */
function is_staff(): bool
{
    $u = current_user();
    return $u !== null && ($u['role'] === 'admin' || $u['role'] === 'trainer');
}

/**
 * May the signed-in person change anything on a staff page?
 *
 * Only administrators. Every staff page that handles a POST calls this before
 * it writes, so a trainer who forges a form gets the same 403 as a trainer who
 * never saw the button.
 */
function staff_may_write(): bool
{
    return is_admin();
}

/**
 * Gate for pages a trainer may READ: material, reading, quizzes, their learners.
 *
 * Refuses a learner exactly as require_admin() refuses one, and audits it the
 * same way, so a denied attempt looks the same in the log whichever gate caught
 * it.
 */
function require_staff(): array
{
    $u = current_user();
    if ($u === null) {
        redirect('login?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/admin'));
    }
    if ($u['role'] !== 'admin' && $u['role'] !== 'trainer') {
        audit('access.denied', 'page', null, (string) ($_SERVER['REQUEST_URI'] ?? ''));
        http_response_code(403);
        exit('You do not have access to this page.');
    }
    return $u;
}

/**
 * Refuse a write attempted by someone who may only read.
 *
 * Call it at the top of the POST branch of a staff page. Separate from
 * require_staff() because the two answer different questions and a page needs
 * both: may you be here, and may you do this.
 */
function require_write(): void
{
    if (staff_may_write()) return;
    audit('write.denied', 'page', null, (string) ($_SERVER['REQUEST_URI'] ?? ''));
    http_response_code(403);
    exit('Your account can read this page but not change it.');
}

/**
 * The course slugs a trainer is assigned to, or null for an administrator,
 * meaning "no restriction".
 *
 * FAILS CLOSED, deliberately, and in two directions:
 *
 *   - A trainer with no rows gets an empty list, and an empty list must be read
 *     by callers as "nothing", never as "no filter". That is why an admin gets
 *     null and a trainer gets an array: the two cases cannot be confused by a
 *     caller that forgets to check the role.
 *   - If trainer_courses does not exist yet — the deploy lands before the
 *     migration is run, every time — db_optional() returns the fallback, and
 *     the fallback here is an empty list. A trainer then sees none of their
 *     courses until setup.php has been run, which is the safe way round.
 */
function trainer_slugs(?array $u = null): ?array
{
    $u = $u ?? current_user();
    if ($u === null) return [];
    if ($u['role'] === 'admin') return null;          // no restriction
    if ($u['role'] !== 'trainer') return [];          // learners hold nothing

    $rows = db_optional(static fn() => db_all(
        'SELECT course_slug FROM trainer_courses WHERE tenant_id = ? AND user_id = ?
         ORDER BY course_slug',
        [tenant_id(), (int) $u['id']]
    ), []);

    return array_values(array_map(static fn($r) => (string) $r['course_slug'], $rows ?: []));
}

/** True if this person may see this course at all. Admins may see every course. */
function may_see_course(string $slug, ?array $u = null): bool
{
    $slugs = trainer_slugs($u);
    return $slugs === null || in_array($slug, $slugs, true);
}

/** Send anyone who is not an administrator to the sign-in page. */
function require_admin(): array
{
    $u = current_user();
    if ($u === null) {
        redirect('login?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/admin'));
    }
    if ($u['role'] !== 'admin') {
        audit('access.denied', 'page', null, (string) ($_SERVER['REQUEST_URI'] ?? ''));
        http_response_code(403);
        exit('You do not have access to this page.');
    }
    return $u;
}
