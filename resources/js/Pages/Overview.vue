<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    CalendarCheck, ChevronDown, ChevronUp, CircleCheck, Clock, Cpu, EllipsisVertical, Gamepad2,
    Thermometer, Ticket, TriangleAlert, Wallet, Wrench,
} from 'lucide-vue-next';
import Shell from '../Layouts/Shell.vue';
import StackedBars from '../Components/Charts/StackedBars.vue';
import MiniBars from '../Components/Charts/MiniBars.vue';
import Sparkline from '../Components/Charts/Sparkline.vue';
import IcafeDashboard from '../Components/IcafeDashboard.vue';
import AnimatedNumber from '../Components/Charts/AnimatedNumber.vue';
import { markDelay, useEntered } from '../Components/Charts/motion';

type Urgent = { id: number; description: string; status: string; priority: string; club: string | null; equipment: string | null; created_at: string | null };
type Alert = { id: number; equipment_id: number; metric: string; opened_at: string; equipment: string; club: string };
type Overview = {
    generated_at: string;
    kpis: { open: number; created_30: number; created_change: number | null; closed_30: number; closed_change: number | null };
    monthly: { labels: string[]; closed: number[]; progress: number[]; new: number[] };
    work: { hours_30: number; costs: { currency: string; cost_minor: number }[]; first_response_minutes: number | null };
    created_daily: number[]; created_14: number; created_14_change: number | null;
    closed_daily: number[]; closed_14: number;
    inspections: { overdue: number; soon: number; planned: number; unscheduled: number };
    urgent: Urgent[]; alerts: Alert[]; alert_count: number;
    devices: { monitored: number; stale: number };
    published: { app_id: number; build_id: string | null; source_status: string; checked_at: string | null }[];
};
const props = defineProps<{ overview: Overview; financeClubs: { id: number; name: string; icafe_license: number | null; icafe_currency: string; timezone: string }[]; canManage: boolean }>();
const page = usePage<{ auth: { user: { name: string } } }>();
const o = computed(() => props.overview);

const nf = new Intl.NumberFormat('ru-RU');
const fmt = (n: number) => nf.format(n);
const money = (minor: number, currency: string) => new Intl.NumberFormat('ru-RU', { style: 'currency', currency, maximumFractionDigits: minor % 100 ? 2 : 0 }).format(minor / 100);
const duration = (m: number | null) => m === null ? 'Нет данных' : m < 60 ? `${m} мин` : `${Math.floor(m / 60)} ч ${m % 60} мин`;
const greeting = computed(() => {
    const h = Number(new Intl.DateTimeFormat('ru-RU', { hour: 'numeric', hour12: false, timeZone: 'Asia/Tashkent' }).format(new Date()));
    return h < 5 ? 'Доброй ночи' : h < 12 ? 'Доброе утро' : h < 18 ? 'Добрый день' : 'Добрый вечер';
});
const days = computed(() => {
    const end = new Date(o.value.generated_at);
    return Array.from({ length: 14 }, (_, i) => new Date(end.getTime() - (13 - i) * 864e5).toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', timeZone: 'Asia/Tashkent' }));
});
const series = computed(() => [
    { key: 'closed', label: 'Закрыты', color: 'var(--chart-1)', values: o.value.monthly.closed },
    { key: 'progress', label: 'В работе', color: 'var(--chart-2)', values: o.value.monthly.progress },
    { key: 'new', label: 'Новые', color: 'var(--chart-3)', values: o.value.monthly.new },
]);
const monthlyTotal = computed(() => series.value.reduce((n, s) => n + s.values.reduce((a, b) => a + b, 0), 0));
const inspectionTotal = computed(() => Object.values(o.value.inspections).reduce((a, b) => a + b, 0));
const share = (n: number) => inspectionTotal.value ? Math.round(n / inspectionTotal.value * 100) : 0;
const columns = computed(() => [
    { key: 'overdue', label: 'Просрочено', value: o.value.inspections.overdue, tone: 'var(--chart-4)' },
    { key: 'soon', label: 'На неделе', value: o.value.inspections.soon, tone: 'var(--chart-5)' },
    { key: 'planned', label: 'В графике', value: o.value.inspections.planned, tone: 'var(--chart-1)' },
]);
const statuses: Record<string, string> = { new: 'Новая', accepted: 'Принята', working: 'В работе', approval: 'Согласование', parts: 'Ждёт запчасти' };
const metrics: Record<string, string> = { cpu_temp: 'CPU °C', gpu_temp: 'GPU °C', cpu_load: 'CPU %', gpu_load: 'GPU %', ram_used_percent: 'RAM %', disk_free_percent: 'Диск', link_speed: 'Линк' };
const games: Record<number, string> = { 730: 'Counter-Strike 2', 570: 'Dota 2' };
const ago = (iso: string | null) => {
    if (!iso) return '';
    const m = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));
    return m < 60 ? `${m} мин назад` : m < 1440 ? `${Math.round(m / 60)} ч назад` : `${Math.round(m / 1440)} дн назад`;
};
const entered = useEntered();
const priorityTile: Record<string, string> = { critical: 'rose', high: 'amber', normal: 'blue', low: '' };
</script>

<template>
  <Head title="Обзор" />
  <Shell>
    <div class="page-header">
      <div><h1>{{ greeting }}, {{ page.props.auth.user.name.split(' ')[0] }}</h1><p>Что происходит в клубах сейчас и за последние 30 дней.</p></div>
      <div class="header-actions">
        <Link href="/tickets" class="button secondary"><Ticket aria-hidden="true" />Все заявки</Link>
        <Link href="/visits" class="button"><CalendarCheck aria-hidden="true" />Запланировать выезд</Link>
      </div>
    </div>

    <section class="overview-grid" aria-label="Ключевые показатели">
      <!-- Tickets by month + work summary -->
      <article class="card hero-card" style="--i:0">
        <div class="hero-chart">
          <div class="card-head"><div><h2>Заявки</h2><p>По месяцам создания и текущему статусу · {{ fmt(monthlyTotal) }} за 7 месяцев</p></div>
            <Link href="/reports" class="button card-menu" aria-label="Отчёты по заявкам"><EllipsisVertical aria-hidden="true" /></Link></div>
          <StackedBars :labels="o.monthly.labels" :series="series" caption="Заявки по месяцам" />
        </div>
        <div class="hero-report">
          <div class="card-head"><div><h2>Работы</h2><p>За последние 30 дней</p></div></div>
          <ul class="report-list stagger-in">
            <li><span class="icon-tile green"><Wrench aria-hidden="true" /></span><div><b>Трудозатраты</b><span><AnimatedNumber :value="o.work.hours_30" :decimals="1" /> ч</span></div></li>
            <li><span class="icon-tile blue"><Wallet aria-hidden="true" /></span><div><b>Расходы</b>
              <span v-if="o.work.costs.length">{{ o.work.costs.map(c => money(c.cost_minor, c.currency)).join(' · ') }}</span><span v-else>Не внесены</span></div></li>
            <li><span class="icon-tile violet"><Clock aria-hidden="true" /></span><div><b>Первый ответ</b><span>{{ duration(o.work.first_response_minutes) }} в среднем</span></div></li>
          </ul>
          <Link href="/reports" class="button report-button">Открыть отчёты</Link>
        </div>
      </article>

      <article class="card kpi lift" style="--i:1">
        <div class="kpi-top"><span class="icon-tile green"><Ticket aria-hidden="true" /></span>
          <span v-if="o.kpis.created_change !== null" class="trend" :title="'Новые заявки за 30 дней к предыдущим 30'">
            {{ o.kpis.created_change > 0 ? '+' : '' }}{{ o.kpis.created_change }}%<ChevronUp v-if="o.kpis.created_change >= 0" aria-hidden="true" /><ChevronDown v-else aria-hidden="true" /></span></div>
        <strong class="kpi-value"><AnimatedNumber :value="o.kpis.open" /></strong><span class="kpi-label">Открытых заявок</span>
        <span class="chip">{{ fmt(o.kpis.created_30) }} новых за 30 дней</span>
      </article>

      <article class="card kpi lift" style="--i:2">
        <div class="kpi-top"><span class="icon-tile amber"><CircleCheck aria-hidden="true" /></span>
          <span v-if="o.kpis.closed_change !== null" class="trend" title="Закрытые за 30 дней к предыдущим 30">
            {{ o.kpis.closed_change > 0 ? '+' : '' }}{{ o.kpis.closed_change }}%<ChevronUp v-if="o.kpis.closed_change >= 0" aria-hidden="true" /><ChevronDown v-else aria-hidden="true" /></span></div>
        <strong class="kpi-value"><AnimatedNumber :value="o.kpis.closed_30" /></strong><span class="kpi-label">Закрыто</span>
        <span class="chip">Последние 30 дней</span>
      </article>

      <article class="card kpi chart-kpi lift" style="--i:3">
        <div><strong class="kpi-inline"><AnimatedNumber :value="o.closed_14" /></strong><span class="kpi-label">Закрыто за 14 дней</span></div>
        <MiniBars :values="o.closed_daily" :days="days" label="Закрытые заявки по дням" />
      </article>

      <article class="card kpi chart-kpi lift" style="--i:4">
        <div><h2 class="kpi-title">Новые заявки</h2><span class="kpi-label">По дням, 14 дней</span></div>
        <Sparkline :values="o.created_daily" :days="days" label="Новые заявки по дням" />
        <div class="kpi-foot"><strong><AnimatedNumber :value="o.created_14" /></strong>
          <span v-if="o.created_14_change !== null" class="trend">{{ o.created_14_change > 0 ? '+' : '' }}{{ o.created_14_change }}%</span></div>
      </article>
    </section>

    <section class="overview-row">
      <article class="card inspections" style="--i:5">
        <div class="card-head"><div class="title-with-tile"><span class="icon-tile"><Cpu aria-hidden="true" /></span><h2>Осмотры ПК</h2></div>
          <Link href="/visits" class="button secondary small-button">Выезды</Link></div>
        <div class="big-number"><strong><AnimatedNumber :value="inspectionTotal" /></strong>
          <span v-if="o.inspections.overdue" class="chip">{{ share(o.inspections.overdue) }}% просрочено</span></div>
        <div class="inspection-cols">
          <div v-for="c in columns" :key="c.key" class="inspection-col">
            <span class="muted">{{ c.label }}</span><b><AnimatedNumber :value="share(c.value)" />%</b><small>{{ fmt(c.value) }} ПК</small>
            <div class="col-bar"><i class="grow-y" :class="{ in: entered }" :style="{ height: Math.max(c.value ? 6 : 0, share(c.value)) + '%', background: c.tone, transitionDelay: markDelay(columns.indexOf(c) * 3, 120) }"></i></div>
          </div>
        </div>
        <p v-if="o.inspections.unscheduled" class="muted footnote">Без даты осмотра: {{ fmt(o.inspections.unscheduled) }} ПК</p>
      </article>

      <article class="card lists" style="--i:6">
        <section>
          <div class="card-head"><h2>Срочные заявки</h2><Link href="/tickets" class="button card-menu" aria-label="Все заявки"><EllipsisVertical aria-hidden="true" /></Link></div>
          <p v-if="!o.urgent.length" class="list-empty">Открытых заявок нет.</p>
          <ul class="rows stagger-in">
            <li v-for="t in o.urgent" :key="t.id">
              <Link :href="'/tickets/' + t.id" class="row">
                <span class="icon-tile" :class="priorityTile[t.priority]"><Ticket aria-hidden="true" /></span>
                <span class="row-main"><b>{{ t.description }}</b><small>{{ t.club }}<template v-if="t.equipment"> · {{ t.equipment }}</template></small></span>
                <span class="row-side"><span class="badge" :data-status="t.status">{{ statuses[t.status] ?? t.status }}</span><small>{{ ago(t.created_at) }}</small></span>
              </Link>
            </li>
          </ul>
        </section>
        <section>
          <div class="card-head"><h2>Требуют внимания</h2><Link href="/monitoring" class="button card-menu" aria-label="Мониторинг"><EllipsisVertical aria-hidden="true" /></Link></div>
          <p v-if="!o.alerts.length" class="list-empty">Активных тревог нет<template v-if="o.devices.monitored">, {{ fmt(o.devices.monitored) }} устройств на связи</template>.</p>
          <ul class="rows stagger-in">
            <li v-for="a in o.alerts" :key="a.id">
              <Link :href="'/equipment/' + a.equipment_id" class="row">
                <span class="icon-tile rose"><Thermometer aria-hidden="true" /></span>
                <span class="row-main"><b>{{ a.equipment }}</b><small>{{ a.club }}</small></span>
                <span class="row-side"><span class="chip">{{ metrics[a.metric] ?? a.metric }}</span><small>{{ ago(a.opened_at) }}</small></span>
              </Link>
            </li>
            <li v-if="o.devices.stale">
              <Link href="/monitoring" class="row"><span class="icon-tile amber"><TriangleAlert aria-hidden="true" /></span>
                <span class="row-main"><b>Нет свежих данных</b><small>Связь с агентом старше 10 минут. Это не доказывает, что ПК выключен.</small></span>
                <span class="row-side"><span class="chip">{{ fmt(o.devices.stale) }}</span></span></Link>
            </li>
            <li v-for="p in o.published" :key="p.app_id">
              <Link href="/updates" class="row"><span class="icon-tile blue"><Gamepad2 aria-hidden="true" /></span>
                <span class="row-main"><b>{{ games[p.app_id] ?? p.app_id }}</b><small>Опубликованная сборка · {{ p.source_status === 'ok' ? 'источник доступен' : 'источник недоступен' }}</small></span>
                <span class="row-side"><span class="chip">{{ p.build_id ?? 'Нет данных' }}</span><small>{{ ago(p.checked_at) }}</small></span></Link>
            </li>
          </ul>
        </section>
      </article>
    </section>

    <IcafeDashboard v-if="financeClubs.length" :clubs="financeClubs" :can-manage="canManage" />
  </Shell>
</template>

<style scoped>
.header-actions { display:flex; gap:10px; flex-wrap:wrap }
.overview-grid { display:grid; grid-template-columns:minmax(0,2.1fr) minmax(0,1fr) minmax(0,1fr); grid-template-rows:auto auto; gap:20px; margin-bottom:20px }
.overview-grid .card { margin:0 }
.hero-card { grid-row:span 2; display:grid; grid-template-columns:minmax(0,1.5fr) minmax(0,1fr); padding:0 }
.hero-chart { padding:24px; border-right:1px solid var(--border); min-width:0 }
.hero-report { padding:24px; display:flex; flex-direction:column; min-width:0 }
.report-list { list-style:none; margin:8px 0 24px; padding:0; display:grid; gap:30px; flex:1; align-content:start }
.report-list li { display:flex; gap:14px; align-items:center }
.report-list b { display:block; font-weight:500; font-size:15px }
.report-list li>div>span { display:block; color:var(--muted); margin-top:3px; font-variant-numeric:tabular-nums; font-size:13.5px }
.report-button { width:100%; margin-top:auto; min-height:40px }

.kpi { display:flex; flex-direction:column; gap:6px; min-height:212px }
.kpi-top { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px }
.kpi .trend { color:var(--text) }
.kpi-value { font-size:24px; font-weight:600; letter-spacing:-.03em; font-variant-numeric:tabular-nums; line-height:1.1 }
.kpi-inline { display:block; font-size:21px; font-weight:600; letter-spacing:-.02em; font-variant-numeric:tabular-nums }
.kpi-title { font-size:20px; margin:0 }
.kpi-label { color:var(--muted); font-size:14px }
.kpi .chip { align-self:flex-start; margin-top:auto }
.chart-kpi { justify-content:space-between; gap:18px }
.kpi-foot { display:flex; justify-content:space-between; align-items:baseline }
.kpi-foot strong { font-size:20px; font-weight:600; font-variant-numeric:tabular-nums }

.overview-row { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,2.1fr); gap:20px; margin-bottom:20px }
.overview-row .card { margin:0 }
.title-with-tile { display:flex; align-items:center; gap:12px }
.title-with-tile .icon-tile { width:34px; height:34px; border-radius:10px }
.title-with-tile h2 { font-size:16px }
.small-button { min-height:30px; padding:4px 12px; font-size:12.5px }
.big-number { display:flex; align-items:center; gap:10px; padding-bottom:20px; border-bottom:1px solid var(--border) }
.big-number strong { font-size:26px; font-weight:600; letter-spacing:-.03em; font-variant-numeric:tabular-nums }
.inspection-cols { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); min-height:230px }
.inspection-col { display:flex; flex-direction:column; gap:6px; padding:16px 10px 0; border-right:1px dashed var(--border) }
.inspection-col:last-child { border-right:0 }
.inspection-col b { font-size:22px; font-weight:600; font-variant-numeric:tabular-nums }
.inspection-col>span { font-size:13px }
.col-bar { flex:1; display:flex; align-items:flex-end; margin-top:12px; min-height:90px }
.col-bar i { display:block; width:100%; border-radius:10px 10px 4px 4px; opacity:.9 }
.footnote { font-size:12.5px; margin:14px 0 0 }

.lists { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); padding:0 }
.lists>section { padding:24px; min-width:0 }
.lists>section+section { border-left:1px solid var(--border) }
.rows { list-style:none; margin:0; padding:0; display:grid; grid-template-columns:minmax(0,1fr); gap:4px }
.rows>li { min-width:0 }
.row { display:flex; align-items:center; gap:12px; padding:9px 8px; margin:0 -8px; border-radius:12px; transition:background-color .15s ease,transform var(--dur-fast) var(--ease-out) }
.row .icon-tile { transition:transform var(--dur-fast) var(--ease-out) }
@media (hover:hover) and (pointer:fine) and (prefers-reduced-motion:no-preference) {
  .row:hover { transform:translateX(2px) }
  .row:hover .icon-tile { transform:scale(1.04) }
}
.row:hover { background:var(--hover) }
.row-main { flex:1; min-width:0 }
.row-main b { display:block; font-weight:500; font-size:14.5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis }
.row-main small { display:block; margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis }
.row-side { display:flex; flex-direction:column; align-items:flex-end; gap:4px; flex:none }
.row-side small { font-size:11.5px }
.list-empty { color:var(--muted); margin:0 0 8px }

@media (max-width:1280px) {
  .overview-grid { grid-template-columns:repeat(2,minmax(0,1fr)) }
  .hero-card { grid-column:1/-1; grid-row:auto }
  .overview-row { grid-template-columns:1fr }
}
@media (max-width:760px) {
  .hero-card { grid-template-columns:1fr }
  .hero-chart { border-right:0; border-bottom:1px solid var(--border); padding:18px }
  .hero-report { padding:18px }
  .lists { grid-template-columns:1fr }
  .lists>section { padding:18px }
  .lists>section+section { border-left:0; border-top:1px solid var(--border) }
  .kpi { min-height:0 }
}
@media (max-width:520px) {
  .overview-grid { grid-template-columns:1fr }
  .header-actions { width:100% } .header-actions .button { flex:1 }
}
</style>
