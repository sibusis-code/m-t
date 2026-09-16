/* ---- Procurement Officer NQF 5: the eight knowledge modules ----------------
   Generated from the provider's QCTO curriculum document (curriculum
   332302-001-01-00, SAQA 111445) on 16 Sep 2026 — SECTION 3A, which gives the
   modules, their credits, each topic and its weighting, and the topic elements.
   These are the REGISTERED figures, not marketing copy, and must not be
   hand-edited. Every module's weightings sum to 100 and the credits sum to 79;
   both are asserted at the bottom of this file, so a bad edit fails loudly.

   The qualification is 180 credits in total: 79 knowledge, 63 practical skill
   and 38 work experience. Only the knowledge modules are studied on the site.

   THREE NUMBERING ERRORS IN THE SOURCE, corrected here and listed so nobody
   "fixes" them back:
     - SECTION 1 lists both KM-01 topics as KM-01-KT01. The second is KT02.
     - The fourth topic of KM-03 is headed "3.2.2. KM-04-KT04". It sits under
       section 3, is about sourcing, and is KM-03-KT04.
     - KM-08's first topic is headed "8.8.1." where the others read "8.2.N.".
   Topics are assigned by the section number they sit under, never by the code
   printed in the heading.

   What is deliberately NOT here: the teaching prose, and the "idea in one
   paragraph" that pm-modules.js carries per topic — those come from the learner
   guides, which are Centenary's material and access-controlled. A topic without
   one simply renders without it.

   Assessment papers, marking memos and facilitator guides are never published.

   ---- TO PUT A DOCUMENT LIVE ----
   Not here. Links live in the database; paste them on the Material page under
   Administration, and materials.php hands them only to a signed-in learner who
   is enrolled. A module with nothing attached renders as "ask HR for a copy". */
(function () {

  const MODULES = [
  {
    id: "KM-01", code: "332302-001-01-00-KM-01",
    title: "Introduction to supply chain management",
    nqf: 5, credits: 10, hours: 100,
    purpose: "Develop an understanding of the supply chain as an integrated system",
    topics: [
      { code: "KM-01-KT01", n: "Supply chain concepts", w: 50,
        covers: [
          "Essential components of a supply chain",
          "Fundamental concepts and principles of supply chain management",
          "Key roles of supply chain management"
        ] },
      { code: "KM-01-KT02", n: "Supply chain management processes", w: 50,
        covers: [
          "Supply chain management processes",
          "Internal and external supply chain integration",
          "Supply chain tools, and technologies",
          "Supply chain trade-offs"
        ] }
    ]
  },
  {
    id: "KM-02", code: "332302-001-01-00-KM-02",
    title: "Demand execution management operations",
    nqf: 5, credits: 10, hours: 100,
    purpose: "Develop an understanding of demand execution management activities The learning contract time, which is the time that reflects the required duration of enrolment for this module, is at least 12",
    topics: [
      { code: "KM-02-KT01", n: "Customer/end user relationship management", w: 25,
        covers: [
          "Introduction to customer relationship management",
          "Customer and service segmentation"
        ] },
      { code: "KM-02-KT02", n: "Customer service management", w: 25,
        covers: [
          "Dimensions of customer services",
          "Service delivery",
          "Service level agreements (SLAs)",
          "Service recovery"
        ] },
      { code: "KM-02-KT03", n: "Customer/End user order management", w: 25,
        covers: [
          "Customer/End user order forecast",
          "Customer/End user order processes"
        ] },
      { code: "KM-02-KT04", n: "Managing service delivery", w: 25,
        covers: [
          "Planning order delivery",
          "Credit control",
          "Service execution management"
        ] }
    ]
  },
  {
    id: "KM-03", code: "332302-001-01-00-KM-03",
    title: "Sourcing in procurement and supply",
    nqf: 6, credits: 12, hours: 120,
    purpose: "Develop an understanding of sourcing in procurement and supply",
    topics: [
      { code: "KM-03-KT01", n: "Stages of the sourcing processes in creating added value", w: 25,
        covers: [
          "The main aspects of sourcing processes",
          "The main stages of a sourcing process",
          "The use of electronic systems at different stages of the sourcing process",
          "The relationship between achieving compliance with processes and the achievement of outcomes"
        ] },
      { code: "KM-03-KT02", n: "The main options for sourcing of requirements from suppliers", w: 25,
        covers: [
          "The sourcing process in relation to procurement",
          "The main approaches to the sourcing of requirements from suppliers",
          "Selection and award criteria for sourcing from external suppliers",
          "Main consequences on supply chains when sourcing requirements from suppliers internally",
          "The development of a plan for sourcing goods or services from external suppliers",
          "The assessment of financial stability of potential suppliers"
        ] },
      { code: "KM-03-KT03", n: "The main processes that can be applied to the sourcing of requirements from external suppliers", w: 25,
        covers: [
          "Sources of information on market data that can impact on the sourcing of requirements from external supplier",
          "Obtaining quotations",
          "Criteria for the assessment of tenders and quotations",
          "Electronic systems"
        ] },
      { code: "KM-03-KT04", n: "Compliance issues when sourcing from suppliers", w: 25,
        covers: [
          "Legislative, regulatory and organisational requirements when sourcing in the not for profit, private and public sectors",
          "Legislative, regulatory and organisational requirements when sourcing from international suppliers"
        ] }
    ]
  },
  {
    id: "KM-04", code: "332302-001-01-00-KM-04",
    title: "Inventory management",
    nqf: 5, credits: 10, hours: 100,
    purpose: "Develop an understanding of inventory management activities",
    topics: [
      { code: "KM-04-KT01", n: "Introduction to inventory management", w: 50,
        covers: [
          "Concepts of inventory management",
          "Key principles of inventory management"
        ] },
      { code: "KM-04-KT02", n: "Introduction to inventory optimisation", w: 50,
        covers: [
          "Concepts of inventory management",
          "Financial impact"
        ] }
    ]
  },
  {
    id: "KM-05", code: "332302-001-01-00-KM-05",
    title: "Negotiating and contracting in procurement and supply",
    nqf: 6, credits: 13, hours: 130,
    purpose: "Develop an understanding of the supply chain as an integrated system",
    topics: [
      { code: "KM-05-KT01", n: "Legal issues that relate to the formation of contracts", w: 25,
        covers: [
          "Legal documentation that can comprise a commercial agreement for the supply of goods or services",
          "Legal issues that relate to the creation of commercial agreements with customers or suppliers",
          "Types of contractual agreements made between customers and suppliers"
        ] },
      { code: "KM-05-KT02", n: "Main approaches in the negotiation of commercial agreements with external organisations", w: 25,
        covers: [
          "The application of commercial negotiations in the work of procurement and supply",
          "The types of approaches that can be pursued in commercial negotiations",
          "The balance of power in commercial negotiations can affect outcomes",
          "The different types of relationships that impact on commercial negotiations"
        ] },
      { code: "KM-05-KT03", n: "How to prepare for negotiations with external organisations", w: 25,
        covers: [
          "Evaluation of costs and prices in commercial negotiations",
          "Economic factors that impact on commercial negotiations",
          "Variables that can be used in a commercial negotiation",
          "Resources required for a negotiation"
        ] },
      { code: "KM-05-KT04", n: "How commercial negotiations should be undertaken", w: 25,
        covers: [
          "The stages of a commercial negotiation",
          "Methods that can influence the achievement of desired outcomes",
          "Communication skills that help achieve desired outcomes",
          "Analysing the process and outcomes of the negotiations to inform future practice"
        ] }
    ]
  },
  {
    id: "KM-06", code: "332302-001-01-00-KM-06",
    title: "Procurement operations",
    nqf: 5, credits: 10, hours: 100,
    purpose: "Develop an understanding of procurement activities",
    topics: [
      { code: "KM-06-KT01", n: "Introduction to procurement and supply environments, operations and workflow", w: 50,
        covers: [
          "Concepts of procurement",
          "Supplier relationship management (SRM)",
          "Types of materials and services to be sourced",
          "Key elements of the procurement process",
          "Procurement and supply environments",
          "Procurement and supply operations",
          "Procurement performance management"
        ] },
      { code: "KM-06-KT02", n: "Procurement planning and control", w: 50,
        covers: [
          "Procurement planning",
          "Procurement and supply workflow",
          "Placement of material orders on suppliers",
          "Procurement control",
          "Procurement interfaces",
          "Development of an implementation plan for strategic sourcing process",
          "Development of an implementation plan for category management process"
        ] }
    ]
  },
  {
    id: "KM-07", code: "332302-001-01-00-KM-07",
    title: "Returns management",
    nqf: 5, credits: 7, hours: 70,
    purpose: "Develop an understanding of returns activities",
    topics: [
      { code: "KM-07-KT01", n: "Introduction to returns management", w: 50,
        covers: [
          "Concepts of returns management",
          "Types of returns management",
          "Key elements of the returns process",
          "Returns management performance management"
        ] },
      { code: "KM-07-KT02", n: "Returns management planning and control", w: 50,
        covers: [
          "Returns management planning",
          "Placement of orders for returns management",
          "Returns management control",
          "Returns management interfaces"
        ] }
    ]
  },
  {
    id: "KM-08", code: "332302-001-01-00-KM-08",
    title: "Performance management and improvement of operations",
    nqf: 5, credits: 7, hours: 70,
    purpose: "Develop an understanding of activities aimed at the improvement of performance and operations",
    topics: [
      { code: "KM-08-KT01", n: "Performance improvement for a strategic sourcing or category management process", w: 20,
        covers: [
          "The main aspects of performance improvement for strategic sourcing processes",
          "The main aspects of performance improvement for category management processes"
        ] },
      { code: "KM-08-KT02", n: "Contract management", w: 40,
        covers: [
          "Legal aspects relating to the performance of contracts",
          "Main approaches to achieve the management of contracts",
          "Main techniques for the management of contracts and suppliers"
        ] },
      { code: "KM-08-KT03", n: "Project management principles applied to supply chain planning and control", w: 40,
        covers: [
          "Principles of project management",
          "Application of a project management approach to order management"
        ] }
    ]
  }
  ];

  /* No links in this file — see the note at the top. materials.js fills these
     in from the server for a learner who is entitled to them. */
  MODULES.forEach(function (m) {
    m.docs = { guide: null, workbook: null, video: null };
  });

  /* The curriculum registry. Two qualifications are carried on the site now, so
     pages ask for the one they are showing rather than reaching for a global
     named after the Project Manager. pm-modules.js registers itself the same
     way, and keeps window.PM_MODULES for the pages that still read it. */
  window.ACADEMY_CURRICULA = window.ACADEMY_CURRICULA || {};
  window.ACADEMY_CURRICULA["procurement-officer"] = {
    slug: "procurement-officer",
    title: "Occupational Certificate: Procurement Officer",
    saqa: "111445",
    curriculum: "332302-001-01-00",
    short: "Procurement Officer",
    nqf: 5,
    credits: 180,            // the whole qualification
    knowledgeCredits: 79,    // what is studied on the site
    workbooks: false,        // this pack has learner guides only, no workbooks
    plan: null,              // no study planner written for it yet
    modules: MODULES,
    byId: MODULES.reduce(function (a, m) { a[m.id] = m; return a; }, {})
  };

  window.PO_MODULES = MODULES;
  window.PO_MODULE_BY_ID = window.ACADEMY_CURRICULA["procurement-officer"].byId;

  /* ---- self-check: the registered figures, verified in the browser ----
     These are regulated numbers. If an edit breaks one, say so loudly rather
     than rendering a qualification with the wrong credit total. */
  (function () {
    const credits = MODULES.reduce(function (a, m) { return a + m.credits; }, 0);
    if (credits !== 79) console.error('po-modules: knowledge credits are ' + credits + ', the curriculum says 79');
    if (MODULES.length !== 8) console.error('po-modules: ' + MODULES.length + ' modules, the curriculum says 8');
    MODULES.forEach(function (m) {
      const w = m.topics.reduce(function (a, t) { return a + t.w; }, 0);
      if (w !== 100) console.error('po-modules: ' + m.id + ' topic weightings sum to ' + w + '%, not 100%');
    });
  })();

})();