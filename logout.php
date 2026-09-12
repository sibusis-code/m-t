<?php
declare(strict_types=1);

/* Signing out.
 *
 * POST only, and CSRF-checked. A sign-out on GET can be triggered by any image
 * tag on any page anywhere — <img src="https://sps.../logout"> — which is not a
 * security hole so much as a way to make the site look broken to someone who
 * cannot work out why they keep getting signed out.
 */

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/csrf.php';
require __DIR__ . '/lib/auth.php';

if (is_post() && csrf_valid()) {
    auth_sign_out();
}

redirect('login');
