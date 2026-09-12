/* The written material for a topic, on the module page.
 *
 * Same manners as materials.js and quiz-widget.js: it asks the server, and if
 * the answer is "not signed in", "not enrolled", or "nothing written yet" it
 * does nothing at all and leaves the page exactly as the curriculum rendered
 * it. A module with no written material must look deliberate, not broken —
 * most of them have none yet.
 *
 * WHY THIS IS PER TOPIC AND NOT PER AREA (changed 8 Sep 2026)
 * ----------------------------------------------------------
 * The first version attached one section to each .lesson row, so the five areas
 * listed under a topic each got their own expander. That was built on an
 * assumption about the Learner Guides that turned out to be wrong: they are
 * written topic by topic, not area by area. Cutting them recovers one block of
 * material per topic — 51 blocks across the qualification — and forcing that
 * into area-sized pieces would have meant either inventing divisions the author
 * did not make, or dropping four fifths of the text.
 *
 * So the areas stay what they actually are: the syllabus for that topic, listed
 * on the card. The material opens as one block beneath them. Where a topic DOES
 * have its material split into named parts, each part gets a sub-heading inside
 * the same block — so both shapes render through one path and there is no
 * second layout to keep working.
 *
 * The HTML comes from the server already escaped (see lib/sections.php on why
 * the stored form is plain text). This file does not build markup out of
 * anything a person typed; it only places what it was given.
 */
(function () {
  'use strict';

  var params = new URLSearchParams(location.search);
  var moduleCode = params.get('m');
  if (!moduleCode) return;

  var COURSE = 'project-management';

  fetch('lessons.php?course=' + encodeURIComponent(COURSE) +
        '&module=' + encodeURIComponent(moduleCode), { credentials: 'same-origin' })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (data) {
      if (!data || !data.in || !data.enrolled || !data.sections) return;
      apply(data.sections);
    })
    .catch(function () { /* silent, on purpose — see the note above */ });

  /* Reading time is shown because "4,616 words" means nothing to most people
     and "about 23 minutes" means something to everyone. 200 wpm is the usual
     figure for studying rather than skimming. */
  function readingTime(words) {
    var m = Math.max(1, Math.round(words / 200));
    return m < 60 ? 'about ' + m + ' min' : 'about ' + Math.round(m / 60) + ' hr';
  }

  function apply(sections) {
    /* The topic cards are rendered by module.html from pm-modules.js. Each
       carries its code in .meta. */
    document.querySelectorAll('#m-topics .module').forEach(function (card) {
      var meta = card.querySelector('.meta');
      if (!meta) return;
      var code = (meta.textContent || '').split('·')[0].trim();
      var areas = sections[code];
      if (!areas) return;

      /* Object keys are area_index as strings; sort numerically so 10 does not
         come before 2 once a topic has more than nine parts. */
      var keys = Object.keys(areas).sort(function (a, b) { return (+a) - (+b); });
      if (!keys.length) return;

      var words = 0;
      keys.forEach(function (k) { words += (areas[k].words || 0); });

      build(card, code, keys.map(function (k) { return areas[k]; }), words);
    });
  }

  function build(card, code, parts, words) {
    var id = 'read-' + code;

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'read-open';
    btn.setAttribute('aria-expanded', 'false');
    btn.setAttribute('aria-controls', id);
    btn.innerHTML = '<span class="read-caret" aria-hidden="true"></span>' +
                    '<span class="read-label">Read this topic</span>' +
                    '<span class="read-meta">' + words.toLocaleString() + ' words · ' +
                      readingTime(words) + '</span>';

    var body = document.createElement('div');
    body.className = 'read-body';
    body.id = id;
    body.hidden = true;

    parts.forEach(function (p) {
      /* A title is only shown when the material was actually split into named
         parts. Topic-level material has none, and an invented heading would be
         a claim about the author's structure that nobody made. */
      if (p.title) {
        var h = document.createElement('h5');
        h.className = 'read-part';
        h.textContent = p.title;
        body.appendChild(h);
      }
      var d = document.createElement('div');
      d.className = 'read-text';
      d.innerHTML = p.html;      // server-escaped; see the header note
      body.appendChild(d);
    });

    btn.addEventListener('click', function () {
      var open = !body.hidden;
      body.hidden = open;
      btn.setAttribute('aria-expanded', open ? 'false' : 'true');
      btn.classList.toggle('open', !open);
    });

    /* After the syllabus list, before the "mark this topic done" tick — the
       order somebody actually works in: see what it covers, read it, tick it. */
    var tick = card.querySelector('.topic-tick');
    if (tick) { card.insertBefore(btn, tick); card.insertBefore(body, tick); }
    else { card.appendChild(btn); card.appendChild(body); }
  }
})();
