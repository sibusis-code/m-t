/* ---- New Venture Creation NQF 4: Skills Programme 1 -----------------------
   Further Education and Training Certificate: New Venture Creation
   SAQA ID 66249 · NQF Level 4 · 149 credits · Qualification type: Certificate

   Generated on 23 Sep 2026 from Centenary's own curriculum guide for the
   qualification — "FETC: New Venture Creation, SP1 — Start and Run a New
   Venture, Learner Guide", version V0001-2017, 352 pages. The unit standard
   IDs, titles, levels and credit values are the REGISTERED figures and must
   not be hand-edited. The six unit standards are asserted at the bottom of
   this file to total 31 credits, so a bad edit fails loudly.

   ---- THIS IS NOT A QCTO QUALIFICATION, AND THE SHAPE IS DIFFERENT ---------
   The Project Manager and Procurement Officer are occupational certificates:
   knowledge, practical and workplace modules, each module with its own code,
   registered topic weightings, notional hours, and an EISA at the end.

   New Venture Creation is a legacy unit-standard qualification. It has none of
   those things. It is made of UNIT STANDARDS, delivered in SKILLS PROGRAMMES,
   and assessed by a registered assessor, moderated, then verified by the SETA.
   So this file deliberately does NOT carry:
     - topic weightings — the guide gives none, and inventing them would be
       inventing where the marks are. Pages that show a weighting check for one
       and leave it out rather than printing a made-up percentage.
     - notional hours — one credit is ten notional hours on the NQF, but the
       guide prints no hours figure per section, so neither does this file.
     - practical / workplace components — this qualification has none.
   Everything downstream keys off the flags in the registry block at the foot
   of this file. Nothing guesses from the slug.

   ---- WHAT WE HOLD IS SKILLS PROGRAMME 1, NOT THE WHOLE QUALIFICATION -----
   The qualification is 149 credits. The guide we were given covers Skills
   Programme 1 only: six sections, six unit standards, 31 credits. Its own
   opening page says "this is the first skills programme leading to the
   following qualification". The site says exactly that and no more — the
   remaining skills programmes have to come from the provider before they can
   be listed, and there is no honest way to imply otherwise.

   ---- MODULES ARE THE GUIDE'S SECTIONS --------------------------------------
   A learner studies sections, and every section opens with its own "Unit
   Standard Alignment" table naming the standard it is aligned to. So a module
   here is a section, and it carries the unit standard as its code.

   ONE JUDGEMENT CALL, stated so nobody has to reverse-engineer it. Section 5's
   alignment table names TWO standards — 114592 (8 credits), which is also
   section 4's, and 263534 (4 credits). Credit each section with the standard
   it introduces and the six sum to exactly 31; credit section 5 with both and
   114592 gets counted twice and the programme comes to 39, which is wrong.
   So section 5 carries 263534, and its `alsoUs` field records that the section
   also completes 114592, which section 4 began. That is what the document
   supports. Do not "tidy" it into per-section credit sums.

   ---- SOURCE INCONSISTENCIES, corrected here and listed so nobody
        "fixes" them back ---------------------------------------------------
     - The contents page lists section 1 as "1.1 What is thinking?" with
       sub-topics 1.1.1-1.1.3. The body of the guide at page 26 heads the same
       section "1.1 The concept of business thinking" with different
       sub-headings. The BODY wins here, because that is the page a learner
       actually opens. The same is true of every other title in this file.
     - Page 41 heads "Blocks in thinking" as 1.1 and "The skill of thinking"
       as 1.3; the contents page has them as 1.3 and 1.4, which is the sequence
       that is internally consistent. Topics are numbered by the contents page.
     - The contents page prints "successful entrepreneur ship". Typographic
       breaks like that one are closed up; nothing else is reworded.
     - The exit-level outcome table on pages 9-10 jumps from outcome 2 to
       outcome 4. Outcome 3 belongs to a later skills programme.

   What is deliberately NOT here: the teaching prose, and the "idea in one
   paragraph" that pm-modules.js carries per topic. Those come from the learner
   guide, which is Centenary's material and access-controlled. A topic without
   one simply renders without it.

   THE FORMATIVE ACTIVITIES, THE SUMMATIVE ASSESSMENT AND THE THINK SHEETS in
   this guide are assessment instruments. They are not reproduced here, and the
   guide itself is not a public download — see the note below on putting a
   document live. Assessment papers, marking memos and facilitator guides are
   never published.

   ---- TO PUT A DOCUMENT LIVE ----
   Not here. Links live in the database; paste them on the Material page under
   Administration, and materials.php hands them only to a signed-in learner who
   is enrolled. A module with nothing attached renders as "ask HR for a copy". */
(function () {

  /* The six unit standards of Skills Programme 1, exactly as the alignment
     table on page 10 of the guide registers them. All six are Core. */
  const UNIT_STANDARDS = [
    { id: "114600", title: "Apply innovative thinking to the development of a small business",  nqf: 4, credits: 4, type: "Core" },
    { id: "114596", title: "Research the viability of new venture ideas/opportunities",          nqf: 4, credits: 5, type: "Core" },
    { id: "263356", title: "Demonstrate an understanding of an entrepreneurial profile",         nqf: 4, credits: 5, type: "Core" },
    { id: "114592", title: "Produce business plans for a new venture",                           nqf: 4, credits: 8, type: "Core" },
    { id: "263534", title: "Implement an action plan for a new venture",                         nqf: 4, credits: 4, type: "Core" },
    { id: "263514", title: "Demonstrate an understanding of the function of the market mechanisms in a new venture", nqf: 4, credits: 5, type: "Core" }
  ];

  const MODULES = [
  {
    id: "SP1-01", code: "114600", ref: "Section 1",
    title: "Innovative thinking",
    nqf: 4, credits: 4, hours: null,
    us: "114600",
    purpose: "Develop techniques for releasing creativity in developing ideas and opportunities for a new venture, determine the role of innovation in its development and growth, and apply the principles and practices of innovation",
    topics: [
      { code: "SP1-01-T1", ref: "1.1", n: "The concept of business thinking",
        covers: [
          "Creativity, innovation, analytical and lateral thinking, and value engineering — defined and compared",
          "Developing techniques and applying creativity in developing ideas",
          "What creative people are like, and how creative you are",
          "Creative thinking techniques"
        ] },
      { code: "SP1-01-T2", ref: "1.2", n: "Perception errors",
        covers: [
          "Over generalising, magnifying and minimising",
          "Black and white thinking, and negativeness",
          "Misattributing, and tunnel vision"
        ] },
      { code: "SP1-01-T3", ref: "1.3", n: "Blocks in thinking",
        covers: [
          "Wrong ideas about thinking, and where they come from",
          "Recognising what stops you using your brainpower"
        ] },
      { code: "SP1-01-T4", ref: "1.4", n: "The skill of thinking",
        covers: [
          "What one can do about thinking: wait, expose, borrow, organise, process, clarify",
          "Being conscious of the process, and giving it time",
          "Developing attitudes, and developing tools",
          "Being aware of mistakes, and escaping inhibiting procedures"
        ] },
      { code: "SP1-01-T5", ref: "1.5", n: "Analytical thinking tools",
        covers: [
          "PNI — positive, negative and interesting",
          "CAF — considering all factors",
          "AGO — aims, goals and objectives",
          "C&S — consequences and sequels",
          "OPV — other points of view"
        ] },
      { code: "SP1-01-T6", ref: "1.6", n: "Creativity",
        covers: [
          "The difference between analytical and lateral thinking",
          "What creative people are like",
          "How creative are you?",
          "Guidelines to creative thinking",
          "Imaginative thinking, and thinking in pictures"
        ] },
      { code: "SP1-01-T7", ref: "1.7", n: "How to improve your imaginative abilities",
        covers: [
          "Thinking tools for imagination: recalling, creating, foreseeing, fantasy, visualising",
          "Techniques for idea production"
        ] },
      { code: "SP1-01-T8", ref: "1.8", n: "The concept of innovation",
        covers: [
          "Types of innovation: product, process and behavioural",
          "Innovation depends on customers learning",
          "Radical or incremental innovation",
          "The impact of innovations on a new venture",
          "The relationship between successful entrepreneurship and innovation",
          "The value innovation process"
        ] },
      { code: "SP1-01-T9", ref: "1.9", n: "Apply innovative thinking in business",
        covers: [
          "New products and services for greater profitability or viability",
          "Alternative ways to run operations, cut costs and increase income",
          "Generating employment without putting the business at risk",
          "Addressing the skills needs of employees without risking the venture",
          "Minimising the impact of opposition firms"
        ] }
    ]
  },
  {
    id: "SP1-02", code: "114596", ref: "Section 2",
    title: "Research a new business opportunity",
    nqf: 4, credits: 5, hours: null,
    us: "114596",
    purpose: "Identify and assess your own business ideas, analyse the viability of the one you choose against specific screening variables, research its potential, analyse the risks it carries, and evaluate it on your research findings",
    topics: [
      { code: "SP1-02-T1", ref: "2.1", n: "Types of risk",
        covers: [
          "Compliance risks, and their consequences",
          "Employee risks",
          "Environmental risks",
          "Financial risks",
          "Health and safety risks",
          "Operational risks",
          "Political and economic risks",
          "Strategic risks"
        ] },
      { code: "SP1-02-T2", ref: "2.2", n: "Ways to analyse competition",
        covers: [
          "Paying attention to their advertising",
          "Visiting regularly, in person and online",
          "Asking your business colleagues",
          "Asking their customers and clients",
          "Being a customer yourself",
          "Signing up to what they offer"
        ] },
      { code: "SP1-02-T3", ref: "2.3", n: "Determining the right price for a product or service",
        covers: [
          "How to set the correct price — the cost basis, then the market",
          "Whether your price should be lower than your competitors'",
          "When it is acceptable to increase your prices",
          "How much flexibility you have to move prices up or down",
          "Whether to offer goods or services for free",
          "The marketing mix: product, position, price, promotion",
          "Pricing strategically: cost-based, skimming, negotiated, expected, differential, lifetime",
          "What to avoid"
        ] },
      { code: "SP1-02-T4", ref: "2.4", n: "How to research your business idea",
        covers: [
          "Analysis: company, customer, competitor, collaborators",
          "SWOT analysis, and the rules for doing one properly",
          "Conducting your own research: forms, data, analysis, report",
          "Research methods: questionnaires, interviews, documentation review, observation, focus groups",
          "The research report, and what a reader wants from it"
        ] },
      { code: "SP1-02-T5", ref: "2.5", n: "Evaluate business opportunities",
        covers: [
          "Focus, commitment, and whether the idea is viable",
          "Building a customer base, and what to do when the approach is not working",
          "Questions to ask when evaluating a business opportunity",
          "How to quickly value a small business",
          "Common business valuation methods"
        ] },
      { code: "SP1-02-T6", ref: "2.6", n: "Business insurance cover guides",
        covers: [
          "Legal liability cover: public, employers, professional indemnity, directors",
          "Equipment, buildings and contents, motor, and legal expenses cover"
        ] },
      { code: "SP1-02-T7", ref: "2.7", n: "Ten rules to make a business grow",
        covers: [
          "Find a niche, and be small yet think big",
          "Differentiate your products, and make the first impression count",
          "Build a reputation, and improve constantly",
          "Listen to your customers, and plan for success",
          "Be innovative, and work smart"
        ] }
    ]
  },
  {
    id: "SP1-03", code: "263356", ref: "Section 3",
    title: "Understanding entrepreneurship",
    nqf: 4, credits: 5, hours: null,
    us: "263356",
    purpose: "Describe entrepreneurship and the characteristics of a successful entrepreneur, develop your own entrepreneurial characteristics, and explain the methods that enhance an entrepreneurial profile",
    topics: [
      { code: "SP1-03-T1", ref: "3.1", n: "The economic area in South Africa",
        covers: [
          "South African currency, and who else uses it",
          "Trade organisations: the WTO, the OECD, the G-20 and SACU",
          "Economic statistics, and the three ways of measuring GDP",
          "Inflation, and the consumer price index",
          "Poverty and unemployment",
          "South Africa's main industries, exports and imports",
          "Investments, and foreign direct investment"
        ] },
      { code: "SP1-03-T2", ref: "3.2", n: "Local entrepreneurs in South Africa",
        covers: [
          "Defining entrepreneurship, and defining an entrepreneur",
          "Employment opportunities created by entrepreneurs",
          "The role of an entrepreneur in social development",
          "Assistance for a new business: BBBEE and SMMEs, the dti, the IDC",
          "Types of business opportunity: network marketing, affiliate programmes, franchising",
          "Guidelines for choosing the right franchise",
          "The advantages and disadvantages of entrepreneurship"
        ] },
      { code: "SP1-03-T3", ref: "3.3", n: "Business success and failures",
        covers: [
          "Reasons for business failure — the seven pitfalls",
          "Examples of businesses that failed, and what took them down",
          "One entrepreneur's account of nine ventures that did not work"
        ] },
      { code: "SP1-03-T4", ref: "3.4", n: "A list of successful entrepreneurs",
        covers: [
          "Success stories, local and international",
          "Setting your sights, educating yourself, and letting passion pay off"
        ] },
      { code: "SP1-03-T5", ref: "3.5", n: "Five traits of successful entrepreneurs",
        covers: [
          "Making strategic decisions on limited data",
          "Learning from your mistakes",
          "Understanding your own weaknesses",
          "Spotting patterns, and separating the key data",
          "Partnering successfully with others"
        ] },
      { code: "SP1-03-T6", ref: "3.6", n: "Skills, aptitudes, personality and values of entrepreneurs",
        covers: [
          "The characteristics of a successful entrepreneur",
          "Assessing your personality traits",
          "Assessing your entrepreneurial skills",
          "Identifying your strengths and weaknesses",
          "Evaluating your readiness to be an entrepreneur",
          "The competence model for entrepreneurs"
        ] },
      { code: "SP1-03-T7", ref: "3.7", n: "Development of new entrepreneurs",
        covers: [
          "Methods to develop mind power",
          "The benefits of positive thinking",
          "Harnessing the law of attraction, and making it work for you",
          "Visualisation, and exercises to practise it",
          "Affirmation, and how to use it properly",
          "Using mental laws and goal setting to develop your mind",
          "Personal development as an entrepreneur",
          "Self-motivational techniques"
        ] },
      { code: "SP1-03-T8", ref: "3.8", n: "Compile a personal plan for your own base-line knowledge and skills",
        covers: [
          "Discovering your strengths and weaknesses",
          "Deciding what you want instead, and why you want it",
          "Short-term and long-term goals",
          "What you need to learn, and where to find it",
          "Scheduling it, and setting a timeline"
        ] }
    ]
  },
  {
    id: "SP1-04", code: "114592", ref: "Section 4",
    title: "Fundamental knowledge for a business plan",
    nqf: 4, credits: 8, hours: null,
    us: "114592",
    purpose: "Identify, gather and analyse the information a plan for a new venture needs, and formulate an ethical framework for the venture's operational plans",
    topics: [
      { code: "SP1-04-T1", ref: "4.1", n: "Business plan fundamentals",
        covers: [
          "What a business plan is for, and the purposes it serves",
          "The business description",
          "The management and the organisation",
          "The market and the competitors",
          "The products or service offerings",
          "Marketing and sales, and the financial information behind them"
        ] },
      { code: "SP1-04-T2", ref: "4.2", n: "Setting goals and objectives",
        covers: [
          "Analysing competitive positioning with a SWOT",
          "Defining business goals: specific, measurable, attainable, relevant, timely",
          "Describing the measures, and structuring the goals into a hierarchy",
          "Writing a vision statement",
          "Writing a mission statement, and putting it to work",
          "Short-term, long-term and enabling goals"
        ] },
      { code: "SP1-04-T3", ref: "4.3", n: "The management and organisation of a business",
        covers: [
          "The business structure: functional, divisional, geographic",
          "Management roles and responsibilities",
          "Job analysis, job specifications and job descriptions",
          "Information on employees, and the forms an employer needs",
          "A code of conduct: values, principles, responsibility and compliance",
          "Group dynamics, and what makes a work group succeed"
        ] },
      { code: "SP1-04-T4", ref: "4.4", n: "The products or services offerings",
        covers: [
          "Physical description, and the uses it appeals to",
          "Stages of development",
          "Competitive comparison",
          "Sales literature, sourcing and technology"
        ] },
      { code: "SP1-04-T5", ref: "4.5", n: "Operational plan",
        covers: [
          "Production or manufacturing: capacity, productivity, labour, quality assurance",
          "Facilities: location, improvements, lease or rent, maintenance",
          "Inventory, and what it costs to hold too much or too little",
          "Distribution, and the relationship with your suppliers",
          "Maintenance and service: order fulfilment and customer service"
        ] },
      { code: "SP1-04-T6", ref: "4.6", n: "Marketing and sales",
        covers: [
          "What a marketing plan is for, and the six parts of one",
          "Marketing tools: the name, the logo, the brand, e-commerce",
          "Promotion and advertising, and the paid media to choose from",
          "Media publicity, and a press release with news value in it",
          "Mailing lists, and marketing on local search"
        ] },
      { code: "SP1-04-T7", ref: "4.7", n: "Financial analysis",
        covers: [
          "Understand, identify, analyse and adjust",
          "The key financial questions a business has to be able to answer",
          "Reading the balance sheet, the income statement and the cash flow statement",
          "Liquidity, debt, profitability, efficiency and value ratios",
          "The uses and the limitations of ratio analysis"
        ] },
      { code: "SP1-04-T8", ref: "4.8", n: "Banking",
        covers: [
          "Registered banks in South Africa, and how the sector is regulated",
          "The role of the bank in the modern business sector",
          "Applying for business finance, and what the bank wants to know",
          "How the bank processes your application"
        ] },
      { code: "SP1-04-T9", ref: "4.9", n: "Safety requirements",
        covers: [
          "The key health and safety obligations an employer carries",
          "Risk assessments, and the safe handling of hazardous substances",
          "Emergency procedures, and a plan people can actually follow"
        ] },
      { code: "SP1-04-T10", ref: "4.10", n: "Insurance for business",
        covers: [
          "Liability cover, and workers' compensation",
          "Cover for assets, for people, and for business interruption"
        ] }
    ]
  },
  {
    id: "SP1-05", code: "263534", ref: "Section 5",
    title: "Prepare a business plan",
    nqf: 4, credits: 4, hours: null,
    us: "263534",
    /* This section's alignment table also names 114592, which section 4
       introduces and section 5 completes. See the note at the top of this file
       for why the credit sits on 263534 only. */
    alsoUs: ["114592"],
    purpose: "Design and present the business, financial and marketing plans on a budget, design an action plan for the venture, set up its premises and operating systems, implement its financial systems, and identify the risks it carries",
    topics: [
      { code: "SP1-05-T1", ref: "5.1", n: "Business plan structure",
        covers: [
          "The nine parts of the plan, from the front page to the appendices",
          "Business overview, and the products or services",
          "Market analysis, market research and strategy",
          "The competition, and how you compare with it",
          "Marketing strategy, business structure and management",
          "Finances: establishment costs, profit and loss, balance sheet, cash flow",
          "The action plan: the task, who does it, and by when",
          "Appendices: a competitor analysis and a strategic SWOT",
          "The structure of the marketing plan inside it"
        ] },
      { code: "SP1-05-T2", ref: "5.2", n: "Types of businesses",
        covers: [
          "What makes a company separate from the people who own it",
          "The Companies Act of 2008, and what it changed",
          "Non-profit companies, and profit companies",
          "State-owned, private, personal liability and public companies",
          "External companies, and close corporations",
          "Sole proprietorships, partnerships, co-operatives and trusts"
        ] },
      { code: "SP1-05-T3", ref: "5.3", n: "Statutory requirements to register a business",
        covers: [
          "Registering the business with the CIPC",
          "Registering with SARS, and registering as a VAT vendor",
          "Registering for employee tax: PAYE and the Skills Development Levy",
          "Registering with the Department of Labour under COIDA",
          "Registering with the Unemployment Insurance Fund"
        ] }
    ]
  },
  {
    id: "SP1-06", code: "263514", ref: "Section 6",
    title: "Understanding the function of the market",
    nqf: 4, credits: 5, hours: null,
    us: "263514",
    purpose: "Explain the free market system in terms of perfect and imperfect competition, analyse how demand and supply interact to set a price, analyse the factors that influence economic activity, and describe the development and significance of markets",
    topics: [
      { code: "SP1-06-T1", ref: "6.1", n: "Characteristics of different economic systems",
        covers: [
          "Capitalism, socialism and the mixed economy",
          "Traditionalism, and central planning",
          "Market economies, and planned economies",
          "The advantages and disadvantages of each, and which countries run them"
        ] },
      { code: "SP1-06-T2", ref: "6.2", n: "The interaction of role players in the economic system",
        covers: [
          "Consumers' buying behaviour",
          "The trade's behaviour — wholesalers and retailers",
          "Competitors' position and behaviour",
          "Government behaviour, and the controls it puts on marketing"
        ] },
      { code: "SP1-06-T3", ref: "6.3", n: "The role of competition in a free market system",
        covers: [
          "Competitive prices, and the fact that producers are consumers too",
          "The effect on efficiency, productivity and innovation",
          "Restructuring the sectors that have lost competitiveness",
          "The advantages and disadvantages of competition, for the consumer and for the business"
        ] },
      { code: "SP1-06-T4", ref: "6.4", n: "Glossary of terms related to economics",
        covers: [
          "A working glossary, from absolute advantage to the WTO",
          "The conditions for the existence of perfect and imperfect markets",
          "The laws of demand and supply, and what each one means for a new venture",
          "Demand and supply curves, and the things that shift them",
          "Reaching equilibrium, and reading excess supply or excess demand",
          "The theory of consumer choice, and what a change in price does to it"
        ] },
      { code: "SP1-06-T5", ref: "6.5", n: "Issues influencing the economy",
        covers: [
          "The business cycle: prosperity, recession, depression, recovery",
          "Measuring economic activity: GDP, the interest rate, the BA rate, the CPI",
          "Productivity and production, and what drives each",
          "Costs and production: explicit costs, implicit costs, economic profit",
          "Inflation, and its impact on a new venture"
        ] },
      { code: "SP1-06-T6", ref: "6.6", n: "Money and its role in the economy",
        covers: [
          "What money does in a society",
          "How money creates a hierarchical society",
          "The right to buy, and the right to save",
          "Reasons for the decline in the value of money"
        ] },
      { code: "SP1-06-T7", ref: "6.7", n: "The effects of cyclical movements in a market system",
        covers: [
          "What a cyclical movement is",
          "Cyclical and non-cyclical industries, with examples",
          "What the cycle means for a new venture"
        ] },
      { code: "SP1-06-T8", ref: "6.8", n: "Different types of business",
        covers: [
          "The basic forms of ownership",
          "Classification by what actually generates the profit"
        ] },
      { code: "SP1-06-T9", ref: "6.9", n: "South Africa's economic growth",
        covers: [
          "Growth, and the two challenges: energy supply and unemployment",
          "State-owned enterprises in South Africa",
          "South Africa's competitive economic policy",
          "Black economic empowerment",
          "The Accelerated and Shared Growth Initiative",
          "South Africa as the gateway to Africa",
          "Infrastructure, and South African inventions and innovations"
        ] }
    ]
  }
  ];

  /* No links in this file — see the note at the top. materials.js fills these
     in from the server for a learner who is entitled to them. */
  MODULES.forEach(function (m) {
    m.docs = { guide: null, workbook: null, video: null };
  });

  /* The curriculum registry. Three qualifications are carried on the site now,
     so pages ask for the one they are showing. The fields below the credits are
     the ones that stop a unit-standard qualification being described in QCTO
     language: every page that would otherwise say "knowledge module", "module
     code", "notional hours", "topic weighting" or "EISA" reads them from here,
     and falls back to the QCTO wording when a curriculum does not set them. */
  window.ACADEMY_CURRICULA = window.ACADEMY_CURRICULA || {};
  window.ACADEMY_CURRICULA["new-venture-creation"] = {
    slug: "new-venture-creation",
    title: "FETC: New Venture Creation",
    fullTitle: "Further Education and Training Certificate: New Venture Creation",
    short: "New Venture Creation",
    saqa: "66249",
    curriculum: "",            // a unit-standard qualification has no curriculum code
    version: "V0001-2017",     // the version of the guide this file was generated from
    nqf: 4,
    credits: 149,              // the whole qualification
    knowledgeCredits: 31,      // what Skills Programme 1 carries, and what is studied here
    programme: "Skills Programme 1 — Start and Run a New Venture",
    unitStandards: UNIT_STANDARDS,

    workbooks: true,           // the guide requires a Workplace Guide alongside it
    workbookName: "Workplace Guide",
    workbookDesc: "The workplace activities for this section. You complete them, file them in your portfolio of evidence and hand the portfolio to your facilitator.",

    moduleNoun: "Learner guide section",
    codeLabel: "Unit standard",
    partOf: "Skills Programme 1 — 31 credits",
    weighted: false,           // the guide registers no topic weightings
    plan: null,                // no study planner written for it yet

    /* Not an EISA. A unit-standard qualification is assessed by a registered
       assessor, moderated, and then verified by the SETA — which is what page
       20 of the guide sets out, and what a learner should be told. */
    finalAssess: {
      h: "At the end of everything",
      p: "Your portfolio of evidence goes to a registered assessor, a moderator confirms the decision, and the SETA verifier checks the completed workbooks. The qualification is awarded on the credits from all of its skills programmes, not on this one alone."
    },
    progressLabel: "Sections of Skills Programme 1",
    progressNote: "credits of the 31 in Skills Programme 1 covered by your own record. Competence is decided by your assessor after moderation, and the workplace activities are assessed against your own venture.",

    modules: MODULES,
    byId: MODULES.reduce(function (a, m) { a[m.id] = m; return a; }, {})
  };

  window.NVC_MODULES = MODULES;
  window.NVC_UNIT_STANDARDS = UNIT_STANDARDS;

  /* ---- self-check: the registered figures, verified in the browser ----
     These are regulated numbers. If an edit breaks one, say so loudly rather
     than rendering a qualification with the wrong credit total. */
  (function () {
    const usCredits = UNIT_STANDARDS.reduce(function (a, u) { return a + u.credits; }, 0);
    if (usCredits !== 31) console.error('nvc-modules: the unit standards total ' + usCredits + ' credits, the guide says 31');
    if (UNIT_STANDARDS.length !== 6) console.error('nvc-modules: ' + UNIT_STANDARDS.length + ' unit standards, the guide says 6');
    if (MODULES.length !== 6) console.error('nvc-modules: ' + MODULES.length + ' sections, the guide says 6');

    const modCredits = MODULES.reduce(function (a, m) { return a + m.credits; }, 0);
    if (modCredits !== usCredits) console.error('nvc-modules: the sections total ' + modCredits + ' credits and the unit standards ' + usCredits + ' — every standard must be credited to exactly one section');

    /* Every section's code is a unit standard, every standard is used once, and
       the credits agree with it. This is the check that catches the section 5
       double-count described at the top of this file. */
    const seen = {};
    MODULES.forEach(function (m) {
      const u = UNIT_STANDARDS.filter(function (x) { return x.id === m.code; })[0];
      if (!u) { console.error('nvc-modules: ' + m.id + ' cites unit standard ' + m.code + ', which is not in the alignment table'); return; }
      if (seen[m.code]) console.error('nvc-modules: unit standard ' + m.code + ' is credited to both ' + seen[m.code] + ' and ' + m.id);
      seen[m.code] = m.id;
      if (u.credits !== m.credits) console.error('nvc-modules: ' + m.id + ' claims ' + m.credits + ' credits, unit standard ' + m.code + ' is registered at ' + u.credits);
    });
    UNIT_STANDARDS.forEach(function (u) {
      if (!seen[u.id]) console.error('nvc-modules: unit standard ' + u.id + ' is not delivered by any section');
    });

    /* Topic codes have to start with their module id: lib/curriculum.php tells a
       module code from a topic code by exactly that, and the database keys a
       learner's progress on both. */
    MODULES.forEach(function (m) {
      m.topics.forEach(function (t) {
        if (t.code.indexOf(m.id + '-') !== 0) console.error('nvc-modules: topic ' + t.code + ' is filed under ' + m.id + ' but its code does not start with it');
      });
    });
  })();

})();
