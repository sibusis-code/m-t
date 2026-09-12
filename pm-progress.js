/* ---- Project Manager NQF 5: progress tracking ----------------------------

   The client asked how a self-paced programme gets tracked so people finish on
   time and there is something to report on, "without placing a strain on HR or
   the provider". This is the answer.

   WHAT THIS IS
     A learner ticks off topics as they work through them. When a module is
     finished they submit a dated record to HR, and can print a report for a
     manager to countersign.

   WHAT THIS IS NOT — and the pages say so plainly
     Self-reported effort, not an assessment result. Being found competent in a
     module is Centenary's decision after assessment, and the qualification is
     awarded by the QCTO after the EISA. Nothing here changes or predicts that.

   ---- WHERE THE TICKS LIVE, AND WHY THAT CHANGED (Aug 2026) ----------------

   This file used to say there was no database, and gave reasons: five learners,
   the compliance artefacts come from the provider anyway, and storing employee
   learning records on our own infrastructure would make somebody a responsible
   party under POPIA for data the employer already holds. Those were the right
   reasons on static hosting, where the only alternative was a third-party form
   service outside South Africa.

   There is now a back end on Xneelo in Johannesburg, and the calculation
   inverts. Browser storage was never neutral — it had three properties nobody
   chose:

     - tied to one browser on one device, so opening the site on a phone starts
       again from zero;
     - deleted, silently and completely, by clearing browsing data;
     - on a shared site machine, the NEXT learner sees the PREVIOUS learner's
       record, which is a disclosure of somebody's training performance to a
       colleague.

   So there are two stores now, and which one is in use depends on one thing:

     SIGNED IN  -> the account. Server is the record; this file keeps a copy in
                   memory for the synchronous API below and writes each tick
                   through as it happens.
     SIGNED OUT -> localStorage, exactly as before. Somebody browsing the
                   modules without an account still gets to tick things off, and
                   is offered the chance to bring it with them if they later
                   sign in.

   ---- WHY THE API STAYED SYNCHRONOUS --------------------------------------

   module.html and pm-progress.php call P.topicDone() and P.moduleStats()
   straight through, inline, dozens of times while rendering. Making those
   return promises would have meant rewriting both pages around async rendering
   for no gain. Instead the network happens at the edges — one hydrate on load,
   one small write per tick — and everything in between reads memory. Pages that
   want to know when the hydrate has landed listen for 'pmprogress:sync' on
   window; it fires exactly once whether the answer was an account, an empty
   account, or no account at all, so a page can always stop saying "loading".

   Progress is held per module in the shape it has always had, which is what
   makes importing a browser's saved copy a copy rather than a translation:
     { "KM-01": { topics: { "KM-01-KT01": "2026-08-12T09:00:00.000Z" },
                  done: "2026-08-20T…" } } */
(function () {
  var FIELD  = 'pmProgress';
  var COURSE = 'project-management';   // the slug this file's curriculum belongs to

  /* Each academy exposes its profile under its own global — SPSProfile,
     FungiProfile, EquinixProfile — because the sites were branded separately.
     Hardcoding one name silently disabled progress on the other two: available()
     returned false and the ticks removed themselves, with no error to notice.
     So find whichever object on `window` actually implements the profile API. */
  var cached = null;
  function profile() {
    if (cached) return cached;
    for (var k in window) {
      if (!/Profile$/.test(k)) continue;
      var o;
      try { o = window[k]; } catch (e) { continue; }   // cross-origin frames throw
      if (o && typeof o.get === 'function' && typeof o.save === 'function' &&
          typeof o.available === 'function') { cached = o; return o; }
    }
    return null;
  }

  function localStore() {
    var p = profile();
    return (p && p.get()[FIELD]) || {};
  }
  function commitLocal(next) {
    var p = profile();
    if (!p) return false;
    var patch = {}; patch[FIELD] = next;
    return p.save(patch);
  }

  function clone(o) { return JSON.parse(JSON.stringify(o || {})); }
  function now() { return new Date().toISOString(); }

  function moduleEntry(all, id) {
    if (!all[id]) all[id] = { topics: {}, done: null };
    if (!all[id].topics) all[id].topics = {};
    return all[id];
  }

  function isEmptyTree(t) {
    for (var k in t) {
      if (!t.hasOwnProperty(k)) continue;
      var m = t[k] || {};
      if (m.done) return false;
      for (var c in (m.topics || {})) if (m.topics.hasOwnProperty(c)) return false;
    }
    return true;
  }

  /* ---- which store is in play -------------------------------------------- */

  var mode = 'local';    // 'local' until the account probe says otherwise
  var mem  = {};         // the account's copy, once hydrated
  var acct = null;       // window.SPSAccount, if this page loaded profile.js

  function store() { return mode === 'account' ? mem : localStore(); }

  /* Always deferred, never synchronous. This file is loaded before the inline
     script that renders each page, so firing during our own execution would
     shout into a room where nobody has arrived yet — and the one case where
     that happens (no profile.js on the page) is precisely the case where the
     page still needs to be told to stop waiting. */
  function announce() {
    setTimeout(function () {
      try { window.dispatchEvent(new Event('pmprogress:sync')); } catch (e) {}
    }, 0);
  }

  /* A write that failed must not leave the screen claiming otherwise. Rather
     than trying to reverse one tick, re-read the truth and repaint: whatever
     the server has is what the learner has. */
  function write(params) {
    if (!acct) return;
    params.course = COURSE;
    acct.post(params).then(function (res) {
      if (res && res.ok) return;
      warn(res && res.error === 'signed-out'
        ? 'You have been signed out, so that tick was not saved. Sign in again and it will be.'
        : 'That tick could not be saved just now. Check your connection — the page will show '
          + 'what is actually recorded.');
      resync();
    });
  }

  function resync() {
    if (!acct) return;
    acct.refresh().then(function (s) {
      mem = (s && s.progress && s.progress[COURSE]) || {};
      announce();
    });
  }

  /* ---- the API, unchanged in shape --------------------------------------- */

  var API = {
    /* True when ticks can be saved at all. With an account that is always;
       without one it depends on whether this browser will let us write. */
    available: function () {
      if (mode === 'account') return true;
      var p = profile();
      return !!(p && p.available());
    },

    /** 'account' once hydrated for a signed-in learner, otherwise 'local'. */
    mode: function () { return mode; },

    all: function () { return store(); },

    /**
     * Re-read progress from the account and repaint every listener.
     *
     * Exposed for the one case where something OTHER than this file changed a
     * learner's progress: passing a topic quiz ticks the topic on the server
     * (see quiz_tick_topic_on_pass() in quiz.php), so the copy held here is a
     * tick behind and nothing in this file knows it. Re-reading is right rather
     * than ticking locally as well — the server has already decided, and a
     * second toggle would post a duplicate and could un-tick it.
     *
     * A no-op without an account, where the local store is already the truth.
     */
    resync: function () { resync(); },

    topicDone: function (moduleId, topicCode) {
      var m = store()[moduleId];
      return !!(m && m.topics && m.topics[topicCode]);
    },

    toggleTopic: function (moduleId, topicCode) {
      var all = clone(store());
      var m = moduleEntry(all, moduleId);
      var on;
      if (m.topics[topicCode]) { delete m.topics[topicCode]; on = false; }
      else { m.topics[topicCode] = now(); on = true; }

      if (mode === 'account') {
        mem = all;                                       // optimistic: the tick
        write({ a: 'topic', module: moduleId,            // appears immediately
                item: topicCode, on: on ? '1' : '0' });
      } else {
        commitLocal(all);
      }
      return on;
    },

    moduleDone: function (moduleId) {
      var m = store()[moduleId];
      return (m && m.done) || null;
    },

    setModuleDone: function (moduleId, on) {
      var all = clone(store());
      var m = moduleEntry(all, moduleId);
      m.done = on ? now() : null;

      if (mode === 'account') {
        mem = all;
        write({ a: 'module', module: moduleId, on: on ? '1' : '0' });
      } else {
        commitLocal(all);
      }
      return m.done;
    },

    /* Per-module counts. `total` comes from the curriculum, not from what the
       learner has touched, so an untouched module still reports out of its real
       number of topics. */
    moduleStats: function (mod) {
      var saved = store()[mod.id] || { topics: {} };
      var total = mod.topics.length;
      var done = mod.topics.filter(function (t) { return saved.topics && saved.topics[t.code]; }).length;
      return {
        id: mod.id, done: done, total: total,
        pct: total ? Math.round(done / total * 100) : 0,
        complete: !!saved.done, completedAt: saved.done || null
      };
    },

    overall: function (modules) {
      var t = 0, d = 0, mods = 0, credits = 0;
      modules.forEach(function (m) {
        var s = API.moduleStats(m);
        t += s.total; d += s.done;
        if (s.complete) { mods++; credits += (+m.credits || 0); }
      });
      return {
        topicsDone: d, topicsTotal: t,
        pct: t ? Math.round(d / t * 100) : 0,
        modulesComplete: mods, modulesTotal: modules.length,
        creditsClaimed: credits,
        creditsTotal: modules.reduce(function (a, m) { return a + (+m.credits || 0); }, 0)
      };
    },

    /* A flat, dated record — the thing that actually gets sent or printed. It is
       deliberately plain text: it has to survive an email client, a printout and
       being pasted into a spreadsheet years from now. */
    report: function (modules) {
      var o = API.overall(modules);
      var lines = modules.map(function (m) {
        var s = API.moduleStats(m);
        return [
          s.complete ? '[x]' : (s.done ? '[~]' : '[ ]'),
          m.id,
          m.title,
          s.done + '/' + s.total + ' topics',
          m.credits + ' cr',
          s.complete ? 'marked complete ' + s.completedAt.slice(0, 10) : ''
        ].filter(Boolean).join('  ');
      });
      return { summary: o, lines: lines, text: lines.join('\n') };
    },

    clear: function () {
      if (mode === 'account') {
        mem = {};
        write({ a: 'clear' });
        announce();
        return;
      }
      commitLocal({});
    }
  };

  window.PM_PROGRESS = API;

  /* ---- messages ----------------------------------------------------------
     One bar, at the top of the page, for the two things this file ever needs to
     say to a learner: "your old progress is still in this browser, shall I bring
     it across?" and "that did not save". Built here rather than in each page's
     markup because all three pages that show progress need both. */

  function bar(className) {
    var el = document.createElement('div');
    el.className = 'pm-bar ' + className;
    document.body.insertBefore(el, document.body.firstChild);
    return el;
  }

  function warn(text) {
    var existing = document.querySelector('.pm-bar.pm-warn');
    if (existing) { existing.textContent = text; return; }
    var el = bar('pm-warn');
    el.setAttribute('role', 'alert');
    el.textContent = text;
  }

  /* The import offer.

     Only ever shown when the account has nothing recorded for this course and
     this browser does. Anything already on the account wins, and the server
     side only ever adds, so pressing it twice is harmless — but it is offered
     rather than done automatically, because silently moving somebody's data
     between two places is not ours to decide even when it is obviously helpful. */
  function offerImport() {
    var local = localStore();
    if (isEmptyTree(local)) return;

    var el = bar('pm-import');
    var n = 0;
    for (var k in local) {
      if (!local.hasOwnProperty(k)) continue;
      for (var c in (local[k].topics || {})) if (local[k].topics.hasOwnProperty(c)) n++;
    }

    el.innerHTML =
      '<div><strong>You have progress saved in this browser from before you had an account.</strong>' +
      '<span>' + n + ' topic' + (n === 1 ? '' : 's') + ' ticked off. Bring it across and it ' +
      'follows you to any device. Nothing already on your account is overwritten.</span></div>' +
      '<div class="pm-bar-act"><button type="button" class="btn btn-primary" id="pmImportGo">' +
      'Bring it across</button>' +
      '<button type="button" class="linkish" id="pmImportNo">Not now</button></div>';

    el.querySelector('#pmImportNo').addEventListener('click', function () { el.remove(); });
    el.querySelector('#pmImportGo').addEventListener('click', function () {
      var btn = this;
      btn.disabled = true; btn.textContent = 'Bringing it across…';
      acct.post({ a: 'import', course: COURSE, payload: JSON.stringify(local) })
        .then(function (res) {
          if (!res || !res.ok) {
            btn.disabled = false; btn.textContent = 'Bring it across';
            warn('That did not work. Nothing was changed — try again in a moment.');
            return;
          }
          mem = res.progress || mem;
          el.className = 'pm-bar pm-done';
          el.innerHTML = '<div><strong>Brought across — ' + res.added + ' item' +
            (res.added === 1 ? '' : 's') + ' added to your account.</strong>' +
            '<span>Your browser still has its own copy. Clear it from ' +
            '<a href="profile">your profile</a> if this is a shared machine.</span></div>';
          announce();
        });
    });
  }

  /* ---- start -------------------------------------------------------------
     profile.js owns the session probe and is loaded before this file on every
     page that uses it. If it is not there — an academy that has not been ported
     yet, or a page that only wants the local behaviour — everything above still
     works against localStorage, which is what it did before accounts existed. */
  acct = window.SPSAccount || null;

  if (!acct) { announce(); return; }

  acct.ready(function (session) {
    if (!session) { announce(); return; }

    /* Active, not merely present. A withdrawn or completed enrolment is refused
       by the server on every write, so treating it as the live store would give
       a learner a page that ticks and then un-ticks itself. They keep the local
       behaviour instead — as does somebody signed in but on a different
       qualification, reading these pages out of interest. */
    var enrolled = (session.courses || []).some(function (c) {
      return c.slug === COURSE && c.status === 'active';
    });
    if (!enrolled) { announce(); return; }

    mode = 'account';
    mem  = (session.progress && session.progress[COURSE]) || {};
    announce();

    if (isEmptyTree(mem)) offerImport();
  });
})();
