/* ---- SPS Academy profile (local only) ----
   No account, no password, no backend. The profile lives in this browser's
   localStorage and never leaves the device: it holds employment details, which
   are POPIA-regulated, and the public half of this site is static hosting with
   nowhere safe to put them.

   Unlike the other local stores this one PERSISTS. On a shared or
   site machine the next person would see it, which is why every screen that
   touches the profile offers a visible way to clear it.

   Loaded on every page. It does three jobs:
     1. exposes window.SPSProfile (get/save/clear/has)
     2. upgrades the nav profile link to an initials avatar once one exists
     3. prefills the registration form */
(function(){
  var KEY='sps.profile.v1';

  /* ---- the signed-in account, if there is one ---------------------------
     Since Aug 2026 there is a back end, so "profile" has two meanings on this
     site and they are not the same thing:

       - the LOCAL profile below: a name and a department typed into this
         browser by somebody with no account, used to prefill forms. Still
         useful, still nobody else's business, still never leaves the device.
       - the ACCOUNT: a real sign-in, created by the academy, whose progress is
         held against the person rather than against the browser.

     This block owns the second one and exposes it as window.SPSAccount, because
     this file is already loaded on every page and adding a second script tag to
     seventeen pages to ask one question would be the worse trade.

     Deliberately NOT cached in sessionStorage. A stale "nobody is signed in"
     immediately after signing in is exactly the bug that sent the first
     administrator to the homepage instead of the admin list, and one small
     request per page load is a price this site can afford. */
  var A={ session:null, loaded:false, waiting:[] };

  function settle(s){
    A.session=s; A.loaded=true;
    var q=A.waiting; A.waiting=[];
    q.forEach(function(cb){ try{ cb(s); }catch(e){} });
  }

  function probe(){
    return fetch('account.php',{credentials:'same-origin',headers:{'Accept':'application/json'}})
      .then(function(r){ return r.json(); })
      .then(function(j){ return j && j.in ? j : null; })
      /* A failed probe is not an error worth showing anyone. It means the page
         behaves exactly as it did before there were accounts, which is a
         working page. */
      .catch(function(){ return null; });
  }

  var Account={
    /* cb(session|null), now if the answer is already known. */
    ready:function(cb){ if(A.loaded) cb(A.session); else A.waiting.push(cb); },
    get:function(){ return A.session; },
    signedIn:function(){ return !!A.session; },

    /** Re-ask. Used after a rejected token, and after a write that failed. */
    refresh:function(){
      return probe().then(function(s){ A.session=s; A.loaded=true; return s; });
    },

    /**
     * A write to account.php, with one retry.
     *
     * The retry is not defensive padding: the CSRF token rotates whenever any
     * form on the site is submitted successfully, so a module page left open in
     * another tab will legitimately hold a token that has moved on. The server
     * names that failure 'token' precisely so this can tell it apart from a
     * refusal and fix it silently.
     */
    post:function(params){
      return send(params,false);
    }
  };

  function send(params,retried){
    if(!A.session) return Promise.resolve({ok:false,error:'signed-out'});
    var body=new URLSearchParams();
    body.set('_token',A.session.token||'');
    for(var k in params) if(params.hasOwnProperty(k)) body.set(k,params[k]);

    return fetch('account.php',{
      method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},
      body:body.toString()
    })
    .then(function(r){ return r.json().catch(function(){ return {ok:false,error:'http'}; }); })
    .then(function(j){
      if(j&&j.error==='token'&&!retried){
        return Account.refresh().then(function(){ return send(params,true); });
      }
      return j||{ok:false,error:'http'};
    })
    .catch(function(){ return {ok:false,error:'network'}; });
  }

  window.SPSAccount=Account;
  probe().then(settle);

  function read(){
    try{ return JSON.parse(localStorage.getItem(KEY))||null; }catch(e){ return null; }
  }
  function write(o){
    try{ localStorage.setItem(KEY,JSON.stringify(o)); return true; }
    catch(e){ return false; }   // private mode / storage disabled / quota
  }

  var P={
    get:function(){ return read()||{}; },
    exists:function(){ return !!read(); },
    save:function(patch){
      var p=read()||{};
      for(var k in patch) p[k]=patch[k];
      p.updated=new Date().toISOString();
      return write(p);
    },
    clear:function(){ try{ localStorage.removeItem(KEY); }catch(e){} },
    /* Storage can be unavailable (Safari private mode, locked-down browsers).
       Callers need to be able to say so rather than silently losing data. */
    available:function(){
      try{ localStorage.setItem(KEY+'.t','1'); localStorage.removeItem(KEY+'.t'); return true; }
      catch(e){ return false; }
    },
    initials:function(){
      var n=(this.get().name||'').trim();
      if(!n) return '';
      var parts=n.split(/\s+/);
      return ((parts[0]||'')[0]+(parts.length>1?(parts[parts.length-1]||'')[0]:'')).toUpperCase();
    },
    firstName:function(){ return ((this.get().name||'').trim().split(/\s+/)[0])||''; }
  };
  window.SPSProfile=P;

  /* ---- nav avatar ----
     Two states share one control. Signed in, it is the way to the learner's own
     page and wears their real initials; signed out it is the local profile, as
     it has always been. Doing it here rather than in the markup is what keeps
     the seventeen static pages static — they ship one <a> and this decides what
     it means. */
  function paintNav(){
    var link=document.querySelector('.nav-profile'); if(!link) return;
    var av=link.querySelector('.np-av'), lbl=link.querySelector('.np-label');
    var s=Account.get();

    if(s){
      link.setAttribute('href','my');
      link.classList.add('signed-in');
      if(av){ av.textContent=s.initials||''; av.classList.add('filled'); }
      if(lbl) lbl.textContent=s.first||'My learning';
      link.setAttribute('title','My learning — signed in as '+(s.name||''));
      return;
    }

    var ini=P.initials();
    if(ini){
      if(av){ av.textContent=ini; av.classList.add('filled'); }
      if(lbl) lbl.textContent=P.firstName()||'Profile';
      link.setAttribute('title','Your profile — '+(P.get().name||''));
    }
  }

  /* ---- a way in ----
     There was no "Sign in" anywhere on the site, because until now there was
     nothing to sign in to: Kgomotso reached the admin pages by typing the URL.
     A learner cannot be asked to do that. Added here rather than to every
     page's markup for the same reason as the avatar above. */
  function paintSignIn(){
    if(Account.get()) return;                             // already in
    var links=document.getElementById('navLinks'); if(!links) return;
    if(links.querySelector('.nav-signin')) return;        // this page ships one
    if(/(^|\/)login(\.php)?$/.test(location.pathname)) return;

    var a=document.createElement('a');
    a.className='nav-signin';
    a.href='login';
    a.textContent='Sign in';
    var cta=links.querySelector('.nav-cta');
    links.insertBefore(a,cta||null);
  }

  /* ---- prefill the registration form ---- */
  function prefillContact(){
    var form=document.querySelector('form.form'); if(!form) return;
    var s=Account.get(), p=P.get(), map, where;

    if(s){
      /* An account beats the local profile: it is what the academy actually
         holds, so a form filled from it matches their records rather than
         whatever was typed into this browser months ago. */
      map={'Name':s.name,'Employee number':s.empno,'Department':s.dept,'Email':s.email};
      where='your academy account';
    }else{
      if(!P.exists()) return;
      map={'Name':p.name,'Employee number':p.empno,'Department':p.dept,
           'Line manager':p.manager,'Email':p.email,'Phone':p.phone};
      where='<a href="profile">your profile</a>';
    }
    var filled=0;
    for(var name in map){
      var el=form.querySelector('[name="'+name+'"]');
      // never overwrite something already there (e.g. ?course= from Skills Gap)
      if(el&&!el.value&&map[name]){ el.value=map[name]; filled++; }
    }
    if(!filled) return;
    if(form.querySelector('.prefill-note')) return;   // the probe repaints; don't stack notes
    var note=document.createElement('p');
    note.className='prefill-note';
    note.innerHTML='Filled in from '+where+'. Edit anything that\'s out of date — '+
                   'changing it here won\'t change what the academy has on file.';
    form.insertBefore(note,form.querySelector('.two')||form.firstChild);
  }

  /* ---- save-a-course button on the course detail page ---- */
  function wireCourseSave(){
    var btn=document.getElementById('c-save'); if(!btn) return;
    var slug=new URLSearchParams(location.search).get('c'); if(!slug){ btn.remove(); return; }

    function label(){
      var list=P.get().courses||[];
      var on=list.some(function(c){return c.slug===slug;});
      btn.classList.toggle('on',on);
      btn.innerHTML=on
        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px"><path d="m5 12 5 5L20 7"/></svg> Saved to profile'
        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg> Save to my profile';
    }
    label();
    btn.addEventListener('click',function(){
      if(!P.available()){
        btn.textContent='Storage is blocked in this browser';
        btn.disabled=true; return;
      }
      var list=(P.get().courses||[]).slice();
      var i=-1; list.forEach(function(c,ix){ if(c.slug===slug) i=ix; });
      if(i>=0) list.splice(i,1);
      else list.push({slug:slug,title:(document.getElementById('c-h1')||{}).textContent||slug});
      P.save({courses:list});
      label();
    });
  }

  function init(){
    paintNav(); prefillContact(); wireCourseSave();
    /* The probe is in flight while the page renders, so the nav is painted
       twice: once with what is known now, and again when the answer lands. */
    Account.ready(function(){ paintNav(); paintSignIn(); prefillContact(); });
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init);
  else init();
})();
