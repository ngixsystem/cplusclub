<script setup lang="ts">
import {Head,Link,router} from '@inertiajs/vue3';
import {computed,ref,watch} from 'vue';
import Shell from '../Layouts/Shell.vue';
type Filters={view?:string;club_id?:number|string;equipment_id?:number|string;assignee_id?:number|string;from?:string;to?:string};
type Row={id:number;ticket_id:number;club_name:string;equipment_name:string|null;assignee_name:string|null;minutes:number;cost_minor?:number;currency?:string;actions?:string;consumables?:string;closed_at?:string|null;created_at?:string;description?:string;cause?:string;solution?:string;work_result?:string;verification_result?:string;costs?:{currency:string;cost_minor:number}[]};
const props=defineProps<{rows:{data:Row[];total:number;next_page_url:string|null;prev_page_url:string|null};totals:{currency:string;minutes:number;cost_minor:number}[];filters:Filters;clubs:{id:number;name:string}[];equipment:{id:number;name:string;club_id:number}[];assignees:{id:number;name:string}[];errors?:Record<string,string>}>();
const fields=(f:Filters):Filters=>({...f,club_id:f.club_id??'',equipment_id:f.equipment_id??'',assignee_id:f.assignee_id??'',from:f.from??'',to:f.to??''});
const form=ref<Filters>(fields(props.filters));watch(()=>props.filters,v=>form.value=fields(v));
const closed=computed(()=>props.filters.view==='closed');
const machines=computed(()=>props.equipment.filter(e=>!form.value.club_id||e.club_id===Number(form.value.club_id)));
const query=(f:Filters)=>Object.fromEntries(Object.entries(f).filter(([,v])=>v!==''&&v!==null&&v!==undefined));
const exportUrl=computed(()=>'/reports/export?'+new URLSearchParams(Object.entries(query(props.filters)).map(([k,v])=>[k,String(v)])).toString());
function apply(){router.get('/reports',query(form.value),{preserveState:true,preserveScroll:true});}
function tab(view:string){router.get('/reports',query({...props.filters,view}),{preserveScroll:true});}
const date=(s?:string|null)=>s?new Date(s).toLocaleString('ru-RU',{timeZone:'Asia/Tashkent'}):'Не указана';
const money=(n:number)=>new Intl.NumberFormat('ru-RU',{maximumFractionDigits:2}).format(Number(n)/100);
</script>
<template><Head title="Отчёты"/><Shell>
 <div class="heading"><div><h1>Отчёты и трудозатраты</h1><p>Выполненные работы и фактические расходы клуба</p></div><a :href="exportUrl" class="badge">Скачать CSV</a></div>
 <nav class="toolbar report-tabs" aria-label="Вид отчёта"><button :class="closed?'':'secondary'" :aria-pressed="closed" @click="tab('closed')">Закрытые заявки</button><button :class="!closed?'':'secondary'" :aria-pressed="!closed" @click="tab('work')">Трудозатраты и расходы</button></nav>
 <form class="panel form-grid" @submit.prevent="apply">
  <label>Клуб отчёта<select v-model="form.club_id" @change="form.equipment_id=''"><option value="">Все доступные клубы</option><option v-for="c in clubs" :key="c.id" :value="c.id">{{c.name}}</option></select></label>
  <label>ПК / оборудование<select v-model="form.equipment_id"><option value="">Всё оборудование</option><option v-for="e in machines" :key="e.id" :value="e.id">{{e.name}} · #{{e.id}}</option></select></label>
  <label>Исполнитель отчёта<select v-model="form.assignee_id"><option value="">Все исполнители</option><option v-for="u in assignees" :key="u.id" :value="u.id">{{u.name}}</option></select></label>
  <label>С даты<input v-model="form.from" type="date"/></label><label>По дату включительно<input v-model="form.to" type="date"/></label>
  <div class="toolbar"><button>Применить фильтры</button><Link :href="'/reports?view='+filters.view">Сбросить</Link></div>
  <p v-for="e in errors" :key="e" class="error" role="alert">{{e}}</p>
 </form>
 <p>{{closed?'Период по дате закрытия. Расходы включают все записи выбранных заявок.':'Период по дате записи трудозатрат, независимо от статуса заявки.'}} Часовой пояс: Asia/Tashkent. CSV выгружает все страницы с применёнными фильтрами.</p>
 <section class="stats"><article><span>{{closed?'Закрытых заявок':'Записей трудозатрат'}}</span><strong>{{rows.total}}</strong></article><article v-for="t in totals" :key="t.currency"><span>{{t.currency}} · {{(Number(t.minutes)/60).toFixed(1)}} ч</span><strong>{{money(t.cost_minor)}} {{t.currency}}</strong></article></section>
 <section class="panel"><p v-if="!rows.data.length">По выбранным условиям записей нет.</p>
  <p v-if="rows.data.length" class="scroll-hint">На узком экране таблицу можно прокрутить вправо.</p>
  <div v-if="rows.data.length" class="report-table" tabindex="0" aria-label="Таблица отчёта"><table><thead><tr><th>Клуб / заявка</th><th>ПК / исполнитель</th><th>{{closed?'Результат':'Работы'}}</th><th>{{closed?'Закрыта':'Запись'}}</th><th>Минуты / расходы</th></tr></thead><tbody>
   <tr v-for="r in rows.data" :key="r.id"><td><Link :href="'/tickets/'+r.ticket_id">{{r.club_name}} / #{{r.ticket_id}}</Link><p v-if="closed">{{r.description}}</p></td><td>{{r.equipment_name||'Без привязки к ПК'}}<p>{{r.assignee_name||'Не назначен'}}</p></td>
    <td v-if="closed"><p><strong>Причина:</strong> {{r.cause}}</p><p><strong>Решение:</strong> {{r.solution}}</p><details><summary>Работы и проверка</summary><p>{{r.work_result}}</p><p>{{r.verification_result}}</p></details></td><td v-else>{{r.actions}}<p>{{r.consumables}}</p></td>
    <td>{{date(closed?r.closed_at:r.created_at)}}</td><td>{{r.minutes}} мин<template v-if="closed"><p v-for="c in r.costs" :key="c.currency">{{money(c.cost_minor)}} {{c.currency}}</p><p v-if="!r.costs?.length">Расходы не внесены</p></template><p v-else>{{money(r.cost_minor??0)}} {{r.currency}}</p></td>
   </tr>
  </tbody></table></div>
  <div class="pagination"><Link v-if="rows.prev_page_url" :href="rows.prev_page_url">Назад</Link><Link v-if="rows.next_page_url" :href="rows.next_page_url">Далее</Link></div>
 </section>
</Shell></template>
<style scoped>.report-tabs{display:flex;gap:12px;margin:20px 0;flex-wrap:wrap}.report-table{overflow-x:auto}table{min-width:800px}td{vertical-align:top;max-width:340px;overflow-wrap:anywhere}td p{white-space:pre-wrap}summary{cursor:pointer}.form-grid .toolbar{gap:12px;flex-wrap:wrap}.scroll-hint{display:none;color:var(--muted)}@media(max-width:800px){.scroll-hint{display:block}}</style>
