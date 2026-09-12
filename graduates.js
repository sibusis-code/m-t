/* ---- Where our learners are now -----------------------------------------
   The graduates page. Everything it shows comes from the two lists below —
   there is no other place to edit, and no back end behind this page.

   WHO SAID THESE NINE COULD BE PUBLISHED, AND WHEN
   ------------------------------------------------
   Confirmed 20 Aug 2026 by the site owner. These eighteen are the second cohort
   of the Bubble G.U.M project, which runs under Centenary Networks and is led by
   Kgomotso Moloantoa. They already appear under their own names and photographs
   in Centenary's own published magazine and cohort deck, which is where both
   the pictures and the titles on this page come from. Publishing them here is
   Centenary republishing its own material about its own learners.

   WHERE THE PICTURES CAME FROM
   ----------------------------
   Straight out of the deck, which is a .pptx and therefore a zip: the originals
   are in ppt/media/. Slides 12 and 14 carry the two halves of the cohort, and
   slide 11 the Dartcom group photograph. They run from 366px to 2131px wide, so
   the cards are sharp on a phone.

   The first version of this page used crops taken from a screenshot of the
   slides at 167x150px, because the deck had not been found yet. If more cohorts
   arrive as screenshots, ask for the deck before cropping anything: it is the
   same pictures at ten times the resolution and it carries the names as text
   rather than as pixels to be read by eye.

   Slide 13 has five more photographs with no names on them at all, and slide 15
   a second group shot. Neither is used: an unnamed face on a public page is
   somebody who cannot be told they are on it, and cannot ask to come off.

   That is the record. If anyone ever asks on what basis these names are on a
   public website, this paragraph is the answer, and it is deliberately specific
   about who confirmed it and on what day.

   REVIEW is therefore false and the page is live. It was true for one round so
   the layout could be looked at before anything was published.

   Anyone who asks to come off comes off the same day — see the last paragraph
   of this note. That obligation does not go away because a magazine exists.

   REVIEW MODE
   -----------
   Set REVIEW = true to put the page back into preview: it renders everyone
   regardless of consent, shows an orange "not for sharing" banner, and injects
   a noindex tag. Use it when adding a cohort nobody has cleared yet. Turning it
   off again publishes only the people with consent:true, so it fails closed —
   an uncleared entry left behind disappears rather than going public.

   ADDING SOMEONE
   --------------
     name      Their name as they want it written.
     role      Their trade or job title. "Electrical Technician".
     cohort    Which COHORTS id they belong to. Unknown or missing puts them
               in a plain grid at the end, which is untidy but not broken.
     kind      'graduate' or 'intern'. Only changes the little label.
     course    What they did with Centenary. Free text.
     year      When they finished. A string, so "2024" or "2023-24" both work.
     now       Where they are today — one sentence, in plain words.
                 "Site supervisor at Blue Falcon Energy"
                 "Founded Motsepe Electrical, now employing four people"
               THIS IS THE FIELD THAT MATTERS. Kgomotso asked for the people
               who got jobs and started companies; a job title alone does not
               answer that. Where `now` is set it replaces the role on the card.
     photo     A file in images/graduates/. Optional — leave it out and the
               card shows their initials, which looks deliberate rather than
               broken. A photo that fails to load falls back to the same
               initials, so a missing file cannot put a broken image on a
               client's site.
     consent   MUST be true to be published. See below.

   THE consent FIELD IS NOT A FORMALITY
   ------------------------------------
   A name, a photograph and an employer together are personal information under
   POPIA, and this page is public — Google will index it, and it will outlive
   the person's interest in being on it. So we do not publish anyone who has not
   said yes.

   Set consent:true only once that person has actually agreed to their name,
   photo and current employer appearing on a public web page — or, as with the
   2022 cohort above, once they are already published under their own name in
   Centenary's own material and the owner of that material has confirmed it. An
   email saying "yes, happy for you to use it" is enough; a verbal "sure" during
   a call is not, because in a year nobody will remember it. Whatever the basis
   is, write it down at the top of this file the way the 2022 one is written
   down, naming who confirmed it and when.

   Anyone who asks to come off comes off the same day. Delete their entry AND
   their photograph from images/graduates/ — do not merely set consent:false,
   or their details stay in the page source for anyone who looks. */
(function () {
  'use strict';

  /* Preview switch. See "REVIEW MODE" in the note above before changing this. */
  var REVIEW = false;

  /* A cohort is a group who came through together. `photo` and `stats` are
     optional: a cohort with them gets the wide panel, a cohort without gets
     just a heading over its faces. */
  var COHORTS = [
    {
      id: '2021',
      eyebrow: 'First cohort · 2021–2024',
      title: 'Apprenticeship & Mentorship',
      blurb: 'Three years, one intake, and every one of them qualified. They went ' +
             'into the trade on a starting salary of R16 000 a month.',
      photo: 'images/graduates/cohort-2021-dartcom.jpg',
      alt: 'The first Centenary Networks apprenticeship cohort outside Dartcom',
      stats: [
        { n: '100%',    l: 'Pass rate' },
        { n: 'R16k',    l: 'Starting salary' },
        { n: '2021–24', l: 'Apprenticeship' }
      ]
    },
    /* The deck splits the second cohort in two and gives each half its own
       heading, so the page does too. The difference is real: the first nine
       have trade titles and are working, the second nine are named by the
       field they are training in. Collapsing them into one grid would lose
       that. "Trades-preneurs" is Centenary's own word for the first group,
       off slide 12 — a trade plus the business skill to sell it. */
    {
      id: '2022-trades',
      eyebrow: 'Second cohort · electrical & civil',
      title: 'Trades-preneurs'
    },
    {
      id: '2022-learners',
      eyebrow: 'Second cohort · electrical & civil',
      title: 'Learners'
    }
  ];

  var PEOPLE = [
    /* The 2022 intake — the Bubble G.U.M project's second cohort. Names, trades
       and photographs are lifted from Centenary's own cohort deck; see the note
       at the top of this file for the basis on which they are published.

       `now` is still absent on all nine. Nobody has told us where they are
       working today, and that — not a trade title — is what Kgomotso asked this
       page for. Centenary's learner magazine already carries each one's course
       journey and outcome, so the answers exist; they just have not reached
       this file yet. Fill `now` in and the card says something worth reading. */
    /* Slide 12 — the trades-preneurs, in the order the deck lays them out. */
    { name: 'Langelihle Ngidi', role: 'Electrical Technician', cohort: '2022-trades',
      photo: 'images/graduates/langelihle-ngidi.jpg', consent: true },
    { name: 'Sandiso Mthiyane', role: 'Electrical Technician', cohort: '2022-trades',
      photo: 'images/graduates/sandiso-mthiyane.jpg', consent: true },
    { name: 'Sibusiso Seopela', role: 'Field Technician', cohort: '2022-trades',
      photo: 'images/graduates/sibusiso-seopela.jpg', consent: true },
    { name: 'Tshepo Thobejane', role: 'Electrical Technician', cohort: '2022-trades',
      photo: 'images/graduates/tshepo-thobejane.jpg', consent: true },
    { name: 'Koketso Mokgethi', role: 'Electrical Technician', cohort: '2022-trades',
      photo: 'images/graduates/koketso-mokgethi.jpg', consent: true },
    { name: 'Thabelo Ndou', role: 'Electrical Technician', cohort: '2022-trades',
      photo: 'images/graduates/thabelo-ndou.jpg', consent: true },
    { name: 'Khotso Moloantoa', role: 'Electrical Technician', cohort: '2022-trades',
      photo: 'images/graduates/khotso-moloantoa.jpg', consent: true },
    { name: 'Vuyo Matanga', role: 'Field Technician', cohort: '2022-trades',
      photo: 'images/graduates/vuyo-matanga.jpg', consent: true },
    { name: 'Nandipha Sithole', role: 'Metering Technician', cohort: '2022-trades',
      photo: 'images/graduates/nandipha-sithole.jpg', consent: true },

    /* Slide 14 — the learners. The deck labels these nine by the field they are
       training in ("Profession: Civil Engineering") rather than by a job title,
       which is why their cards read differently from the nine above. That is
       the source's distinction, not ours, so it is kept.

       Face-to-name pairing was taken from the slide GEOMETRY, not from the
       order the shapes happen to sit in the file — PowerPoint stores shapes in
       z-order, and on this slide the caption order is not the reading order.
       Zipping the two lists together would have put four of these nine faces
       under the wrong person's name. */
    { name: 'Portia Mokgomogane', role: 'Civil Engineering', cohort: '2022-learners',
      photo: 'images/graduates/portia-mokgomogane.jpg', consent: true },
    { name: 'Pfukani Baloyi', role: 'Civil Engineering', cohort: '2022-learners',
      photo: 'images/graduates/pfukani-baloyi.jpg', consent: true },
    { name: 'Ndumiso Sithebe', role: 'Electrical Engineering', cohort: '2022-learners',
      photo: 'images/graduates/ndumiso-sithebe.jpg', consent: true },
    { name: 'Muvhoni Tshisimni', role: 'Civil Engineering', cohort: '2022-learners',
      photo: 'images/graduates/muvhoni-tshisimni.jpg', consent: true },
    { name: 'Nonjabulo Khanile', role: 'Electrical Engineering', cohort: '2022-learners',
      photo: 'images/graduates/nonjabulo-khanile.jpg', consent: true },
    { name: 'Nhlavutelo Makhuvele', role: 'Civil Engineering', cohort: '2022-learners',
      photo: 'images/graduates/nhlavutelo-makhuvele.jpg', consent: true },
    { name: 'Layer Mathebula', role: 'Civil Engineering', cohort: '2022-learners',
      photo: 'images/graduates/layer-mathebula.jpg', consent: true },
    { name: 'Zanele Tambani', role: 'Electrical Engineering', cohort: '2022-learners',
      photo: 'images/graduates/zanele-tambani.jpg', consent: true },
    { name: 'Murendeni Netshikhudini', role: 'Electrical Engineering', cohort: '2022-learners',
      photo: 'images/graduates/murendeni-netshikhudini.jpg', consent: true }
  ];

  var host = document.getElementById('grads');
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
  function face(p) {
    var ini = '<span class="face-ini" aria-hidden="true">' + esc(initials(p.name)) + '</span>';
    if (!p.photo) return ini;
    return ini + '<img class="face-img" loading="lazy" alt="' + esc(p.name) + '"' +
           ' src="' + esc(p.photo) + '" onerror="this.remove()">';
  }

  function card(p) {
    var line = p.now || p.role || p.course || '';
    return '<figure class="face">' +
      '<span class="face-shot">' + face(p) + '</span>' +
      '<figcaption>' +
        '<strong>' + esc(p.name) + '</strong>' +
        (line ? '<span>' + esc(line) + '</span>' : '') +
        (p.now && p.role ? '<em>' + esc(p.role) + '</em>' : '') +
      '</figcaption>' +
    '</figure>';
  }

  function cohortPanel(c) {
    if (!c.photo && !c.stats) return '';
    var stats = (c.stats || []).map(function (s) {
      return '<div><strong>' + esc(s.n) + '</strong><span>' + esc(s.l) + '</span></div>';
    }).join('');
    return '<div class="cohort"><div class="cohort-top">' +
      (c.photo ? '<img src="' + esc(c.photo) + '" alt="' + esc(c.alt || c.title) + '">' : '') +
      '<div class="cohort-body">' +
        (c.eyebrow ? '<span class="eyebrow">' + esc(c.eyebrow) + '</span>' : '') +
        '<h3>' + esc(c.title) + '</h3>' +
        (c.blurb ? '<p>' + esc(c.blurb) + '</p>' : '') +
        (stats ? '<div class="cohort-stats">' + stats + '</div>' : '') +
      '</div>' +
    '</div></div>';
  }

  var shown = PEOPLE.filter(function (p) {
    return p.name && (REVIEW || p.consent === true);
  });

  /* Nothing to show at all — no consented people and no cohort panel. Deliberately
     not apologetic and not a fake "coming soon" teaser: it says what is true and
     what somebody reading it can do about it. */
  var panels = COHORTS.map(cohortPanel).join('');
  if (!shown.length && !panels) {
    host.innerHTML =
      '<div class="corp" style="margin-top:0">' +
        '<div>' +
          '<span class="tag-new">In progress</span>' +
          '<h3>We are still collecting these</h3>' +
          '<p>Centenary Networks has trained and placed people since long before this ' +
            'academy existed. We are going back to them one by one and asking permission ' +
            'to put their name and photograph on a public page, which is not something we ' +
            'will do without being asked first.</p>' +
          '<p style="margin-top:10px">Came through Centenary, and happy to be listed? ' +
            'We would like to hear from you.</p>' +
        '</div>' +
        '<a href="contact" class="btn btn-primary">Get in touch</a>' +
      '</div>';
    return;
  }

  var html = '';

  if (REVIEW) {
    /* The static noindex lives in graduates.html — but this file is on the sync
       manifest and that file is not, so a sync would carry nine unconsented
       names to three more public sites and leave the protection behind. Put it
       back from here if it is missing. A tag added by script is weaker than one
       in the source, which is why graduates.html still has its own; this is the
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
        '<p>This is how the page will look. The nine people below have not yet been ' +
          'asked whether their name and photograph may appear on a public website, so ' +
          'nothing here is approved for publication. The page is also hidden from ' +
          'search engines until it is.</p>' +
        '<p>What is still needed: permission from each person, and one line each on ' +
          'where they are working now or what they have started.</p>' +
      '</div>';
  }

  var placed = {};
  COHORTS.forEach(function (c) {
    var mine = shown.filter(function (p) { return p.cohort === c.id; });
    mine.forEach(function (p) { placed[p.name] = true; });
    if (!c.photo && !c.stats && !mine.length) return;

    html += cohortPanel(c);
    if (!c.photo && !c.stats) {
      html += '<div class="sec-head grad-sub">' +
        (c.eyebrow ? '<span class="eyebrow">' + esc(c.eyebrow) + '</span>' : '') +
        '<h3>' + esc(c.title) + '</h3></div>';
    }
    if (mine.length) html += '<div class="facegrid">' + mine.map(card).join('') + '</div>';
  });

  /* Anyone whose cohort does not match one above still gets shown, rather than
     silently vanishing because of a typo in a field nobody validates. */
  var rest = shown.filter(function (p) { return !placed[p.name]; });
  if (rest.length) html += '<div class="facegrid">' + rest.map(card).join('') + '</div>';

  host.innerHTML = html;
})();
