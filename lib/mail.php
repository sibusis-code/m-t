<?php
declare(strict_types=1);

/* Notification email.
 *
 * A NOTIFICATION, not the record. The record is the database row. This is the
 * whole difference from the FormSubmit arrangement it replaces: there, if the
 * mail failed or was filed in the wrong folder, the registration simply did not
 * exist anywhere. Here, mail failing is an inconvenience — the row is already
 * committed and the admin list will still show it.
 *
 * DELIVERABILITY WARNING, and this needs a DNS change to fix properly:
 * centenarynetworks.com receives its mail through Google Workspace, and its SPF
 * record therefore authorises Google's servers to send as that domain. Xneelo's
 * mail server is not in that record. Mail sent from here claiming to be
 * @centenarynetworks.com will fail SPF and is likely to be filed as spam or
 * rejected outright.
 *
 * So: From is the subdomain we control and can add an SPF record for, and the
 * human address goes in Reply-To, where it does not affect authentication.
 * Until an SPF record for sps.centenarynetworks.com exists at GoDaddy naming
 * Xneelo, treat these notifications as unreliable and read the admin list.
 */

defined('APP_BOOTED') or exit('lib/mail.php is not a page.');

/**
 * The one notification this system sends to HR: something has arrived, go and
 * look at the list.
 */
function notify(string $subject, string $body, ?string $replyTo = null): bool
{
    $to = (string) (app_config('notify_email') ?? '');
    if ($to === '') {
        app_log('NOTIFY SKIPPED — no notify_email configured');
        return false;
    }
    return mail_send($to, $subject, $body, $replyTo);
}

/**
 * Send to a named address rather than to HR.
 *
 * Added for password resets, and it is worth being clear about what changes
 * with that. Everywhere else on this site, mail is a convenience over a record
 * that is already safely in the database — if it fails, somebody reads the
 * admin list instead. A reset link is the opposite: the mail IS the mechanism,
 * and if it does not arrive the learner cannot get back in.
 *
 * That is why the return value is checked by the caller, why failures are
 * logged loudly, and why the admin can set a password by hand from
 * /admin-users. Until the SPF record described above exists at GoDaddy, that
 * page is the reliable route and this one is the convenient one.
 */
function mail_send(string $to, string $subject, string $body, ?string $replyTo = null,
                   string $contentType = 'text/plain; charset=UTF-8'): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        app_log('MAIL SKIPPED — not a valid address');
        return false;
    }

    /* Header injection: a newline in the subject would let a crafted value add
       Bcc: headers of its own. Subjects here are ours rather than a visitor's,
       but the one place that stops being true is the day somebody passes a form
       field through, and by then nobody remembers to check. */
    $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject));

    $host = $_SERVER['HTTP_HOST'] ?? 'sps.centenarynetworks.com';
    $host = preg_replace('/[^a-z0-9.\-]/i', '', $host);
    $from = 'noreply@' . $host;

    $headers = [
        'From: ' . tenant_name() . ' <' . $from . '>',
        /* text/plain for everything that calls this directly; a multipart type
           when mail_send_html() has already built the parts. Passed in rather
           than sniffed from the body, because guessing at the body is how a
           message ends up declaring a boundary it does not have. */
        'Content-Type: ' . $contentType,
        'MIME-Version: 1.0',
        'X-Mailer: sps-academy',
        // Stops holiday auto-replies and out-of-office loops bouncing back.
        'Auto-Submitted: auto-generated',
    ];
    if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }

    // -f sets the envelope sender, which is what SPF is actually checked
    // against. Without it, the server's default account is used and the
    // mismatch with the From header makes the failure worse, not better.
    /* DEVELOPMENT ONLY: write the message to a file instead of sending it.
     *
     * Off unless 'mail_file' is set in the configuration, and it is not set in
     * config.sample.php — so production, which is built from that sample, cannot
     * arrive in this state by drifting into it.
     *
     * It earns its place because a password reset cannot be tested any other
     * way: the token is stored as a hash, so the only readable copy of the link
     * that ever exists is the one in the email. Without this, the flow could be
     * reasoned about and not exercised, and "reasoned about" is how a reset
     * link ends up pointing at the wrong host.
     *
     * The path is refused if it is anywhere under the web root. A file of
     * outgoing mail served over HTTP would publish every reset link the moment
     * it was written.
     */
    $sink = (string) (app_config('mail_file') ?? '');
    if ($sink !== '') {
        $docroot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($docroot !== '' && path_inside($sink, $docroot)) {
            app_log('MAIL SINK REFUSED — mail_file is inside the web root');
            return false;
        }
        app_log('MAIL DIVERTED TO FILE (development) — ' . $subject);
        @file_put_contents(
            $sink,
            "===== " . gmdate('c') . " =====\nTo: " . $to . "\nSubject: " . $subject . "\n"
            . implode("\n", $headers) . "\n\n" . $body . "\n\n",
            FILE_APPEND
        );
        return true;
    }

    $ok = @mail($to, $subject, $body, implode("\r\n", $headers), '-f' . $from);

    // The address is not logged. A failure log that lists who the site emails is
    // a slow leak of the learner list into a file with a different lifetime.
    if (!$ok) app_log('MAIL FAILED — ' . $subject);
    return $ok;
}

/**
 * Send a letter that has both an HTML and a plain-text form.
 *
 * WHY BOTH, ALWAYS. The text part is not a courtesy for old software. It is
 * what a learner reads when their client blocks HTML, when the mail is opened
 * on a watch or read aloud, and — the case that actually decides it — it is
 * what several spam filters score the message on. An HTML-only mail from a
 * domain that already fails SPF (see this file's header) is close to the
 * worst-scoring shape a legitimate message can have. So $text is a required
 * argument, not an optional one: there is no call site that can forget it.
 *
 * multipart/alternative, and the parts are ordered worst-to-best because that
 * is what the format means — a client shows the LAST part it understands.
 *
 * Everything mail_send() does about header injection, the envelope sender and
 * the development file sink applies here too, so this builds the body and the
 * content headers and then hands over to it rather than repeating any of that.
 */
function mail_send_html(string $to, string $subject, string $html, string $text, ?string $replyTo = null): bool
{
    /* A boundary that cannot occur in either part. Random, because a fixed one
       appearing inside a learner's own name or a course title would split the
       message in the wrong place — unlikely, and silent when it happens. */
    $b = '=_' . bin2hex(random_bytes(16));

    $body = "This is a message in MIME format. If you can read this, your mail\r\n"
          . "program does not support multipart messages.\r\n"
          . "\r\n--" . $b . "\r\n"
          . "Content-Type: text/plain; charset=UTF-8\r\n"
          . "Content-Transfer-Encoding: 8bit\r\n\r\n"
          . str_replace("\r\n", "\n", $text) . "\r\n"
          . "\r\n--" . $b . "\r\n"
          . "Content-Type: text/html; charset=UTF-8\r\n"
          . "Content-Transfer-Encoding: 8bit\r\n\r\n"
          . str_replace("\r\n", "\n", $html) . "\r\n"
          . "\r\n--" . $b . "--\r\n";

    return mail_send($to, $subject, $body, $replyTo,
                     'multipart/alternative; boundary="' . $b . '"');
}

function tenant_name(): string
{
    $t = tenant();
    return (string) ($t['academy_name'] ?? 'Academy');
}
