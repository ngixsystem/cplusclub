<script setup lang="ts">
import {computed,onMounted,onUnmounted,ref,watch} from 'vue';
import {Link,useForm} from '@inertiajs/vue3';
type Pc={id:number;name:string;zone:string|null;online:boolean|null;cpu_temp:number|null;gpu_temp:number|null;observed_at:string|null;severity:string;imported:boolean};
type Snapshot={pcs:Pc[];alerts?:{id:number;equipment_id:number;metric:string;opened_at:string}[];error:string|null;updated_at:string;connection_updated_at:string|null;template:{warning:number;critical:number;hold_seconds:number}};
const props=defineProps<{clubs:{id:number;name:string;icafe_license?:number|null}[];canManage:boolean}>();
const club=ref(props.clubs.find(c=>c.icafe_license)?.id??props.clubs[0]?.id??0),data=ref<Snapshot|null>(null),error=ref(''),busy=ref(false),search=ref(''),filter=ref('all');
const importing=useForm({import:''}),template=useForm({warning:50,critical:79,hold_seconds:0});
const labels:Record<string,string>={normal:'Норма',warning:'Повышенная',critical:'Перегрев',unknown:'Нет данных',offline:'Офлайн'};
const shown=computed(()=>data.value?.pcs.filter(p=>(filter.value==='all'||p.severity===filter.value)&&(p.name+' '+(p.zone??'')).toLowerCase().includes(search.value.toLowerCase()))??[]);
const count=(kind:string)=>data.value?.pcs.filter(p=>p.severity===kind).length??0;
const degrees=(n:number|null)=>n===null?'Нет данных':new Intl.NumberFormat('ru-RU',{maximumFractionDigits:1}).format(n)+' °C';
const updated=computed(()=>data.value?.connection_updated_at?new Date(data.value.connection_updated_at).toLocaleTimeString('ru-RU'):'нет данных');
let timer:ReturnType<typeof setTimeout>|undefined,controller:AbortController|undefined,initialized=false;
async function refresh(){if(!club.value||busy.value||document.hidden)return;const c=new AbortController();controller=c;busy.value=true;error.value='';const timeout=setTimeout(()=>c.abort('timeout'),45000);
 try{const r=await fetch('/monitoring/clubs/'+club.value,{signal:c.signal,headers:{Accept:'application/json'}});if(r.status===401||r.redirected){window.location.assign('/login');return;}if(!r.ok)throw new Error('Не удалось обновить мониторинг');const result=await r.json();if(c.signal.aborted)return;data.value=result;if(!initialized){Object.assign(template,result.template);initialized=true;}}
 catch(e){if(!c.signal.aborted||c.signal.reason==='timeout')error.value='Нет связи с сервером. Показания на экране могут быть устаревшими.';}
 finally{clearTimeout(timeout);if(controller===c){busy.value=false;clearTimeout(timer);timer=setTimeout(refresh,15000);}}
}
watch(club,()=>{controller?.abort();clearTimeout(timer);busy.value=false;data.value=null;initialized=false;filter.value='all';void refresh();});
function visible(){if(!document.hidden)void refresh();}
onMounted(()=>{void refresh();document.addEventListener('visibilitychange',visible);});onUnmounted(()=>{controller?.abort();controller=undefined;clearTimeout(timer);document.removeEventListener('visibilitychange',visible);});
</script>
<template>
 <section class="panel pc-board">
  <div class="toolbar"><div><h2>Температуры компьютеров</h2><p class="monitor-note" style="margin:4px 0 0">Живое состояние клуба</p></div><label>Клуб мониторинга<select v-model="club"><option v-for="c in clubs" :key="c.id" :value="c.id">{{c.name}}</option></select></label></div>
  <p class="monitor-note">CPU / GPU · автообновление каждые 15 секунд · онлайн-статусы: {{updated}}. Температуры поступают от Windows-агента, не из iCafeCloud.</p>
  <div class="monitor-tools"><button v-if="canManage" :disabled="!club||importing.processing" @click="importing.post('/monitoring/clubs/'+club+'/import',{preserveScroll:true,onSuccess:()=>refresh()})">{{importing.processing?'Импортируем…':'Импортировать ПК из iCafeCloud'}}</button><button class="secondary" :disabled="busy||!club" @click="refresh">{{busy?'Обновляем…':'Обновить'}}</button></div>
  <p v-for="e in importing.errors" :key="e" class="error" role="alert">{{e}}</p><p v-if="error||data?.error" class="monitor-warning" role="alert">{{error||data?.error}}</p>
  <div class="pc-legend"><span class="normal">Норма &lt; {{data?.template.warning??50}} °C</span><span class="warning">{{data?.template.warning??50}}–{{data?.template.critical??79}} °C</span><span class="critical">Перегрев &gt; {{data?.template.critical??79}} °C</span><span>Серый: офлайн / нет датчиков</span></div>
  <p class="monitor-note">Цвет по худшему показателю CPU/GPU. Если один датчик отсутствует, безопасный статус не подтверждается. Показания старше 3 минут скрываются.</p>
  <div v-if="data" class="monitor-summary"><span>Всего <b>{{data.pcs.length}}</b></span><span>Онлайн {{data.pcs.filter(p=>p.online===true).length}}</span><span>Офлайн {{count('offline')}}</span><span>Перегрев {{count('critical')}}</span><span>Повышенная {{count('warning')}}</span><span>Нет данных {{count('unknown')}}</span></div>
  <details v-if="data?.alerts?.length" class="live-alerts"><summary>Активные тревоги: {{data.alerts.length}}</summary><p v-for="a in data.alerts" :key="a.id"><Link :href="'/equipment/'+a.equipment_id">ПК #{{a.equipment_id}}</Link> · {{a.metric}} · {{a.opened_at}}</p></details><div class="monitor-filters"><label>Поиск ПК / зоны<input v-model="search" placeholder="Например, 001"/></label><label>Показать<select v-model="filter"><option value="all">Все компьютеры</option><option v-for="(label,key) in labels" :key="key" :value="key">{{label}}</option></select></label></div>
  <div v-if="!shown.length" class="empty">{{busy?'Получаем данные…':data?.pcs.length?'По фильтру компьютеров нет.':'ПК ещё не импортированы. Администратор может импортировать список из iCafeCloud.'}}</div>
  <div class="pc-tiles"><Link v-for="pc in shown" :key="pc.id" :href="'/equipment/'+pc.id" :class="['pc-tile',error?'unknown':pc.severity]" :aria-label="pc.name+': '+(error?'Нет свежих данных':labels[pc.severity])">
   <div class="pc-top"><strong>{{pc.name}}</strong><span>{{pc.online===true?'Онлайн':pc.online===false?'Офлайн':'Связь неизвестна'}}</span></div>
   <svg class="pc-symbol" viewBox="0 0 160 108" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="12" y="14" width="102" height="66" rx="6" fill="currentColor" fill-opacity=".06"/><path d="M12 65h102M54 81v12m18-12v12M42 94h42"/><path d="M27 44h17l6-13 11 25 9-17 6 5h22" opacity=".8"/><rect x="124" y="9" width="25" height="85" rx="5" fill="currentColor" fill-opacity=".06"/><circle cx="136.5" cy="36" r="7"/><circle cx="136.5" cy="60" r="7"/><path d="M132 81h9"/><circle cx="136.5" cy="19" r="1" fill="currentColor"/></svg>
   <div class="temp-readings"><div><small>CPU</small><b>{{error?'Нет данных':degrees(pc.cpu_temp)}}</b></div><div><small>GPU</small><b>{{error?'Нет данных':degrees(pc.gpu_temp)}}</b></div></div>
   <div class="pc-bottom"><span>{{error?'Нет свежих данных':labels[pc.severity]}}</span><small>#{{pc.id}} · {{pc.zone||'Без зоны'}}</small></div>
  </Link></div>
  <details v-if="canManage&&club" class="template-settings"><summary>Единый шаблон триггеров CPU/GPU для всех ПК клуба</summary><form class="form-grid" @submit.prevent="template.post('/monitoring/clubs/'+club+'/template',{preserveScroll:true,onSuccess:()=>{initialized=false;void refresh();}})"><label>Жёлтый от, °C<input v-model="template.warning" type="number" step="0.01" min="1" max="149" required/></label><label>Красный выше, °C<input v-model="template.critical" type="number" step="0.01" min="1" max="150" required/></label><label>Выдержка тревоги, секунд<input v-model="template.hold_seconds" type="number" min="0" max="3600" required/></label><button :disabled="template.processing">Сохранить шаблон</button><p v-for="e in template.errors" :key="e" class="error">{{e}}</p></form><p class="monitor-note">Шаблон применяется к CPU и GPU всех текущих и будущих ПК клуба. Цвет меняется сразу; выдержка влияет только на создание тревоги. Восстановление — ниже жёлтого порога.</p></details>
 </section>
</template>
<style scoped>
.pc-board{--pc-green:var(--success);--pc-yellow:var(--warning);--pc-red:var(--error);--pc-gray:var(--subtle)}
.monitor-tools,.monitor-summary,.pc-legend{display:flex;gap:10px;flex-wrap:wrap;margin:16px 0}
.monitor-note{font-size:12.5px;color:var(--muted);max-width:900px}
.pc-legend span{display:inline-flex;align-items:center;gap:7px;font-size:12px;padding:4px 10px;border-radius:999px;background:var(--surface-2);border:1px solid var(--border);color:var(--muted)}
.pc-legend span:before{content:"";width:8px;height:8px;border-radius:50%;background:var(--tone,var(--pc-gray))}
.normal{--tone:var(--pc-green)}.warning{--tone:var(--pc-yellow)}.critical{--tone:var(--pc-red)}.unknown,.offline{--tone:var(--pc-gray)}
.monitor-summary{border-block:1px solid var(--border);padding:12px 0;font-variant-numeric:tabular-nums;color:var(--muted)}
.monitor-summary b{color:var(--text)}
.monitor-filters{display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-bottom:20px}
.pc-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
.pc-tile{position:relative;display:block;color:var(--text);background:var(--surface-2);border:1px solid var(--border);border-radius:14px;padding:14px 16px;transition:border-color .2s,background-color .2s,transform .25s cubic-bezier(.16,1,.3,1);animation:rise-in .5s cubic-bezier(.16,1,.3,1) both}
.pc-tile:hover{transform:translateY(-2px)}
.pc-tile:nth-child(2){animation-delay:40ms}.pc-tile:nth-child(3){animation-delay:80ms}.pc-tile:nth-child(4){animation-delay:120ms}.pc-tile:nth-child(5){animation-delay:160ms}.pc-tile:nth-child(6){animation-delay:200ms}.pc-tile:nth-child(7){animation-delay:240ms}.pc-tile:nth-child(8){animation-delay:280ms}.pc-tile:nth-child(9){animation-delay:320ms}.pc-tile:nth-child(10){animation-delay:360ms}.pc-tile:nth-child(11){animation-delay:400ms}.pc-tile:nth-child(12){animation-delay:440ms}.pc-tile:nth-child(n+13){animation-delay:480ms}
.pc-tile:hover{border-color:color-mix(in srgb,var(--tone) 55%,var(--border));background:var(--hover)}
.pc-tile.critical{border-color:color-mix(in srgb,var(--pc-red) 45%,var(--border))}
.pc-top{display:flex;gap:8px;justify-content:space-between;align-items:center}
.pc-top strong{font-size:16px;font-weight:600;overflow-wrap:anywhere}
.pc-top span{font-size:11.5px;color:var(--muted);text-align:right}
.pc-symbol{width:100%;height:72px;margin:10px 0;color:var(--tone);opacity:.9}
.temp-readings{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.temp-readings div{display:grid;gap:3px}
.temp-readings small{font-size:11px;color:var(--muted)}
.temp-readings b{font-size:16px;font-weight:600;font-variant-numeric:tabular-nums;transition:color .4s}
.pc-tile.warning .temp-readings b,.pc-tile.critical .temp-readings b{color:var(--tone)}
.pc-bottom{border-top:1px solid var(--border);margin-top:12px;padding-top:10px;display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:12px}
.pc-bottom span{display:inline-flex;align-items:center;gap:6px;color:var(--tone);font-weight:500}
.pc-bottom span:before{content:"";width:7px;height:7px;border-radius:50%;background:var(--tone)}
.pc-bottom small{color:var(--muted)}
.template-settings{border-top:1px solid var(--border);padding-top:18px;margin-top:24px}
.template-settings summary{cursor:pointer;font-weight:500;padding:8px 0}
.template-settings form{margin-top:16px}
.monitor-warning{background:var(--warning-bg);color:var(--warning);border-radius:var(--radius-control);padding:11px 14px}
.live-alerts summary{cursor:pointer;font-weight:500;padding:8px 0}
@media(max-width:600px){.monitor-filters{grid-template-columns:1fr}.pc-tiles{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.pc-tile{padding:10px}.pc-top{flex-direction:column;align-items:flex-start}.pc-symbol{height:56px}.temp-readings{grid-template-columns:1fr}.pc-bottom{flex-direction:column;align-items:flex-start}}
</style>
