<?php
declare(strict_types=1);

/* The letters the academy sends a learner: the welcome letter when they are
 * enrolled, and the report when they finish a module's self-checks.
 *
 * WHY EMAIL HTML IS WRITTEN LIKE THIS, AND NOT LIKE THE REST OF THE SITE
 *
 * None of the normal rules apply. A mail client is not a browser:
 *
 *   - <style> in the head is stripped by Gmail's web client, so every rule has
 *     to be an inline style attribute on the tag it applies to.
 *   - flexbox and grid are unsupported in Outlook, which renders through Word.
 *     Layout is <table>, as it was in 2003, because that is what still works.
 *   - external CSS, web fonts and remote images are blocked by default in most
 *     clients until the reader clicks "show images". So there is no stylesheet,
 *     no font, and NO LOGO — the academy's logo is an SVG, which Gmail will not
 *     render at all, and a broken image at the top of a welcome letter is worse
 *     than a wordmark set in type. See letter_shell().
 *   - the whole thing has to survive being forwarded, quoted and printed.
 *
 * So the markup here is deliberately old-fashioned and repetitive. Do not
 * "tidy" it into semantic HTML with a stylesheet; it will look correct in the
 * browser and fall apart in the clients learners actually use.
 *
 * WHAT NEVER GOES IN A LETTER
 *
 *   - A password. Not the readable one admin.php shows on screen when an
 *     account is created, not any other. That password is generated to be read
 *     off a screen and handed over in person; putting it in an email would move
 *     the credential into a channel this site cannot secure, and one that
 *     currently fails SPF. When an account needs a password, the letter carries
 *     an invite LINK, which expires, works once, and is useless once used.
 *   - A quiz answer, for the reason lib/bundle.php exists.
 *   - Anything about another learner.
 *
 * DELIVERABILITY. Read the header of lib/mail.php. Until an SPF record for
 * this host exists in DNS, these letters are likely to be filed as spam. The
 * database row is the record; the letter is a notification of it. Nothing on
 * this site depends on one arriving.
 */

defined('APP_BOOTED') or exit('lib/letters.php is not a page.');

/* Sent once each, per learner. The values are stored in letters_sent.kind, so
   they are data — changing one re-sends every letter of that kind to everybody
   who has already had it. */
const LETTER_WELCOME = 'welcome';
const LETTER_MODULE  = 'module_results';

/* ---------------------------------------------------------------------------
   The shell
   --------------------------------------------------------------------------- */

/**
 * A brand value, or a fallback, WITHOUT the fatal that brand() raises.
 *
 * brand() kills the page for a key that is not in brand.php, and it is right to
 * — a blank in a footer is invisible and goes live. An email is the one place
 * that rule has to bend: lib/brand.php is the single file tools/sync-backend.php
 * refuses to copy, so the day these letters reach Fungi, Equinix or Maziv they
 * arrive at three sites whose brand.php has never heard of email_accent. A
 * fatal there would take down the enrolment page, not just the letter. So these
 * two helpers ask first, and a missing colour renders neutral grey rather than
 * SPS orange — unbranded reads as unfinished, which it is, while another
 * company's colour reads as a mistake nobody catches.
 */
function letter_brand(string $key, string $fallback = ''): string
{
    return brand_has($key) ? brand($key) : $fallback;
}

/** A brand colour, checked to be a hex triplet before it goes into markup. */
function letter_colour(string $key, string $fallback): string
{
    $v = letter_brand($key);
    return preg_match('/^#[0-9a-f]{6}$/i', $v) ? $v : $fallback;
}

/** The site's own address, used for links inside a letter. */
function letter_site_url(string $path = ''): string
{
    $scheme = (($_SERVER['HTTPS'] ?? '') === 'on'
               || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') return '';
    return $scheme . '://' . $host . app_base_path() . ltrim($path, '/');
}

/**
 * Wrap a letter's content in the academy's envelope.
 *
 * @param string $title    what the letter is, for the heading strip
 * @param string $content  HTML for the body — already escaped by its caller
 * @param string $footNote an extra line above the accreditation, or ''
 */
function letter_shell(string $title, string $content, string $footNote = ''): string
{
    $accent = letter_colour('email_accent', '#4d4c4d');
    $ink    = letter_colour('email_ink', '#2b2b2b');
    $academy = e(letter_brand('academy', tenant_name()));
    $company = e(letter_brand('company'));
    $accred  = letter_brand('accred_no');
    $email   = letter_brand('academy_email');
    $site    = letter_site_url('my');

    $foot = [];
    if ($footNote !== '') $foot[] = $footNote;
    if ($company !== '') $foot[] = 'Training delivered for ' . $company . '.';
    if ($accred !== '') {
        $foot[] = 'Centenary Networks is accredited by the QCTO, accreditation number '
                . e($accred) . '.';
    }
    if ($email !== '') {
        $foot[] = 'Questions about your studies: <a href="mailto:' . e($email) . '" '
                . 'style="color:' . $accent . ';">' . e($email) . '</a>';
    }
    $foot[] = 'This message was sent automatically because you are enrolled with the '
            . $academy . '. It is a notification — your results are always on the site itself.';

    /* meta color-scheme stops iOS Mail and Outlook.com inverting the colours
       into something unreadable when the reader is in dark mode. */
    return '<!doctype html>' . "\n"
      . '<html lang="en"><head>'
      . '<meta charset="utf-8">'
      . '<meta name="viewport" content="width=device-width,initial-scale=1">'
      . '<meta name="color-scheme" content="light">'
      . '<meta name="supported-color-schemes" content="light">'
      . '<title>' . e($title) . '</title>'
      . '</head>'
      . '<body style="margin:0;padding:0;background:#f2f0ed;'
      .   '-webkit-text-size-adjust:100%;">'

      /* Preheader: the grey line a client shows next to the subject. Without
         one, the client picks the first text it finds, which is the wordmark. */
      . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">'
      . e($title) . '</div>'

      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
      .   ' style="background:#f2f0ed;padding:24px 12px;">'
      . '<tr><td align="center">'

      . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"'
      .   ' style="width:100%;max-width:600px;background:#ffffff;border:1px solid #d9d4cc;'
      .   'border-radius:6px;overflow:hidden;font-family:Georgia,\'Times New Roman\',serif;">'

      /* Header. Type, not an image — see this file's header comment. */
      . '<tr><td style="background:' . $ink . ';padding:22px 28px;">'
      . '<div style="font-family:Helvetica,Arial,sans-serif;font-size:17px;font-weight:bold;'
      .   'color:#ffffff;letter-spacing:.02em;">' . $academy . '</div>'
      . '<div style="font-family:Helvetica,Arial,sans-serif;font-size:12px;'
      .   'color:#c9c5c0;padding-top:3px;">' . e($title) . '</div>'
      . '</td></tr>'
      . '<tr><td style="height:4px;background:' . $accent . ';font-size:0;line-height:0;">&nbsp;</td></tr>'

      . '<tr><td style="padding:28px;color:' . $ink . ';font-size:16px;line-height:1.6;">'
      . $content
      . '</td></tr>'

      . '<tr><td style="border-top:1px solid #e6e2dc;padding:18px 28px;'
      .   'font-family:Helvetica,Arial,sans-serif;font-size:11px;line-height:1.6;color:#6b6862;">'
      . implode('<br>', $foot)
      . '</td></tr>'

      . '</table>'

      . ($site !== ''
          ? '<div style="font-family:Helvetica,Arial,sans-serif;font-size:11px;color:#8b8781;'
            . 'padding-top:12px;">' . e($site) . '</div>'
          : '')

      . '</td></tr></table></body></html>';
}

/** A paragraph in a letter. */
function letter_p(string $html): string
{
    return '<p style="margin:0 0 14px;">' . $html . '</p>';
}

/** The one thing the letter wants the reader to do. */
function letter_button(string $url, string $label): string
{
    $accent = letter_colour('email_accent', '#4d4c4d');
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" '
         . 'style="margin:6px 0 18px;"><tr><td style="background:' . $accent . ';border-radius:4px;">'
         . '<a href="' . e($url) . '" style="display:inline-block;padding:12px 26px;'
         . 'font-family:Helvetica,Arial,sans-serif;font-size:15px;font-weight:bold;'
         . 'color:#ffffff;text-decoration:none;">' . e($label) . '</a>'
         . '</td></tr></table>'
         /* Buttons are the first thing a client mangles, so the address is
            printed as well. A learner who cannot click can still get in. */
         . '<p style="margin:0 0 16px;font-family:Helvetica,Arial,sans-serif;font-size:12px;'
         . 'color:#6b6862;word-break:break-all;">Or paste this into your browser:<br>'
         . e($url) . '</p>';
}

/* ---------------------------------------------------------------------------
   Letter one — welcome, on enrolment
   --------------------------------------------------------------------------- */

/**
 * What the learner has been registered for, and how to get in.
 *
 * @param array       $user      the users row
 * @param string      $slug      course slug
 * @param string      $title     course title as recorded on the enrolment
 * @param string|null $inviteUrl a set-a-password link, when the account is new
 *                               and is being invited. Null when the account
 *                               already existed or the password was handed over
 *                               in person — a welcome letter never carries a
 *                               password, only ever a link.
 * @return array{subject:string, html:string, text:string}
 */
function letter_welcome(array $user, string $slug, string $title, ?string $inviteUrl = null): array
{
    $name    = trim((string) ($user['first_name'] ?? '')) ?: 'there';
    $academy = letter_brand('academy', tenant_name());
    $signIn  = letter_site_url('login');
    $modules = curriculum_modules();
    $tracked = $slug === 'project-management' && $modules !== [];

    $subject = 'You are enrolled — ' . $title;

    /* ---- HTML ---- */
    $h  = '<p style="margin:0 0 16px;font-size:19px;">Hello ' . e($name) . ',</p>';
    $h .= letter_p('You have been registered on the <strong>' . e($title) . '</strong> '
        . 'programme with the ' . e($academy) . '. This letter confirms your place and '
        . 'sets out what you have been enrolled for.');

    $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
        . 'style="background:#f7f6f4;border:1px solid #e6e2dc;border-radius:4px;margin:0 0 18px;">'
        . '<tr><td style="padding:16px 18px;font-family:Helvetica,Arial,sans-serif;font-size:14px;'
        . 'line-height:1.7;">'
        . '<strong style="font-size:15px;">' . e($title) . '</strong><br>'
        . '<span style="color:#6b6862;">Enrolled: ' . e(letter_date()) . '</span>';

    if ($tracked) {
        $credits = 0;
        $topics  = 0;
        foreach ($modules as $m) { $credits += (int) $m['credits']; $topics += count($m['topics']); }
        $h .= '<br><span style="color:#6b6862;">' . count($modules) . ' knowledge modules · '
            . $topics . ' topics · ' . $credits . ' credits</span>';
    }
    $h .= '</td></tr></table>';

    if ($tracked) {
        $h .= letter_p('<strong>What you will study</strong>');
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="margin:0 0 18px;font-family:Helvetica,Arial,sans-serif;font-size:14px;">';
        foreach ($modules as $id => $m) {
            $h .= '<tr>'
                . '<td style="padding:7px 10px 7px 0;border-bottom:1px solid #f0ede8;'
                . 'color:#6b6862;white-space:nowrap;vertical-align:top;width:1%;">' . e($id) . '</td>'
                . '<td style="padding:7px 0;border-bottom:1px solid #f0ede8;">' . e($m['title']) . '</td>'
                . '<td style="padding:7px 0 7px 10px;border-bottom:1px solid #f0ede8;'
                . 'color:#6b6862;text-align:right;white-space:nowrap;">'
                . (int) $m['credits'] . ' cr</td>'
                . '</tr>';
        }
        $h .= '</table>';
    }

    if ($inviteUrl !== null) {
        $h .= letter_p('<strong>Set your password</strong>');
        $h .= letter_p('Choose your own password using the link below. It works once, '
            . 'and expires in seven days.');
        $h .= letter_button($inviteUrl, 'Set my password');
    } else {
        $h .= letter_p('<strong>Signing in</strong>');
        $h .= letter_p('Sign in with your email address, ' . e((string) $user['email'])
            . ', and the password you have been given. If you do not have one, use '
            . '“Forgotten your password?” on the sign-in page.');
        if ($signIn !== '') $h .= letter_button($signIn, 'Sign in');
    }

    $h .= letter_p('Once you are in you can read every topic on screen, work through the '
        . 'self-check quizzes, and see your own progress.');

    /* The one thing a learner most often misunderstands about an academy
       account, said in the welcome letter rather than after the fact. */
    $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
        . 'style="border-left:3px solid #d9d4cc;margin:4px 0 4px;"><tr>'
        . '<td style="padding:2px 0 2px 14px;font-size:14px;color:#5c5c5c;">'
        . 'The quizzes on the site are self-checks the academy built to help you study. '
        . 'They do not count towards being found competent — that is Centenary’s decision '
        . 'after the real assessment, and the qualification itself is awarded by the QCTO '
        . 'after the EISA.'
        . '</td></tr></table>';

    $h .= letter_p('We are glad to have you with us.');
    $h .= letter_p('— ' . e($academy));

    /* ---- text ---- */
    $t  = 'Hello ' . $name . ",\n\n"
        . 'You have been registered on the ' . $title . ' programme with the '
        . $academy . '. This letter confirms your place.' . "\n\n"
        . 'ENROLLED FOR' . "\n"
        . '  ' . $title . "\n"
        . '  Enrolled: ' . letter_date() . "\n";
    if ($tracked) {
        $credits = 0; $topics = 0;
        foreach ($modules as $m) { $credits += (int) $m['credits']; $topics += count($m['topics']); }
        $t .= '  ' . count($modules) . ' knowledge modules, ' . $topics . ' topics, '
            . $credits . ' credits' . "\n\n"
            . 'WHAT YOU WILL STUDY' . "\n";
        foreach ($modules as $id => $m) {
            $t .= '  ' . $id . '  ' . $m['title'] . ' (' . (int) $m['credits'] . ' credits)' . "\n";
        }
    }
    $t .= "\n";
    if ($inviteUrl !== null) {
        $t .= 'SET YOUR PASSWORD' . "\n"
            . 'Choose your own password here. The link works once and expires in seven days.'
            . "\n\n  " . $inviteUrl . "\n\n";
    } else {
        $t .= 'SIGNING IN' . "\n"
            . 'Sign in with your email address, ' . (string) $user['email'] . ', and the '
            . 'password you have been given. If you do not have one, use "Forgotten your '
            . 'password?" on the sign-in page.' . "\n";
        if ($signIn !== '') $t .= "\n  " . $signIn . "\n";
        $t .= "\n";
    }
    $t .= 'Once you are in you can read every topic on screen, work through the self-check '
        . 'quizzes, and see your own progress.' . "\n\n"
        . 'The quizzes on the site are self-checks the academy built to help you study. They '
        . 'do not count towards being found competent -- that is Centenary\'s decision after '
        . 'the real assessment, and the qualification itself is awarded by the QCTO after the '
        . 'EISA.' . "\n\n"
        . 'We are glad to have you with us.' . "\n\n"
        . '-- ' . $academy . "\n";

    return [
        'subject' => $subject,
        'html'    => letter_shell('Enrolment confirmation', $h),
        'text'    => $t,
    ];
}

/* ---------------------------------------------------------------------------
   Letter two — the module report
   --------------------------------------------------------------------------- */

/**
 * A learner's results for one finished module.
 *
 * @param array  $rows topic code => ['pct'=>int,'score'=>int,'out_of'=>int,'attempts'=>int]
 * @return array{subject:string, html:string, text:string}
 */
function letter_module_results(array $user, string $moduleId, array $rows, string $courseTitle): array
{
    $name    = trim((string) ($user['first_name'] ?? '')) ?: 'there';
    $academy = letter_brand('academy', tenant_name());
    $modTitle = curriculum_module_title($moduleId);
    $accent  = letter_colour('email_accent', '#4d4c4d');

    $score = 0; $outOf = 0;
    foreach ($rows as $r) { $score += (int) $r['score']; $outOf += (int) $r['out_of']; }
    $pct = $outOf > 0 ? (int) round($score / $outOf * 100) : 0;

    $subject = $moduleId . ' complete — your self-check results';

    /* ---- HTML ---- */
    $h  = '<p style="margin:0 0 16px;font-size:19px;">Well done, ' . e($name) . '.</p>';
    $h .= letter_p('You have worked through every topic in <strong>' . e($moduleId) . ' '
        . e($modTitle) . '</strong> and completed each of its self-check quizzes. '
        . 'Here is how you did.');

    $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
        . 'style="background:#f7f6f4;border:1px solid #e6e2dc;border-radius:4px;margin:0 0 20px;">'
        . '<tr><td align="center" style="padding:20px;font-family:Helvetica,Arial,sans-serif;">'
        . '<div style="font-size:38px;font-weight:bold;color:' . $accent . ';line-height:1.1;">'
        . $pct . '%</div>'
        . '<div style="font-size:13px;color:#6b6862;padding-top:5px;">'
        . $score . ' of ' . $outOf . ' across ' . count($rows) . ' '
        . (count($rows) === 1 ? 'topic' : 'topics') . ', best attempt each</div>'
        . '</td></tr></table>';

    $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
        . 'style="margin:0 0 20px;font-family:Helvetica,Arial,sans-serif;font-size:14px;">'
        . '<tr>'
        . '<th align="left" style="padding:0 0 7px;border-bottom:2px solid #e6e2dc;'
        . 'font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#6b6862;">Topic</th>'
        . '<th align="right" style="padding:0 0 7px 10px;border-bottom:2px solid #e6e2dc;'
        . 'font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#6b6862;'
        . 'white-space:nowrap;">Best</th>'
        . '</tr>';
    foreach ($rows as $code => $r) {
        $h .= '<tr>'
            . '<td style="padding:9px 10px 9px 0;border-bottom:1px solid #f0ede8;vertical-align:top;">'
            . '<span style="color:#6b6862;font-size:12px;">' . e((string) $code) . '</span><br>'
            . e(curriculum_topic_title((string) $code))
            . ((int) $r['attempts'] > 1
                ? '<br><span style="color:#8b8781;font-size:12px;">'
                  . (int) $r['attempts'] . ' attempts</span>'
                : '')
            . '</td>'
            . '<td align="right" style="padding:9px 0 9px 10px;border-bottom:1px solid #f0ede8;'
            . 'vertical-align:top;white-space:nowrap;">'
            . '<strong>' . (int) $r['score'] . '/' . (int) $r['out_of'] . '</strong><br>'
            . '<span style="color:#6b6862;font-size:12px;">' . (int) $r['pct'] . '%</span>'
            . '</td></tr>';
    }
    $h .= '</table>';

    $h .= letter_p($pct >= 80
        ? 'That is a strong result. Keep the same approach going into the next module.'
        : ($pct >= 50
            ? 'A solid start. It is worth going back over the topics you scored lowest on — '
              . 'the quizzes can be retaken as many times as you like.'
            : 'These are worth revisiting. Read those topics again and retake the quizzes — '
              . 'there is no limit on attempts, and nothing here counts against you.'));

    $my = letter_site_url('my');
    if ($my !== '') $h .= letter_button($my, 'See all my results');

    $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
        . 'style="border-left:3px solid #d9d4cc;margin:4px 0;"><tr>'
        . '<td style="padding:2px 0 2px 14px;font-size:14px;color:#5c5c5c;">'
        . 'These are self-check scores. They do not count towards being found competent — '
        . 'that is Centenary’s decision after the real assessment, and the qualification is '
        . 'awarded by the QCTO after the EISA.'
        . '</td></tr></table>';

    $h .= letter_p('— ' . e($academy));

    /* ---- text ---- */
    $t  = 'Well done, ' . $name . ".\n\n"
        . 'You have worked through every topic in ' . $moduleId . ' ' . $modTitle
        . ' and completed each of its self-check quizzes. Here is how you did.' . "\n\n"
        . 'OVERALL: ' . $pct . '%  (' . $score . ' of ' . $outOf . ' across '
        . count($rows) . ' ' . (count($rows) === 1 ? 'topic' : 'topics') . ', best attempt each)'
        . "\n\n";
    foreach ($rows as $code => $r) {
        $t .= sprintf("  %-7s %2d/%-2d  %3d%%%s\n    %s\n",
            (string) $code, (int) $r['score'], (int) $r['out_of'], (int) $r['pct'],
            (int) $r['attempts'] > 1 ? '  (' . (int) $r['attempts'] . ' attempts)' : '',
            curriculum_topic_title((string) $code));
    }
    $t .= "\n" . ($pct >= 80
        ? 'That is a strong result. Keep the same approach going into the next module.'
        : ($pct >= 50
            ? 'A solid start. It is worth going back over the topics you scored lowest on -- '
              . 'the quizzes can be retaken as many times as you like.'
            : 'These are worth revisiting. Read those topics again and retake the quizzes -- '
              . 'there is no limit on attempts, and nothing here counts against you.')) . "\n";
    if ($my !== '') $t .= "\n  " . $my . "\n";
    $t .= "\n" . 'These are self-check scores. They do not count towards being found competent '
        . '-- that is Centenary\'s decision after the real assessment, and the qualification is '
        . 'awarded by the QCTO after the EISA.' . "\n\n"
        . '-- ' . $academy . "\n";

    return [
        'subject' => $subject,
        'html'    => letter_shell($courseTitle . ' · ' . $moduleId, $h),
        'text'    => $t,
    ];
}

/* ---------------------------------------------------------------------------
   Sending, once
   --------------------------------------------------------------------------- */

/** Today, written the way a South African reader expects it. */
function letter_date(): string
{
    $tz = new DateTimeZone('Africa/Johannesburg');
    return (new DateTimeImmutable('now', $tz))->format('j F Y');
}

/** Has this exact letter already gone to this learner? */
function letter_already_sent(int $userId, string $kind, string $ref): bool
{
    return db_optional(fn() => db_value(
        'SELECT id FROM letters_sent WHERE tenant_id = ? AND user_id = ? AND kind = ? AND ref = ?',
        [tenant_id(), $userId, $kind, $ref]
    ), null) !== null;
}

/**
 * Send a letter, at most once per learner per reference.
 *
 * The claim is written BEFORE the send, not after. If the process dies between
 * mail() and the insert, the alternative ordering sends the letter again on the
 * next attempt — and a learner receiving two copies of their results is a worse
 * failure than one receiving none, because the second is visible on the site
 * and the first is not. The row records whether delivery succeeded, so a letter
 * that failed can be found and re-sent deliberately.
 *
 * Returns false when it was already sent, when the table is not migrated yet,
 * or when mail failed — all three are conditions the callers treat the same
 * way: carry on, the database row is the record.
 */
function letter_send_once(array $user, string $kind, string $ref, array $letter): bool
{
    $to = (string) ($user['email'] ?? '');
    if ($to === '') return false;

    $userId = (int) ($user['id'] ?? 0);
    if ($userId <= 0) return false;

    /* db_optional: letters_sent arrives with a release and the migration is a
       separate manual step on Xneelo. Until /setup has been run, this returns
       null and NOTHING is sent — deliberately. Sending without being able to
       record it is how a learner gets the same letter on every page load. */
    $claimed = db_optional(function () use ($userId, $kind, $ref) {
        if (letter_already_sent($userId, $kind, $ref)) return 'already';
        db_insert('letters_sent', [
            'tenant_id' => tenant_id(),
            'user_id'   => $userId,
            'kind'      => $kind,
            'ref'       => $ref,
            'delivered' => 0,
            'sent_at'   => now(),
        ]);
        return 'claimed';
    }, null);

    if ($claimed === null) {
        app_log('LETTER SKIPPED — letters_sent is not migrated yet (' . $kind . ')');
        return false;
    }
    if ($claimed === 'already') return false;

    $ok = mail_send_html($to, $letter['subject'], $letter['html'], $letter['text']);

    if ($ok) {
        db_optional(fn() => db_run(
            'UPDATE letters_sent SET delivered = 1 WHERE tenant_id = ? AND user_id = ? AND kind = ? AND ref = ?',
            [tenant_id(), $userId, $kind, $ref]
        ), null);
    }

    // The address is not audited, for the reason mail_send() does not log it.
    audit($ok ? 'letter.sent' : 'letter.send_failed', 'users', $userId, $kind . ' · ' . $ref);
    return $ok;
}

/* ---------------------------------------------------------------------------
   The two things that trigger a letter
   --------------------------------------------------------------------------- */

/**
 * Send the welcome letter for one enrolment. Once per learner per course.
 *
 * @param string|null $inviteUrl a set-a-password link when the account is new
 *                               and being invited; null otherwise. NEVER a
 *                               password — see this file's header.
 */
function letter_send_welcome(array $user, string $courseSlug, string $courseTitle,
                             ?string $inviteUrl = null): bool
{
    if (!function_exists('curriculum_modules')) {
        app_log('LETTER — lib/curriculum.php was not loaded by this page');
        return false;
    }
    $ref = $courseSlug !== '' ? $courseSlug : 'course';
    return letter_send_once($user, LETTER_WELCOME, $ref,
                            letter_welcome($user, $courseSlug, $courseTitle, $inviteUrl));
}

/**
 * A learner has just submitted a quiz. If that finished a module, report it.
 *
 * "Finished" means every topic in the module that HAS a published quiz has at
 * least one attempt from this learner. Not every topic in the curriculum —
 * a module whose quizzes are not all published yet would then never complete,
 * and the learner would work through everything available and hear nothing.
 *
 * Called after the attempt is recorded, and every failure inside is swallowed:
 * a learner has just answered ten questions and is waiting for a score, and no
 * problem with email may turn that into an error page. The attempt is already
 * committed either way.
 *
 * @return bool whether a letter went out — for tests, not for the page.
 */
function letter_module_completed(array $user, string $courseSlug, string $topicCode,
                                 string $courseTitle): bool
{
    try {
        if (!function_exists('curriculum_modules') || !function_exists('quiz_for_module')) return false;

        $moduleId = curriculum_module_of($topicCode);
        if ($moduleId === '') return false;

        $userId = (int) ($user['id'] ?? 0);
        $ref    = $courseSlug . '/' . $moduleId;
        // Cheapest question first: most attempts are retakes in a module that
        // has already been reported, and that costs one indexed lookup.
        if ($userId <= 0 || letter_already_sent($userId, LETTER_MODULE, $ref)) return false;

        $rows = [];
        foreach (curriculum_module_topics($moduleId) as $code) {
            $quiz = quiz_for_module($courseSlug, $code);
            if ($quiz === null || !(int) $quiz['published']) continue;   // not open to learners yet

            $best = quiz_best_result($userId, (int) $quiz['id']);
            if ($best === null) return false;                            // still one to do

            $rows[$code] = [
                'pct'      => (int) $best['pct'],
                'score'    => (int) $best['score_count'],
                'out_of'   => (int) $best['question_count'],
                'attempts' => (int) $best['attempts'],
            ];
        }
        if ($rows === []) return false;

        return letter_send_once($user, LETTER_MODULE, $ref,
                                letter_module_results($user, $moduleId, $rows, $courseTitle));
    } catch (Throwable $e) {
        /* Deliberately swallowed. The score page must render. */
        app_log('MODULE LETTER FAILED (' . $topicCode . '): ' . $e->getMessage());
        return false;
    }
}
