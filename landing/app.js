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

/* Scroll reveals. Groups stagger 70ms per item (capped), single blocks rise alone.
 * Elements already on screen at load reveal at once; nothing re-animates on
 * scrolling back up. Without IntersectionObserver everything stays visible. */
(function(){
  const root=document.documentElement;
  if(!root.classList.contains('m'))return;
  if(!('IntersectionObserver' in window)){root.classList.remove('m');return;}
  window.cplusMotion=true;
  const single=['.section-top','#services>h2','.process .eyebrow','.process h2','.routine','#pricing>h2','.section-intro','.pricing-note','.expansion>.eyebrow','.expansion>div','.platform>div:first-child','.platform-list>p','.terms h2','.footer'];
  const groups=['.service-grid>article','.steps>article','.plans>.plan','.platform-list>div','.terms details'];
  const els=[];
  single.forEach(sel=>document.querySelectorAll(sel).forEach(el=>{el.classList.add('reveal');els.push(el);}));
  groups.forEach(sel=>document.querySelectorAll(sel).forEach((el,i)=>{el.classList.add('reveal');el.style.transitionDelay=Math.min(i,5)*70+'ms';els.push(el);}));
  const calc=document.querySelector('.calculator');if(calc){calc.classList.add('reveal-line');els.push(calc);}
  const io=new IntersectionObserver(entries=>{for(const e of entries){if(!e.isIntersecting)continue;e.target.classList.add('in');io.unobserve(e.target);
    // Drop the stagger once shown so hover/focus styles are never delayed.
    setTimeout(()=>{e.target.style.transitionDelay='';},1200);}},{rootMargin:'0px 0px -10% 0px',threshold:0.12});
  els.forEach(el=>io.observe(el));
  // Anchor jumps (nav, "Подобрать тариф"): reveal the target section immediately.
  document.querySelectorAll('a[href^="#"]').forEach(a=>a.addEventListener('click',()=>{const h=a.getAttribute('href')||'';if(h.length<2)return;const t=document.getElementById(h.slice(1));if(t)t.querySelectorAll('.reveal,.reveal-line').forEach(el=>el.classList.add('in'));}));
})();
