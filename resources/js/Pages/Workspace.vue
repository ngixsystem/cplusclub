<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import Shell from '../Layouts/Shell.vue';
type Row = { id: number; name?: string; address?: string; description?: string; status: string; priority?: string; workstation_number?: string; next_inspection_date?: string; club?: { name: string }; assignee?: { name: string } };
const props = defineProps<{ ticketComputers:{id:number;club_id:number;name:string;type:string;workstation_number:string|null;zone:string|null}[]; section: string; search: string; stats: Record<string, number>; clubs: {id:number;name:string}[]; rows: {data:Row[];total:number;prev_page_url:string|null;next_page_url:string|null} }>();
const page = usePage<{auth:{user:{role:string}}}>();
const titles: Record<string,string> = {overview:'Обзор',clubs:'Клубы',equipment:'Оборудование',tickets:'Заявки'};
const status: Record<string,string> = {new:'Новая',accepted:'Принята',working:'В работе',approval:'Согласование',parts:'Ожидает запчастей',resolved:'Решена',closed:'Закрыта',active:'Активно'};
const search = ref(props.search); const adding = ref(false);
const canCreate = computed(() => props.section === 'tickets' || (props.section === 'clubs' ? ['owner','lead'].includes(page.props.auth.user.role) : props.section === 'equipment' && page.props.auth.user.role !== 'representative'));
const form = useForm({icafe_license:'',icafe_token:'',icafe_currency:'UZS',equipment_id:'',name:'',address:'',timezone:'Asia/Tashkent',contacts:'',support_hours:'Пн–Пт 09:00–18:00',notes:'',club_id:props.clubs[0]?.id ?? '',workstation_number:'',type:'pc',zone:'',next_inspection_date:new Date().toISOString().slice(0,10),category:'Диагностика',description:'',priority:'normal'});
const computers = computed(() => props.ticketComputers.filter(pc => pc.club_id === Number(form.club_id)).sort((a,b)=>a.name.localeCompare(b.name,'ru',{numeric:true})));
watch(()=>form.club_id,()=>{form.equipment_id='';form.clearErrors('equipment_id');});
function save() { form.post('/'+props.section, {onSuccess:()=>{adding.value=false; form.reset();}}); }
</script>
<template>
  <Head :title="titles[section]" /><Shell>
    <div class="heading"><div><h1>{{ titles[section] }}</h1></div><button v-if="canCreate" @click="adding=!adding">{{ adding ? 'Отменить' : '+ Добавить' }}</button></div>
    <section class="stats"><article v-for="(label,key) in {clubs:'Клубов',equipment:'Устройств',tickets:'Открытых заявок',critical:'Критических'}" :key="key"><span>{{ label }}</span><strong>{{ stats[key] }}</strong></article></section>
    <form v-if="adding" class="panel form-grid" @submit.prevent="save">
      <template v-if="section==='clubs'"><label>Название<input v-model="form.name" required /></label><label>Адрес<input v-model="form.address" required /></label><label>Часовой пояс<input v-model="form.timezone" required /></label><label>Часы поддержки<input v-model="form.support_hours" required /></label><label>Контакты<input v-model="form.contacts" /></label><label>Лицензия iCafeCloud (необязательно)<input v-model="form.icafe_license" type="number" min="1" /></label><label>API-токен iCafeCloud<input v-model="form.icafe_token" type="password" autocomplete="new-password" /></label><label>Валюта<select v-model="form.icafe_currency"><option>UZS</option><option>USD</option><option>RUB</option><option>KZT</option><option>EUR</option></select></label><p>Передавайте API-токен только через HTTPS или SSH-туннель.</p></template>
      <template v-else><label>Клуб<select v-model="form.club_id" required><option v-for="club in clubs" :value="club.id" :key="club.id">{{ club.name }}</option></select></label>
        <template v-if="section==='equipment'"><label>Название<input v-model="form.name" required /></label><label>Номер места<input v-model="form.workstation_number" /></label><label>Зона<input v-model="form.zone" /></label><label>Тип<select v-model="form.type"><option value="pc">ПК</option><option value="server">Сервер</option><option value="switch">Коммутатор</option><option value="router">Маршрутизатор</option><option value="ups">UPS</option><option value="peripheral">Периферия</option></select></label><label>Первый срок осмотра<input type="date" v-model="form.next_inspection_date" required /></label></template>
        <template v-else><label>Оборудование клуба<select v-model="form.equipment_id"><option value="">Общая заявка — без привязки к оборудованию</option><option v-for="pc in computers" :key="pc.id" :value="pc.id">{{pc.name}}{{pc.type === 'server' ? ' · Сервер' : ''}}{{pc.workstation_number ? ' · место '+pc.workstation_number : ''}}{{pc.zone ? ' · '+pc.zone : ''}}</option></select></label><p v-if="!computers.length">В выбранном клубе пока нет ПК или серверов. Добавьте или импортируйте оборудование, либо создайте общую заявку.</p><label>Категория<input v-model="form.category" required /></label><label>Описание<textarea v-model="form.description" required /></label><label>Приоритет<select v-model="form.priority"><option value="low">Низкий</option><option value="normal">Обычный</option><option value="high">Высокий</option><option value="critical">Критический — весь клуб или группа ПК</option></select></label></template>
      </template>
      <div class="error" role="alert" v-for="(error,key) in form.errors" :key="key">{{ error }}</div><button :disabled="form.processing">{{ form.processing?'Сохраняем…':'Сохранить' }}</button>
    </form>
    <section class="panel records-panel">
      <div class="toolbar"><h2>{{ section==='overview'?'Последние заявки':section==='equipment'?'Реестр оборудования':section==='clubs'?'Все клубы':'Все заявки' }} <small>{{ rows.total }}</small></h2><form @submit.prevent="router.get(section==='overview'?'/':'/'+section,{search:search},{preserveState:true})"><input v-model="search" placeholder="Поиск" aria-label="Поиск" /><button class="secondary">Найти</button></form></div>
      <div v-if="!rows.data.length" class="empty"><h3>Пока нет записей</h3><p>Добавьте клуб, затем оборудование и первую заявку. Демонстрационные данные не загружены.</p></div>
      <div v-else class="table-scroll"><table><thead><tr><th>№ / Название</th><th>{{ section==='clubs'?'Адрес':'Клуб' }}</th><th>Статус</th><th>{{ section==='equipment'?'Следующий осмотр':'Детали' }}</th></tr></thead><tbody><tr v-for="row in rows.data" :key="row.id"><td><Link v-if="['tickets','overview'].includes(section)" :href="'/tickets/'+row.id">#{{ row.id }} · {{ row.description }}</Link><Link v-else :href="'/'+section+'/'+row.id">{{ row.name }}</Link></td><td>{{ row.club?.name || row.address || '—' }}</td><td><span class="badge" :data-status="row.status">{{ status[row.status] || row.status }}</span></td><td>{{ row.next_inspection_date || row.assignee?.name || (row.priority==='critical'?'Критическая':'—') }}</td></tr></tbody></table></div>
      <div class="pagination"><Link v-if="rows.prev_page_url" :href="rows.prev_page_url">← Назад</Link><Link v-if="rows.next_page_url" :href="rows.next_page_url">Далее →</Link></div>
    </section>
  </Shell>
</template>
