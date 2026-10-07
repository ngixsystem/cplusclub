<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import Shell from '../Layouts/Shell.vue';
import { watch } from 'vue';
import WorkLog from '../Components/WorkLog.vue';
import AttachmentUpload from '../Components/AttachmentUpload.vue';
import AttachmentGallery from '../Components/AttachmentGallery.vue';
import TicketApproval from '../Components/TicketApproval.vue';
defineOptions({inheritAttrs:false});
const props = defineProps<{ticket:{id:number;version:number;description:string;status:string;equipment:{id:number;name:string}|null;club:{name:string};assignee_id:number|null;work_result:string|null;verification_result:string|null;cause:string|null;solution:string|null;events:{id:number;from_status:string;to_status:string;comment:string|null;created_at:string}[]};assignable:{id:number;name:string}[];transitions:string[];canEdit:boolean;canDecide:boolean;proposals:{id:number;description:string;amount_minor:number;currency:string;expected_duration:string;status:string;author_name:string;decision_name:string|null;decision_comment:string|null;created_at:string;decided_at:string|null}[];attachments:{id:number;name:string;mime:string}[];workLogs:{id:number;minutes:number;actions:string}[]}>();
const page=usePage<{auth:{user:{role:string}}}>();
const labels:Record<string,string>={new:'Новая',accepted:'Принята',working:'В работе',approval:'Ожидает согласования',parts:'Ожидает запчастей',resolved:'Решена',closed:'Закрыта'};
const form=useForm({status:props.transitions[0],version:props.ticket.version,work_result:props.ticket.work_result??'',verification_result:props.ticket.verification_result??'',cause:props.ticket.cause??'',solution:props.ticket.solution??'',comment:''});
const assignment=useForm({assignee_id:props.ticket.assignee_id??'',version:props.ticket.version});
watch(()=>props.ticket.version, value=>{form.version=value; assignment.version=value; form.status=props.transitions[0];});
</script>
<template><Head :title="'Заявка #'+ticket.id" /><Shell>
  <div class="heading"><div><p class="eyebrow">{{ ticket.club.name }}</p><h1>Заявка #{{ ticket.id }}</h1></div><span class="badge">{{ labels[ticket.status] }}</span></div>
  <section class="panel"><p v-if="ticket.equipment">ПК: <Link :href="'/equipment/'+ticket.equipment.id">{{ticket.equipment.name}}</Link></p><h2>Описание</h2><p class="prewrap">{{ ticket.description }}</p></section>
  <form v-if="canEdit && ['owner','lead'].includes(page.props.auth.user.role)" class="panel toolbar" @submit.prevent="assignment.post('/tickets/'+ticket.id+'/assign')"><label>Исполнитель<select v-model="assignment.assignee_id" required><option v-for="u in assignable" :key="u.id" :value="u.id">{{ u.name }}</option></select></label><button :disabled="assignment.processing">Назначить</button><p class="error" v-for="error in assignment.errors" :key="error">{{ error }}</p></form>
  <form v-if="canEdit && transitions.length" class="panel form-grid" @submit.prevent="form.post('/tickets/'+ticket.id+'/transition')">
    <label>Выполненные работы<textarea v-model="form.work_result" /></label><label>Результат проверки<textarea v-model="form.verification_result" /></label><label>Причина<textarea v-model="form.cause" /></label><label>Решение<textarea v-model="form.solution" /></label><label>Комментарий<textarea v-model="form.comment" /></label><label>Следующий статус<select v-model="form.status"><option v-for="s in transitions" :key="s" :value="s">{{ labels[s] }}</option></select></label>
    <p class="error" role="alert" v-for="(error,key) in form.errors" :key="key">{{ error }}</p><button :disabled="form.processing">{{ form.processing?'Сохраняем…':'Сохранить переход' }}</button>
  </form>
  <section v-else class="panel"><h2>Результат</h2><p class="prewrap">{{ ticket.work_result || 'Работа ещё не завершена.' }}</p><p v-if="ticket.verification_result" class="prewrap"><strong>Проверка:</strong> {{ ticket.verification_result }}</p><p v-if="ticket.cause" class="prewrap"><strong>Причина:</strong> {{ ticket.cause }}</p><p v-if="ticket.solution" class="prewrap"><strong>Решение:</strong> {{ ticket.solution }}</p></section>
  <TicketApproval :ticket-id="ticket.id" :version="ticket.version" :status="ticket.status" :can-edit="canEdit" :can-decide="canDecide" :proposals="proposals"/><WorkLog v-if="canEdit" :ticket-id="ticket.id"/><section class="panel"><h2>Вложения</h2><AttachmentUpload type="ticket" :id="ticket.id"/><AttachmentGallery :attachments="attachments"/><p v-for="w in workLogs" :key="w.id">{{w.minutes}} мин · {{w.actions}}</p></section><section class="panel"><h2>История</h2><p v-if="!ticket.events.length">Изменений пока нет.</p><article v-for="event in ticket.events" :key="event.id" class="event"><strong>{{ labels[event.from_status] }} → {{ labels[event.to_status] }}</strong><p>{{ event.comment }}</p><small>{{ new Date(event.created_at).toLocaleString('ru-RU', {timeZone:'Asia/Tashkent'}) }} · Asia/Tashkent</small></article></section>
</Shell></template>
