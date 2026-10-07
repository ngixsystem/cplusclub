<script setup lang="ts">
import {computed, watch} from 'vue';
import {useForm} from '@inertiajs/vue3';
type Proposal={id:number;description:string;amount_minor:number;currency:string;expected_duration:string;status:string;author_name:string;decision_name:string|null;decision_comment:string|null;created_at:string;decided_at:string|null};
const props=defineProps<{ticketId:number;version:number;status:string;canEdit:boolean;canDecide:boolean;proposals:Proposal[]}>();
const quote=useForm({version:props.version,description:'',amount_minor:0,currency:'UZS',expected_duration:''});
const decision=useForm({version:props.version,proposal_id:0,decision:'approve',comment:''});
const completion=useForm({version:props.version,decision:'confirm',comment:''});
const latest=computed(()=>props.proposals[0]);
const labels:Record<string,string>={pending:'Ожидает решения',approved:'Согласовано',rejected:'Отклонено',superseded:'Заменено новым предложением'};
watch(()=>props.version,v=>{quote.version=v;decision.version=v;completion.version=v;});
const date=(value:string)=>new Date(value).toLocaleString('ru-RU',{timeZone:'Asia/Tashkent'});
function decide(action:string){decision.proposal_id=latest.value?.id??0;decision.decision=action;decision.post(`/tickets/${props.ticketId}/decision`,{preserveScroll:true,onSuccess:()=>decision.reset('comment')});}
function complete(action:string){completion.decision=action;completion.post(`/tickets/${props.ticketId}/completion`,{preserveScroll:true,onSuccess:()=>completion.reset('comment')});}
</script>
<template>
 <section v-if="proposals.length||status==='approval'||(canEdit&&status==='working')" class="panel">
  <h2>Согласование работ и стоимости</h2>
  <p v-if="status==='approval'&&!latest">Для этой заявки ещё нет предложения. Специалист должен указать работы, стоимость и срок.</p>
  <article v-for="p in proposals" :key="p.id" class="proposal">
   <div class="toolbar"><strong>Предложение #{{p.id}} · {{labels[p.status]}}</strong><b>{{new Intl.NumberFormat('ru-RU').format(p.amount_minor/100)}} {{p.currency}}</b></div>
   <p class="prewrap">{{p.description}}</p><p>Срок: {{p.expected_duration}}</p>
   <small>{{p.author_name}} · {{date(p.created_at)}} · Asia/Tashkent</small>
   <p v-if="p.decided_at">Решение: {{p.decision_name}} · {{date(p.decided_at)}}</p><p v-if="p.decision_comment" class="prewrap">{{p.decision_comment}}</p>
  </article>
  <form v-if="canDecide&&status==='approval'&&latest?.status==='pending'" @submit.prevent="decide('approve')">
   <label>Комментарий к согласованию<textarea v-model="decision.comment" maxlength="5000" placeholder="При отказе укажите причину"/></label>
   <p v-for="e in decision.errors" :key="e" class="error" role="alert">{{e}}</p>
   <div class="toolbar"><button :disabled="decision.processing">Согласовать работы и стоимость</button><button type="button" class="secondary" :disabled="decision.processing" @click="decide('reject')">Отклонить предложение</button></div>
  </form>
  <details v-if="canEdit&&['working','approval'].includes(status)">
   <summary>{{latest?'Новое предложение / изменить стоимость':'Предложить работы на согласование'}}</summary>
   <p>Изменение условий создаёт новую запись и требует повторного согласования. Предложение не добавляет расход в отчёт автоматически.</p>
   <form class="form-grid" @submit.prevent="quote.post(`/tickets/${ticketId}/proposal`,{preserveScroll:true,onSuccess:()=>quote.reset('description','amount_minor','expected_duration')})">
    <label>Предлагаемые работы<textarea v-model="quote.description" required maxlength="10000"/></label>
    <label>Стоимость в минимальных единицах (100 = 1)<input v-model="quote.amount_minor" type="number" min="0" max="100000000000" step="1" required/></label>
    <label>Валюта предложения<input v-model="quote.currency" pattern="[A-Z]{3}" maxlength="3" required/></label>
    <label>Ожидаемый срок<input v-model="quote.expected_duration" maxlength="255" placeholder="Например, 2 рабочих дня" required/></label>
    <p v-for="e in quote.errors" :key="e" class="error" role="alert">{{e}}</p><button :disabled="quote.processing">Отправить на согласование</button>
   </form>
  </details>
 </section>
 <section v-if="status==='resolved'" class="panel">
  <h2>Подтверждение результата</h2>
  <p>Работы отмечены как решённые. Владелец клуба может принять результат или указать необходимые доработки.</p>
  <form v-if="canDecide" @submit.prevent="complete('confirm')">
   <label>Комментарий к результату<textarea v-model="completion.comment" maxlength="5000" placeholder="Для возврата укажите необходимые доработки"/></label>
   <p v-for="e in completion.errors" :key="e" class="error" role="alert">{{e}}</p>
   <div class="toolbar"><button :disabled="completion.processing">Принять результат и закрыть</button><button type="button" class="secondary" :disabled="completion.processing" @click="complete('rework')">Вернуть на доработку</button></div>
  </form>
 </section>
</template>
<style scoped>
.proposal{border-bottom:1px solid var(--border);padding:16px 0;margin-bottom:16px;overflow-wrap:anywhere}summary{cursor:pointer;padding:14px 0;font-weight:600}textarea{width:100%}.toolbar{flex-wrap:wrap;gap:12px;margin-top:12px}small{color:var(--muted)}
</style>
