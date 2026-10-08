<script setup lang="ts">
import {Link} from '@inertiajs/vue3';
defineProps<{items:{id:number;description?:string;status:string;priority?:string;category?:string;created_at?:string;closed_at?:string|null;club?:{name:string};equipment?:{name:string}|null;assignee?:{name:string}|null}[]}>();
const statuses:Record<string,string>={new:'Новая',accepted:'Принята',working:'В работе',approval:'Ожидает согласования',parts:'Ожидает запчастей',resolved:'Решена',closed:'Закрыта'};
const priorities:Record<string,string>={low:'Низкий',normal:'Обычный',high:'Высокий',critical:'Критический'};
function date(value?:string|null){if(!value)return 'Не указана';const d=new Date(value);return Number.isNaN(d.getTime())?'Не указана':d.toLocaleString('ru-RU',{timeZone:'Asia/Tashkent',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});}
</script>
<template>
  <div class="ticket-list">
    <article v-for="ticket in items" :key="ticket.id" class="ticket-card" :data-priority="ticket.priority">
      <div class="ticket-top"><span class="ticket-number">Заявка #{{ticket.id}}</span><span class="badge" :data-status="ticket.status">{{statuses[ticket.status] || ticket.status}}</span><span class="priority">{{priorities[ticket.priority || 'normal'] || ticket.priority}} приоритет</span></div>
      <h3><Link :href="'/tickets/'+ticket.id">{{ticket.description}}</Link></h3>
      <div class="ticket-meta"><div><span>Клуб</span><strong>{{ticket.club?.name || 'Не указан'}}</strong></div><div><span>Оборудование</span><strong>{{ticket.equipment?.name || 'Общая заявка'}}</strong></div><div><span>Исполнитель</span><strong>{{ticket.assignee?.name || 'Не назначен'}}</strong></div><div><span>Категория</span><strong>{{ticket.category || 'Не указана'}}</strong></div></div>
      <footer><span>Создана {{date(ticket.created_at)}}</span><span v-if="ticket.closed_at">Закрыта {{date(ticket.closed_at)}}</span><Link :href="'/tickets/'+ticket.id" :aria-label="'Открыть заявку #'+ticket.id">Открыть заявку →</Link></footer>
    </article>
    <p class="timezone">Время указано по Ташкенту (UTC+5)</p>
  </div>
</template>
<style scoped>
.ticket-list{padding:20px;display:grid;gap:14px}.ticket-card{min-width:0;border:1px solid var(--border);border-left:3px solid var(--border);border-radius:10px;padding:20px;background:var(--surface)}
.ticket-card[data-priority=critical]{border-left-color:var(--error)}.ticket-card[data-priority=high]{border-left-color:var(--accent-text)}
.ticket-top{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.ticket-number,.priority{font-size:12px;color:var(--muted)}.priority{margin-left:auto}.ticket-card[data-priority=critical] .priority{color:var(--error)}
h3{font-size:18px;line-height:1.5;margin:14px 0 20px;overflow-wrap:anywhere}h3 a{color:var(--text);text-decoration:none}h3 a:hover{text-decoration:underline}
.ticket-meta{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.ticket-meta div{display:grid;gap:5px;overflow-wrap:anywhere}.ticket-meta span{font-size:12px;color:var(--muted)}.ticket-meta strong{font-size:14px;font-weight:500}
footer{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:20px;padding-top:12px;border-top:1px solid var(--border);font-size:12px;color:var(--muted)}footer a{margin-left:auto;padding:10px 0;color:var(--accent-text);font-weight:600}.timezone{font-size:12px;color:var(--muted);margin:0}a:focus-visible{outline:2px solid var(--accent-text);outline-offset:4px}
@media(max-width:700px){.ticket-list{padding:12px}.ticket-card{padding:16px}.ticket-meta{grid-template-columns:repeat(2,minmax(0,1fr))}.priority{margin-left:0}footer{align-items:flex-start;flex-direction:column}footer a{margin-left:0}h3{font-size:16px}}
</style>
