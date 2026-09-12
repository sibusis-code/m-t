/* The self-check questions on the module page — taken in place, on the card.
 *
 * Same manners as materials.js and the same reason for them: this asks
 * quiz.php for what THIS learner is allowed to see, and does nothing at all
 * when the answer is anonymous, not enrolled, or "no questions written for
 * this yet". A topic with no questions must look deliberate, not broken.
 *
 * PER TOPIC, NOT PER MODULE (unchanged, and now load-bearing)
 * ----------------------------------------------------------
 * A learner reads one topic and then answers questions on it, so that is where
 * the questions belong. Centenary's own bank is written the same way: ten
 * questions per topic, fifty-one topics. The 80% bar is therefore per topic
 * too — see QUIZ_DEFAULT_PASS_PCT in lib/quiz.php for why that number lives in
 * code rather than in every row.
 *
 * TAKEN HERE RATHER THAN ON quiz.php (changed 11 Sep 2026)
 * -------------------------------------------------------
 * It used to be a link to a separate page. Kgomotso asked for the whole loop —
 * read, answer, fail, re-read, answer again — to happen in one place without
 * the page moving under the learner, because the re-reading is the point and
 * sending somebody to a different URL to do it is how it gets skipped.
 *
 * quiz.php is NOT deleted and still works: it is what a learner with
 * JavaScript off gets, and what older emailed links point at. Both paths grade
 * through quiz_grade_and_record() and tick through quiz_tick_topic_on_pass(),
 * so neither can drift into being the lenient one.
 *
 * WHAT THIS FILE IS NOT TRUSTED WITH
 * ---------------------------------
 * Not the marking. The GET carries choice text only — is_correct never reaches
 * this file before answers are posted — and the server re-reads the key when
 * it grades. The correct answers in the result come back WITH the grade, after
 * the attempt is recorded, so there is nothing here to read ahead.
 */
(function () {
  'use strict';

  var params = new URLSearchParams(location.search);
  var code   = (params.get('m') || '').toUpperCase();
  if (!code) return;

  /* Same constant as materials.js, for the same reason — one course carries a
     tracked curriculum today, and adding a second is a visible change here and
     in learner_catalogue(), not an inference that quietly breaks. */
  var COURSE = 'project-management';

  var section = document.getElementById('m-quizsec');
  var host    = document.getElementById('m-quiz');

  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function token() {
    var a = window.SPSAccount;
    var s = a && a.get ? a.get() : null;
    return (s && s.token) || '';
  }

  function api(topic) {
    return 'quiz.php?as=json&course=' + encodeURIComponent(COURSE) +
           '&module=' + encodeURIComponent(topic);
  }

  /* ------------------------------------------------------------------ *
   * One topic's quiz, from the summary row through to a pass.
   * ------------------------------------------------------------------ */

  function mount(card, topic, summary) {
    var wrap = document.createElement('div');
    wrap.className = 'qz-topic';
    card.insertBefore(wrap, card.querySelector('.topic-tick') || null);

    var state = {
      questions: null,      // fetched on first open, then kept
      passPct: summary.pass_pct || 80,
      best: summary.attempts > 0 ? { pct: summary.best_pct, attempts: summary.attempts } : null,
      busy: false
    };

    function scoreLine() {
      if (!state.best) {
        return esc(summary.questions) + ' question' + (summary.questions === 1 ? '' : 's') +
               ' · ' + esc(state.passPct) + '% to pass';
      }
      var passed = state.best.pct >= state.passPct;
      return '<strong>' + esc(state.best.pct) + '%</strong> best of ' +
             esc(state.best.attempts) + ' attempt' + (state.best.attempts === 1 ? '' : 's') +
             ' · ' + (passed ? 'passed' : 'needs ' + esc(state.passPct) + '%');
    }

    function idle() {
      var passed = state.best && state.best.pct >= state.passPct;
      wrap.className = 'qz-topic' + (passed ? ' qz-topic-passed' : '');
      wrap.innerHTML =
        '<span class="qz-topic-score">' + scoreLine() + '</span>' +
        '<button type="button" class="btn btn-ghost qz-start">' +
          (state.best ? (passed ? 'Answer them again' : 'Try these again') : 'Answer the questions') +
        '</button>';
      wrap.querySelector('.qz-start').addEventListener('click', open);
    }

    function open() {
      if (state.busy) return;
      if (state.questions) return paint();
      state.busy = true;
      wrap.innerHTML = '<span class="qz-topic-score">Fetching the questions…</span>';

      fetch(api(topic), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          state.busy = false;
          if (!d || !d.available || !d.questions || !d.questions.length) { idle(); return; }
          state.questions = d.questions;
          state.passPct   = d.pass_pct || state.passPct;
          paint();
        })
        .catch(function () { state.busy = false; idle(); });
    }

    /* The questions, unanswered. */
    function paint() {
      var h = ['<form class="qz-form" novalidate>'];
      h.push('<p class="qz-bar">Answer all ' + state.questions.length +
             ' — you need <strong>' + esc(state.passPct) + '%</strong> to tick this topic off.</p>');

      state.questions.forEach(function (q, i) {
        h.push('<fieldset class="qz-q" data-q="' + esc(q.id) + '">');
        h.push('<legend><span class="qz-n">' + (i + 1) + '</span>' + esc(q.prompt) + '</legend>');
        q.choices.forEach(function (c) {
          var id = 'q' + q.id + 'c' + c.id;
          h.push('<label class="qz-choice" for="' + esc(id) + '">' +
                   '<input type="radio" id="' + esc(id) + '" name="a[' + esc(q.id) + ']" value="' + esc(c.id) + '">' +
                   '<span>' + esc(c.text) + '</span>' +
                 '</label>');
        });
        h.push('</fieldset>');
      });

      h.push('<div class="qz-actions">' +
               '<button type="submit" class="btn btn-primary">Mark my answers</button>' +
               '<button type="button" class="btn btn-ghost qz-cancel">Not now</button>' +
             '</div>');
      h.push('<p class="qz-err" role="alert" hidden></p>');
      h.push('</form>');

      wrap.className = 'qz-topic qz-topic-open';
      wrap.innerHTML = h.join('');
      wrap.querySelector('.qz-cancel').addEventListener('click', idle);
      wrap.querySelector('.qz-form').addEventListener('submit', submit);
    }

    function submit(ev) {
      ev.preventDefault();
      if (state.busy) return;

      var form = ev.target;
      var body = new URLSearchParams();
      var answered = 0;

      state.questions.forEach(function (q) {
        var picked = form.querySelector('input[name="a[' + q.id + ']"]:checked');
        if (picked) { body.set('a[' + q.id + ']', picked.value); answered++; }
      });

      var err = form.querySelector('.qz-err');
      if (answered < state.questions.length) {
        /* Refused here rather than server-side because an unanswered question
           grades as wrong, and somebody who simply missed one further up the
           card should not be told they failed because of it. */
        err.textContent = 'There ' + (state.questions.length - answered === 1 ? 'is 1 question' :
                          'are ' + (state.questions.length - answered) + ' questions') +
                          ' still unanswered. Answer everything, then mark it.';
        err.hidden = false;
        return;
      }
      err.hidden = true;

      body.set('course', COURSE);
      body.set('module', topic);
      body.set('as', 'json');
      body.set('_token', token());

      state.busy = true;
      form.querySelector('button[type=submit]').disabled = true;

      post(body)
        .then(function (d) {
          state.busy = false;
          if (!d || !d.graded) {
            err.textContent = (d && d.message) ||
              'That could not be marked just now. Check your connection and try again.';
            err.hidden = false;
            form.querySelector('button[type=submit]').disabled = false;
            return;
          }
          state.best = {
            pct: (state.best && state.best.pct > d.pct) ? state.best.pct : d.pct,
            attempts: (state.best ? state.best.attempts : 0) + 1
          };
          result(d);
        })
        .catch(function () {
          state.busy = false;
          form.querySelector('button[type=submit]').disabled = false;
          err.textContent = 'That could not be marked just now. Check your connection and try again.';
          err.hidden = false;
        });
    }

    /* One retry on a rotated token, for the reason profile.js gives: the token
       moves whenever any form on the site is submitted, so a module page left
       open in another tab legitimately holds a stale one. */
    function post(body, retried) {
      return fetch('quiz.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
        body: body.toString()
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d && d.error === 'token' && !retried && window.SPSAccount) {
            return window.SPSAccount.refresh().then(function () {
              body.set('_token', token());
              return post(body, true);
            });
          }
          return d;
        });
    }

    /* The marked paper: the score, then every question it got wrong with the
       answer that was right. A pass says so and stops; a fail sends them back
       to the reading on the same card rather than to another page. */
    function result(d) {
      var byQ = {};
      (d.breakdown || []).forEach(function (b) { byQ[b.question_id] = b; });

      var h = ['<div class="qz-result ' + (d.pass ? 'qz-result-pass' : 'qz-result-fail') + '">'];
      h.push('<div class="qz-result-pct">' + esc(d.pct) + '%</div>');
      h.push('<div class="qz-result-say"><strong>' +
               (d.pass ? 'Passed — topic ticked off.' : 'Not yet — you need ' + esc(d.pass_pct) + '%.') +
             '</strong><span>' + esc(d.score_count) + ' of ' + esc(d.question_count) +
             ' correct' + (d.pass ? '' : '. Read the topic again, then try these once more — ' +
             'you can retake them as often as you like.') + '</span></div>');
      h.push('</div>');

      var wrong = state.questions.filter(function (q) {
        return byQ[q.id] && !byQ[q.id].correct;
      });
      if (wrong.length) {
        h.push('<div class="qz-review"><span class="lbl">What to look at again</span>');
        wrong.forEach(function (q) {
          var b = byQ[q.id];
          var right = null, mine = null;
          q.choices.forEach(function (c) {
            if (c.id === b.correct_id) right = c.text;
            if (c.id === b.choice_id)  mine  = c.text;
          });
          h.push('<div class="qz-review-q"><p class="qz-review-prompt">' + esc(q.prompt) + '</p>' +
                 (mine ? '<p class="qz-review-mine">You said: ' + esc(mine) + '</p>' : '') +
                 (right ? '<p class="qz-review-right">The answer: ' + esc(right) + '</p>' : '') +
                 '</div>');
        });
        h.push('</div>');
      }

      h.push('<div class="qz-actions">');
      if (!d.pass) h.push('<button type="button" class="btn btn-ghost qz-reread">Read the topic again</button>');
      h.push('<button type="button" class="btn ' + (d.pass ? 'btn-ghost' : 'btn-primary') + ' qz-retry">' +
             (d.pass ? 'Answer them again' : 'Try these again') + '</button>');
      h.push('<button type="button" class="btn btn-ghost qz-close">Close</button>');
      h.push('</div>');

      wrap.className = 'qz-topic qz-topic-open' + (d.pass ? ' qz-topic-passed' : '');
      wrap.innerHTML = h.join('');

      wrap.querySelector('.qz-retry').addEventListener('click', paint);
      wrap.querySelector('.qz-close').addEventListener('click', idle);

      var rr = wrap.querySelector('.qz-reread');
      if (rr) rr.addEventListener('click', function () {
        /* lessons.js put the reading on this same card. Open it and take them
           to it, rather than describing where it is. */
        var btn = card.querySelector('.read-open');
        var body = card.querySelector('.read-body');
        if (btn && body && body.hidden) btn.click();
        (body || card).scrollIntoView({ behavior: 'smooth', block: 'start' });
      });

      /* The server ticked the topic; this page's copy of progress is now a
         tick behind. Ask it to re-read rather than ticking a second time. */
      if (d.ticked && window.PM_PROGRESS && window.PM_PROGRESS.resync) {
        window.PM_PROGRESS.resync();
      }
    }

    idle();
  }

  /* ------------------------------------------------------------------ */

  fetch('quiz.php?course=' + encodeURIComponent(COURSE), {
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json' }
  })
    .then(function (r) {
      if (!r.ok && r.status !== 403) throw new Error('http ' + r.status);
      return r.json();
    })
    .then(function (data) {
      if (!data || !data.in || !data.enrolled || !data.quizzes) return;

      document.querySelectorAll('#m-topics .module').forEach(function (card) {
        var meta = card.querySelector('.meta');
        if (!meta) return;
        var topic = (meta.textContent || '').split('·')[0].trim();
        var mine  = data.quizzes[topic];
        if (!mine || !mine.available) return;
        mount(card, topic, mine);
      });

      /* A module-level set, if one was ever saved. Nothing writes these any
         more, but an existing one stays reachable rather than disappearing
         with its learners' attempts still attached to it. It keeps the old
         link-out shape: these are rare, and none of them is the read-then-
         answer loop this file was rebuilt for. */
      if (!section || !host) return;
      var whole = data.quizzes[code];
      if (!whole || !whole.available) return;

      host.innerHTML =
        '<div class="qz-card">' +
          '<div class="qz-card-score">' +
            (whole.attempts > 0
              ? '<strong>' + esc(whole.best_pct) + '%</strong> best of ' + esc(whole.attempts) +
                ' attempt' + (whole.attempts === 1 ? '' : 's')
              : '<strong>' + esc(whole.questions) + '</strong> question' +
                (whole.questions === 1 ? '' : 's') + ', not attempted yet') +
          '</div>' +
          '<a class="btn btn-primary" href="quiz?course=' + encodeURIComponent(COURSE) +
            '&module=' + encodeURIComponent(code) + '">' +
            (whole.attempts > 0 ? 'Try it again' : 'Start the self-check') + '</a>' +
        '</div>';
      section.hidden = false;
    })
    .catch(function () { /* silent, on purpose — see the note above */ });
})();
