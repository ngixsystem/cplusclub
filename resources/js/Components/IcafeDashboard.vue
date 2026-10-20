<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import RevenueChart from './RevenueChart.vue';
type Club={id:number;name:string;icafe_license:number|null;icafe_currency:string;timezone:string};
type Shift={id:string;operator:string;start:string;end:string|null;duration_seconds?:number;total:number;cash:number;card:number;qr:number};
type Snapshot={connected:boolean;shifts?:Shift[];computers?:{name:string;online:boolean;busy:boolean}[];total_pcs?:number;online_pcs?:number;updated_at?:string|null;stale?:boolean;error?:string;period_start?:string;period_end?:string};
type Detail={detail?:Record<string,number|null>;chart?:{labels:string[];values:number[]};stale?:boolean;error?:string};
const props=defineProps<{clubs:Club[];canManage:boolean}>();
const clubId=ref(props.clubs.find(c=>c.icafe_license)?.id ?? props.clubs[0]?.id ?? 0), selected=ref(''), expanded=ref('');
const data=ref<Snapshot|null>(null), detail=ref<Detail|null>(null), loading=ref(false), error=ref('');
const club=computed(()=>props.clubs.find(c=>c.id===Number(clubId.value)));
const shifts=computed(()=>data.value?.shifts ?? []);
const active=computed(()=>shifts.value.filter(s=>!s.end));
const current=computed(()=>shifts.value.find(s=>s.id===selected.value));
const total=computed(()=>active.value.reduce((n,s)=>n+s.total,0));
const weekly=computed(()=>{const result=new Map<string,number>();if(data.value?.period_start){const d=new Date(data.value.period_start+'T00:00:00Z');for(let i=0;i<7;i++){result.set(d.toISOString().slice(0,10),0);d.setUTCDate(d.getUTCDate()+1);}}for(const s of shifts.value){const day=s.start.slice(0,10);result.set(day,(result.get(day)??0)+s.total);}return {labels:[...result.keys()].map(k=>k.slice(8,10)+'.'+k.slice(5,7)),values:[...result.values()]};});
const duration=(s:Shift)=>s.duration_seconds==null?'—':Math.floor(s.duration_seconds/3600)+' ч '+Math.floor(s.duration_seconds%3600/60)+' мин';
const money=(n:number|null|undefined)=>n==null?'Нет данных':new Intl.NumberFormat('ru-RU',{maximumFractionDigits:2}).format(n)+' '+(club.value?.icafe_currency??'');
const updated=computed(()=>data.value?.updated_at?new Intl.DateTimeFormat('ru-RU',{timeZone:club.value?.timezone,hour:'2-digit',minute:'2-digit',second:'2-digit'}).format(new Date(data.value.updated_at)):'ещё не получены');
let timer:ReturnType<typeof setTimeout>|undefined, controller:AbortController|undefined;
async function json(url:string,signal:AbortSignal){const r=await fetch(url,{signal,headers:{Accept:'application/json'},credentials:'same-origin'});if(r.status===401||r.redirected){window.location.assign('/login');throw new Error('Сессия завершена');}if(!r.ok)throw new Error(r.status===403?'Нет доступа к этому клубу':'Не удалось получить данные');return r.json();}
async function refresh(){
 if(!club.value||loading.value||document.hidden)return;
 loading.value=true;error.value='';const c=new AbortController();controller=c;
 try{
  const snapshot=await json('/clubs/'+clubId.value+'/dashboard',c.signal);if(c.signal.aborted)return;data.value=snapshot;
  if(!shifts.value.some(s=>s.id===selected.value))selected.value=active.value[0]?.id ?? shifts.value[0]?.id ?? '';
  if(selected.value){const result=await json('/clubs/'+clubId.value+'/dashboard/shifts/'+selected.value,c.signal);if(!c.signal.aborted)detail.value=result;}
 }catch(e){if(!c.signal.aborted)error.value=e instanceof Error?e.message:'Ошибка связи';}
 finally{if(controller===c){loading.value=false;clearTimeout(timer);timer=setTimeout(refresh,15000);}}
}
function reset(){controller?.abort();clearTimeout(timer);loading.value=false;data.value=null;detail.value=null;selected.value='';expanded.value='';void refresh();}
function choose(id:string){controller?.abort();clearTimeout(timer);loading.value=false;selected.value=id;detail.value=null;void refresh();}
function visibility(){if(!document.hidden)void refresh();}
watch(clubId,reset);
onMounted(()=>{void refresh();document.addEventListener('visibilitychange',visibility);});
onUnmounted(()=>{controller?.abort();controller=undefined;clearTimeout(timer);document.removeEventListener('visibilitychange',visibility);});
const detailLabels:Record<string,string>={cash_sales:'Поступления наличными',cash_refund:'Возвраты наличными',center_expenses:'Расходы центра',pc_cash_amount:'ПК / наличные',console_cash_amount:'Консоли / наличные',shop_cash_amount:'Товары / наличные',pc_card_amount:'ПК / карты',console_card_amount:'Консоли / карты',shop_card_amount:'Товары / карты',pc_qr_amount:'ПК / QR',console_qr_amount:'Консоли / QR',shop_qr_amount:'Товары / QR'};
</script>
<template>
 <section class="live-dashboard" aria-labelledby="icafe-title">
 <div class="dash-head"><div><h2 id="icafe-title">Дашборд клуба</h2><p>Кассовые смены и компьютеры из iCafeCloud</p></div>
  <div class="dash-tools"><label v-if="clubs.length>1" class="club-select">Клуб<select v-model="clubId"><option v-for="c in clubs" :key="c.id" :value="c.id">{{c.name}}</option></select></label>
  <span class="live-state"><span class="live-indicator" :class="{warning:error||data?.stale}"></span>{{error||data?.stale?'Данные требуют обновления':'Обновлено '+updated}}</span>
  <button class="secondary" :disabled="loading||!club" @click="refresh">{{loading?'Обновляем…':'Обновить'}}</button></div></div>
 <p v-if="error||data?.error" class="dashboard-warning" role="alert">{{error||data?.error}} Последние успешные данные сохранены.</p>
 <section v-if="data?.connected===false" class="card empty"><h3>iCafeCloud не подключён</h3><p>Добавьте лицензию и API-токен в карточке клуба, чтобы видеть смены и выручку.</p><Link v-if="canManage" :href="'/clubs/'+clubId" class="button">Подключить iCafeCloud</Link></section>
 <div v-else-if="!data?.shifts" class="card empty">{{loading?'Получаем данные iCafeCloud…':'Данные ещё не получены. Нажмите «Обновить».'}}</div>
 <template v-else>
 <section class="metric-grid"><article><span>Открытые смены</span><strong>{{money(total)}}</strong><small>{{active.length}} активных смен</small></article><article><span>Онлайн</span><strong>{{data.online_pcs}} <small>/ {{data.total_pcs}} ПК</small></strong><small>Подключены к iCafeCloud</small></article><article><span>Офлайн</span><strong>{{(data.total_pcs??0)-(data.online_pcs??0)}}</strong><small>Нет подключения</small></article><article><span>За 7 дней</span><strong>{{money(shifts.reduce((n,s)=>n+s.total,0))}}</strong><small>{{data.period_start}} — {{data.period_end}}</small></article></section>
 <div class="dash-grid">
 <section class="card shift-panel"><div class="toolbar"><div><p class="eyebrow">Кассовая смена</p><h3 class="operator">{{current?.operator ?? 'Нет смен за период'}}</h3></div><label v-if="shifts.length">Оператор и смена<select :value="selected" @change="choose(($event.target as HTMLSelectElement).value)"><option v-for="s in shifts" :key="s.id" :value="s.id">{{s.operator}} · {{s.start}} · {{s.end?'Закрыта':'Открыта'}}</option></select></label></div>
 <template v-if="current"><div class="shift-summary"><div><small>Итого за смену</small><strong>{{money(current.total)}}</strong></div><div><small>Начало</small><b>{{current.start}}</b><small>Длительность: {{duration(current)}}</small></div><div><small>Закрытие</small><b>{{current.end??'Смена открыта'}}</b></div></div>
 <p v-if="detail?.error" class="dashboard-warning" role="alert">{{detail.error}}</p>
 <RevenueChart v-if="detail?.chart" :labels="detail.chart.labels" :values="detail.chart.values" :currency="club?.icafe_currency??''"/><p v-else class="empty">{{loading?'Загружаем график…':'График недоступен'}}</p>
 <p class="chart-note">Поступления по интервалам iCafeCloud (Total), не накопительный итог. Отрицательные значения — корректировки и возвраты.</p>
 <div class="payment-grid"><div><small>Наличные</small><b>{{money(current.cash)}}</b></div><div><small>Карты</small><b>{{money(current.card)}}</b></div><div><small>QR</small><b>{{money(current.qr)}}</b></div></div>
 </template></section>
 <section class="card"><p class="eyebrow">Состояние зала</p><h3>Компьютеры</h3><p class="chart-note">Онлайн и офлайн — связь с iCafeCloud, а не занятость места.</p><div class="pc-grid"><div v-for="pc in data.computers" :key="pc.name" :class="['pc',{'pc-online':pc.online}]" :title="pc.busy?'Есть игровая сессия':'Нет игровой сессии'"><b>{{pc.name}}</b><span>{{pc.online?'Онлайн':'Офлайн'}}</span></div></div></section>
 </div>
 <section class="card"><div class="card-head"><div><h3>Смены за 7 дней</h3><p>Полные суммы смен по дате открытия, включая открытые смены</p></div></div><RevenueChart :labels="weekly.labels" :values="weekly.values" :currency="club?.icafe_currency??''"/>
 <div class="table-scroll"><table><thead><tr><th>Оператор</th><th>Открытие</th><th>Закрытие</th><th>Сумма смены</th><th>Детали</th></tr></thead><tbody><template v-for="s in shifts" :key="s.id"><tr><td><b>{{s.operator}}</b><span v-if="!s.end" class="shift-open">Открыта</span></td><td>{{s.start}}<br/><small>{{duration(s)}}</small></td><td>{{s.end??'—'}}</td><td class="numeric">{{money(s.total)}}</td><td><button class="secondary" :aria-expanded="expanded===s.id" @click="expanded=expanded===s.id?'':s.id; if(expanded)choose(s.id)">{{expanded===s.id?'Скрыть':'Подробнее'}}</button></td></tr><tr v-if="expanded===s.id"><td colspan="5"><div class="payment-grid"><div><small>Наличные</small><b>{{money(s.cash)}}</b></div><div><small>Карты</small><b>{{money(s.card)}}</b></div><div><small>QR</small><b>{{money(s.qr)}}</b></div></div><div v-if="selected===s.id&&detail?.detail" class="detail-grid"><div v-for="(value,key) in detail.detail" :key="key"><small>{{detailLabels[key]??key}}</small><b>{{money(value)}}</b></div></div><p v-else>{{detail?.error??'Загрузка детализации…'}}</p></td></tr></template></tbody></table></div><p v-if="!shifts.length" class="empty">За выбранный период смен нет.</p></section>
 </template>
 </section>
</template>
<style scoped>
.live-dashboard { margin-top:8px }
.dash-head { display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap; margin:12px 0 16px }
.dash-head h2 { font-size:20px; margin:0 }
.dash-head p { margin:4px 0 0; color:var(--muted) }
.dash-tools { display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap }
.club-select { min-width:220px }
.live-state { display:flex; align-items:center; gap:8px; font-size:12.5px; color:var(--muted); min-height:38px }
.live-indicator { height:8px; width:8px; border-radius:50%; background:var(--chart-1); box-shadow:0 0 0 3px var(--success-bg) }
.live-indicator.warning { background:var(--warning); box-shadow:0 0 0 3px var(--warning-bg) }
.metric-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:20px; margin-bottom:20px }
.metric-grid article { background:var(--surface); border:1px solid var(--border); padding:20px 22px; border-radius:var(--radius-card) }
.metric-grid span,.metric-grid small { color:var(--muted); font-size:13px }
.metric-grid strong { display:block; font-size:24px; font-weight:600; font-variant-numeric:tabular-nums; letter-spacing:-.03em; margin:14px 0 6px; overflow-wrap:anywhere }
.dash-grid { display:grid; grid-template-columns:minmax(0,2fr) minmax(0,1fr); gap:20px }
.dash-grid .card { margin-bottom:20px }
.operator { font-size:18px }
.shift-summary { display:flex; gap:36px; flex-wrap:wrap; margin-top:22px; padding-bottom:18px; border-bottom:1px solid var(--border) }
.shift-summary div,.payment-grid div,.detail-grid div { display:grid; gap:6px }
.shift-summary strong { font-size:26px; font-weight:600; letter-spacing:-.03em; font-variant-numeric:tabular-nums }
.shift-summary b { font-size:14px; font-weight:500 }
.payment-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; padding:18px 0 0 }
.payment-grid b,.detail-grid b { font-variant-numeric:tabular-nums; font-weight:500 }
.chart-note { color:var(--muted); font-size:12.5px; margin:12px 0 0 }
.live-dashboard table { min-width:720px }
.live-dashboard td { white-space:nowrap }
.numeric { font-variant-numeric:tabular-nums; white-space:nowrap }
.shift-open { display:block; font-size:11.5px; color:var(--success); margin-top:4px }
.detail-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:18px; border-top:1px solid var(--border); padding:18px 0 }
.dashboard-warning { background:var(--warning-bg); color:var(--warning); border-radius:var(--radius-control); padding:11px 14px }
.pc-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(72px,1fr)); gap:8px; margin-top:16px }
/* Live status: colour crossfades when a PC goes on/offline. Tiles are not links, so no hover lift. */
.pc { padding:10px 6px; border:1px solid var(--border); border-radius:10px; display:grid; gap:4px; text-align:center; background:var(--surface-2); transition:background-color 300ms ease,border-color 300ms ease }
.pc b { font-weight:500; font-size:13px }
.pc span { font-size:11px; color:var(--subtle) }
.pc-online { border-color:color-mix(in srgb,var(--chart-1) 45%,var(--border)); background:var(--success-bg) }
.pc-online span { color:var(--success) }
@media (max-width:1100px) { .metric-grid { grid-template-columns:1fr 1fr } .dash-grid { grid-template-columns:1fr } }
@media (max-width:600px) { .metric-grid { gap:10px } .metric-grid article { padding:14px } .metric-grid strong { font-size:20px } .shift-summary { gap:18px } .payment-grid,.detail-grid { grid-template-columns:1fr 1fr } .club-select { min-width:0; width:100% } }
</style>
