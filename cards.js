/* Course cards: real photo banners (loaded over gradient fallback) + link each card to its course page */
(function(){
  var CARDS=[
    ["Occupational Certificate: Project Manager","project-management","1454165804606-c3d57bc86b40"],
    ["AI & Software Development","ai-software-development","1461749280684-dccba630e2f6"],
    ["Procurement Skills","procurement","1521737604893-d14cc237f11d"],
    ["AI Fundamentals for the Workplace","ai-fundamentals","1677442136019-21780ecad995"],
    ["AI Tools for Productivity","ai-tools-productivity","1581091226825-a6a2a5aee158"],
    ["Responsible & Ethical AI Use","responsible-ai","1620712943543-bcc4688e7485"],
    ["AI for Leaders & Managers","ai-leaders","1552664730-d307ca884978"],
    ["AI & New Venture Creation","new-venture","1559136555-9303baea8ebd"],
    ["Deploying TinyML","deploying-tinyml","1593376853899-fbb47a057fa0"],
    ["Fundamentals of TinyML","fundamentals-tinyml","1518770660439-4636190af475"],
    ["AI Strategy for Business Leaders","ai-strategy","1542744173-8e7e53415bb0"],
    ["AI in Medicine: Foundations","ai-medicine-foundations","1576091160550-2173dba999ef"],
    ["AI Ethics in Business","ai-ethics-business","1488229297570-58520851e868"],
    ["AI in Action: Organizational Strategy","ai-in-action","1504384308090-c894fdcc538d"],
    ["Cybersecurity","cybersecurity","1550751827-4bd374c3f58b"],
    ["AI in Medicine: Natural Language","ai-medicine-nlp","1518186285589-2f7649de83e0"],
    ["Computer Science for Lawyers","cs-for-lawyers","1589829545856-d10d557cf95f"],
    ["AI Essentials for Business","ai-essentials-business","1454165804606-c3d57bc86b40"],
    ["Agentic AI Foundations","agentic-ai","1531746790731-6c087fecd65a"],
    ["Competing in the Age of AI","competing-age-ai","1485827404703-89b55fcc595e"],
    ["Leadership in Emerging Technology","leadership-emerging-tech","1521737604893-d14cc237f11d"],
    ["AI in Medicine: Biomedical Signal","ai-medicine-signal","1559757148-5c350d0d3c56"]
  ];
  var img=function(id){return "https://images.unsplash.com/photo-"+id+"?auto=format&fit=crop&w=720&q=70";};
  var ARROW=' <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;vertical-align:-1px;color:var(--accent)"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
  document.querySelectorAll('.ccard').forEach(function(card){
    if(card.dataset.href) return;   // external course — courses-index.js links it out
    if(card.dataset.locked) return; // locked by locks.js — no link, no photo, no arrow
    var h=card.querySelector('h4'); if(!h) return;
    var t=h.textContent.trim(), hit=null;
    for(var i=0;i<CARDS.length;i++){ if(t.indexOf(CARDS[i][0])>-1){ hit=CARDS[i]; break; } }
    if(!hit) return;
    var slug=hit[1], photo=hit[2], href="course?c="+slug;
    var inner=card.querySelector('.ccard-img-inner'), imgWrap=card.querySelector('.ccard-img');
    if(inner&&imgWrap){ var pre=new Image(); pre.onload=(function(inner,imgWrap,photo){return function(){inner.style.backgroundImage="url('"+img(photo)+"')";inner.style.backgroundColor="#15131a";imgWrap.classList.add('has-photo');};})(inner,imgWrap,photo); pre.src=img(photo); }
    if(!h.querySelector('a')) h.innerHTML='<a href="'+href+'" style="color:inherit">'+h.innerHTML+'</a>';
    card.style.cursor='pointer';
    card.addEventListener('click',function(e){ if(e.target.closest('a,button'))return; location.href=href; });
    var av=card.querySelector('.ccard-foot .avail'); if(av&&!av.querySelector('svg')) av.insertAdjacentHTML('beforeend',ARROW);
  });
})();
