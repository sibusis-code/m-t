/* ===== M&T Academy — client-side AI assistant (self-contained, no backend) ===== */
(function(){
  if(window.__aiwLoaded)return; window.__aiwLoaded=1;
  var css=
  ".aiw-btn{position:fixed;right:20px;bottom:20px;z-index:300;display:inline-flex;align-items:center;gap:9px;background:#3e4096;color:#fff;border:0;border-radius:30px;padding:12px 18px;font:600 14px/1 Inter,-apple-system,Segoe UI,sans-serif;cursor:pointer;box-shadow:0 16px 36px -14px rgba(0,161,198,.6)}"+
  ".aiw-btn:hover{background:#2f3194}.aiw-btn svg{width:18px;height:18px}.aiw-btn .dot{width:7px;height:7px;border-radius:50%;background:#1f8a70}"+
  ".aiw-panel{position:fixed;right:20px;bottom:20px;z-index:301;width:min(384px,calc(100vw - 32px));height:min(564px,calc(100vh - 40px));background:#fff;border:1px solid #dfe7ea;border-radius:16px;box-shadow:0 34px 80px -28px rgba(0,0,0,.55);display:none;flex-direction:column;overflow:hidden}"+
  ".aiw-panel.open{display:flex}"+
  ".aiw-head{background:#10333f;color:#fff;padding:14px 15px;display:flex;align-items:center;gap:11px;flex:0 0 auto}"+
  ".aiw-head .av{width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,#3e4096,#5b5fc7);display:grid;place-items:center}.aiw-head .av svg{width:19px;height:19px}"+
  ".aiw-head h4{margin:0;font-size:15px;color:#fff;font-weight:700;line-height:1.1}.aiw-head p{margin:2px 0 0;font-size:11px;color:#b7b2ac}"+
  ".aiw-head .x{margin-left:auto;background:none;border:0;color:#b7b2ac;cursor:pointer;padding:4px;line-height:0}.aiw-head .x:hover{color:#fff}.aiw-head .x svg{width:18px;height:18px}"+
  ".aiw-body{flex:1;overflow-y:auto;padding:15px;background:#f4f8fa;display:flex;flex-direction:column;gap:10px}"+
  ".aiw-msg{max-width:88%;font-size:13.5px;line-height:1.5;padding:10px 13px;border-radius:13px;word-wrap:break-word}"+
  ".aiw-msg.bot{background:#fff;border:1px solid #dfe7ea;align-self:flex-start;color:#2b2b2b;border-bottom-left-radius:4px}"+
  ".aiw-msg.me{background:#3e4096;color:#fff;align-self:flex-end;border-bottom-right-radius:4px}"+
  ".aiw-msg.bot a{color:#2f3194;font-weight:700}.aiw-msg.bot b{color:#1c1c1f}"+
  ".aiw-cards{display:flex;flex-direction:column;gap:7px;align-self:flex-start;max-width:94%}"+
  ".aiw-card{display:block;background:#fff;border:1px solid #dfe7ea;border-left:3px solid #3e4096;border-radius:10px;padding:9px 12px;text-decoration:none}.aiw-card:hover{border-color:#3e4096}"+
  ".aiw-card b{display:block;font-size:13px;color:#1c1c1f;line-height:1.25}.aiw-card span{font-size:11px;color:#5c5c5c;text-transform:uppercase;letter-spacing:.05em}"+
  ".aiw-chipset{display:flex;flex-wrap:wrap;gap:6px;align-self:flex-start;max-width:96%}"+
  ".aiw-chip{background:#fff;border:1px solid #dfe7ea;border-radius:30px;padding:7px 11px;font:600 12px Inter,sans-serif;color:#2b2b2b;cursor:pointer}.aiw-chip:hover{border-color:#3e4096;color:#2f3194}"+
  ".aiw-foot{display:flex;gap:8px;padding:10px 12px;border-top:1px solid #dfe7ea;background:#fff;flex:0 0 auto}"+
  ".aiw-foot input{flex:1;border:1px solid #dfe7ea;border-radius:9px;padding:10px 12px;font:14px Inter,sans-serif;color:#2b2b2b}.aiw-foot input:focus{outline:2px solid #3e4096}"+
  ".aiw-foot button{background:#3e4096;border:0;color:#fff;border-radius:9px;padding:0 14px;cursor:pointer;line-height:0}.aiw-foot button:hover{background:#2f3194}.aiw-foot button svg{width:18px;height:18px}"+
  ".aiw-typing span{display:inline-block;width:6px;height:6px;margin:0 1px;border-radius:50%;background:#b7b2ac;opacity:.75}"+
  "@media(max-width:480px){.aiw-panel{right:8px;bottom:8px;width:calc(100vw - 16px);height:calc(100vh - 16px)}.aiw-btn{right:14px;bottom:14px}}";
  var st=document.createElement('style'); st.appendChild(document.createTextNode(css)); document.head.appendChild(st);

  var COURSES=[
   {t:"Occupational Certificate: Project Manager",slug:"project-management",cat:"Project Management",kw:"project management pm manager planning scheduling scope budget cost risk stakeholders procurement quality delivery accredited qualification nqf 5 240 credits saqa 101869 qcto portfolio evidence eisa"},
   {t:"Occupational Certificate: Procurement Officer",slug:"procurement-officer",cat:"Procurement",kw:"procurement officer purchasing buyer supply chain sourcing suppliers tenders quotations inventory stock negotiation contracts service level agreements returns supplier performance accredited qualification nqf 5 180 credits saqa 111445 teta qcto portfolio evidence eisa"},
   {t:"AI & Software Development",slug:"ai-software-development",cat:"Software Development",kw:"ai & software development software development programme code coding programming python git api database testing deployment ai assistants copilot build applications developers"},
   {t:"Procurement Skills",slug:"procurement",cat:"Procurement",kw:"procurement buying purchasing supply chain supplier vendor sourcing strategic sourcing category commodity strategy spend analysis make or buy outsourcing benchmarking fundamentals cost models tco rfq rfp rfx tender bid management open restricted negotiated evaluation scoring award quote quotation purchase order po spec specification contract service level agreement sla contract management negotiations negotiating risk governance compliance conflict of interest fraud bbbee transformation audit trail masterclass masterclasses coaching mentoring tarryn norris waria mcips stores warehouse"},
   {t:"AI Fundamentals for the Workplace",slug:"ai-fundamentals",cat:"Beginner · Business",kw:"basics fundamentals introduction beginner workplace nontechnical everyday start"},
   {t:"AI Tools for Productivity",slug:"ai-tools-productivity",cat:"Beginner · Business",kw:"tools productivity writing email research automation workflow assistants admin"},
   {t:"Responsible & Ethical AI Use",slug:"responsible-ai",cat:"Compliance",kw:"responsible ethical ethics privacy bias safe compliance policy popia data"},
   {t:"AI for Leaders & Managers",slug:"ai-leaders",cat:"Leadership",kw:"leaders managers leadership management decision adoption teams executives boss"},
   {t:"AI & New Venture Creation",slug:"new-venture",cat:"Business",kw:"venture startup entrepreneur business innovation founder"},
   {t:"Deploying TinyML",slug:"deploying-tinyml",cat:"Advanced · Computer Science",kw:"tinyml deploy microcontroller tensorflow embedded edge iot device sensors coding"},
   {t:"Fundamentals of TinyML",slug:"fundamentals-tinyml",cat:"Computer Science",kw:"tinyml fundamentals machine learning embedded basics iot sensors"},
   {t:"AI Strategy for Business Leaders",slug:"ai-strategy",cat:"Executive · Business",kw:"strategy business leaders value roi executive transformation hype impact"},
   {t:"AI in Medicine: Foundations",slug:"ai-medicine-foundations",cat:"Health & Medicine",kw:"medicine medical health clinical healthcare foundations doctor research"},
   {t:"AI Ethics in Business",slug:"ai-ethics-business",cat:"Business",kw:"ethics business bias responsible governance"},
   {t:"AI in Action: Organizational Strategy",slug:"ai-in-action",cat:"Executive",kw:"organizational organisational strategy adoption operations action"},
   {t:"Cybersecurity: Policy & Technology",slug:"cybersecurity",cat:"Executive",kw:"cybersecurity security cyber threats policy technology risk hacking protection"},
   {t:"AI in Medicine: Natural Language Processing",slug:"ai-medicine-nlp",cat:"Health & Medicine",kw:"medicine nlp natural language clinical text notes health"},
   {t:"Computer Science for Lawyers",slug:"cs-for-lawyers",cat:"Social Sciences",kw:"lawyers legal law computer science legaltech"},
   {t:"AI Essentials for Business",slug:"ai-essentials-business",cat:"Beginner · Business",kw:"business essentials basics professional value"},
   {t:"Agentic AI Foundations",slug:"agentic-ai",cat:"Advanced · Computer Science",kw:"agentic agents autonomous automation advanced"},
   {t:"Competing in the Age of AI",slug:"competing-age-ai",cat:"Executive · Business",kw:"competing competition strategy industry advantage business"},
   {t:"Leadership in Emerging Technology",slug:"leadership-emerging-tech",cat:"Executive",kw:"leadership emerging technology security strategy risk executive"},
   {t:"AI in Medicine: Biomedical Signal Interpretation",slug:"ai-medicine-signal",cat:"Health & Medicine",kw:"medicine biomedical signal ecg eeg health diagnosis deep learning"}
  ];
  var STOP="the and for with course courses class classes find want need looking about can you help please from that this your our".split(" ");

  function escapeHtml(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
  function search(s){
    var toks=s.replace(/[^a-z0-9 ]/g,' ').split(/\s+/).filter(function(w){return w.length>2 && STOP.indexOf(w)<0;});
    return COURSES.map(function(m){
      var hay=(m.t+" "+m.kw+" "+m.cat).toLowerCase(),sc=0;
      toks.forEach(function(t){ if(hay.indexOf(t)>=0)sc++; });
      return {m:m,sc:sc};
    }).filter(function(x){return x.sc>0;}).sort(function(a,b){return b.sc-a.sc;}).slice(0,3).map(function(x){return x.m;});
  }
  function T(v){return {k:"text",v:v};}
  function C(v){return {k:"cards",v:v};}
  function CH(v){return {k:"chips",v:v};}

  function reply(q){
    var s=q.toLowerCase();
    function has(){for(var i=0;i<arguments.length;i++){if(s.indexOf(arguments[i])>=0)return true;}return false;}
    if(/^(hi|hello|hey|howzit|good (morning|afternoon|day)|yo|hi there)\b/.test(s)) return [T("Howzit! I'm the Academy assistant. I can help you <b>find a course</b>, explain how the academy works, or point you to HR to register. What are you after?"),CH(["Find a course","How does it work?","What does it cost?","Register my interest"])];
    if(has("catalogue","catalog","new courses","what else","more courses","beyond ai","non-ai","other courses")) return [T("The catalogue goes well beyond AI — technical, business, compliance, safety and admin. What you see listed on this site is what's live so far, and more is being loaded in. If you want something that isn't here yet, <a href='contact'>ask HR</a> — if we can arrange it, you can do it."),CH(["Find a course","Register my interest"])];
    if(has("accredit","qcto","centenary","qualification","nqf","credential")) return [T("Accreditation details for the academy are set out in the footer of every page. If you're after a full qualification rather than a short course, the <b>Occupational Certificate: Project Manager</b> is the formal route — or <a href='contact'>ask HR</a>, since not everything in the catalogue is on the site yet."),C([COURSES[0]])];
    if(has("price","pricing","cost","fee","how much","rate","quote","afford","pay","free")) return [T("Nothing — every course is <b>fully funded by M&amp;T</b>. You just need to <a href='contact'>register your interest</a> and square the timing with your line manager."),CH(["Register my interest"])];
    if(has("contact","talk","human","agent","call","speak","reach","phone","email","enquire","enquiry","hr","register")) return [T("Send it through to <a href='mailto:kgomotso@centenarynetworks.com'>kgomotso@centenarynetworks.com</a> or call <a href='tel:0123456789'>012 345 6789</a>, or fill in the <a href='contact'>registration form</a> and they'll come back to you.")];
    if(has("download","pdf","workbook","resource","slides","material","handout")) return [T("Every course comes with downloadable <b>PDF resources</b> — workbooks, slides, cheat sheets and reading lists — on each course page under “Downloadable resources.”")];
    if(has("video","watch","stream","play")) return [T("Courses are <b>video-led</b> — short lessons you can stream on any device, at your own pace, plus downloadable resources. Open any course and press play."),CH(["Find a course"])];
    if(has("mobile","tablet","desktop","device","laptop")) return [T("Yes — the platform works on <b>mobile, tablet and desktop</b>. Videos stream and PDFs download on any device.")];
    if(has("how do i start","how does it work","how it works","get started","begin","enrol","enroll","sign up","how do we")) return [T("Pick a <b>course</b>, chat to your line manager about the timing, then register your interest with HR. You study online at your own pace — no travel, fitted around your shift. Want a suggestion to start with?"),CH(["Find a course","Register my interest"])];
    if(has("find","recommend","suggest","browse","which","what course","learn","interested","topic","looking for","explore","study")){
      var f1=search(s);
      if(f1.length) return [T("Here are courses that fit:"),C(f1)];
      return [T("Sure — what topic? For example:"),CH(["Project management","Cybersecurity","AI for managers","TinyML","AI in medicine"])];
    }
    var f=search(s);
    if(f.length) return [T("Here's what matches:"),C(f)];
    return [T("I can help you <b>find a course</b>, explain <b>how the academy works</b>, or point you to <b>HR</b> to register. Try a topic like “cybersecurity,” “AI for managers” or “TinyML.”"),CH(["Find a course","How does it work?","What does it cost?","Register my interest"])];
  }

  var ICON_SPARK='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5 18 18M18 6l-2.5 2.5M8.5 15.5 6 18"/><circle cx="12" cy="12" r="3"/></svg>';
  var btn=document.createElement('button'); btn.className='aiw-btn'; btn.setAttribute('aria-label','Open assistant');
  btn.innerHTML='<span class="dot"></span>'+ICON_SPARK+'Ask the Academy';
  var panel=document.createElement('div'); panel.className='aiw-panel'; panel.setAttribute('role','dialog');
  panel.innerHTML=
   '<div class="aiw-head"><div class="av">'+ICON_SPARK+'</div><div><h4>Academy Assistant</h4><p>Find a course · how it works · register</p></div>'+
   '<button class="x" aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button></div>'+
   '<div class="aiw-body"></div>'+
   '<div class="aiw-foot"><input type="text" placeholder="Ask about our courses…" aria-label="Message"><button class="aiw-send" aria-label="Send"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg></button></div>';
  document.body.appendChild(btn); document.body.appendChild(panel);

  var body=panel.querySelector('.aiw-body'), inp=panel.querySelector('input'), seeded=false;
  function scroll(){body.scrollTop=body.scrollHeight;}
  function addMsg(cls,html){var d=document.createElement('div');d.className='aiw-msg '+cls;d.innerHTML=html;body.appendChild(d);scroll();return d;}
  function addCards(items){var w=document.createElement('div');w.className='aiw-cards';w.innerHTML=items.map(function(m){/* Most entries are courses at course?c=<slug>; an entry may instead carry
     its own href (the partner programme menu is a page, not a course). */
    var h=m.href||('course?c='+m.slug);
    return '<a class="aiw-card" href="'+h+'"><b>'+m.t+'</b><span>'+m.cat+'</span></a>';}).join('');body.appendChild(w);scroll();}
  function addChips(items){var w=document.createElement('div');w.className='aiw-chipset';w.innerHTML=items.map(function(c){return '<button class="aiw-chip">'+c+'</button>';}).join('');w.querySelectorAll('.aiw-chip').forEach(function(b){b.addEventListener('click',function(){send(b.textContent);});});body.appendChild(w);scroll();}
  function botTurn(blocks){
    var t=document.createElement('div'); t.className='aiw-msg bot aiw-typing'; t.innerHTML='<span></span><span></span><span></span>'; body.appendChild(t); scroll();
    setTimeout(function(){ t.remove(); blocks.forEach(function(b){ if(b.k==='text')addMsg('bot',b.v); else if(b.k==='cards')addCards(b.v); else if(b.k==='chips')addChips(b.v); }); },450);
  }
  function send(text){ if(!text||!text.trim())return; addMsg('me',escapeHtml(text)); inp.value=''; botTurn(reply(text)); }

  function open(){ panel.classList.add('open'); btn.style.display='none'; if(!seeded){ seeded=true; botTurn([T("Howzit! I'm the Academy assistant. I can help you <b>find a course</b>, explain how the academy works, or point you to HR to register."),CH(["Find a course","How does it work?","What does it cost?","Register my interest"])]); } inp.focus(); }
  function close(){ panel.classList.remove('open'); btn.style.display=''; }
  btn.addEventListener('click',open);
  panel.querySelector('.x').addEventListener('click',close);
  panel.querySelector('.aiw-send').addEventListener('click',function(){send(inp.value);});
  inp.addEventListener('keydown',function(e){ if(e.key==='Enter'){e.preventDefault();send(inp.value);} });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape'&&panel.classList.contains('open'))close(); });
})();
