<?php
/* The registration form — and, since 17 Aug 2026, the thing that receives it.
 *
 * This page used to POST to formsubmit.co, a third-party service outside South
 * Africa. Every name, work email, phone number and employee number typed here
 * left the country before anyone at Centenary saw it, on no written basis, and
 * the only copy lived in one inbox. It now posts to itself and writes to our own
 * database, in Johannesburg.
 *
 * It keeps its URL — the rewrite in .htaccess serves this file at /contact — so
 * every link across the other fourteen pages is untouched.
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/audit.php';
require __DIR__ . '/lib/mail.php';
require __DIR__ . '/lib/csrf.php';
require __DIR__ . '/lib/registration.php';
require __DIR__ . '/lib/chrome.php';
/* Before a single byte is printed. The session cookie is a header, so a page
   that prints first and starts its session later gets no session at all — and
   the CSRF token in the form below it is then unbacked, so the form can never
   be submitted. See the note in app_session_start(). */
app_session_start();


$errors = [];
$old    = [];

/* A course can be proposed by the link that got the visitor here —
   contact?course=Occupational%20Certificate:%20Project%20Manager — which is how
   the "Register Now" button on the Project Manager page passes its subject. */
$old['course_title'] = isset($_GET['course']) ? mb_substr(trim((string) $_GET['course']), 0, 190) : '';

if (is_post()) {

    /* Bots fill in every field they find, including the one that is hidden.
       A caught bot is shown the thank-you page: telling it that it failed only
       teaches whoever wrote it what to change. */
    if (post_str('_honey') !== '') {
        audit('registration.honeypot', 'registrations', null, 'silently discarded');
        redirect('thanks');
    }

    [$clean, $errors] = registration_validate($_POST);

    if (!csrf_valid()) {
        $errors['_form'] = 'This page had been open a while and the form expired. '
                         . 'Nothing was lost — please send it again.';
    }

    if (!$errors) {
        if (registration_rate_limited()) {
            $errors['_form'] = 'That is a lot of registrations from one connection in a '
                             . 'short time. Please wait a few minutes, or email us directly.';
            audit('registration.rate_limited');
        } else {
            /* A refresh on the POST, or an impatient double click, should not
               make two rows and two emails out of one person's intention. */
            $existing = registration_duplicate_id($clean);
            if ($existing !== null) {
                audit('registration.duplicate_ignored', 'registrations', $existing);
                csrf_rotate();
                redirect('thanks');
            }

            $id = registration_store($clean);
            registration_notify($clean, $id);
            csrf_rotate();
            redirect('thanks');
        }
    }

    $old = $clean;
}

/** Print an error under a field, and mark the field, in one call. */
function err(array $errors, string $field): string
{
    return isset($errors[$field])
        ? '<p class="field-err">' . e($errors[$field]) . '</p>'
        : '';
}
function bad(array $errors, string $field): string
{
    return isset($errors[$field]) ? ' has-err' : '';
}
function old(array $old, string $field): string
{
    return e((string) ($old[$field] ?? ''));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register Your Interest — <?= e(brand('academy')) ?></title>
<meta name="description" content="Register your interest with HR for a <?= e(brand('academy')) ?> course, or ask a question about the next intake.">
<link rel="stylesheet" href="<?= e(asset('styles.css')) ?>">
</head>
<body>
<?php chrome_nav('site', ['active' => 'contact']); ?>
<section class="section-soft page-top" id="contact">
  <div class="wrap">
    <div class="sec-head reveal">
      <span class="eyebrow">Upskill Yourself</span>
      <h2>Ready to join the next intake?</h2>
      <p>Fill in the form below and HR will confirm your place on the next intake. Have a word with your line manager first so the two of you can plan the cover — then send this through.</p>
    </div>
    <div class="contact-grid">
      <div class="contact-info reveal">
        <p><span class="lbl">Academy &amp; registrations</span><a href="mailto:<?= e(brand('academy_email')) ?>"><?= e(brand('academy_email')) ?></a></p>
        <?php /* Some companies publish a second, company-side address and some do
                 not. Omitted entirely rather than left blank: a label with nothing
                 after it reads as a page that failed to load, not as a company
                 that has one address. */ ?>
        <?php if (brand_has('enquiries_email')): ?>
        <p><span class="lbl">General enquiries</span><a href="mailto:<?= e(brand('enquiries_email')) ?>"><?= e(brand('enquiries_email')) ?></a></p>
        <?php endif; ?>
        <p><span class="lbl">Phone</span><a href="tel:<?= e(brand('phone_href')) ?>"><?= e(brand('phone')) ?></a></p>
        <p><span class="lbl">Office hours</span><?= e(brand('office_hours')) ?></p>
        <p><span class="lbl">Course cost</span>Fully funded by <?= e(brand('company_short')) ?></p>
        <p><span class="lbl">Delivery</span>Online · from any <?= e(brand('company_short')) ?> site or from home</p>
      </div>
      <form class="form reveal" action="contact" method="POST" novalidate>
        <?= csrf_field() ?>
        <input type="text" name="_honey" tabindex="-1" autocomplete="off" style="display:none">

        <?php if (isset($errors['_form'])): ?>
          <p class="form-err" role="alert"><?= e($errors['_form']) ?></p>
        <?php elseif ($errors): ?>
          <p class="form-err" role="alert">There is something to fix below before this can be sent.</p>
        <?php endif; ?>

        <div class="two">
          <div class="field<?= bad($errors,'full_name') ?>"><label for="f-name">Full name</label>
            <input id="f-name" type="text" name="full_name" value="<?= old($old,'full_name') ?>" placeholder="Your name" required>
            <?= err($errors,'full_name') ?></div>
          <div class="field"><label for="f-emp">Employee number</label>
            <input id="f-emp" type="text" name="employee_no" value="<?= old($old,'employee_no') ?>" placeholder="<?= e(brand('empno_example')) ?>"></div>
        </div>
        <div class="two">
          <div class="field"><label for="f-dept">Department / team</label>
            <input id="f-dept" type="text" name="department" value="<?= old($old,'department') ?>" placeholder="<?= e(brand('dept_example')) ?>"></div>
          <div class="field"><label for="f-mgr">Line manager</label>
            <input id="f-mgr" type="text" name="line_manager" value="<?= old($old,'line_manager') ?>" placeholder="Manager's name"></div>
        </div>
        <div class="two">
          <div class="field<?= bad($errors,'email') ?>"><label for="f-email">Work email</label>
            <input id="f-email" type="email" name="email" value="<?= old($old,'email') ?>" placeholder="you@company.co.za" required>
            <?= err($errors,'email') ?></div>
          <div class="field<?= bad($errors,'phone') ?>"><label for="f-phone">Contact number</label>
            <input id="f-phone" type="tel" name="phone" value="<?= old($old,'phone') ?>" placeholder="+27">
            <?= err($errors,'phone') ?></div>
        </div>
        <div class="field"><label for="f-course">Which course are you interested in?</label>
          <input id="f-course" type="text" name="course_title" value="<?= old($old,'course_title') ?>" placeholder="e.g. AI Fundamentals for the Workplace"></div>
        <div class="field"><label for="f-msg">Anything else we should know?</label>
          <textarea id="f-msg" name="message" rows="4" placeholder="Shift patterns, questions about the course, anything HR should factor in"><?= old($old,'message') ?></textarea></div>

        <!-- The consent tick and the notice it links to are what make holding this
             information lawful, and what let us prove it later. The wording says who
             gets it and why, because "I agree to the terms" is not informed consent. -->
        <div class="field field-consent<?= bad($errors,'consent') ?>">
          <label class="check">
            <input type="checkbox" name="consent" value="1"<?= !empty($old['consent']) ? ' checked' : '' ?> required>
            <span>I agree that <?= e(brand('company_short')) ?> and Centenary Networks may use the details above to
              contact me about this course and to register me for it. I have read the
              <a href="privacy" target="_blank" rel="noopener">privacy notice</a>.</span>
          </label>
          <?= err($errors,'consent') ?>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%">Register My Interest</button>
      </form>
    </div>
  </div>
</section>

<?php chrome_footer('site', ['employees' => true, 'extra' => 'Our catalogue is a mix: items are non-accredited professional short courses unless explicitly marked as an accredited qualification, and not everything in it is listed on this site yet. RPL outcomes depend on assessment and on the accreditation scope held for each qualification. Videos and downloadable resources shown are placeholders for demonstration.']); ?>
<script src="<?= e(asset('site.js')) ?>"></script>
<script src="<?= e(asset('profile.js')) ?>"></script>
<script src="<?= e(asset('assistant.js')) ?>"></script>
</body></html>
