<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import TimeSeries from './Charts/TimeSeries.vue';
import Sparkline from './Charts/Sparkline.vue';
import AnimatedNumber from './Charts/AnimatedNumber.vue';
import type { TicketFilters } from './tickets';

/* Ticket dashboard: stat tiles + two Grafana-style panels. Follows the list's club,
 * priority, assignee and category filters; has its own period and series toggles. */
type Stats = { range: number; labels: string[]; series: { new: number[]; closed: number[]; open: number[] }; totals: { new: number; closed: number; open_now: number; open_start: number; median_minutes: number | null } };
const props = defineProps<{ filters: TicketFilters }>();

const SERIES = {
    new: { label: 'Новые', color: 'var(--chart-3)' },
    open: { label: 'В работе', color: 'var(--chart-2)' },
    closed: { label: 'Закрытые', color: 'var(--chart-1)' },
} as const;
type Key = keyof typeof SERIES;

const read = <T,>(k: string, d: T): T => { try { const v = localStorage.getItem(k); return v ? JSON.parse(v) as T : d; } catch { return d; } };
const write = (k: string, v: unknown) => { try { localStorage.setItem(k, JSON.stringify(v)); } catch { /* optional */ } };
const range = ref<number>([7, 30, 90].includes(read('cclub-ticket-range', 30)) ? read('cclub-ticket-range', 30) : 30);
const shown = ref<Record<Key, boolean>>({ new: true, open: true, closed: true, ...read('cclub-ticket-series', {}) });
if (!Object.values(shown.value).some(Boolean)) shown.value = { new: true, open: true, closed: true };
watch(range, v => write('cclub-ticket-range', v));
watch(shown, v => write('cclub-ticket-series', v), { deep: true });
function toggle(k: Key) {
    const next = { ...shown.value, [k]: !shown.value[k] };
    if (Object.values(next).some(Boolean)) shown.value = next; // at least one series stays on
}

const data = ref<Stats | null>(null);
const loading = ref(false);
const error = ref('');
let ctrl: AbortController | undefined;
async function load() {
    ctrl?.abort(); const c = new AbortController(); ctrl = c;
    loading.value = true; error.value = '';
    const q = new URLSearchParams({ range: String(range.value) });
    const f = props.filters;
    if (f.club) q.set('club', String(f.club));
    if (f.priority.length) q.set('priority', f.priority.join(','));
    if (f.assignee !== null && f.assignee !== '') q.set('assignee', String(f.assignee));
    if (f.category) q.set('category', f.category);
    try {
        const r = await fetch('/tickets/stats?' + q, { signal: c.signal, headers: { Accept: 'application/json' } });
        if (r.status === 401) { window.location.assign('/login'); return; }
        if (!r.ok) throw new Error();
        data.value = await r.json();
    } catch { if (!c.signal.aborted) error.value = 'Не удалось обновить графики. Показаны последние данные.'; }
    finally { if (ctrl === c) loading.value = false; }
}
const scope = computed(() => JSON.stringify([props.filters.club, props.filters.priority, props.filters.assignee, props.filters.category]));
watch([scope, range], load);
onMounted(load);
onUnmounted(() => ctrl?.abort());

const flow = computed(() => !data.value ? [] : (['new', 'closed'] as const).filter(k => shown.value[k]).map(k => ({ key: k, ...SERIES[k], values: data.value!.series[k] })));
const openSeries = computed(() => data.value && shown.value.open ? [{ key: 'open', ...SERIES.open, values: data.value.series.open }] : []);
const days = computed(() => (data.value?.labels ?? []).map(d => new Date(d + 'T00:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })));
const openDelta = computed(() => data.value ? data.value.totals.open_now - data.value.totals.open_start : 0);
const median = computed(() => {
    const m = data.value?.totals.median_minutes;
    if (m === null || m === undefined) return null;
    return m < 60 ? { v: m, u: 'мин' } : m < 2880 ? { v: Math.round(m / 6) / 10, u: 'ч' } : { v: Math.round(m / 144) / 10, u: 'дн' };
});
const rangeLabel = computed(() => `${range.value} дн`);
</script>

<template>
  <section class="panel dash" :class="{ loading }" aria-labelledby="dash-title" :aria-busy="loading">
    <div class="dash-head">
      <div><h2 id="dash-title">Динамика заявок</h2><p>По дням, время Ташкента. Учитывает фильтры клуба, приоритета, исполнителя и категории.</p></div>
      <div class="dash-controls">
        <div class="seg" role="group" aria-label="Период">
          <button v-for="r in [7, 30, 90]" :key="r" type="button" :aria-pressed="range === r" @click="range = r">{{ r }} дней</button>
        </div>
        <div class="series" role="group" aria-label="Показать на графиках">
          <button v-for="(s, k) in SERIES" :key="k" type="button" class="s-chip" :aria-pressed="shown[k]" @click="toggle(k)">
            <i :style="{ background: s.color }" aria-hidden="true"></i>{{ s.label }}
          </button>
        </div>
      </div>
    </div>
    <p v-if="error" class="dash-warn" role="alert">{{ error }}</p>

    <div v-if="data" class="tiles">
      <article class="tile">
        <span class="tile-label"><i :style="{ background: SERIES.new.color }"></i>Новые за {{ rangeLabel }}</span>
        <strong class="tabular"><AnimatedNumber :value="data.totals.new" /></strong>
        <Sparkline :values="data.series.new" :days="days" label="Новые заявки по дням" :color="SERIES.new.color" />
      </article>
      <article class="tile">
        <span class="tile-label"><i :style="{ background: SERIES.closed.color }"></i>Закрыто за {{ rangeLabel }}</span>
        <strong class="tabular"><AnimatedNumber :value="data.totals.closed" /></strong>
        <Sparkline :values="data.series.closed" :days="days" label="Закрытые заявки по дням" :color="SERIES.closed.color" />
      </article>
      <article class="tile">
        <span class="tile-label"><i :style="{ background: SERIES.open.color }"></i>В работе сейчас</span>
        <strong class="tabular"><AnimatedNumber :value="data.totals.open_now" />
          <small :class="['delta', openDelta > 0 ? 'up' : openDelta < 0 ? 'down' : '']">{{ openDelta > 0 ? '+' : '' }}{{ openDelta }} за период</small></strong>
        <Sparkline :values="data.series.open" :days="days" label="Незакрытые заявки на конец дня" :color="SERIES.open.color" />
      </article>
      <article class="tile">
        <span class="tile-label">Медиана решения</span>
        <strong class="tabular" v-if="median"><AnimatedNumber :value="median.v" :decimals="1" /> <small>{{ median.u }}</small></strong>
        <strong v-else class="none">Нет данных</strong>
        <p class="tile-note">От создания до «Решена» для заявок, закрытых за {{ rangeLabel }}</p>
      </article>
    </div>

    <div v-if="data" class="panels" :class="{ single: !flow.length || !openSeries.length }">
      <article v-if="flow.length" class="chart-panel">
        <header><h3>Новые и закрытые</h3><span>заявок в день</span></header>
        <ul class="legend"><li v-for="s in flow" :key="s.key"><i :style="{ background: s.color }"></i>{{ s.label }}<b class="tabular">{{ s.values.reduce((a, b) => a + b, 0) }}</b></li></ul>
        <TimeSeries :labels="data.labels" :series="flow" caption="Новые и закрытые заявки по дням" />
      </article>
      <article v-if="openSeries.length" class="chart-panel">
        <header><h3>В работе</h3><span>незакрытые на конец дня</span></header>
        <ul class="legend"><li><i :style="{ background: SERIES.open.color }"></i>В работе<b class="tabular">{{ data.totals.open_now }}</b></li></ul>
        <TimeSeries :labels="data.labels" :series="openSeries" caption="Незакрытые заявки на конец дня" />
      </article>
    </div>
    <div v-else class="dash-empty">{{ loading ? 'Загружаем графики…' : 'Графики пока недоступны.' }}</div>
  </section>
</template>

<style scoped>
.dash { display:grid; gap:18px; transition:opacity .15s ease }
.dash.loading .tiles,.dash.loading .panels { opacity:.55; transition:opacity .15s ease }
.dash-head { display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap }
.dash-head h2 { margin:0 }
.dash-head p { margin:4px 0 0; color:var(--muted); font-size:12.5px }
.dash-controls { display:flex; gap:10px; flex-wrap:wrap; align-items:center }
.seg { display:flex; gap:2px; padding:3px; border-radius:10px; background:var(--surface-2); border:1px solid var(--border) }
.seg button { min-height:30px; padding:4px 12px; background:transparent; color:var(--muted); font-size:12.5px; border-radius:8px }
.seg button:hover { color:var(--text); background:var(--hover); opacity:1 }
.seg button[aria-pressed=true] { background:var(--surface); color:var(--text); box-shadow:0 0 0 1px var(--border),0 1px 2px rgba(0,0,0,.2) }
.series { display:flex; gap:6px; flex-wrap:wrap }
.s-chip { min-height:32px; padding:4px 12px; gap:8px; border-radius:999px; background:transparent; color:var(--muted); border:1px solid var(--border); font-size:12.5px }
.s-chip i { width:14px; height:2px; border-radius:1px; transition:opacity .15s ease }
.s-chip:hover { color:var(--text); border-color:var(--border-strong); opacity:1 }
.s-chip[aria-pressed=true] { color:var(--text); border-color:var(--border-strong); background:var(--surface-2) }
.s-chip[aria-pressed=false] i { opacity:.3 }
.dash-warn { margin:0; background:var(--warning-bg); color:var(--warning); border-radius:var(--radius-control); padding:9px 12px; font-size:13px }

.tiles { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px }
.tile { display:grid; gap:6px; align-content:start; padding:14px 16px 10px; border:1px solid var(--border); border-radius:12px; background:var(--surface-2); min-width:0 }
.tile-label { display:flex; align-items:center; gap:8px; color:var(--muted); font-size:12.5px }
.tile-label i { width:12px; height:2px; border-radius:1px }
.tile strong { font-size:26px; font-weight:600; letter-spacing:-.03em; line-height:1.15; display:flex; align-items:baseline; gap:8px; flex-wrap:wrap }
.tile strong small { font-size:13px; font-weight:500; color:var(--muted); letter-spacing:0 }
.tile strong.none { font-size:16px; color:var(--muted); font-weight:500 }
.delta.up { color:var(--warning) } .delta.down { color:var(--success) }
.tile-note { margin:4px 0 6px; font-size:11.5px; color:var(--subtle); line-height:1.4 }
.tile :deep(.spark) { height:46px }

.panels { display:grid; grid-template-columns:minmax(0,1.6fr) minmax(0,1fr); gap:12px }
.panels.single { grid-template-columns:1fr }
.chart-panel { display:grid; gap:8px; padding:14px 16px 10px; border:1px solid var(--border); border-radius:12px; min-width:0 }
.chart-panel header { display:flex; align-items:baseline; gap:10px; flex-wrap:wrap }
.chart-panel h3 { margin:0; font-size:14px }
.chart-panel header span { color:var(--muted); font-size:12px }
.legend { display:flex; gap:14px; flex-wrap:wrap; list-style:none; margin:0; padding:0; font-size:12.5px; color:var(--muted) }
.legend li { display:flex; align-items:center; gap:7px }
.legend i { width:14px; height:2px; border-radius:1px }
.legend b { color:var(--text); font-weight:600 }
.dash-empty { padding:24px; text-align:center; color:var(--muted) }
@media (max-width:1100px) { .tiles { grid-template-columns:repeat(2,minmax(0,1fr)) } .panels { grid-template-columns:1fr } }
@media (max-width:560px) { .tiles { grid-template-columns:1fr 1fr; gap:8px } .tile { padding:12px } .tile strong { font-size:22px } .dash-controls { width:100% } }
@media (prefers-reduced-motion:reduce) { .dash,.dash.loading .tiles,.dash.loading .panels { transition:none } }
</style>
