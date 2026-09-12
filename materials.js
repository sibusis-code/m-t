/* Course material on the module page.
 *
 * pm-modules.js gives every module empty docs, because that file is served to
 * anybody who opens the site and a link written into it would be published the
 * moment it was pasted. This asks the server instead, and the server answers
 * only for a learner who is signed in and enrolled on the course.
 *
 * WHAT COMES BACK IS NEVER THE REAL ADDRESS — not a Drive link, and not a
 * path to a file the academy holds. Every slot is a URL back to
 * materials.php, which logs the open and then either redirects (a link) or
 * streams the bytes itself (a file — see lib/material_files.php). Either way
 * the page never contains the real thing: a screenshot, a shared browser
 * history or "copy link address" gives away nothing that works for somebody
 * else.
 *
 * WHEN IT DOES NOTHING, which is most of the time and is the point:
 *
 *   - signed out, or not enrolled       -> the server says so, we leave the page
 *   - the endpoint is not there at all  -> on GitHub Pages there is no PHP, so
 *                                          the fetch 404s and we leave the page
 *   - no material attached yet          -> nothing to swap in
 *
 * In every one of those cases module.html's own markup stands: "Ask HR for a
 * copy" for a document, "no recordings yet" for a video. That is a true
 * statement for an anonymous visitor, so there is nothing to correct.
 */
(function () {
  'use strict';

  var host = document.getElementById('m-docs');
  if (!host) return;                       // not a module page

  /* The module page puts the code in the URL — module?m=KM-04 — and
     pm-modules.js has already resolved it by the time this runs. */
  var params = new URLSearchParams(location.search);
  /* Upper-cased to match module.html, which does the same before looking the
     module up. "module?m=km-04" is a link somebody will type by hand. */
  var code   = (params.get('m') || '').toUpperCase();
  if (!code) return;

  /* One course carries a tracked curriculum today. Kept as a constant rather
     than guessed from the page so that adding a second one is a visible change
     here and in learner_catalogue(), not an inference that quietly breaks. */
  var COURSE = 'project-management';

  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  var DOC_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
    'stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex:0 0 auto">' +
    '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>' +
    '<polyline points="14 2 14 8 20 8"/></svg>';

  /* Deliberately the same shape as the card module.html already draws, so a
     learner sees one design whether the material is there or not. */
  function card(title, desc, href) {
    var inner = '<div class="dmeta"><h4>' + esc(title) + '</h4><p>' + esc(desc) + '</p>' +
      '<span class="dl">' + DOC_ICON + ' Open the document ↗</span></div>';
    return '<a class="dcard" href="' + esc(href) + '" target="_blank" rel="noopener noreferrer">' +
      inner + '</a>';
  }

  fetch('materials.php?course=' + encodeURIComponent(COURSE), {
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json' }
  })
    .then(function (r) {
      /* 403 is "signed in but not on this course" and is a normal answer, not a
         fault. Anything that is not JSON — a 404 from Pages, an HTML error page
         — throws here and is swallowed below. */
      if (!r.ok && r.status !== 403) throw new Error('http ' + r.status);
      return r.json();
    })
    .then(function (data) {
      if (!data || !data.in || !data.enrolled || !data.materials) return;

      var mine = data.materials[code];
      if (!mine) return;

      /* Each slot is now { open, native, name } rather than a bare URL —
         'open' is what card()/the video branch below actually link to;
         'native' and 'name' only matter for the video slot. */
      var docs = [];
      if (mine.guide) {
        docs.push(card('Learner Guide',
          'The full notes for ' + code + ' — every topic written out, with the examples ' +
          'and diagrams.', mine.guide.open));
      }
      if (mine.workbook) {
        docs.push(card('Learner Workbook',
          'The activities, exercises and self-assessments for this module. Completed and ' +
          'handed to your facilitator.', mine.workbook.open));
      }
      /* Only replace what we actually have. A module with a workbook and no
         guide keeps the "ask HR" card for the guide, which is still true. */
      if (docs.length === 2) {
        host.innerHTML = docs.join('');
      } else if (docs.length === 1) {
        host.insertAdjacentHTML('afterbegin', docs[0]);
        var stale = host.querySelectorAll('.dcard');
        for (var i = 1; i < stale.length; i++) {
          var h4 = stale[i].querySelector('h4');
          if (h4 && docs[0].indexOf('>' + h4.textContent + '<') !== -1) stale[i].remove();
        }
      }

      var vid = document.getElementById('m-video');
      if (vid && mine.video) {
        if (mine.video.native) {
          /* A file the academy holds — an actual <video> element, since
             there is nothing else that could play a raw byte stream, and
             materials.php's Range support (see lib/material_files.php) is
             what makes the scrub bar actually work. */
          vid.innerHTML = '<div class="videobox" style="aspect-ratio:16/9;border-radius:14px;' +
            'overflow:hidden;background:#000"><video controls preload="metadata" ' +
            'style="width:100%;height:100%" src="' + esc(mine.video.open) + '"></video></div>';
        } else {
          /* A link out — a YouTube/Drive URL needs the destination site to
             render it, and an iframe would need a weaker sharing setting
             than "anyone with the link" plus bypass the redirect that logs
             the open, so this stays a card rather than an embed. */
          vid.innerHTML = '<a class="dcard" href="' + esc(mine.video.open) + '" target="_blank" ' +
            'rel="noopener noreferrer"><div class="dmeta"><h4>Facilitator session</h4>' +
            '<p>The recorded session for this module. It opens in a new tab.</p>' +
            '<span class="dl">' + DOC_ICON + ' Watch the recording ↗</span></div></a>';
        }
      }
    })
    .catch(function () {
      /* Silent on purpose. Every failure here means "this visitor does not get
         material", and the page already says the right thing for that case.
         An error message would be noise on the catalogue pages, which are
         public and where this is the expected outcome. */
    });
})();
