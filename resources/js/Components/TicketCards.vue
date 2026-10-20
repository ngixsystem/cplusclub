<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Clock, Cpu } from 'lucide-vue-next';

type Item = { id: number; description?: string; status: string; priority?: string; category?: string; created_at?: string; updated_at?: string; first_responded_at?: string | null; resolved_at?: string | null; closed_at?: string | null; club?: { name: string }; equipment?: { id: number; name: string; workstation_number?: string | null } | null; assignee?: { name: string } | null };
const props = withDefaults(defineProps<{ items: Item[]; view?: 'cards' | 'list'; loading?: boolean }>(), { view: 'cards', loading: false });

const statuses: Record<string, string> = { new: 'Новая', accepted: 'Принята', working: 'В работе', approval: 'Согласование', parts: 'Ждёт запчасти', resolved: 'Решена', closed: 'Закрыта' };
const priorities: Record<string, string> = { low: 'Низкий', normal: 'Обычный', high: 'Высокий', critical: 'Критический' };
const steps = ['new', 'accepted', 'working', 'resolved', 'closed'];
const stepLabels = ['Новая', 'Принята', 'В работе', 'Решена', 'Закрыта'];
const paused = (s: string) => s === 'approval' || s === 'parts';
const stepIndex = (s: string) => paused(s) ? 2 : Math.max(0, steps.indexOf(s));

// Age clock: re-renders once a minute.
const now = ref(Date.now());
let tick: ReturnType<typeof setInterval> | undefined;
// Cards stagger in on first render only; filtering must never replay it.
const intro = ref(true);
let introTimer: ReturnType<typeof setTimeout> | undefined;
onMounted(() => { tick = setInterval(() => { now.value = Date.now(); }, 60000); introTimer = setTimeout(() => { intro.value = false; }, 700); });
onUnmounted(() => { clearInterval(tick); clearTimeout(introTimer); });

const minutes = (from?: string | null, to?: string | number | null) => {
    if (!from) return 0;
    const end = typeof to === 'number' ? to : to ? new Date(to).getTime() : now.value;
    return Math.max(0, Math.round((end - new Date(from).getTime()) / 60000));
};
const span = (m: number) => m < 60 ? `${m} мин` : m < 1440 ? `${Math.floor(m / 60)} ч ${m % 60} мин` : `${Math.floor(m / 1440)} дн ${Math.floor((m % 1440) / 60)} ч`;
/** Age chip. Thresholds follow the service terms: 2 h for an incident, 24 h for planned work;
 *  a ticket nobody has answered turns amber after 30 min and red after 2 h. */
function age(t: Item): { text: string; tone: '' | 'warn' | 'bad' | 'done' } {
    if (t.status === 'resolved' || t.status === 'closed') {
        const end = t.resolved_at ?? t.closed_at;
        return { text: end ? `решена за ${span(minutes(t.created_at, end))}` : 'решена', tone: 'done' };
    }
    const m = minutes(t.created_at, now.value);
    if (t.status === 'new' && !t.first_responded_at) return { text: `без ответа ${span(m)}`, tone: m >= 120 ? 'bad' : m >= 30 ? 'warn' : '' };
    return { text: `открыта ${span(m)}`, tone: m >= 1440 ? 'bad' : m >= 120 ? 'warn' : '' };
}
const initials = (n?: string) => (n ?? '').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]?.toUpperCase()).join('') || '—';
const updated = (v?: string) => { if (!v) return ''; const m = minutes(v, now.value); return m < 1 ? 'только что' : `${span(m)} назад`; };
const firstLine = (s?: string) => (s ?? '').trim() || 'Без описания';
const pc = (t: Item) => t.equipment ? t.equipment.name + (t.equipment.workstation_number ? ` · место ${t.equipment.workstation_number}` : '') : null;
</script>

<template>
  <div :class="['tickets', view, { 'stagger-in': intro, loading }]" :aria-busy="loading">
    <template v-if="view === 'cards'">
      <article v-for="t in items" :key="t.id" class="t-card" :data-priority="t.priority">
        <header class="t-top">
          <span class="t-id tabular">#{{ t.id }}</span>
          <span class="badge" :data-status="t.status">{{ statuses[t.status] ?? t.status }}</span>
          <span class="t-prio" :data-p="t.priority"><i aria-hidden="true"></i>{{ priorities[t.priority ?? 'normal'] }}</span>
        </header>
        <p class="t-where"><span>{{ t.club?.name ?? 'Клуб не указан' }}</span>
          <template v-if="pc(t)"><span aria-hidden="true">·</span><Link :href="'/equipment/' + t.equipment!.id" class="t-pc"><Cpu aria-hidden="true" />{{ pc(t) }}</Link></template>
          <template v-else><span aria-hidden="true">·</span><span class="muted">общая заявка</span></template>
        </p>
        <h3><Link :href="'/tickets/' + t.id" class="t-title">{{ firstLine(t.description) }}</Link></h3>

        <div class="track" :data-paused="paused(t.status)" role="img" :aria-label="'Этап: ' + (statuses[t.status] ?? t.status)">
          <span v-for="(s, i) in steps" :key="s" class="seg" :class="{ done: i < stepIndex(t.status) || (i === stepIndex(t.status) && (t.status === 'closed')), now: i === stepIndex(t.status) && t.status !== 'closed' }" :title="stepLabels[i]"></span>
        </div>

        <footer class="t-foot">
          <span class="age" :data-tone="age(t).tone"><Clock aria-hidden="true" />{{ age(t).text }}</span>
          <span v-if="t.category" class="chip">{{ t.category }}</span>
          <span class="who" :title="t.assignee?.name ?? 'Исполнитель не назначен'">
            <span class="avatar" :class="{ empty: !t.assignee }" aria-hidden="true">{{ t.assignee ? initials(t.assignee.name) : '?' }}</span>
            <span class="who-name">{{ t.assignee?.name ?? 'Не назначен' }}</span>
          </span>
          <span class="upd">{{ updated(t.updated_at) }}</span>
        </footer>
      </article>
    </template>

    <template v-else>
      <Link v-for="t in items" :key="t.id" :href="'/tickets/' + t.id" class="t-row" :data-priority="t.priority">
        <span class="t-id tabular">#{{ t.id }}</span>
        <span class="t-row-main"><b>{{ firstLine(t.description) }}</b><small>{{ t.club?.name }}<template v-if="pc(t)"> · {{ pc(t) }}</template></small></span>
        <span class="badge" :data-status="t.status">{{ statuses[t.status] ?? t.status }}</span>
        <span class="age" :data-tone="age(t).tone"><Clock aria-hidden="true" />{{ age(t).text }}</span>
        <span class="avatar" :class="{ empty: !t.assignee }" :title="t.assignee?.name ?? 'Не назначен'">{{ t.assignee ? initials(t.assignee.name) : '?' }}</span>
      </Link>
    </template>
    <p class="timezone">Время указано по Ташкенту (UTC+5)</p>
  </div>
</template>

<style scoped>
.tickets { padding:20px; display:grid; gap:12px; transition:opacity .15s ease }
.tickets.cards { grid-template-columns:repeat(auto-fill,minmax(340px,1fr)); gap:14px }
.tickets.loading { opacity:.55 }
.timezone { grid-column:1/-1; font-size:12px; color:var(--muted); margin:4px 0 0 }

/* Card */
.t-card { position:relative; min-width:0; display:flex; flex-direction:column; gap:12px; padding:18px 18px 16px 22px; border:1px solid var(--border); border-radius:14px; background:var(--surface); overflow:hidden; transition:border-color .18s ease,box-shadow .18s ease }
.t-card:before { content:""; position:absolute; left:0; top:0; bottom:0; width:3px; background:var(--p,transparent) }
.t-card:hover { border-color:var(--border-strong); box-shadow:0 12px 30px -16px rgba(0,0,0,.45) }
[data-priority=critical] { --p:var(--error) } [data-priority=high] { --p:var(--warning) } [data-priority=normal] { --p:var(--info) } [data-priority=low] { --p:var(--border-strong) }
.t-top { display:flex; align-items:center; gap:10px; flex-wrap:wrap }
.t-id { font-size:12px; color:var(--muted); font-weight:600 }
.t-prio { margin-left:auto; display:inline-flex; align-items:center; gap:6px; font-size:12px; color:var(--muted) }
.t-prio i { width:7px; height:7px; border-radius:50%; background:var(--pc,var(--subtle)) }
.t-prio[data-p=critical] { --pc:var(--error); color:var(--error) } .t-prio[data-p=high] { --pc:var(--warning); color:var(--warning) } .t-prio[data-p=normal] { --pc:var(--info) }
.t-where { display:flex; flex-wrap:wrap; align-items:center; gap:6px; margin:0; font-size:12.5px; color:var(--muted); min-width:0 }
.t-pc { display:inline-flex; align-items:center; gap:5px; color:var(--text); font-weight:500 }
.t-pc svg { width:13px; height:13px; color:var(--muted) }
.t-pc:hover { text-decoration:underline; text-underline-offset:3px }
.t-card h3 { margin:0; font-size:15.5px; font-weight:600; line-height:1.4; letter-spacing:-.01em }
.t-title { color:var(--text); display:-webkit-box; -webkit-line-clamp:2; line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; overflow-wrap:anywhere }
.t-title:after { content:""; position:absolute; inset:0; z-index:0 }
.t-pc,.t-foot { position:relative; z-index:1 }

/* Status path: five steps; approval / parts pause at "В работе". */
.track { display:grid; grid-template-columns:repeat(5,1fr); gap:4px; margin-top:2px }
.seg { height:4px; border-radius:2px; background:var(--hover) }
.seg.done { background:var(--success) }
.seg.now { background:var(--info) }
.track[data-paused=true] .seg.now { background:var(--warning) }

.t-foot { display:flex; align-items:center; gap:8px; flex-wrap:wrap; padding-top:12px; border-top:1px solid var(--border); font-size:12px }
.age { display:inline-flex; align-items:center; gap:5px; padding:3px 8px; border-radius:999px; background:var(--surface-2); border:1px solid var(--border); color:var(--muted); font-size:12px; font-variant-numeric:tabular-nums; white-space:nowrap }
.age svg { width:12px; height:12px }
.age[data-tone=warn] { color:var(--warning); background:var(--warning-bg); border-color:transparent }
.age[data-tone=bad] { color:var(--error); background:var(--error-bg); border-color:transparent }
.age[data-tone=done] { color:var(--success); background:var(--success-bg); border-color:transparent }
.who { display:inline-flex; align-items:center; gap:7px; margin-left:auto; min-width:0; color:var(--muted) }
.who-name { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:130px }
.avatar { flex:none; width:24px; height:24px; border-radius:50%; display:grid; place-items:center; font-size:10.5px; font-weight:600; background:var(--active); color:var(--text) }
.avatar.empty { background:transparent; border:1px dashed var(--border-strong); color:var(--subtle) }
.upd { color:var(--subtle); font-size:11.5px; white-space:nowrap }

/* Compact list */
.tickets.list { gap:0; padding:8px 12px }
.t-row { position:relative; display:grid; grid-template-columns:64px minmax(0,1fr) auto 170px 28px; align-items:center; gap:14px; padding:11px 10px 11px 16px; border-bottom:1px solid var(--border); color:var(--text); border-radius:8px; transition:background-color .12s ease }
.t-row:before { content:""; position:absolute; left:4px; top:12px; bottom:12px; width:3px; border-radius:2px; background:var(--p,transparent) }
.t-row:hover { background:var(--hover) }
.t-row-main { min-width:0 }
.t-row-main b { display:block; font-weight:500; font-size:14px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis }
.t-row-main small { display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis }
.t-row .age { justify-self:start }

@media (max-width:760px) {
  .tickets { padding:12px }
  .tickets.cards { grid-template-columns:1fr }
  .t-row { grid-template-columns:52px minmax(0,1fr) auto; }
  .t-row .age,.t-row .avatar { display:none }
  .upd { display:none }
}
@media (prefers-reduced-motion:reduce) { .tickets { transition:none } }
</style>
