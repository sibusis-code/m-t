<?php
/* TEMPLATE ONLY — the real config is not in this repository and must never be.
 *
 * Two separate reasons, and each one on its own is enough:
 *
 *   1. Everything in this repository is deployed into the web root, so a file
 *      here can be requested over HTTP by anyone who guesses the name.
 *   2. The repository itself is version-controlled and shared. A credential
 *      committed once stays in the history even after it is deleted.
 *
 * So the real file lives OUTSIDE the web root. The Xneelo SFTP login lands in
 * the home directory and the web root is a directory below it, which gives us
 * somewhere the web server cannot reach:
 *
 *     ~/private/sps-config.php        <- put it here, chmod 600
 *     ~/public_html/                  <- (or the subdomain's own directory)
 *
 * Copy this file, fill it in, upload it once, and leave it alone. It is not
 * part of a deploy and will not be overwritten by one.
 */

return [

  /* Which company this installation serves. Every query is scoped to this
     tenant, so a mistake here shows one company another company's learners.
     It is deliberately a hand-set string rather than something derived from the
     hostname: a misconfigured vhost should break the site, not leak across it. */
  'tenant' => 'sps',

  'db' => [
    'driver' => 'mysql',
    'host'   => 'localhost',
    'name'   => 'centenary_academy',
    'user'   => '',
    'pass'   => '',
  ],

  /* HMAC key used to hash IP addresses before they are stored.

     An IP address is personal information under POPIA. We do need to recognise
     "the same source submitted forty registrations in a minute", but we do not
     need the address itself to do that. Hashing with a server-side key gives us
     the comparison and keeps the address out of the database.

     Generate once, then never change it — changing it orphans every existing
     hash. php -r "echo bin2hex(random_bytes(32));" */
  'ip_pepper' => '',

  /* Where the "a new registration arrived" notification goes. This is a
     convenience, NOT the record — the record is the database row. That is the
     whole point of the change: with FormSubmit the inbox was the only copy. */
  'notify_email' => 'kgomotso@centenarynetworks.com',

  /* Bumped whenever the privacy notice changes in a way that affects what
     people agreed to. Stored against every consent so we can answer "what
     exactly did this person agree to, and when" years later. */
  'policy_version' => '2026-09-01',

  /* Switches setup.php on, and is the ONLY thing that does.

     Xneelo gives us SFTP but no shell, so the tables have to be created through
     a browser. While this is a non-empty string, /setup is reachable by anyone
     who knows the token; while it is empty, /setup is a plain 404.

     Fill it in, load /setup over https, run it, then COME BACK AND EMPTY IT.
     Generate with: php -r "echo bin2hex(random_bytes(32));" */
  'setup_token' => '',

  /* Never true on a live site. Turns database and PHP errors into on-screen
     output, which is exactly how connection strings end up in screenshots. */
  'debug' => false,
];
