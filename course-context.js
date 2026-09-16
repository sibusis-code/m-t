/* Which qualification is this page about?
 *
 * Until 16 Sep 2026 the answer was always "the Project Manager", and four files
 * said so in a constant of their own — lessons.js, materials.js, quiz-widget.js
 * and pm-progress.js each carried `var COURSE = 'project-management'`, with a
 * note saying that a second qualification should be a visible change here
 * rather than an inference. This is that change, made once.
 *
 * Procurement Officer (SAQA 111445) is the second, and the two curricula share
 * module and topic codes: both have a KM-01, and both have a KM-01-KT01. Every
 * table that stores a learner's work is keyed by course_slug as well as module
 * code, so the records cannot collide — but only if the page sends the right
 * slug. That is what this file decides, in one place, for all of them.
 *
 * THE RULE: a slug is honoured only if a curriculum file for it has actually
 * loaded. Anything else — a typo, a stale bookmark, a slug someone invents in
 * the address bar — falls back to the Project Manager, which is what every one
 * of these pages assumed before this file existed. So the worst case is the old
 * behaviour, never material or progress filed under a course that isn't there.
 *
 * Load it AFTER the curriculum files and BEFORE the scripts that read it.
 */
(function () {
  'use strict';

  var DEFAULT = 'project-management';
  var known   = window.ACADEMY_CURRICULA || {};

  var asked = '';
  try {
    asked = new URLSearchParams(location.search).get('c') || '';
  } catch (e) { asked = ''; }         // very old browsers: fall through to the default

  window.ACADEMY_COURSE = Object.prototype.hasOwnProperty.call(known, asked) ? asked : DEFAULT;

  /** The curriculum for this page, or null if none of them loaded. */
  window.ACADEMY_CURRICULUM = known[window.ACADEMY_COURSE] || null;
})();
