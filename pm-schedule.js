/* ---- Project Manager NQF 5: turning a self-paced programme into a diary ----

   The provider has confirmed that this qualification is delivered
   SELF-PACED. Nothing here changes that. What this file does is work out what a
   chosen weekly commitment implies — when each module would start and finish,
   and when it would all be over — and emit calendar events so the time is
   actually blocked out rather than intended.

   Two figures are registered and are not ours to adjust: each module's credits,
   and the notional hours that follow from them (SAQA: 1 credit = 10 notional
   hours). Everything else on this page is arithmetic on top of those.

   OFFICIAL_DATES stays null until Centenary confirms a programme calendar. While
   it is null the page says plainly that the plan is indicative — we must not
   present our own arithmetic as the provider's schedule.

   Exposed as window.PM_SCHEDULE in the browser and module.exports under Node so
   the .ics generation can be tested directly. */
(function () {

  /* Set this only when Centenary confirms real programme dates. */
  var OFFICIAL_DATES = null;
  /* The intake start date, confirmed by Centenary Networks on 12 Aug 2026.
     This is theirs; the per-module dates below it are still ours, so the page
     goes on saying the plan is indicative. Two different claims, and collapsing
     them would put the provider's name on our arithmetic. */
  var OFFICIAL_START = '2026-09-10';
  /* Calendar identity. This ends up inside the .ics file the learner downloads,
     so it has to name the academy they actually study with — the one field in
     here that is not brand-neutral. Change both when porting to another site. */
  var BRAND = 'M&T Academy';
  var UID_HOST = 'mt.academy';

  var DAY_MS = 86400000;
  var DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  var ICS_DAYS = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];

  /* Which weekdays the study sessions land on, by sessions-per-week. Spread
     across the week rather than stacked, so a missed session has room to be
     made up before the next one. 1=Mon … 5=Fri. */
  var SESSION_DAYS = { 1: [3], 2: [2, 4], 3: [1, 3, 5] };

  /* ---- date helpers: all UTC, so a browser west of Greenwich doesn't shift
     every all-day event back by one day ---- */
  function toUTC(d) {
    return new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
  }
  function parseISO(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(s || '').trim());
    if (!m) return null;
    return new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
  }
  function addDays(d, n) { return new Date(d.getTime() + n * DAY_MS); }
  function ymd(d) {
    return d.getUTCFullYear() +
      String(d.getUTCMonth() + 1).padStart(2, '0') +
      String(d.getUTCDate()).padStart(2, '0');
  }
  function human(d) {
    return d.getUTCDate() + ' ' +
      ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getUTCMonth()] +
      ' ' + d.getUTCFullYear();
  }
  /* Nudge a date forward to the next weekday that is not a weekend, so a module
     never appears to begin on a Sunday. */
  function nextWeekday(d) {
    var x = new Date(d.getTime());
    while (x.getUTCDay() === 0 || x.getUTCDay() === 6) x = addDays(x, 1);
    return x;
  }

  /* ---- the plan ----
     Modules run in curriculum order, one after another. Each takes as many whole
     weeks as its notional hours need at the chosen pace, minimum one week. */
  function planModules(modules, startISO, hoursPerWeek) {
    var start = parseISO(startISO);
    if (!start || !modules || !modules.length) return null;
    var hpw = Math.max(1, +hoursPerWeek || 1);

    var cursor = nextWeekday(start);
    var rows = modules.map(function (m) {
      var hours = +m.hours || (+m.credits || 0) * 10;
      var weeks = Math.max(1, Math.ceil(hours / hpw));
      var from = nextWeekday(cursor);
      var to = addDays(from, weeks * 7 - 1);        // inclusive last day
      cursor = addDays(to, 1);
      return {
        id: m.id, title: m.title, credits: m.credits,
        hours: hours, weeks: weeks, from: from, to: to,
        purpose: m.purpose || ''
      };
    });

    var totalHours = rows.reduce(function (a, r) { return a + r.hours; }, 0);
    return {
      rows: rows,
      start: rows[0].from,
      end: rows[rows.length - 1].to,
      totalHours: totalHours,
      totalWeeks: rows.reduce(function (a, r) { return a + r.weeks; }, 0),
      totalCredits: rows.reduce(function (a, r) { return a + (+r.credits || 0); }, 0),
      hoursPerWeek: hpw,
      official: !!OFFICIAL_DATES
    };
  }

  /* ---- iCalendar ----
     RFC 5545 is fussy in three ways that break calendars silently if ignored:
     CRLF line endings, escaping of , ; \ and newlines inside text, and folding
     of any line over 75 octets. All three are handled here. */
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/\\/g, '\\\\')
      .replace(/;/g, '\\;')
      .replace(/,/g, '\\,')
      .replace(/\r?\n/g, '\\n');
  }

  function fold(line) {
    // fold on octets, not characters — a multi-byte char must not be split
    var bytes = [];
    for (var i = 0; i < line.length; i++) {
      var cp = line.charCodeAt(i);
      var ch = line[i];
      var size = cp < 0x80 ? 1 : cp < 0x800 ? 2 : 3;
      bytes.push({ ch: ch, size: size });
    }
    var out = '', cur = 0, first = true;
    for (var j = 0; j < bytes.length; j++) {
      var limit = first ? 75 : 74;             // continuation lines carry a leading space
      if (cur + bytes[j].size > limit) {
        out += '\r\n ';
        cur = 1; first = false;
      }
      out += bytes[j].ch;
      cur += bytes[j].size;
    }
    return out;
  }

  function stamp() {
    var d = new Date();
    return d.getUTCFullYear() +
      String(d.getUTCMonth() + 1).padStart(2, '0') +
      String(d.getUTCDate()).padStart(2, '0') + 'T' +
      String(d.getUTCHours()).padStart(2, '0') +
      String(d.getUTCMinutes()).padStart(2, '0') +
      String(d.getUTCSeconds()).padStart(2, '0') + 'Z';
  }

  /* Local (floating) date-time — no Z and no TZID, so the event lands at the
     stated wall-clock time in whatever timezone the learner's calendar uses.
     That is the right behaviour for a study block. */
  function localDT(d, hh, mm) {
    return ymd(d) + 'T' +
      String(hh).padStart(2, '0') + String(mm).padStart(2, '0') + '00';
  }

  function buildICS(plan, opts) {
    if (!plan) return '';
    opts = opts || {};
    var sessions = SESSION_DAYS[opts.sessionsPerWeek] || SESSION_DAYS[2];
    var timeStr = opts.sessionTime || '09:00';
    var tm = /^(\d{1,2}):(\d{2})$/.exec(timeStr) || [null, '9', '00'];
    var hh = +tm[1], mm = +tm[2];
    var perSession = plan.hoursPerWeek / sessions.length;
    var durMin = Math.max(30, Math.round(perSession * 60));
    var base = opts.baseUrl || '';
    var uidSeed = stamp();

    var L = [
      'BEGIN:VCALENDAR',
      'VERSION:2.0',
      'PRODID:-//' + BRAND + '//Project Manager NQF 5//EN',
      'CALSCALE:GREGORIAN',
      'METHOD:PUBLISH',
      'X-WR-CALNAME:' + esc('Project Manager NQF 5 — study plan'),
      'X-WR-CALDESC:' + esc(
        'Self-paced Occupational Certificate: Project Manager (SAQA 101869). ' +
        'Module blocks and study sessions at ' + plan.hoursPerWeek + ' hours per week. ' +
        (plan.official ? '' : 'Indicative — Centenary confirms the official programme dates.'))
    ];

    /* one all-day block per module */
    plan.rows.forEach(function (r, i) {
      L.push('BEGIN:VEVENT');
      L.push('UID:' + r.id.toLowerCase() + '-block-' + uidSeed + '@' + UID_HOST);
      L.push('DTSTAMP:' + stamp());
      L.push('DTSTART;VALUE=DATE:' + ymd(r.from));
      L.push('DTEND;VALUE=DATE:' + ymd(addDays(r.to, 1)));   // DTEND is exclusive
      L.push('SUMMARY:' + esc(r.id + ' · ' + r.title));
      L.push('DESCRIPTION:' + esc(
        'Module ' + (i + 1) + ' of ' + plan.rows.length + ' — ' + r.credits +
        ' credits, ' + r.hours + ' notional hours, ' + r.weeks +
        (r.weeks === 1 ? ' week' : ' weeks') + '.' +
        (r.purpose ? '\n\n' + r.purpose : '') +
        (base ? '\n\n' + base + 'module?m=' + r.id : '') +
        '\n\nSelf-paced: this block is the plan, not a deadline set by the provider.'));
      L.push('TRANSP:TRANSPARENT');          // a module block should not show as busy
      L.push('END:VEVENT');
    });

    /* recurring study sessions across the whole programme */
    var firstSession = new Date(plan.start.getTime());
    while (sessions.indexOf(firstSession.getUTCDay()) < 0) firstSession = addDays(firstSession, 1);
    var endEx = addDays(plan.end, 1);

    L.push('BEGIN:VEVENT');
    L.push('UID:study-sessions-' + uidSeed + '@' + UID_HOST);
    L.push('DTSTAMP:' + stamp());
    L.push('DTSTART:' + localDT(firstSession, hh, mm));
    L.push('DTEND:' + localDT(firstSession, hh, mm + durMin));
    L.push('RRULE:FREQ=WEEKLY;BYDAY=' + sessions.map(function (d) { return ICS_DAYS[d]; }).join(',') +
           ';UNTIL=' + ymd(endEx) + 'T235900');
    L.push('SUMMARY:' + esc('Project Manager study session'));
    L.push('DESCRIPTION:' + esc(
      plan.hoursPerWeek + ' hours a week across ' + sessions.length +
      (sessions.length === 1 ? ' session' : ' sessions') + '. ' +
      'Protected time for the Occupational Certificate: Project Manager.' +
      (base ? '\n\n' + base + 'pm-schedule' : '')));
    L.push('END:VEVENT');

    L.push('END:VCALENDAR');
    return L.map(fold).join('\r\n') + '\r\n';
  }

  var API = {
    OFFICIAL_DATES: OFFICIAL_DATES,
    OFFICIAL_START: OFFICIAL_START,
    SESSION_DAYS: SESSION_DAYS,
    DAY_NAMES: DAY_NAMES,
    planModules: planModules,
    buildICS: buildICS,
    human: human,
    ymd: ymd,
    parseISO: parseISO,
    fold: fold,
    esc: esc
  };

  if (typeof module !== 'undefined' && module.exports) module.exports = API;
  if (typeof window !== 'undefined') window.PM_SCHEDULE = API;
})();
