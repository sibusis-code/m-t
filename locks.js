/* ---- Course locks ---------------------------------------------------------
   Narrows the catalogue to a named set of courses — used while the material for
   a route is being prepared. Everything else stays *visible* and locked rather
   than hidden: the breadth of the catalogue is the point of the courses page,
   and removing the cards would make the academy look empty rather than focused.

   Whether it is on right now is LOCKS_ON, below. The three academies set it
   independently: this is a business decision per site, not shared behaviour.

   THIS IS THE ONLY PLACE TO CHANGE WHEN A COURSE REOPENS.
     - OPEN_TITLES  unlocks a catalogue card (matched against its <h4>)
     - OPEN_SLUGS   unlocks the course page behind it (course.html?c=<slug>)
   A course usually needs both. External cards (Google, Microsoft, Coursera)
   have no course page, so they only need a title.

   Note the two lists are deliberately not derived from one another. Locking is
   a business decision per course, and a mechanical link between "has a page"
   and "is open" is exactly the kind of cleverness that reopens something by
   accident.

   LOCKS_ON is the switch. Off, the whole catalogue is open and the lists below
   are ignored — they are left intact on purpose, so turning it back on restores
   exactly the state it had before without anyone having to remember it. */
(function () {
  var LOCKS_ON = false;

  var OPEN_TITLES = [
    'Occupational Certificate: Project Manager',
    'Google Project Management Certificate'
  ];
  var OPEN_SLUGS = ['project-management'];

  var LOCK_ICON =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" ' +
    'stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="2"/>' +
    '<path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>';

  function titleOpen(t) {
    t = (t || '').trim();
    return OPEN_TITLES.some(function (o) { return t.indexOf(o) > -1; });
  }

  var API = {
    openTitles: OPEN_TITLES,
    openSlugs: OPEN_SLUGS,
    on: LOCKS_ON,
    slugOpen: function (slug) { return !LOCKS_ON || OPEN_SLUGS.indexOf(slug) > -1; },
    cardOpen: function (card) {
      if (!LOCKS_ON) return true;
      var h = card.querySelector('h4');
      return h ? titleOpen(h.textContent) : true;
    }
  };
  window.COURSE_LOCKS = API;

  if (!LOCKS_ON) return;

  /* Decorate the locked cards. This runs before cards.js and courses-index.js,
     which both bail out on data-locked rather than wiring up a click. */
  [].slice.call(document.querySelectorAll('.ccard')).forEach(function (card) {
    if (API.cardOpen(card)) return;

    card.classList.add('locked');
    card.dataset.locked = '1';
    card.setAttribute('aria-disabled', 'true');

    var body = card.querySelector('.ccard-body');
    var h = card.querySelector('h4');
    if (body && h && !card.querySelector('.lock-badge')) {
      var b = document.createElement('span');
      b.className = 'lock-badge';
      b.innerHTML = LOCK_ICON + 'Not open yet';
      body.insertBefore(b, h);
    }

    var av = card.querySelector('.ccard-foot .avail');
    if (av) av.textContent = 'Opening later';
  });
})();
