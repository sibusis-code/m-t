/* ---- Meet the trainers ---------------------------------------------------
   The specialists who deliver the skills courses. Everything the page shows
   comes from the TRAINERS list below — there is no other place to edit, and no
   back end behind this page.

   WHY THIS PAGE EXISTS
   --------------------
   Kgomotso, 22 Aug 2026, setting up the procurement course: "You will ask both
   for their CV and or maybe use their linkedin profiles under meet the
   trainers." So the page is her instruction, not an invention, and the two
   people she named are the two entries below.

   These are CENTENARY'S specialists, not any one client's — the same people
   teach on every academy. That is why this file is on the sync manifest in
   tools/sync-backend.php, exactly like graduates.js: one list, four sites, no
   argument about who has agreed to what. trainers.html is NOT synced, because
   like every other .html it carries its own site's chrome.

   REVIEW MODE
   -----------
   Set REVIEW = true to put the page into preview: it renders everyone
   regardless of consent, shows an orange "not for sharing" banner, and injects
   a noindex tag. Turning it off publishes only the people with consent:true, so
   it fails closed — an uncleared entry left behind disappears rather than going
   public. This is the same switch, and the same wiring, as graduates.js.

   IT WAS TRUE while the page was being built and reviewed locally, and went
   FALSE on 24 Aug 2026 the moment these sites were deployed. That is the whole
   point of the switch: REVIEW renders everyone regardless of consent, which is
   exactly right on a laptop and exactly wrong on a live client site linked from
   every page's nav.

   Turning it back on is a LOCAL preview action. If you turn it on, do not
   deploy until you have turned it off again.

   ADDING SOMEONE
   --------------
     name      Their name as they want it written.
     role      What they teach, in a few words. "Procurement and sourcing".
     org       Their title and company, if they have one worth naming.
     creds     Qualifications, as a short string. Optional.
     teaches   Course slugs they deliver — each becomes a link to the course.
     bio       Two or three sentences of professional history. Not a CV dump.
     linkedin  Full profile URL. Optional; Kgomotso suggested these as an
               alternative to a CV where somebody would rather not send one.
     photo     A file in images/trainers/. Optional — without it the card shows
               their initials, which looks deliberate rather than broken, and a
               photo that 404s falls back to the same initials.
     pending   true while we are still waiting for their profile. The card says
               so plainly instead of carrying invented copy.
     consent   MUST be true to be published. See below.

   THE consent FIELD IS NOT A FORMALITY
   ------------------------------------
   A name, a photograph and a named employer together are personal information
   under POPIA, and this page is public on five domains. There is a second
   problem on top of the first: listing somebody as "your trainer" asserts a
   commercial relationship. If the engagement is not agreed, that assertion is
   not ours to publish, however flattering it is to both sides.

   Set consent:true only once that person has agreed to their name and
   professional history appearing on a public web page, AND the engagement is
   actually agreed.

   BOTH ARE TRUE AS OF 24 AUG 2026. Sibusiso: they can be named, and both are
   highly interested in joining the programme. That is what turned the two
   flags below from false to true.

   PHOTOGRAPHS: both arrived and were cleared on 25 Aug 2026, so this is settled
   for the two published trainers. Taryn McCormick's is in the folder too, but a
   file arriving is not the same as clearance — see her entry. Anyone without a
   file falls back to initials, which looks deliberate rather than broken. Drop
   a file into images/trainers/ and set the `photo` key; the note in that folder
   has the naming, and asks you to check they are happy with THAT photograph
   rather than with "a photo".

   CONSENT ALONE IS NOT ENOUGH TO PUBLISH
   --------------------------------------
   A trainer also needs something worth reading. `pending` marks somebody we
   have permission to name but nothing yet to say about — and a card carrying a
   name and no substance invites "who is this?" on a client's website, which is
   worse for that person than not being listed for another week. So the filter
   below is consent AND NOT pending, and Fiston appears the moment his profile
   arrives without anyone having to remember a second switch.

   DO NOT PARK AN UNCLEARED PERSON IN THIS FILE
   --------------------------------------------
   A consent:false entry does not render — but THIS FILE IS SERVED PUBLICLY, at
   /trainers.js, on four academy sites. A name, an employer, qualifications and
   a bio describing somebody as one of our trainers are all readable there
   whether or not a card is drawn. consent:false stops the page from asserting
   it; it does not stop anyone reading it.

   So a person we have not cleared does not go in this array at all, not even
   switched off. Their drafted record waits in _drafts/, which is excluded from
   the deploy mirror, from make-deploy-zip.php, from .htaccess and from the dev
   server, and it moves in here in the same edit that sets consent:true.

   That road runs both ways, and a record has gone back down it — on 10 Sep 2026,
   for a trainer whose contract is not signed yet. This note used to name the
   person it was describing, which was fine while they were published here and
   is not fine now: saying in a public file that a named individual is waiting
   in our drafts folder is the same disclosure the paragraph above is about.
   Who it is, and how to put them back, is in _drafts/ where it cannot be read
   from the web.

   The same reasoning is why an uncleared photograph gets a RewriteRule in
   .htaccess rather than just being left unlinked: the deploy mirrors images/
   wholesale. Unlinked is not unpublished, in either file.

   WHAT WE DO NOT PUBLISH, EVER
   ----------------------------
   Tarryn's covering email to Kgomotso describes her as a Black female
   professional who is ADHD specially abled. None of that is on this page and
   none of it belongs here. It was written to one person, in confidence, to make
   a case for herself — health information and demographic detail do not become
   publishable because they arrived in an email we were forwarded. If she wants
   any of it on her own profile, she can say so herself.

   The same line applies to the CV she sent: the client savings figures, the
   schooling and the personal section stay off. A profile deck is written to be
   shown. A CV is sent to be read.
   -------------------------------------------------------------------------- */
(function () {

  /* Preview switch. See "REVIEW MODE" in the note above before changing this.
     OFF since 24 Aug 2026, because the four sites went live that day. */
  var REVIEW = false;

  var TRAINERS = [

    /* Procurement. Her Waria Consulting profile deck and CV came to us from
       Kgomotso on 22 Aug 2026. The bio below is drawn from the deck — a
       marketing document, written to be shown — plus the qualifications page of
       the CV, and nothing else.

       Cleared to publish 24 Aug 2026.

       Photograph supplied and cleared for use, 25 Aug 2026. */
    {
      name: 'Tarryn Norris',
      role: 'Procurement, sourcing and supplier development',
      org: 'Managing Director, Waria Consulting (Pty) Ltd',
      creds: 'MCIPS · B.Com Business Management & Marketing · MBA (Supply Chain), in progress',
      teaches: [['procurement', 'Procurement Skills']],
      bio: 'Twenty years in procurement, sourcing and operations across financial services ' +
           'and insurance — Hollard, Absa, First National Bank and Standard Bank — covering ' +
           'strategic sourcing, category management, tenders, contracts and supplier ' +
           'relationship management. She founded Waria Consulting in 2017 and now consults ' +
           'on procurement strategy and supplier development. Her own record includes ' +
           'building a black-owned and black-woman-owned supplier base across a bank’s ' +
           'marketing categories, and designing an enterprise development programme for ' +
           'new-entrant suppliers — which is the ground this course covers for people ' +
           'working for service providers.',
      photo: 'images/trainers/tarryn-norris.jpg',
      linkedin: '',          // ask her for the URL, or leave it off
      consent: true
    },

    /* Technical and artisan training. Kgomotso, 24 Aug 2026: "Add Fiston as the
       subject matter trainer under our Team."

       Everything below comes from HIS OWN EMAIL SIGNATURE, sent to that thread
       on 24 Aug 2026 — name, title, company, qualifications, ECSA registration.
       He chose to put those in writing, which is a very different thing from us
       going and collecting them.

       ⚠ DO NOT USE HIS LINKEDIN. Kgomotso suggested taking his LinkedIn photo
       and profile; he replied to the same thread, to all of us: "Please allow me
       to submit a different one." He then did, and THAT is the photograph below,
       cleared for use 25 Aug 2026. Nothing here came from LinkedIn and nothing
       should. His own written profile is still to come — replace the summary
       below with it when it arrives.

       There is no narrative bio here on purpose. He has asked to write his own,
       and a bio we composed about a man who is in the middle of sending us his
       would be rude as well as redundant. What is below is a factual placement:
       who he is and what his organisation does, no more.

       He is no longer `pending` — the programme menu is real, published at
       /programmes, and it is what he is the subject matter trainer for. */
    {
      name: 'Fiston Nselike',
      role: 'Technical, artisan and renewable energy training',
      org: 'Chief Executive Officer, Fisha Renaissance NPC',
      creds: 'ECSA Pr. Eng. Tech · BTech Electrical Engineering · MBA · Postgrad in Business Management',
      teaches: [['programmes', 'Technical & Artisan Programmes']],
      bio: 'Chief Executive of Fisha Renaissance NPC, a Johannesburg training organisation ' +
           'working across artisan development, electrical compliance, renewable energy, ' +
           'welding and fabrication, plumbing, and workplace and employability programmes. ' +
           'Registered with the Engineering Council of South Africa as a Professional ' +
           'Engineering Technologist.',
      photo: 'images/trainers/fiston-nselike.jpg',
      linkedin: '',          // NOT to be filled from LinkedIn — see the note above
      consent: true
    },

    /* ⚠ TARYN McCORMICK IS NOT TARRYN NORRIS. Two different people, and the
       first names differ by one letter — Taryn / Tarryn. Tarryn Norris is the
       procurement trainer at the top of this file, Managing Director of Waria
       Consulting. Taryn McCormick is HR by profession and teaches wellness and
       the Project Manager qualification. They share no employer, no subject and
       no photograph. Before editing either record, check which one you have.

       Added 25 Aug 2026, on Kgomotso's instruction relayed through the client:
       "Taryn McCormick, wellness teacher, HR by profession, she also does
       wellness and she will teach project manager." The three facts below are
       exactly that sentence and nothing more has been added to them — no
       employer, no qualifications, no narrative. Same discipline as Fiston's
       entry: a factual placement is honest, an invented profile is not.

       PHOTOGRAPH supplied by the client on 25 Aug 2026 while this record was
       being written, renamed to the convention in images/trainers/README.md.

       CLEARED TO PUBLISH 25 Aug 2026 — the client confirmed consent covering
       both being named and this photograph, which is what that folder's README
       asks for ("not just for a photo — it is her face on five public
       websites"). That is what turned consent from false to true and removed
       pending. The .htaccess rule that was holding the image back came out at
       the same moment: leaving it would have rendered a broken image rather
       than falling back to her initials.

       STILL THIN, DELIBERATELY. We have no employer and no qualifications for
       her, so `org` and `creds` are empty and the card simply omits both — the
       renderer drops them rather than leaving a gap. Her card is therefore
       shorter than Tarryn's and Fiston's, who carry an MCIPS and an ECSA
       registration. That is a fair reflection of what we actually know and not
       something to paper over: fill the two fields in when they arrive, and do
       not invent them meanwhile.

       Her wellness teaching has nowhere to point yet: there is no wellness
       course record, and the Wellness & Health School on /courses currently
       holds the three AI in Medicine courses and nothing else. `teaches` below
       therefore links only the qualification, which does exist. Add the wellness
       course first; do not invent a slug for it here. */
    {
      name: 'Taryn McCormick',
      role: 'Wellness, and human resources',
      org: '',               // to be supplied — see note above
      creds: '',             // to be supplied — see note above
      teaches: [['project-management', 'Occupational Certificate: Project Manager']],
      bio: 'A human resources professional by background, teaching wellness and the ' +
           'Occupational Certificate: Project Manager.',
      photo: 'images/trainers/taryn-mccormick.jpg',
      linkedin: '',
      consent: true          // confirmed 25 Aug 2026 — name and photograph both
    },
    /* Artificial intelligence, and the platform the academies themselves run on.

       Sibusiso asked for this card on 10 Sep 2026, relaying Kgomotso: he is to
       be the one teaching AI across the academies, and his picture should go up
       with the rest.

       CONSENT IS NOT THE USUAL QUESTION HERE. Everybody else on this list was
       cleared by somebody on their behalf, which is why each entry carries a
       date and a source. This one is the person himself asking to be listed,
       and supplying his own photograph to do it with. Recorded the same way
       regardless, because a card that says who cleared it is the point.

       WHAT IS HIS AND WHAT IS NOT. The role, the titles and the qualification
       below are his own words, and so is the employer: he confirmed Centenary
       Networks on 10 Sep 2026, which is why the org line names it now and did
       not before.

       HE DOES NOT OWN ANY OF IT, and the bio said otherwise for half a day. It
       read that the catalogue, the learner accounts, the course material and the
       quizzes "are all his", which is true of the software and false of
       everything else in that list. He is employed by Centenary Networks; the
       material belongs to whoever wrote it, and the learner accounts belong to
       the academy and the people in them. He asked for it to say he built the
       platform, and nothing more. Keep it that way: on a page whose whole job
       is who may be named and on what basis, a sentence handing one person the
       client's course material and their learners is the wrong kind of wrong.

       THE PHOTOGRAPH is his own studio portrait, cropped to head-and-shoulders
       to match the other three and the 180px card column: the original is
       full-length, and at that crop his face would have been about a tenth of
       the frame. The full-length original is in private/trainers/, so the crop
       can be redone without asking him for the file again. */
    {
      name: 'Sibusiso Seopela',
      role: 'Artificial intelligence, and software development',
      org: 'Software Developer, IT Lead and Academies Developer · Centenary Networks',
      creds: 'Diploma in Information Technology (NQF 6) · Four years in software development',
      teaches: [
        ['ai-fundamentals', 'AI Fundamentals for the Workplace'],
        ['ai-tools-productivity', 'AI Tools for Productivity'],
        ['responsible-ai', 'Responsible & Ethical AI Use'],
        ['ai-leaders', 'AI for Leaders & Managers'],
        ['ai-software-development', 'AI & Software Development']
      ],
      bio: 'A software engineer working in AI, and the developer of the academy ' +
           'platform: he builds and maintains the sites the academies run on. He ' +
           'holds a Diploma in Information Technology ' +
           'at NQF 6 and four years in software development, and he teaches the AI line ' +
           'across the academies: what the tools actually do, how to use them in the ' +
           'working day, and how to build with them.',
      photo: 'images/trainers/sibusiso-seopela.jpg',
      linkedin: '',
      consent: true          // 10 Sep 2026 — he asked for the card himself
    }
  ];

  /* Who may actually appear on a public page. Consent, and something to say.
     See the two notes above for why both halves are needed. */
  var shown = TRAINERS.filter(function (t) {
    return t.name && (REVIEW || (t.consent === true && !t.pending));
  });

  /* THE SINGLE SOURCE OF TRUTH FOR "MAY WE NAME THIS PERSON".
     -------------------------------------------------------
     course.html has its own facilitator panel, and until 24 Aug 2026 it had its
     own copy of the answer — which meant Tarryn's name, employer and
     qualifications went live on the course page while this page was still
     correctly publishing nobody. One consent decision in two places is not a
     consent decision, it is a bug waiting for the day somebody withdraws.

     So the list is published here and course.html looks people up in it. If the
     script has not loaded, the lookup finds nothing and the course page falls
     back to its "the academy team" wording — it fails closed, which is the only
     acceptable direction for this particular check. */
  window.ACADEMY_TRAINERS = shown.map(function (t) {
    return { name: t.name, role: t.role, org: t.org, creds: t.creds };
  });

  var host = document.getElementById('trainers');
  if (!host) return;

  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  /* Initials, for anyone without a photograph. Two letters at most: three
     initials in a small circle is a smudge. */
  function initials(name) {
    return String(name).trim().split(/\s+/).slice(0, 2)
      .map(function (w) { return w.charAt(0).toUpperCase(); }).join('');
  }

  /* A photo that 404s must not leave a broken image on a client's site, so the
     img removes itself and hands over to the initials block sitting behind it. */
  function face(t) {
    var ini = '<span class="tr-ini" aria-hidden="true">' + esc(initials(t.name)) + '</span>';
    if (!t.photo) return ini;
    return ini + '<img class="tr-img" loading="lazy" alt="' + esc(t.name) + '"' +
           ' src="' + esc(t.photo) + '" onerror="this.remove()">';
  }

  function card(t) {
    var teaches = (t.teaches || []).map(function (c) {
      /* Most trainers teach a course, which lives at course?c=<slug>. Fiston's
         entry points at the partner menu instead, which is a page of its own.
         A slug with no slash is a course; anything else is taken as a path. */
      var href = c[0].indexOf('/') < 0 && c[0] !== 'programmes'
        ? 'course?c=' + esc(c[0])
        : esc(c[0]);
      return '<a class="tr-course" href="' + href + '">' + esc(c[1]) + '</a>';
    }).join('');

    var body = t.pending
      ? '<p class="tr-pending">Profile still to come — we have asked for a CV or a ' +
        'LinkedIn profile.</p>'
      : (t.bio ? '<p class="tr-bio">' + esc(t.bio) + '</p>' : '');

    return '<article class="trainer">' +
      '<span class="tr-shot">' + face(t) + '</span>' +
      '<div class="tr-body">' +
        '<h3>' + esc(t.name) + '</h3>' +
        (t.role ? '<p class="tr-role">' + esc(t.role) + '</p>' : '') +
        (t.org ? '<p class="tr-org">' + esc(t.org) + '</p>' : '') +
        (t.creds ? '<p class="tr-creds">' + esc(t.creds) + '</p>' : '') +
        body +
        (teaches ? '<div class="tr-courses"><span>Teaches</span>' + teaches + '</div>' : '') +
        (t.linkedin
          ? '<a class="tr-link" href="' + esc(t.linkedin) + '" target="_blank" ' +
            'rel="noopener noreferrer">LinkedIn profile</a>'
          : '') +
      '</div>' +
    '</article>';
  }

  /* Nobody cleared yet, and not in preview. Says what is true rather than
     pretending the page is coming soon. */
  if (!shown.length) {
    host.innerHTML =
      '<div class="grad-empty">' +
        '<div>' +
          '<strong>No trainer profiles are published yet</strong>' +
          '<p>Our skills courses are delivered by specialists from Centenary’s network. ' +
            'Naming somebody here means putting their name and professional history on a ' +
            'public page, which we do not do before they have agreed to it.</p>' +
        '</div>' +
        '<a href="contact" class="btn btn-primary">Ask about a course</a>' +
      '</div>';
    return;
  }

  var html = '';

  if (REVIEW) {
    /* The static noindex lives in trainers.html — but this file is on the sync
       manifest and that file is not, so a sync would carry uncleared names to
       three more public sites and leave the protection behind. Put it back from
       here if it is missing. A tag added by script is weaker than one in the
       source, which is why trainers.html still has its own; this is the
       backstop, not the plan. */
    if (!document.querySelector('meta[name="robots"]')) {
      var m = document.createElement('meta');
      m.name = 'robots';
      m.content = 'noindex, nofollow';
      document.head.appendChild(m);
    }

    html +=
      '<div class="grad-review">' +
        '<strong>Internal preview — not for sharing yet</strong>' +
        '<p>This is how the page will look. Neither specialist has been asked whether ' +
          'their name and professional history may appear on four public academy ' +
          'websites, and neither engagement is signed — so nothing here is approved for ' +
          'publication, and the page is hidden from search engines until it is.</p>' +
        '<p>What is still needed: a yes from each of them, the engagement confirmed, ' +
          'Fiston’s surname and profile, and a photograph each if they want one.</p>' +
      '</div>';
  }

  host.innerHTML = html + '<div class="trainergrid">' + shown.map(card).join('') + '</div>';
})();
