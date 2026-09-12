/* Shared site behaviour: nav toggle, scroll shadow, contact prefill.
 *
 * The scroll-reveal came out on 3 Sep 2026. Two reasons, and the second is the
 * one that mattered:
 *
 *   - Kgomotso asked for no animation on the sites, so the fade it drove is
 *     gone from styles.css and the observer had nothing left to do. The
 *     .reveal class stays in the markup; it is now inert and harmless.
 *   - It was an unguarded `new IntersectionObserver(...)`, which THROWS on a
 *     browser that does not have it — Opera Mini, which has real share in South
 *     Africa. Everything after line 9 in this file then never ran, so a learner
 *     arriving from a Skills Gap recommendation silently lost the course
 *     prefill below. That was a live bug for those users, not a hypothetical,
 *     and deleting the observer fixes it rather than wrapping it in a check for
 *     a feature nothing needs any more.
 */
(function(){
  var nav=document.getElementById('nav'),toggle=document.getElementById('navToggle'),links=document.getElementById('navLinks');
  if(toggle&&links){
    toggle.addEventListener('click',function(){links.classList.toggle('open');});
    links.querySelectorAll('a').forEach(function(a){a.addEventListener('click',function(){links.classList.remove('open');});});
  }
  window.addEventListener('scroll',function(){ if(nav) nav.classList.toggle('scrolled',window.scrollY>10); });

  /* Prefill the course field when arriving from a Skills Gap recommendation
     (contact?course=AI+Fundamentals…) so nobody has to retype the title. */
  var course=new URLSearchParams(location.search).get('course');
  if(course){
    var f=document.querySelector('input[name="Course"]');
    if(f){ f.value=course; f.setAttribute('data-prefilled','1'); }
  }
})();
