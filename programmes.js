/* ---- Technical & artisan programmes --------------------------------------
   The Fisha Renaissance programme menu, rendered on programmes.html.

   WHERE THIS CAME FROM
   --------------------
   Kgomotso, 24 Aug 2026: "Please add the attached as part of our offering. Add
   Fiston as the subject matter trainer under our Team. His course is relevant
   to Fungi Bolide SPS academy." The attachment was Fisha Renaissance NPC's
   Programme & Course Menu 2026 — 57 programmes across seven delivery streams,
   NQF 2 to 5, two days to three years.

   Fisha Renaissance NPC is Fiston Nselike's organisation. He is the subject
   matter trainer for this material; see trainers.js.

   WHY THIS IS A LIST AND NOT 57 COURSE PAGES
   ------------------------------------------
   course.html builds a full course page per entry: modules, lessons, videos,
   workbooks. We have none of that for these. The menu gives a paragraph and a
   duration each, which is exactly enough for a catalogue and nowhere near
   enough for a curriculum — and course.html would quietly fill the gap with
   defaultModules(), inventing a syllabus for 57 real programmes somebody else
   delivers. That is the one thing this must not do.

   So the menu is presented as a menu: what it is, how long, what level. When a
   specific programme is actually sold and a real outline arrives, THAT one gets
   promoted to a course.html entry with its own page.

   NO PRICES. Sibusiso's instruction, and it matches Fisha's own note that
   pricing is left open and quoted per project. Do not add a price column here
   even if someone pastes you a rate card — the page says pricing is quoted, and
   that has to stay true.

   WHAT IS THEIRS AND WHAT IS OURS
   -------------------------------
   The stream names, programme names and the "what it is" text are Fisha's own,
   from a document they supplied for exactly this purpose, lightly trimmed for
   length. The framing around them is ours. Nothing has been invented: where
   their menu says a duration is to be confirmed, this says so too rather than
   guessing a number that somebody would plan around.

   Durations they marked with an asterisk are indicative, drawn from a
   specialist training brochure, and are flagged as such — that is their caveat
   and it travels with the number.

   ON THE SYNC MANIFEST, because the menu is Centenary's partner offering rather
   than any one client's. programmes.html is not, like every other .html.
   -------------------------------------------------------------------------- */
(function () {

  var PROVIDER = {
    name: 'Fisha Renaissance NPC',
    tag: 'Igniting potential. Powering progress.',
    blurb: 'Workforce development, artisan development, technical skills, renewable ' +
           'energy, employability and enterprise. Fifty-seven programmes across seven ' +
           'delivery streams, from two days to three years, at NQF 2 to 5.'
  };

  var STREAMS = [
    { id: 'A', title: 'Artisan & Occupational Development',
      blurb: 'Long-cycle trade pathways, apprenticeships, recognition of prior learning and ' +
             'trade-test readiness across the core engineering trades.' },
    { id: 'B', title: 'Electrical Compliance & Specialist Electrical Skills',
      blurb: 'Regulatory readiness, installation rules and specialist electrical capability ' +
             'for practitioners already working in the field.' },
    { id: 'C', title: 'Renewable Energy & Green Skills',
      blurb: 'Occupational pathways from NQF 4 mounting and installation through to NQF 5 ' +
             'service technician, plus short-course solar capability.' },
    { id: 'D', title: 'Welding, Fabrication & Mechanical Short Courses',
      blurb: 'Two days to two weeks — fast, practical upskilling from first weld to ' +
             'specialist alloy and pipe work.' },
    { id: 'E', title: 'Plumbing & General Technical Skills',
      blurb: 'Entry-level and maintenance capability for property, facilities and hot-water ' +
             'installation work.' },
    { id: 'F', title: 'Workplace, Employability & Career Development',
      blurb: 'Work-integrated learning, placement, work readiness and enterprise development ' +
             '— the bridge between training and employment.' },
    { id: 'G', title: 'Project, Learner & Employer Solutions',
      blurb: 'Managed services for employers, funders and SETA-funded projects — from learner ' +
             'sourcing to project close-out.' }
  ];

  /* s = stream · t = title · d = duration ('' where their menu says to be
     confirmed) · i = their duration carried an asterisk, meaning indicative
     · w = what it is */
  var PROGRAMMES = [

    /* A — Artisan & Occupational Development */
    { s:'A', t:'Occupational Certificate: Electrician', d:'3 years / project dependent',
      w:'A structured artisan-development pathway that integrates occupational knowledge, practical electrical training and workplace experience to develop competent electricians and prepare candidates for the required external assessment and trade pathway.' },
    { s:'A', t:'Trade Test Preparation & Trade Test — Electrical', d:'3 weeks', i:true,
      w:'Focused practical and theoretical preparation for eligible electrical candidates approaching a trade test, with emphasis on closing competency gaps, practising trade-test tasks and strengthening assessment readiness.' },
    { s:'A', t:'Trade Test / ARPL Assessment Support — Electrical', d:'3 to 6 months',
      w:'Administrative and technical support for eligible candidates progressing through ARPL or trade-test assessment processes, including readiness checks, evidence preparation and coordination with the appropriate assessment environment.' },
    { s:'A', t:'Electrician’s Assistant (Low Voltage Systems) — NQF 2', d:'30 days',
      w:'An occupational skills programme that develops foundational practical capability for individuals entering the electrical industry and prepares them to assist with low-voltage electrical work under appropriate supervision. Includes a first assessment attempt.' },
    { s:'A', t:'Occupational Certificate: Diesel Mechanic / Apprenticeship', d:'Approx. 3 years / project dependent',
      w:'An artisan-development pathway for candidates entering diesel-mechanical work, combining structured learning, practical workshop exposure and workplace experience towards occupational competence in the maintenance and repair of diesel-powered equipment.' },
    { s:'A', t:'Occupational Certificate: Boilermaker / Apprenticeship', d:'Approx. 3 years / project dependent',
      w:'A structured artisan pathway developing the knowledge, fabrication skills and workplace experience required for boilermaking work, including preparation, forming, assembly and related metalwork activities.' },
    { s:'A', t:'Trade Test Preparation & Trade Test — Millwright', d:'4 weeks', i:true,
      w:'Trade-development and preparation support for millwright candidates, covering the integrated mechanical and electrical competencies required to install, maintain, diagnose and repair industrial machinery and systems.' },
    { s:'A', t:'Trade Test Preparation & Trade Test — Mechanical Fitter', d:'4 weeks', i:true,
      w:'Practical trade preparation and assessment support for mechanical fitter candidates working with machinery, assemblies, bearings, alignment, maintenance and mechanical systems in industrial environments.' },
    { s:'A', t:'Trade Test Preparation & Trade Test — Fitter & Turner', d:'4 weeks', i:true,
      w:'Trade preparation and practical skills development for candidates working in fitting, machining, turning, assembly and maintenance of precision mechanical components and equipment.' },
    { s:'A', t:'Trade Test Preparation & Trade Test — Motor Mechanic', d:'4 weeks', i:true,
      w:'Preparation and assessment support for motor-mechanic candidates developing competence in vehicle inspection, servicing, fault diagnosis, repair and maintenance.' },
    { s:'A', t:'Trade Test Preparation & Trade Test — Welding', d:'3 weeks', i:true,
      w:'Practical preparation and assessment support for welding candidates, strengthening safe welding practice, process control, joint preparation and trade-test readiness across relevant welding applications.' },
    { s:'A', t:'Trade Test Preparation & Trade Test — Plumbing', d:'3 weeks', i:true,
      w:'Practical trade preparation and assessment support for plumbing candidates, covering installation, maintenance, fault identification and preparation for applicable trade assessment requirements.' },

    /* B — Electrical Compliance & Specialist Electrical Skills */
    { s:'B', t:'Wireman’s Licence / Certificate of Compliance Preparation — Single Phase', d:'3 days class + 2-week evidence period', i:true,
      w:'Preparation for eligible electrical practitioners working towards the regulatory knowledge and evidence requirements associated with single-phase electrical installation work and the Certificate of Compliance environment.' },
    { s:'B', t:'Wireman’s Licence / Certificate of Compliance Preparation — Three Phase', d:'3 days class + 2-week evidence period', i:true,
      w:'Preparation for eligible practitioners requiring three-phase installation-rules knowledge, evidence compilation and readiness for the applicable regulatory process.' },
    { s:'B', t:'Installation Rules P1 & P2', d:'',
      w:'Focused preparation covering the legal, safety and technical rules applicable to electrical installations, supporting practitioners who need stronger understanding of installation requirements and compliance responsibilities. Registration and supporting materials; exam dates are set by the department.' },
    { s:'B', t:'Specialised Installation Rules P1 & P2', d:'',
      w:'A focused revision and preparation intervention for electrical practitioners who need targeted support with installation-rules requirements and assessment readiness.' },
    { s:'B', t:'Electrical Fence Training', d:'',
      w:'Practical training introducing the installation, operation, safety and maintenance principles applicable to electric fencing systems.' },
    { s:'B', t:'Electric Fence COC Course', d:'',
      w:'A compliance-focused course for eligible practitioners requiring knowledge and preparation relating to electric-fence certification and applicable installation responsibilities.' },
    { s:'B', t:'PLC Training', d:'',
      w:'Practical introductory-to-advanced training in programmable logic controllers, helping technical personnel understand PLC operation, inputs and outputs, logic, fault finding and industrial automation applications.' },
    { s:'B', t:'Electrical Semi-Skilled Training', d:'Approx. 3 weeks', i:true,
      w:'A practical short programme that builds basic electrical installation, maintenance, tool-use and safety capability for assistants, maintenance personnel and entry-level technical workers.' },

    /* C — Renewable Energy & Green Skills */
    { s:'C', t:'Renewable Energy Workshop Assistant — NQF 4', d:'',
      w:'An occupational skills programme that introduces learners to renewable-energy workshop activities and develops practical support capability for technical environments; it can also be contextualised for SME energy resilience, business continuity and green-economy awareness.' },
    { s:'C', t:'Solar Photovoltaic Standalone System Mounter — NQF 4', d:'25 days',
      w:'Develops practical capability for the mechanical preparation and mounting of standalone solar PV components, supporting safe and structured entry into solar installation environments. Includes a first assessment attempt.' },
    { s:'C', t:'Solar Photovoltaic Standalone Systems Installer — NQF 4', d:'',
      w:'Develops occupational competence in the electrical installation of standalone solar PV systems, combining technical understanding with practical installation capability relevant to the growing solar sector.' },
    { s:'C', t:'Solar Photovoltaic Service Technician — NQF 5', d:'',
      w:'Develops more advanced competence in inspection, fault finding, servicing and maintenance of solar photovoltaic systems to support reliable operation throughout the system lifecycle.' },
    { s:'C', t:'Higher Occupational Certificate: Solar Photovoltaic Standalone Service Technician — NQF 5', d:'',
      w:'An advanced occupational pathway focused on servicing, diagnosing, maintaining and supporting standalone solar PV systems for candidates progressing into specialised renewable-energy technical roles.' },
    { s:'C', t:'Solar PV Training — Beginner', d:'5 days',
      w:'A practical introduction to solar photovoltaic technology covering core system components, basic system principles, safety and the fundamentals of installation and operation.' },
    { s:'C', t:'Solar PV Training — Advanced', d:'5 days',
      w:'Advanced practical development for technical personnel requiring deeper capability in PV system configuration, installation, commissioning, fault finding, maintenance and technical problem solving.' },
    { s:'C', t:'Energy Performance Certificate (EPC) Practitioner', d:'',
      w:'A skills-development pathway that builds knowledge of building energy performance, energy-efficiency assessment and the regulatory environment associated with Energy Performance Certificates, subject to applicable practitioner requirements.' },
    { s:'C', t:'Solar Trade Test Technician Preparation', d:'',
      w:'Targeted technical preparation for eligible candidates requiring practical solar-related assessment readiness, with emphasis on safe installation, testing, diagnosis and applicable technical competencies.' },

    /* D — Welding, Fabrication & Mechanical Short Courses */
    { s:'D', t:'Basic TIG Welding', d:'1 week', i:true,
      w:'An introductory practical course that teaches the fundamentals of TIG welding, safe equipment use, joint preparation and basic welding technique.' },
    { s:'D', t:'Basic Arc / Stick Welding', d:'1 week', i:true,
      w:'A practical introductory course in shielded metal arc welding, covering safety, electrode selection, basic joints and controlled welding technique.' },
    { s:'D', t:'Basic Gas / CO₂ Welding', d:'1 week', i:true,
      w:'Introduces learners to gas and CO₂ welding processes, equipment setup, safe operation and basic welding applications.' },
    { s:'D', t:'Basic Brazing & Welding', d:'2 days', i:true,
      w:'A short practical course covering basic brazing and joining techniques, equipment handling and safe workshop practice.' },
    { s:'D', t:'Combo Welding', d:'2 weeks', i:true,
      w:'A combined practical course that develops capability across selected welding processes such as gas and arc, MIG and TIG, brazing and CO₂, or stick and gas welding.' },
    { s:'D', t:'Advanced TIG Welding', d:'1 week', i:true,
      w:'Advanced practical TIG development for candidates who already understand the basics and need stronger control, quality and application skills.' },
    { s:'D', t:'Advanced Arc / Stick Welding', d:'1 week', i:true,
      w:'Advanced practical development in arc and stick welding focused on improving consistency, technique, joint quality and application across more demanding work.' },
    { s:'D', t:'Advanced Gas / CO₂ Welding', d:'1 week', i:true,
      w:'Advanced practical development in gas and CO₂ welding for candidates seeking stronger process control and application capability.' },
    { s:'D', t:'Pipe Welding', d:'',
      w:'Practical welding development focused on pipe preparation, positioning, joining and welding technique for relevant industrial applications.' },
    { s:'D', t:'Aluminium Welding', d:'',
      w:'Specialist practical training introducing the techniques, equipment considerations and controls required when welding aluminium.' },
    { s:'D', t:'Stainless Steel Welding', d:'',
      w:'Specialist practical training in welding stainless steel, with attention to process selection, preparation, heat control and weld quality.' },
    { s:'D', t:'Boilermaking Skills Programme', d:'',
      w:'Practical fabrication training covering core boilermaking activities such as pipe bending, plate bending, rolling, measurement and use of workshop machinery.' },

    /* E — Plumbing & General Technical Skills */
    { s:'E', t:'Semi-Skilled Plumbing', d:'',
      w:'Practical introductory plumbing development covering basic installation, repairs, tool use, fault identification and safe working practices for entry-level or maintenance personnel.' },
    { s:'E', t:'Solar Geyser Installation', d:'3 weeks', i:true,
      w:'Practical training in the principles and installation of solar water-heating systems, including safe mounting, plumbing connections and basic system operation.' },
    { s:'E', t:'Hot Water Installation', d:'3 weeks', i:true,
      w:'Practical training in hot-water system installation, connection, safety and basic maintenance for relevant plumbing applications.' },
    { s:'E', t:'Pipe Fitting', d:'3 weeks', i:true,
      w:'Practical development in pipe measurement, preparation, fitting, joining and installation for plumbing and related technical environments.' },
    { s:'E', t:'Handy Person Programme', d:'',
      w:'A broad practical maintenance programme that develops entry-level capability across common property and facilities tasks such as basic maintenance, minor plumbing, basic electrical awareness, tool use and general repairs within lawful limits.' },

    /* F — Workplace, Employability & Career Development */
    { s:'F', t:'Work Integrated Learning (WIL) — P1 & P2', d:'Programme / institution dependent',
      w:'Structured workplace placement and monitoring support for university or TVET learners who need practical industry exposure to complete P1/P2 or related work-integrated learning requirements.' },
    { s:'F', t:'TVET / N6 Workplace Experience Placement', d:'Programme dependent',
      w:'Employer-matching, placement coordination, monitoring and administrative support for N6 graduates and other TVET candidates requiring workplace experience towards career or qualification progression.' },
    { s:'F', t:'Candidacy Programme', d:'Programme dependent',
      w:'A structured professional-development pathway combining workplace exposure, mentoring, competency development, evidence building and career preparation for candidates progressing from academic learning into professional or technical practice.' },
    { s:'F', t:'Work Essentials Programme', d:'30 days',
      w:'A work-readiness programme developing professional conduct, communication, teamwork, workplace safety, digital literacy, accountability and the practical behaviours expected by employers.' },
    { s:'F', t:'Entrepreneurship & Work-Readiness Sprint', d:'2 weeks',
      w:'A short practical intervention covering business basics, customer service, pricing, compliance, digital marketing, tender awareness and employability skills for technical graduates and emerging entrepreneurs.' },
    { s:'F', t:'New Venture Creation', d:'30 days',
      w:'An entrepreneurship programme that helps aspiring business owners understand business planning, customers, costing, financial management, marketing, compliance and the fundamentals of establishing and growing a sustainable enterprise.' },
    { s:'F', t:'Career Awareness & Engineering Futures Programme', d:'Event / project dependent',
      w:'A career-development intervention exposing learners and young people to engineering, artisan and technical career pathways, entry requirements, workplace expectations and opportunities in the changing world of work.' },

    /* G — Project, Learner & Employer Solutions */
    { s:'G', t:'Occupational Certificate: Project Manager', d:'',
      w:'A structured occupational programme developing the knowledge and practical capability required to plan, coordinate, monitor and close projects while managing scope, time, resources, stakeholders and delivery requirements.' },
    { s:'G', t:'Host Employer & Workplace Placement Programme', d:'Project dependent',
      w:'A managed service that matches learners with suitable host employers, coordinates workplace agreements, monitors learner exposure and supports employers with the administration needed for structured workplace learning.' },
    { s:'G', t:'Learner Recruitment, Administration & Programme Coordination', d:'Project dependent',
      w:'An end-to-end project support service covering learner sourcing, screening, onboarding, document control, attendance and progress tracking, stakeholder communication, evidence management and reporting for funded or employer-sponsored programmes.' },
    { s:'G', t:'Skills Development & SETA Project Implementation', d:'Project dependent',
      w:'A managed implementation service for employers, funders and partners requiring support with funded skills programmes, learner administration, workplace coordination, monitoring, evidence, reporting and project close-out.' }
  ];

  var host = document.getElementById('programmes');
  if (!host) return;

  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function row(p) {
    var dur = p.d
      ? esc(p.d) + (p.i ? '<abbr title="Indicative duration, to be confirmed in the final quotation">*</abbr>' : '')
      : '<span class="pg-tbc">Confirmed per project</span>';
    return '<article class="pg-item">' +
      '<div class="pg-main">' +
        '<h4>' + esc(p.t) + '</h4>' +
        '<p>' + esc(p.w) + '</p>' +
      '</div>' +
      '<div class="pg-dur"><span>Duration</span><strong>' + dur + '</strong></div>' +
    '</article>';
  }

  var counts = {};
  PROGRAMMES.forEach(function (p) { counts[p.s] = (counts[p.s] || 0) + 1; });

  var html =
    '<div class="pg-intro">' +
      '<p class="pg-lead">' + esc(PROVIDER.blurb) + '</p>' +
      '<div class="pg-stats">' +
        '<div><strong>' + PROGRAMMES.length + '</strong><span>Programmes &amp; courses</span></div>' +
        '<div><strong>' + STREAMS.length + '</strong><span>Delivery streams</span></div>' +
        '<div><strong>2 days – 3 yrs</strong><span>Duration range</span></div>' +
        '<div><strong>NQF 2–5</strong><span>Occupational levels</span></div>' +
      '</div>' +
    '</div>';

  /* A jump list, because seven streams and fifty-seven rows is a long page and
     most people arrive wanting one of them. */
  html += '<nav class="pg-jump" aria-label="Delivery streams">' +
    STREAMS.map(function (s) {
      return '<a href="#stream-' + s.id + '"><b>' + s.id + '</b>' + esc(s.title) +
             '<i>' + (counts[s.id] || 0) + '</i></a>';
    }).join('') + '</nav>';

  STREAMS.forEach(function (s) {
    var mine = PROGRAMMES.filter(function (p) { return p.s === s.id; });
    if (!mine.length) return;
    html += '<section class="pg-stream" id="stream-' + s.id + '">' +
      '<div class="pg-head"><span class="pg-letter">' + s.id + '</span>' +
        '<div><h3>' + esc(s.title) + '</h3><p>' + esc(s.blurb) + '</p></div></div>' +
      mine.map(row).join('') +
    '</section>';
  });

  /* Fisha's own caveats, carried across rather than paraphrased away. They are
     the difference between a catalogue and a promise. */
  html +=
    '<div class="pg-notes">' +
      '<strong>Before anyone enrols</strong>' +
      '<ul>' +
        '<li>Availability depends on Fisha Renaissance’s current scope, the project ' +
          'requirements, the accreditation or approval arrangements and the delivery model ' +
          'agreed with the client or funder.</li>' +
        '<li>Some specialist trade courses are coordinated and delivered through approved ' +
          'specialist implementation partners, with Fisha Renaissance as the client-facing ' +
          'project lead.</li>' +
        '<li>Durations marked <abbr title="Indicative duration">*</abbr> are indicative and ' +
          'are confirmed in the final quotation before contracting.</li>' +
        '<li><strong>Pricing is quoted per project</strong> — by learner numbers, venue, ' +
          'materials, assessment requirements, workplace components and administration. ' +
          'No prices are published here.</li>' +
        '<li>Entry requirements differ by programme. Trade-test, ARPL, compliance and ' +
          'professional pathways all require eligibility checks before enrolment.</li>' +
        '<li>These are <strong>partner-delivered programmes</strong>. They are not the ' +
          'academy’s own courses, and only the Occupational Certificate: Project Manager ' +
          'route delivered with Centenary Networks carries our own accreditation.</li>' +
      '</ul>' +
    '</div>';

  host.innerHTML = html;
})();
