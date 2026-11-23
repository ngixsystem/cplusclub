<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import Shell from '../Layouts/Shell.vue';

type Equipment = { id: string; name: string; place: string | null; zone: string | null; server?: boolean };
type Event = { at: string; kind: string; text: string; detail?: string; harmful?: boolean };
type Row = { label: string; points: number; max: number; note: string };
const props = defineProps<{
 run: { id: number; mode: 'demo' | 'exam'; status: string; started_at: string; finished_at: string | null;
  ticket: { equipment_id: string | null; category: string; description: string; priority: string; created_at: string } | null;
  fields: Record<string, string>; done: string[]; fix_done: boolean; proposal: { amount: number; expected_duration: string; description: string } | null;
  worklogs: { minutes: number; actions: string }[]; timeline: Event[] };
 scenario: { id: string; title: string; difficulty: string; minutes_target: number; club: { name: string; note: string };
  message: { from: string; channel: string; time: string; text: string }; equipment: Equipment[];
  actions: { id: string; group: 'diag' | 'fix' | 'verify'; label: string }[]; causes: { id: string; label: string }[]; chapters: string[]; categories: string[] };
 hint: { stage: string; text: string } | null;
 result: { score: number; max: number; pass_points: number; passed: boolean; rows: Row[]; harmful: { label: string; reason: string }[]; penalty_per_harmful: number; minutes: number } | null;
}>();
const page = usePage<{ errors: Record<string, string> }>();
const errors = computed(() => Object.values(page.props.errors ?? {}));

const statuses: Record<string, string> = { message: 'Нет заявки', new: 'Новая', accepted: 'Принята', working: 'В работе', approval: 'Ожидает согласования', parts: 'Ожидает запчастей', resolved: 'Решена', closed: 'Закрыта' };
const priorities: Record<string, string> = { low: 'Низкий', normal: 'Обычный', high: 'Высокий', critical: 'Критический' };
const groups = { diag: 'Диагностика', fix: 'Устранение', verify: 'Проверка результата' } as const;
const equipmentLabel = (e: Equipment) => `${e.name}${e.server ? ' · Сервер' : ''}${e.place ? ' · место ' + e.place : ''}${e.zone ? ' · ' + e.zone : ''}`;
const ticketEquipment = computed(() => props.scenario.equipment.find(e => e.id === props.run.ticket?.equipment_id));

const busy = ref(false);
function send(type: string, data: Record<string, unknown> = {}, onSuccess?: () => void) {
 busy.value = true;
 router.post(`/training/practice/run/${props.run.id}`, { type, ...data }, { preserveScroll: true, onSuccess: () => onSuccess?.(), onFinish: () => { busy.value = false; } });
}

const create = ref({ equipment_id: '', category: '', description: '', priority: 'normal' });
const resolveOpen = ref(false);
const resolve = ref({ work_result: '', verification_result: '', cause: '', solution: '', cause_option: '' });
const proposalOpen = ref(false);
const proposal = ref({ description: '', amount: '', expected_duration: '' });
const worklog = ref({ minutes: 30, actions: '', consumables: '' });

const now = ref(Date.now());
let timer: number | undefined;
onMounted(() => { timer = window.setInterval(() => { now.value = Date.now(); }, 1000); });
onBeforeUnmount(() => window.clearInterval(timer));
const elapsed = computed(() => Math.floor(((props.run.finished_at ? new Date(props.run.finished_at).getTime() : now.value) - new Date(props.run.started_at).getTime()) / 1000));
const clock = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
const time = (iso: string) => new Date(iso).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Tashkent' });
const restart = (mode: string) => router.post(`/training/practice/${props.scenario.id}/start`, { mode });
</script>

<template>
 <Head :title="'Практикум: ' + scenario.title" />
 <Shell>
  <div class="page-header">
   <div><h1>Практикум: {{scenario.title}}</h1>
    <p><span class="tp-mode" :class="run.mode">{{ run.mode === 'demo' ? 'Демо — с подсказками, не оценивается' : 'Испытание — оценивается' }}</span> · {{scenario.difficulty}} · норма {{scenario.minutes_target}} мин · идёт {{clock(elapsed)}}</p></div>
   <Link href="/training" class="button secondary">← К обучению</Link>
  </div>
  <p class="tp-sandbox">{{scenario.club.note}}</p>

  <div v-if="hint" class="tp-hint" role="status"><b>Подсказка</b> {{hint.text}}</div>
  <div v-for="(e, i) in errors" :key="i" class="error" role="alert">{{e}}</div>

  <!-- Result -->
  <section v-if="result" class="panel tp-result" :class="{ ok: result.passed }">
   <span class="tp-eyebrow">{{ run.mode === 'demo' ? 'Демо завершено — результат для ориентира' : result.passed ? 'Испытание пройдено' : 'Испытание не пройдено' }}</span>
   <p class="tp-score"><b>{{result.score}}</b> из {{result.max}} баллов · проходной {{result.pass_points}} · {{result.minutes}} мин</p>
   <table class="tp-rows"><tbody>
    <tr v-for="r in result.rows" :key="r.label" :class="{ miss: r.points < r.max }"><th scope="row">{{r.label}}</th><td>{{r.points}} / {{r.max}}</td><td>{{r.note}}</td></tr>
    <tr v-for="h in result.harmful" :key="h.label" class="harm"><th scope="row">Опасное действие</th><td>−{{result.penalty_per_harmful}}</td><td>«{{h.label}}» — {{h.reason}}</td></tr>
   </tbody></table>
   <p v-if="result.harmful.length" class="tp-note">Испытание с опасным действием не засчитывается, даже при высоком балле: на настоящем объекте это остановило бы клуб.</p>
   <div class="tp-buttons">
    <button type="button" @click="restart('exam')">{{ run.mode === 'demo' ? 'Перейти к испытанию' : 'Пройти испытание ещё раз' }}</button>
    <Link href="/training" class="button secondary">К обучению</Link>
   </div>
  </section>

  <div class="tp-grid">
   <div class="tp-main">
    <!-- Incoming message -->
    <section class="panel tp-message">
     <span class="tp-eyebrow">{{scenario.message.channel}} · {{scenario.club.name}} · {{scenario.message.time}}</span>
     <p class="tp-from">{{scenario.message.from}}</p>
     <p>{{scenario.message.text}}</p>
    </section>

    <!-- Create ticket: same fields as the real form -->
    <form v-if="run.status==='message'" class="panel form-grid" @submit.prevent="send('create', { ...create, equipment_id: create.equipment_id || null })">
     <h2>Новая заявка</h2>
     <label>Клуб<input :value="scenario.club.name" readonly /></label>
     <label>Оборудование клуба<select v-model="create.equipment_id"><option value="">Общая заявка — без привязки к оборудованию</option><option v-for="e in scenario.equipment" :key="e.id" :value="e.id">{{equipmentLabel(e)}}</option></select></label>
     <label>Категория<input v-model="create.category" list="practice-categories" required /><datalist id="practice-categories"><option v-for="c in scenario.categories" :key="c">{{c}}</option></datalist></label>
     <label>Описание<textarea v-model="create.description" required rows="4" /></label>
     <label>Приоритет<select v-model="create.priority"><option value="low">Низкий</option><option value="normal">Обычный</option><option value="high">Высокий</option><option value="critical">Критический — весь клуб или группа ПК</option></select></label>
     <button :disabled="busy">Сохранить</button>
    </form>

    <!-- Ticket -->
    <section v-else class="panel tp-ticket">
     <div class="tp-ticket-head"><h2>Заявка У-{{run.id}}</h2><span class="tp-status" :data-status="run.status">{{statuses[run.status]}}</span></div>
     <dl>
      <div><dt>Оборудование</dt><dd>{{ ticketEquipment ? equipmentLabel(ticketEquipment) : 'Общая заявка' }}</dd></div>
      <div><dt>Категория</dt><dd>{{run.ticket?.category}}</dd></div>
      <div><dt>Приоритет</dt><dd>{{priorities[run.ticket?.priority ?? ''] }}</dd></div>
     </dl>
     <p class="tp-desc">{{run.ticket?.description}}</p>

     <div v-if="!run.finished_at" class="tp-transitions">
      <button v-if="run.status==='new'" :disabled="busy" @click="send('transition', { to: 'accepted' })">Принять</button>
      <button v-if="run.status==='accepted'" :disabled="busy" @click="send('transition', { to: 'working' })">В работу</button>
      <button v-if="run.status==='working'" :disabled="busy" @click="resolveOpen = !resolveOpen">{{ resolveOpen ? 'Скрыть форму решения' : 'Решена…' }}</button>
      <button v-if="run.status==='working'" type="button" class="secondary" :disabled="busy" @click="proposalOpen = !proposalOpen">Предложение работ…</button>
      <template v-if="run.status==='resolved'">
       <button :disabled="busy" @click="send('transition', { to: 'closed' })">Закрыть</button>
       <button class="secondary" :disabled="busy" @click="send('transition', { to: 'working' })">Вернуть в работу</button>
      </template>
     </div>

     <form v-if="run.status==='working' && resolveOpen" class="form-grid tp-subform" @submit.prevent="send('transition', { to: 'resolved', ...resolve }, () => { resolveOpen = false; })">
      <h3>Решение заявки</h3>
      <label>Выполненные работы<textarea v-model="resolve.work_result" rows="3" required /></label>
      <label>Проверка<textarea v-model="resolve.verification_result" rows="3" required /></label>
      <label>Основная причина<select v-model="resolve.cause_option" required><option value="" disabled>Выберите причину</option><option v-for="c in scenario.causes" :key="c.id" :value="c.id">{{c.label}}</option></select></label>
      <label>Причина (подробно)<textarea v-model="resolve.cause" rows="2" required /></label>
      <label>Решение<textarea v-model="resolve.solution" rows="2" required /></label>
      <button :disabled="busy">Сохранить переход</button>
     </form>

     <form v-if="run.status==='working' && proposalOpen" class="form-grid tp-subform" @submit.prevent="send('proposal', proposal, () => { proposalOpen = false; })">
      <h3>Предложение работ на согласование</h3>
      <label>Описание работ<textarea v-model="proposal.description" rows="3" required /></label>
      <label>Сумма, USD<input v-model="proposal.amount" type="number" min="0" step="0.01" required /></label>
      <label>Срок выполнения<input v-model="proposal.expected_duration" placeholder="например, сегодня после закрытия" required /></label>
      <button :disabled="busy">Отправить клубу</button>
     </form>
    </section>

    <!-- Actions on the site -->
    <section v-if="run.status==='working'" class="panel tp-actions">
     <h2>Действия на объекте</h2>
     <p class="tp-note">Каждое действие — это то, что вы сделали бы в клубе. Результат появится в истории заявки. Выбирайте осознанно: некоторые действия вредят клубу.</p>
     <div v-for="(title, key) in groups" :key="key" class="tp-group">
      <h3>{{title}}</h3>
      <button v-for="a in scenario.actions.filter(x => x.group === key)" :key="a.id" type="button" class="secondary"
       :class="{ done: run.done.includes(a.id) }" :disabled="busy || (run.done.includes(a.id) && a.group !== 'verify')" @click="send('action', { action: a.id })">
       <span v-if="run.done.includes(a.id)">✓ </span>{{a.label}}</button>
     </div>
    </section>

    <form v-if="run.status==='working' || run.status==='resolved'" class="panel form-grid tp-worklog" @submit.prevent="send('worklog', worklog, () => { worklog.actions = ''; worklog.consumables = ''; })">
     <h2>Трудозатраты и расходы</h2>
     <label>Минуты<input v-model="worklog.minutes" type="number" min="1" max="1440" required /></label>
     <label>Выполненные действия<textarea v-model="worklog.actions" rows="2" required /></label>
     <label>Расходники<textarea v-model="worklog.consumables" rows="1" /></label>
     <button :disabled="busy">Добавить запись</button>
    </form>

    <section v-if="run.status==='closed' || run.status==='resolved'" class="panel tp-fields">
     <h2>Результаты работы</h2>
     <dl>
      <div><dt>Выполненные работы</dt><dd>{{run.fields.work_result}}</dd></div>
      <div><dt>Проверка</dt><dd>{{run.fields.verification_result}}</dd></div>
      <div><dt>Причина</dt><dd>{{run.fields.cause}}</dd></div>
      <div><dt>Решение</dt><dd>{{run.fields.solution}}</dd></div>
     </dl>
    </section>
   </div>

   <!-- Timeline -->
   <aside class="panel tp-timeline" aria-label="История заявки">
    <h2>История</h2>
    <ol>
     <li v-for="(e, i) in [...run.timeline].reverse()" :key="i" :class="[e.kind, { harmful: e.harmful }]">
      <span class="tp-time">{{time(e.at)}}</span>
      <b>{{e.text}}</b>
      <p v-if="e.detail">{{e.detail}}</p>
     </li>
    </ol>
    <p class="tp-note">Материал: <Link v-for="c in scenario.chapters" :key="c" :href="'/knowledge/'+c" class="tp-chapter">{{c}}</Link></p>
   </aside>
  </div>
 </Shell>
</template>

<style scoped>
.tp-mode{font-weight:600;color:var(--accent-text)}
.tp-mode.exam{color:var(--warning)}
.tp-sandbox{font-size:12px;color:var(--muted);margin:-12px 0 16px}
.tp-hint{position:sticky;top:76px;z-index:6;padding:12px 16px;margin-bottom:16px;border:1px solid var(--accent-text);border-radius:8px;background:var(--surface);font-size:13px;line-height:1.6;box-shadow:var(--shadow-pop)}
.tp-hint b{display:block;color:var(--accent-text);font-size:11px;letter-spacing:.06em;text-transform:uppercase;margin-bottom:2px}
.tp-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:16px;align-items:start}
.tp-main{display:grid;gap:16px;min-width:0}
.tp-eyebrow{display:block;font-size:11px;letter-spacing:.06em;text-transform:uppercase;font-weight:600;color:var(--muted)}
.tp-message{border-left:3px solid var(--info)}
.tp-message p{margin:6px 0 0;font-size:14px;line-height:1.7}
.tp-from{font-weight:600}
.tp-ticket-head{display:flex;justify-content:space-between;align-items:center;gap:12px}
.tp-ticket-head h2{margin:0}
.tp-status{padding:3px 10px;border-radius:999px;background:var(--surface-2);border:1px solid var(--border);font-size:12px;font-weight:600}
.tp-status[data-status=working]{color:var(--info)}
.tp-status[data-status=resolved],.tp-status[data-status=closed]{color:var(--success)}
.tp-ticket dl,.tp-fields dl{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin:14px 0}
.tp-fields dl{grid-template-columns:1fr}
.tp-ticket dt,.tp-fields dt{font-size:11px;color:var(--muted)}
.tp-ticket dd,.tp-fields dd{margin:2px 0 0;font-size:13px;white-space:pre-wrap}
.tp-desc{font-size:13px;line-height:1.7;white-space:pre-wrap;padding:12px;border-radius:6px;background:var(--surface-2);margin:0}
.tp-transitions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
.tp-subform{margin-top:16px;padding-top:16px;border-top:1px solid var(--border)}
.tp-subform h3{margin:0;grid-column:1/-1}
.tp-actions h2{margin-top:0}
.tp-group{margin-top:12px}
.tp-group h3{font-size:12px;color:var(--muted);margin:0 0 8px;text-transform:uppercase;letter-spacing:.05em}
.tp-group button{display:block;width:100%;text-align:left;margin:0 0 6px;font-weight:400;white-space:normal;height:auto;min-height:36px;line-height:1.5;padding:8px 12px}
.tp-group button.done{color:var(--muted)}
.tp-note{font-size:12px;color:var(--muted);line-height:1.6}
.tp-timeline{position:sticky;top:76px;max-height:calc(100vh - 100px);overflow:auto}
.tp-timeline h2{margin-top:0}
.tp-timeline ol{list-style:none;margin:0;padding:0;display:grid;gap:10px}
.tp-timeline li{padding:10px 12px;border-radius:6px;border:1px solid var(--border);font-size:12px;line-height:1.6}
.tp-timeline li b{display:block;font-weight:600}
.tp-timeline li p{margin:4px 0 0;color:var(--muted)}
.tp-timeline li.club{border-left:3px solid var(--info)}
.tp-timeline li.status{background:var(--surface-2)}
.tp-timeline li.action{border-left:3px solid var(--accent-text)}
.tp-timeline li.harmful{border-left:3px solid var(--error);background:var(--error-bg)}
.tp-time{float:right;color:var(--muted);font-variant-numeric:tabular-nums}
.tp-chapter{margin-right:8px}
.tp-result{margin-bottom:16px;border-color:var(--warning)}
.tp-result.ok{border-color:var(--success)}
.tp-score{margin:6px 0 12px;font-size:14px}
.tp-score b{font-size:36px;font-variant-numeric:tabular-nums;margin-right:4px}
.tp-rows{width:100%;border-collapse:collapse;font-size:12px}
.tp-rows th,.tp-rows td{text-align:left;padding:8px;border-top:1px solid var(--border);vertical-align:top}
.tp-rows td:nth-child(2){white-space:nowrap;font-variant-numeric:tabular-nums;color:var(--success)}
.tp-rows tr.miss td:nth-child(2){color:var(--warning)}
.tp-rows tr.harm td{color:var(--error)}
.tp-buttons{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
@media(max-width:1100px){.tp-grid{grid-template-columns:1fr}.tp-timeline{position:static;max-height:none}}
</style>
