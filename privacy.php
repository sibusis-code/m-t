<?php
/* The privacy notice.
 *
 * DRAFT — needs Kgomotso's sign-off, and ideally a legal read, before the site
 * goes live on the subdomain. It is written from what is actually true of the
 * system as built rather than from a template, so where it is wrong it is wrong
 * about a fact we can check, not about a paragraph nobody read.
 *
 * Three things in it are genuinely unknown and are marked TO CONFIRM on the
 * page itself rather than quietly guessed:
 *   - who each company has appointed as its Information Officer;
 *   - whether Centenary's Information Officer is registered with the
 *     Information Regulator;
 *   - the retention period the QCTO requires for learner records, which is now
 *     load-bearing rather than hypothetical: the enrolments and learner_progress
 *     tables both hold rows with no expiry date on them, waiting for it.
 *
 * The version string comes from the config so that the notice, and the
 * consents recorded against it, can never disagree about which wording was on
 * screen when somebody ticked the box.
 *
 * CHANGING THIS PAGE means bumping 'policy_version' in the configuration file.
 * A consent row records the version that was on screen, so leaving the version
 * alone after a substantive edit — such as the learner-account section added in
 * August 2026 — makes every earlier consent evidence of wording nobody saw.
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/chrome.php';

$version = (string) (app_config('policy_version') ?? 'unversioned');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Privacy Notice — <?= e(brand('academy')) ?></title>
<meta name="description" content="How <?= e(brand('academy')) ?> and Centenary Networks handle your personal information, and what you can ask us to do with it.">
<meta name="robots" content="noindex">
<link rel="stylesheet" href="<?= e(asset('styles.css')) ?>">
</head>
<body>
<?php chrome_nav('site'); ?>

<section class="section-dark page-top">
  <div class="wrap">
    <div class="sec-head sec-head-wide">
      <span class="eyebrow">Privacy</span>
      <h2>How we handle your information</h2>
      <p class="lede-h">This notice explains what <?= e(brand('academy')) ?> collects when you register for a course, why we need it, who else sees it, and what you can tell us to do with it. It is written to be read, not to be scrolled past.</p>
    </div>
  </div>
</section>

<section class="section">
  <div class="wrap legal">

    <p class="legal-meta">Version <?= e($version) ?> · This notice applies to the <?= e(brand('academy')) ?> website and the course registrations made through it.</p>

    <div class="legal-draft">
      <strong>Draft for approval.</strong> This notice is accurate about how the system works,
      but it has not yet been approved by <?= e(brand('company_short')) ?> or by Centenary Networks, and the three items
      marked <em>to confirm</em> below are outstanding. It should not be treated as final until
      that is done.
    </div>

    <h3>Who is responsible for your information</h3>
    <p>Two organisations are involved, and under the Protection of Personal Information Act 4 of 2013 (POPIA) they have different roles.</p>
    <ul>
      <li><strong><?= e(brand('company')) ?></strong> is your employer and the <em>responsible party</em>. It decides that this training happens and why.</li>
      <li><strong>Centenary Networks (Pty) Ltd</strong> runs the academy and this website on <?= e(brand('company_short')) ?>'s behalf, which makes it an <em>operator</em>. Centenary is also an accredited Skills Development Provider, and in that role it keeps the learner records the Quality Council for Trades and Occupations (QCTO) requires it to keep.</li>
    </ul>
    <p class="legal-tbc"><strong>To confirm:</strong> the name and contact details of the Information Officer appointed by <?= e(brand('company_short')) ?>, and confirmation that Centenary Networks' Information Officer is registered with the Information Regulator.</p>

    <h3>What we collect</h3>
    <p>When you register your interest in a course, we collect only what is on the form: your name, work email address, and — if you choose to give them — your contact number, employee number, department, line manager's name, the course you are interested in, and anything you write in the message box.</p>
    <p>We also record the date and time, and a one-way scrambled version of your internet address. We keep the scrambled version rather than the address itself so that we can spot a flood of automated submissions without holding information that identifies your connection.</p>
    <p>We do <strong>not</strong> collect your ID number at this stage. It is needed later to register you with the QCTO for an accredited qualification, and we will ask for it then, separately, and tell you why.</p>

    <h3>If you are given an academy account</h3>
    <p>When you are enrolled on a course, the academy creates a sign-in for you. It holds your name, work email address, and your employee number and department if you gave them on the registration form — nothing that was not already on that form. Accounts are created by the academy; you cannot make one yourself.</p>
    <p>Once you are signed in, the topics you tick off as you study are saved <strong>to your account</strong> rather than to the computer you are using, together with the date you ticked each one. That is what lets your progress follow you to another device, and what stops the next person on a shared machine seeing your record. Before you sign in — and if you never do — those ticks stay in your browser and reach us only if you send a progress report.</p>
    <p>What you tick is <strong>your own account of what you have studied</strong>. It is not a mark, and it is not an assessment result: being found competent is Centenary's decision after assessment, and the qualification is awarded by the QCTO after the external assessment.</p>
    <p>The academy can see your ticks and their dates, and uses them to know who needs help and to report on how an intake is going. Your line manager sees them only in a progress report that you send.</p>
    <p>Some modules also carry a short <strong>self-check quiz</strong> — multiple choice,
      marked automatically, that you can take as many times as you like. We keep your best
      score and how many times you attempted it, in the same way and for the same reason as
      your topic ticks: it is your own study record, <strong>not a mark and not an assessment
      result</strong>. Being found competent is still Centenary's decision after the real
      assessment, and the qualification is still the QCTO's after the EISA — a quiz score here
      does not change that.</p>
    <h3>Course material, and the record of opening it</h3>
    <p>Most guides, workbooks and recordings are held in <strong>Centenary Networks' Google
      Workspace</strong>, and this site holds only a link to each one — when you open one of
      those you are taken to Google, and Google's own terms and privacy notice apply to what
      happens there. Where the academy holds a file directly instead, this server stores and
      sends it to you itself, with the same access rule either way: only to a learner who is
      signed in and enrolled on that course.</p>
    <p>We record <strong>which material you opened and when</strong>. We use it for two things:
      to notice a learner who has not been able to get started so somebody can help, and to
      evidence to the QCTO that material was made available to you. It is not a mark and it is
      not an assessment.</p>
    <p>The links we give you are for you, whether they point at Google or at a file this server
      holds — the page you see does not contain the underlying Google address, or a direct path
      to a stored file. Please do not forward them: anyone who has a Google link can open that
      file, so passing one on shares Centenary's material with somebody who has not been
      enrolled.</p>

    <p>If you ask for a password reset, we email a one-time link to the address on your account and keep a record that a reset was asked for and when. We do not keep the link itself, only a scrambled version of it, so nobody — including us — can read it back out of our records. The link stops working after an hour or after you use it, whichever comes first.</p>

    <h3>Why we need it, and on what basis</h3>
    <p>We use it to contact you about the course, to confirm your place on an intake, and — if you go on to enrol in an accredited qualification — to register and assess you for it. We rely on your consent, which you give by ticking the box on the registration form, and on the fact that this processing is necessary to deliver training your employer has arranged.</p>
    <p>We do not use your details for marketing, we do not sell them, and we do not use them for anything other than the training you registered for.</p>

    <h3>Who else sees it</h3>
    <ul>
      <li><strong><?= e(brand('company_short')) ?> HR</strong>, who confirm your place and arrange cover with your manager.</li>
      <li><strong>Centenary Networks</strong>, as the training provider.</li>
      <li>For accredited qualifications only: the <strong>QCTO</strong> and the relevant SETA, because a qualification cannot be registered against your name without them being told your name. This is a legal requirement, not a choice either of us makes.</li>
    </ul>

    <h3>Where it is kept</h3>
    <p>On a server in <strong>Johannesburg, South Africa</strong>. Your information is not transferred out of the country. That is a deliberate choice: it means the protections of POPIA apply to it directly, without relying on an assessment of another country's laws.</p>
    <p>The connection to this site is encrypted, passwords are stored in a form that cannot be reversed, and access to the records is logged.</p>

    <h3>How long we keep it</h3>
    <p>If you register your interest and do not go on to enrol, we delete your registration <strong>twelve months</strong> after you sent it. That is long enough to cover the next intake and the one after it.</p>
    <p>If you do enrol in an accredited qualification, your learner record — your account, your enrolment, the progress recorded against it, and any self-check quiz attempts — has to be kept for as long as the QCTO requires. A qualification that cannot be evidenced is a qualification you cannot prove you hold. That period is longer, and it is not something we can shorten at your request.</p>
    <p>You can clear your own recorded progress at any time from the progress report page. Doing so deletes those ticks and does not keep a copy.</p>
    <p class="legal-tbc"><strong>To confirm:</strong> the exact retention period required by the QCTO for learner and assessment records.</p>

    <h3>What you can ask us to do</h3>
    <p>Under POPIA you may ask us to:</p>
    <ul>
      <li>tell you what we hold about you, and give you a copy;</li>
      <li>correct anything that is wrong;</li>
      <li>delete it, where we are not obliged to keep it;</li>
      <li>stop using it, where we are relying on your consent — you can withdraw that consent at any time, though we may then be unable to place you on a course.</li>
    </ul>
    <p>Ask by emailing <a href="mailto:<?= e(brand('academy_email')) ?>"><?= e(brand('academy_email')) ?></a>. We will respond within a reasonable time, and we will not charge you for a first request.</p>

    <h3>If you are not satisfied</h3>
    <p>Tell us first — most things are a misunderstanding and are quicker to fix directly. If that does not resolve it, you have the right to complain to the Information Regulator of South Africa, at <a href="https://inforegulator.org.za" target="_blank" rel="noopener">inforegulator.org.za</a>.</p>

    <h3>Changes to this notice</h3>
    <p>If we change how we handle your information, we will change this notice and give it a new version number. Every consent we hold is recorded against the version that was on screen at the time, so you can always find out exactly what you agreed to.</p>

  </div>
</section>

<?php chrome_footer('site', ['accred' => false, 'employees' => true]); ?>
<script src="<?= e(asset('site.js')) ?>"></script>
</body></html>
