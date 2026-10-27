<script setup lang="ts">
import { ref } from 'vue';import { Head, Link, useForm } from '@inertiajs/vue3';import Shell from '../Layouts/Shell.vue';
const props=defineProps<{clubs:{id:number;name:string}[];channels:{id:number;club_id:number;name:string;chat_id:string;enabled:boolean;kinds:string[]}[];kinds:string[];transport:string;deliveries:{data:{id:number;status:string;attempts:number;sent_at:string|null}[];next_page_url:string|null;prev_page_url:string|null};attempts:{id:number;delivery_id:number;outcome:string;http_status:number|null}[];botStatus:Record<number,{last_error:string|null;polled_at:string|null}|null>}>();
const botText=(id:number)=>{const b=props.botStatus?.[id];if(!b||!b.polled_at)return 'бот ещё не опрошен';return ({webhook:'у бота настроен webhook — кнопки и заявки не работают',token:'Telegram не принимает токен',rate:'Telegram ограничил запросы',unavailable:'Telegram не отвечает'} as Record<string,string>)[b.last_error??'']??'бот на связи';};
const form=useForm({club_id:'',name:'',bot_token:'',chat_id:'',kinds:['test']});
// Human names for subscriptions; unknown kinds fall back to their code.
const labels:Record<string,string>={ticket_intake:'Заявки из группы (/ticket), статусы и оценка',ticket_rated:'Оценки клиентов по заявкам',monthly_report:'Отчёт за месяц (1-го числа)',hardware_changed:'Изменилось железо ПК',latency_problem:'Пинг до игровых серверов вырос',latency_recovered:'Пинг восстановился',link_down:'Линк упал: просьба проверить кабель',link_restored:'Линк восстановлен',monitor_problem:'Тревоги мониторинга (все)',monitor_recovered:'Восстановление после тревог',critical_ticket:'Критические заявки',inspection_due:'Подошёл срок осмотра',published_build:'Вышла новая сборка игры',local_build:'Сборка в клубе обновлена',faceit_file:'Файл FACEIT',source_unavailable:'Источник данных недоступен',test:'Тестовое сообщение'};
const presets=[{name:'Группа клуба: кабель и заявки',kinds:['link_down','link_restored','ticket_intake','monthly_report','test']},{name:'Команда C+CLub: все тревоги',kinds:['monitor_problem','monitor_recovered','critical_ticket','ticket_rated','hardware_changed','latency_problem','latency_recovered','link_down','link_restored','test']}];
function preset(p:{name:string;kinds:string[]}){form.kinds=[...p.kinds];if(!form.name)form.name=p.name;}
const clubName=(id:number)=>props.clubs.find(c=>c.id===id)?.name??'Клуб #'+id;
// Find the group: Telegram returns the chats the bot was recently added to.
const finding=ref(false),findError=ref(''),found=ref<{id:string;title:string;type:string}[]|null>(null),botName=ref('');
async function findChats(){
  findError.value='';found.value=null;
  if(!/^[0-9]+:[A-Za-z0-9_-]+$/.test(form.bot_token)){findError.value='Сначала вставьте токен бота от @BotFather.';return;}
  finding.value=true;
  try{
    const xsrf=decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/)||[])[1]??'');
    const r=await fetch('/integrations/telegram/chats',{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json','X-XSRF-TOKEN':xsrf},body:JSON.stringify({bot_token:form.bot_token})});
    const j=await r.json().catch(()=>({}));
    if(!r.ok||!j.ok){findError.value=j.error??j.message??'Не удалось связаться с Telegram.';return;}
    botName.value=j.bot??'';found.value=j.chats;
    if(j.chats.length===1)pick(j.chats[0]);
  }catch{findError.value='Не удалось связаться с сервером.';}
  finally{finding.value=false;}
}
function pick(c:{id:string;title:string}){form.chat_id=c.id;if(!form.name||presets.some(p=>p.name===form.name))form.name=c.title;}
</script>
<template><Head title="Telegram и интеграции"/><Shell><div class="page-header"><div><h1>Telegram и интеграции</h1><p>Каналы уведомлений, журнал доставки и состояние внешних источников.</p></div><span class="badge" :data-tone="transport==='fake'?'warning':'success'">{{transport==='fake'?'Тестовый транспорт: сообщения не уходят в Telegram':'Отправка через Telegram API'}}</span></div><section class="panel"><h2>iCafeCloud и CCBoot</h2><p>iCafeCloud подключается в карточке каждого клуба: лицензия и API-токен, только чтение смен и состояния ПК. Для CCBoot подтверждённого API нет — интеграция не настроена, образы автоматически не изменяются.</p></section>
<form class="panel tg-form" @submit.prevent="form.post('/integrations/channels',{onSuccess:()=>{form.reset('bot_token');found=null;}})">
<h2>Новый Telegram-канал</h2>
<ol class="tg-steps"><li>Создайте бота в <b>@BotFather</b> и скопируйте токен.</li><li>Добавьте бота в группу клуба и напишите в группе любое сообщение.</li><li>Вставьте токен ниже и нажмите «Найти группу».</li></ol>
<div class="form-grid">
<label>Клуб<select id="tg-club" v-model="form.club_id" required><option value="" disabled>Выберите клуб</option><option v-for="c in clubs" :key="c.id" :value="c.id">{{c.name}}</option></select></label>
<label>Токен бота<input id="tg-token" v-model="form.bot_token" type="password" autocomplete="off" placeholder="1234567890:AA..." required/></label>
</div>
<div class="tg-find"><button type="button" class="secondary" :disabled="finding" @click="findChats">{{finding?'Ищем группы…':'Найти группу'}}</button>
<span v-if="botName" class="muted">Бот @{{botName}}</span></div>
<p v-if="findError" class="error" role="alert">{{findError}}</p>
<div v-if="found" class="tg-chats">
<p v-if="!found.length" class="muted">Бот пока не видит ни одной группы. Добавьте его в группу, напишите там сообщение и нажмите «Найти группу» ещё раз.</p>
<button v-for="c in found" :key="c.id" type="button" class="tg-chat" :aria-pressed="form.chat_id===c.id" @click="pick(c)"><b>{{c.title}}</b><small>{{c.type==='channel'?'Канал':'Группа'}} · {{c.id}}</small></button>
</div>
<div class="form-grid">
<label>Название канала<input id="tg-name" v-model="form.name" required/></label>
<label>Chat ID<input id="tg-chat" v-model="form.chat_id" placeholder="-100…" required/></label>
</div>
<fieldset><legend>Что отправлять</legend>
<div class="tg-presets"><button v-for="p in presets" :key="p.name" type="button" class="secondary small" @click="preset(p)">{{p.name}}</button></div>
<p class="muted tg-hint" v-if="form.kinds.includes('ticket_intake')">В группе можно написать <b>/ticket ПК 12 не запускается игра</b> и приложить фото. Чтобы бот понимал и сообщения «заявка …» без команды, сделайте его администратором группы.</p>
<label v-for="k in kinds" :key="k" class="check"><input type="checkbox" v-model="form.kinds" :value="k"/>{{labels[k]??k}}</label></fieldset>
<button :disabled="form.processing">Сохранить канал</button><p class="error" v-for="error in form.errors" :key="error">{{error}}</p></form>
<section class="panel"><h2>Каналы</h2><p v-if="!channels.length">Каналы ещё не добавлены.</p><div class="toolbar" v-for="c in channels" :key="c.id"><div><strong>{{c.name}}</strong><small class="tg-meta">{{clubName(c.club_id)}} · {{c.chat_id}} · {{c.enabled?'включён':'отключён'}}</small><small class="tg-meta">{{(c.kinds??[]).map(k=>labels[k]??k).join(' · ')}}</small><small class="tg-meta">Бот: {{botText(c.id)}}</small></div><Link :href="'/integrations/channels/'+c.id+'/test'" method="post" as="button" class="secondary">Тестовое сообщение</Link><Link :href="'/integrations/channels/'+c.id+'/toggle'" method="post" as="button" class="secondary">{{c.enabled?'Отключить':'Включить'}}</Link></div></section>
<section class="panel"><h2>Доставка</h2><p>При потерянном ответе Telegram повторная отправка может создать дубль. ID события помогает его распознать.</p><table><tbody><tr v-for="d in deliveries.data" :key="d.id"><td>#{{d.id}}</td><td>{{d.status}}</td><td>Попыток: {{d.attempts}}</td><td><Link v-if="d.status==='failed'" :href="'/integrations/deliveries/'+d.id+'/retry'" method="post" as="button">Повторить</Link></td></tr></tbody></table><Link v-if="deliveries.next_page_url" :href="deliveries.next_page_url">Далее</Link><Link v-if="deliveries.prev_page_url" :href="deliveries.prev_page_url">Назад</Link></section>
<section class="panel"><h2>Последние попытки</h2><p v-for="a in attempts" :key="a.id">Доставка #{{a.delivery_id}} · {{a.outcome}} · HTTP {{a.http_status??'ответ не получен'}}</p></section></Shell></template>

<style scoped>
.tg-form{display:grid;gap:16px}
.tg-form h2{margin:0}
.tg-steps{margin:0;padding-left:20px;color:var(--muted);font-size:13.5px;display:grid;gap:4px}
.tg-find{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.tg-chats{display:flex;gap:8px;flex-wrap:wrap}
.tg-chat{display:grid;justify-items:start;gap:2px;min-height:0;padding:10px 14px;background:var(--surface-2);color:var(--text);border:1px solid var(--border);border-radius:12px;text-align:left}
.tg-chat small{color:var(--muted);font-weight:400}
.tg-chat:hover{border-color:var(--border-strong);opacity:1}
.tg-chat[aria-pressed=true]{border-color:var(--chart-1);box-shadow:0 0 0 3px color-mix(in srgb,var(--chart-1) 20%,transparent)}
.tg-hint{margin:0 0 4px;font-size:13px}
.tg-presets{display:flex;gap:8px;flex-wrap:wrap;width:100%}
.small{min-height:32px;padding:4px 12px;font-size:12.5px}
.tg-meta{display:block;color:var(--muted);margin-top:3px}
.check{display:flex;align-items:center;gap:8px;font-weight:400}
</style>
