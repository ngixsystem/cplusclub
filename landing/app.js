const count=document.querySelector('#pc-count'),range=document.querySelector('#pc-range');
let pcs=50,selected='standard';
const plans={standard:{name:'Обслуживание',base:100,rate:4},priority:{name:'Приоритет',base:150,rate:6}};
const money=n=>'$'+n.toLocaleString('en-US');
function update(value){pcs=Math.min(1000,Math.max(1,Math.round(Number(value)||1)));count.value=pcs;range.value=Math.min(200,Math.max(10,pcs));for(const [key,p] of Object.entries(plans))document.querySelector('#'+key+'-price').textContent=money(p.base+p.rate*pcs);}
count.addEventListener('change',()=>update(count.value));range.addEventListener('input',()=>update(range.value));
function openDialog(id){document.querySelector(id).showModal();}
document.querySelectorAll('dialog').forEach(d=>{d.querySelectorAll('.close,.close-action').forEach(b=>b.addEventListener('click',()=>d.close()));d.addEventListener('click',e=>{if(e.target===d){const r=d.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)d.close();}});});
document.querySelectorAll('[data-plan]').forEach(b=>b.addEventListener('click',()=>{update(count.value);selected=b.dataset.plan;const p=plans[selected];document.querySelector('#plan-title').textContent=p.name;document.querySelector('#plan-summary').textContent=pcs+' ПК × $'+p.rate+' + $'+p.base+' за клуб';document.querySelector('#plan-total').textContent=money(p.base+p.rate*pcs)+' / месяц';document.querySelector('#copy-status').textContent='';openDialog('#plan-dialog');}));
document.querySelector('#copy-calculation').addEventListener('click',async()=>{const p=plans[selected];const text='C+CLUB · '+p.name+'\n'+pcs+' ПК × $'+p.rate+' + $'+p.base+' за клуб = '+money(p.base+p.rate*pcs)+'/месяц.\nПредварительный расчёт. Окончательная стоимость и состав работ согласуются в договоре.';try{await navigator.clipboard.writeText(text);document.querySelector('#copy-status').textContent='Расчёт скопирован.';}catch{document.querySelector('#copy-status').textContent='Не удалось скопировать автоматически. Вы можете выделить расчёт выше.';}});

/* ---------- Motion ----------
 * Scroll reveals, header state, the SLA card (pager + pointer tilt) and the
 * word-by-word statement. Everything degrades to a static, fully visible page. */
(function(){
  const root=document.documentElement;
  const reduce=matchMedia('(prefers-reduced-motion: reduce)').matches;
  const fine=matchMedia('(hover: hover) and (pointer: fine)').matches;

  // Header gets a backdrop once the page scrolls.
  const header=document.querySelector('.header');
  const onScroll=()=>header.classList.toggle('scrolled',scrollY>24);
  addEventListener('scroll',onScroll,{passive:true});onScroll();

  // SLA card: four figures from the service terms.
  const slides=[
    {label:'Инцидент на клиентском ПК',value:'2 ч',sub:'на решение'},
    {label:'Плановые крупные работы',value:'24 ч',sub:'по согласованию'},
    {label:'Плановые выезды',value:'2',sub:'выезда в месяц'},
    {label:'Аудит и чистка ПК',value:'6 мес',sub:'между осмотрами'}
  ];
  const card=document.getElementById('sla-card');
  if(card){
    const dots=[...document.querySelectorAll('.dots button')];
    const label=document.getElementById('sla-label'),value=document.getElementById('sla-value'),sub=document.getElementById('sla-sub');
    const num=document.getElementById('sla-num'),bar=document.getElementById('sla-bar'),track=document.querySelector('.sla-track');
    let i=0,swapTimer;
    const show=n=>{n=Math.max(0,Math.min(slides.length-1,n));if(n===i)return;i=n;dots.forEach((d,k)=>d.setAttribute('aria-selected',String(k===i)));if(num)num.textContent=String(i+1).padStart(2,'0');
      const s=slides[i];if(reduce){label.textContent=s.label;value.textContent=s.value;sub.textContent=s.sub;return;}
      card.classList.add('swap');clearTimeout(swapTimer);
      swapTimer=setTimeout(()=>{label.textContent=s.label;value.textContent=s.value;sub.textContent=s.sub;card.classList.remove('swap');},180);};
    // Scroll drives the slides: the block stays pinned while the page scrolls
    // through its track, and each quarter of the track shows the next figure.
    const progress=()=>{const r=track.getBoundingClientRect();const total=r.height-innerHeight;return total>0?Math.min(1,Math.max(0,-r.top/total)):0;};
    let ticking=false;
    const sync=()=>{ticking=false;const p=progress();if(bar)bar.style.transform=`scaleX(${p.toFixed(3)})`;show(Math.min(slides.length-1,Math.floor(p*slides.length)));};
    addEventListener('scroll',()=>{if(!ticking){ticking=true;requestAnimationFrame(sync);}},{passive:true});
    addEventListener('resize',sync);sync();
    // Arrows and dots scroll to the middle of that figure's part of the track.
    const goTo=k=>{k=Math.max(0,Math.min(slides.length-1,k));const r=track.getBoundingClientRect();const total=r.height-innerHeight;
      scrollTo({top:scrollY+r.top+total*((k+.5)/slides.length),behavior:reduce?'auto':'smooth'});};
    document.querySelector('.sla .prev').addEventListener('click',()=>goTo(i-1));
    document.querySelector('.sla .next').addEventListener('click',()=>goTo(i+1));
    dots.forEach((d,k)=>d.addEventListener('click',()=>goTo(k)));
    // Decorative tilt follows the pointer with easing (mouse only, never on touch).
    if(fine&&!reduce){
      const stage=document.querySelector('.sla-stage');let tx=8,ty=-14,cx=8,cy=-14,raf=0;
      const loop=()=>{cx+=(tx-cx)*.08;cy+=(ty-cy)*.08;card.style.setProperty('--rx',cx.toFixed(2)+'deg');card.style.setProperty('--ry',cy.toFixed(2)+'deg');
        raf=Math.abs(tx-cx)+Math.abs(ty-cy)>.05?requestAnimationFrame(loop):0;};
      stage.addEventListener('pointermove',e=>{const r=stage.getBoundingClientRect();tx=8-((e.clientY-r.top)/r.height-.5)*16;ty=-14+((e.clientX-r.left)/r.width-.5)*36;card.style.transition='none';if(!raf)raf=requestAnimationFrame(loop);});
      stage.addEventListener('pointerleave',()=>{tx=8;ty=-14;if(!raf)raf=requestAnimationFrame(loop);});
    }
  }

  // Statement: words brighten as the line crosses the middle of the screen.
  const statement=document.querySelector('[data-words]');
  if(statement&&!reduce){
    const wrap=node=>{[...node.childNodes].forEach(n=>{if(n.nodeType===3){const f=document.createDocumentFragment();n.textContent.split(/(\s+)/).forEach(t=>{if(!t)return;if(/^\s+$/.test(t)){f.appendChild(document.createTextNode(t));return;}const s=document.createElement('span');s.className='w';s.textContent=t;f.appendChild(s);});n.replaceWith(f);}else if(n.nodeType===1)wrap(n);});};
    wrap(statement);
    const words=[...statement.querySelectorAll('.w')];let ticking=false,active=false;
    const paint=()=>{ticking=false;const r=statement.getBoundingClientRect();const p=Math.min(1,Math.max(0,(innerHeight*.85-r.top)/(innerHeight*.55)));const lit=Math.round(p*words.length);
      words.forEach((w,k)=>{w.style.color=k<lit?(w.dataset.soft?'var(--muted)':'var(--fg)'):'rgba(242,244,238,.22)';});};
    const io=new IntersectionObserver(es=>{active=es[0].isIntersecting;if(active)paint();});io.observe(statement);
    addEventListener('scroll',()=>{if(active&&!ticking){ticking=true;requestAnimationFrame(paint);}},{passive:true});
    // Words in the muted tail light up to the muted tone, the rest to full white.
    statement.querySelectorAll('.soft .w').forEach(w=>w.dataset.soft='1');
    paint();
  }

  // Scroll reveals.
  if(!root.classList.contains('m'))return;
  if(!('IntersectionObserver' in window)){root.classList.remove('m');return;}
  window.cplusMotion=true;
  const single=['.section-head','.routine','.sla-top','.sla-stage','.calculator','.pricing-note','.expansion-art','.expansion-copy','.platform>div:first-child','.terms h2','.footer-grid','.wordmark'];
  const groups=['.service-grid>.svc','.plans>.plan','.platform-list>div','.faq details'];
  const els=[];
  single.forEach(sel=>document.querySelectorAll(sel).forEach(el=>{el.classList.add('reveal');els.push(el);}));
  groups.forEach(sel=>document.querySelectorAll(sel).forEach((el,k)=>{el.classList.add('reveal');el.style.transitionDelay=Math.min(k,5)*70+'ms';els.push(el);}));
  // Sticky process cards rise one by one as they arrive.
  document.querySelectorAll('.step').forEach(el=>{el.classList.add('reveal');els.push(el);});
  const io=new IntersectionObserver(entries=>{for(const e of entries){if(!e.isIntersecting)continue;const t=e.target;t.classList.add('in');io.unobserve(t);
    setTimeout(()=>{t.style.transitionDelay='';},1300);}},{rootMargin:'0px 0px -8% 0px',threshold:.1});
  els.forEach(el=>io.observe(el));
  document.querySelectorAll('a[href^="#"]').forEach(a=>a.addEventListener('click',()=>{const h=a.getAttribute('href')||'';if(h.length<2)return;const t=document.getElementById(h.slice(1));if(t)t.querySelectorAll('.reveal').forEach(el=>el.classList.add('in'));}));
})();

/* Header: mark the section currently in view with aria-current (lime dot). */
(function(){
  const links=[...document.querySelectorAll('.header nav a[href^="#"]')];
  const map=new Map(links.map(a=>[a.getAttribute('href').slice(1),a]));
  const sections=[...map.keys()].map(id=>document.getElementById(id)).filter(Boolean);
  if(!sections.length||!('IntersectionObserver' in window))return;
  const visible=new Set();
  const io=new IntersectionObserver(es=>{es.forEach(e=>e.isIntersecting?visible.add(e.target.id):visible.delete(e.target.id));
    const current=sections.find(s=>visible.has(s.id));
    links.forEach(a=>a.setAttribute('aria-current',String(!!current&&a===map.get(current.id))));},{rootMargin:'-45% 0px -50% 0px'});
  sections.forEach(s=>io.observe(s));
})();
