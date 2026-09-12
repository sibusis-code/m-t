/* ---- Course index: search + credential/subject filters ----
   Filters the cards already in the page rather than re-rendering them, so with
   JS off the catalogue still reads fine — you just get the full list.

   Note there is more than one .course-grid on this page (the cards are split
   under band headings), so everything below works across all of them. Scoping
   to a single grid silently filtered a third of the catalogue. */
(function(){
  var box=document.getElementById('cx'); if(!box) return;

  var grids=[].slice.call(document.querySelectorAll('.course-grid'));
  if(!grids.length) return;

  // each grid's heading, so a band with nothing left in it disappears too
  var groups=grids.map(function(g){
    var lbl=g.previousElementSibling;
    while(lbl&&!lbl.classList.contains('course-band-label')) lbl=lbl.previousElementSibling;
    return {grid:g,label:lbl,cards:[].slice.call(g.querySelectorAll('.ccard'))};
  });
  var cards=groups.reduce(function(a,g){return a.concat(g.cards);},[]);

  var q=document.getElementById('cxq'),
      accRow=document.getElementById('cxAcc'),
      catRow=document.getElementById('cxCat'),
      count=document.getElementById('cxCount');

  var state={q:'',acc:'all',cat:'all'};

  /* Subject chips come from the cards themselves, so adding a course to the
     page is enough — nothing here needs updating too. */
  var cats=[];
  cards.forEach(function(c){
    var v=(c.dataset.cat||'').trim();
    if(v&&cats.indexOf(v)<0) cats.push(v);
  });
  cats.sort();
  cats.forEach(function(v){
    var b=document.createElement('button');
    b.className='cx-chip'; b.dataset.cat=v;
    b.textContent=v.replace(/&amp;/g,'&').toLowerCase()
                   .replace(/(^|\s)\S/g,function(x){return x.toUpperCase();});
    catRow.appendChild(b);
  });

  var empty=document.createElement('div');
  empty.className='cx-empty'; empty.hidden=true;
  empty.innerHTML='<strong>Nothing matches that</strong>'+
    '<p>Try clearing the filters. If what you want isn\'t on the site yet, <a href="contact" style="color:var(--orange-deep);font-weight:700;'+
    'text-decoration:underline">ask HR</a> — if it\'s in the catalogue, you can do it.</p>';
  var last=grids[grids.length-1];
  last.parentNode.insertBefore(empty,last.nextSibling);

  function apply(){
    var shown=0;
    groups.forEach(function(g){
      var onHere=0;
      g.cards.forEach(function(c){
        var okQ=!state.q||(c.dataset.text||'').indexOf(state.q)>=0;
        var okA=state.acc==='all'||(c.dataset.acc||'none')===state.acc;
        var okC=state.cat==='all'||(c.dataset.cat||'')===state.cat;
        var on=okQ&&okA&&okC;
        c.style.display=on?'':'none';
        if(on) onHere++;
      });
      g.grid.style.display=onHere?'':'none';
      if(g.label) g.label.style.display=onHere?'':'none';
      shown+=onHere;
    });
    empty.hidden=shown>0;

    var filtered=state.q||state.acc!=='all'||state.cat!=='all';
    count.innerHTML='Showing <strong>'+shown+'</strong> of <strong>'+cards.length+
      '</strong> courses on the site'+
      (filtered?' <button class="cx-reset" id="cxReset">Clear filters</button>':'')+
      '<br><span style="font-size:12.5px">Ask HR for anything not listed.</span>';
    var r=document.getElementById('cxReset');
    if(r) r.addEventListener('click',reset);
  }

  function reset(){
    state={q:'',acc:'all',cat:'all'};
    q.value='';
    setActive(accRow,'acc','all'); setActive(catRow,'cat','all');
    apply(); q.focus();
  }

  function setActive(row,key,val){
    row.querySelectorAll('.cx-chip').forEach(function(b){
      b.classList.toggle('on',b.dataset[key]===val);
    });
  }

  q.addEventListener('input',function(){ state.q=q.value.trim().toLowerCase(); apply(); });
  accRow.addEventListener('click',function(e){
    var b=e.target.closest('.cx-chip'); if(!b) return;
    state.acc=b.dataset.acc; setActive(accRow,'acc',state.acc); apply();
  });
  catRow.addEventListener('click',function(e){
    var b=e.target.closest('.cx-chip'); if(!b) return;
    state.cat=b.dataset.cat; setActive(catRow,'cat',state.cat); apply();
  });

  /* A search typed into the landing-page hero arrives here as ?q=…, so the
     catalogue opens already filtered instead of showing everything. It seeds
     the same state the search box writes, so Clear filters undoes it. */
  var seed=(new URLSearchParams(location.search).get("q")||"").trim();
  if(seed){ q.value=seed; state.q=seed.toLowerCase(); }

  apply();
})();

/* ---- External course cards ----
   The international courses are hosted by Google, Microsoft, Coursera and
   Helsinki, so they link straight out. Giving them an M&T course page would
   mean inventing modules, videos and workbooks we don't own — the outbound
   link is the honest version. cards.js only rewires cards whose titles it
   recognises, so it leaves these alone. */
(function(){
  [].slice.call(document.querySelectorAll('.ccard[data-href]')).forEach(function(c){
    if(c.dataset.locked) return;   // locked by locks.js — don't link it out either
    var url=c.dataset.href, h=c.querySelector('h4');
    if(h&&!h.querySelector('a')){
      h.innerHTML='<a href="'+url+'" target="_blank" rel="noopener noreferrer" '+
        'style="color:inherit">'+h.innerHTML+'</a>';
    }
    c.style.cursor='pointer';
    c.addEventListener('click',function(e){
      if(e.target.closest('a,button')) return;   // let the link and the badge do their own thing
      window.open(url,'_blank','noopener');
    });
  });
})();

/* ---- Accreditation badge popover ----
   Hover covers pointer users; click/Enter is what makes it reachable on touch
   and by keyboard, which a hover-only tooltip never is. */
(function(){
  var badges=[].slice.call(document.querySelectorAll('.acc-badge'));
  if(!badges.length) return;
  function closeAll(except){
    badges.forEach(function(b){ if(b!==except) b.setAttribute('aria-expanded','false'); });
  }
  badges.forEach(function(b){
    b.setAttribute('aria-expanded','false');
    b.addEventListener('click',function(e){
      e.preventDefault(); e.stopPropagation();
      var open=b.getAttribute('aria-expanded')==='true';
      closeAll(b);
      b.setAttribute('aria-expanded',open?'false':'true');
    });
  });
  document.addEventListener('click',function(){ closeAll(null); });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape') closeAll(null); });
})();
