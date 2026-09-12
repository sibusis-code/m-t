<?php
declare(strict_types=1);

/* Create an account.
 *
 *     php tools/make-user.php kgomotso@centenarynetworks.com "Kgomotso Moloantoa" admin
 *     php tools/make-user.php someone@sps.co.za "Someone Else"
 *
 * The password is GENERATED and printed once, rather than taken as an argument.
 * An argument would sit in the shell history and in the process list, where
 * anyone with an account on the machine could read it. Copy it, give it to the
 * person over something private, and have them change it — which is the next
 * thing to build, and is noted as missing in lib/auth.php.
 *
 * Re-running for an existing address resets that person's password rather than
 * failing, because "I have lost my password" is the common case and there is no
 * self-service reset yet.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/audit.php';
require __DIR__ . '/../lib/auth.php';

$email = strtolower(trim((string) ($argv[1] ?? '')));
$name  = trim((string) ($argv[2] ?? ''));
$role  = strtolower(trim((string) ($argv[3] ?? 'learner')));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
    fwrite(STDERR, "usage: php tools/make-user.php <email> \"<full name>\" [learner|admin]\n");
    exit(1);
}
if (!in_array($role, ['learner', 'admin'], true)) {
    fwrite(STDERR, "role must be 'learner' or 'admin'\n");
    exit(1);
}

/* Readable rather than maximal: this gets read off a screen and typed, and a
   password people cannot transcribe gets written on paper instead. Four blocks
   from a 32-character alphabet is ~80 bits, which is far past anything the
   fifteen-minute lockout in lib/auth.php would let through. */
function readable_password(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';   // no l/1, no o/0
    $out = [];
    for ($block = 0; $block < 4; $block++) {
        $s = '';
        for ($i = 0; $i < 4; $i++) $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $out[] = $s;
    }
    return implode('-', $out);
}

$parts = preg_split('/\s+/', $name, 2);
$first = $parts[0];
$last  = $parts[1] ?? '';

$password = readable_password();
$existing = db_one('SELECT id FROM users WHERE tenant_id = ? AND email = ?', [tenant_id(), $email]);

if ($existing) {
    db_run('UPDATE users SET password_hash = ?, first_name = ?, last_name = ?, role = ?, status = ? WHERE id = ?',
           [auth_hash($password), $first, $last, $role, 'active', (int) $existing['id']]);
    $id = (int) $existing['id'];
    audit('user.password_reset', 'users', $id, 'by tools/make-user.php');
    $what = 'password reset for existing account';
} else {
    $id = db_insert('users', [
        'tenant_id'     => tenant_id(),
        'email'         => $email,
        'password_hash' => auth_hash($password),
        'first_name'    => $first,
        'last_name'     => $last,
        'employee_no'   => null,
        'department'    => null,
        'role'          => $role,
        'status'        => 'active',
        'created_at'    => now(),
        'last_login_at' => null,
    ]);
    audit('user.created', 'users', $id, 'role: ' . $role . ', by tools/make-user.php');
    $what = 'account created';
}

echo "\n  $what\n";
echo "  tenant    " . app_config('tenant') . "\n";
echo "  email     $email\n";
echo "  name      $name\n";
echo "  role      $role\n";
echo "  password  $password\n";
echo "\n  This password is shown once and is not recoverable. Send it to the person\n";
echo "  over something private — not the same email address it signs in with.\n\n";
