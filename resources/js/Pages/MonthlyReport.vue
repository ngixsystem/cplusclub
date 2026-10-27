<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import Shell from '../Layouts/Shell.vue';

type Report = {
  month: string; label: string; club: { id: number; name: string };
  tickets: { created: number; closed: number; open_at_end: number; from_telegram: number; within_2h: number | null; within_24h: number | null;
    median_resolve: number | null; median_response: number | null; categories: { category: string; n: number }[]; priorities: Record<string, number> };
  rating: { count: number; avg: number | null };
  service: { visits: number; inspections: number; cleanings: number; other: number };
  monitoring: { link: number; temperature: number; fixed_by_staff: number; link_tickets: number };
  top: { id: number; name: string; place: string | null; zone: string | null; tickets: number; alerts: number }[];
};
const props = defineProps<{ report: Report; clubs: { id: number; name: string }[]; months: { value: string; label: string }[] }>();

const t = computed(() => props.report.tickets);
const maxCategory = computed(() => Math.max(1, ...t.value.categories.map((c) => Number(c.n))));
const priorityLabel: Record<string, string> = { critical: 'Критичные', high: 'Высокий', normal: 'Обычный', low: 'Низкий' };
const minutes = (m: number | null) => m === null ? '—' : m < 60 ? `${m} мин` : m < 2880 ? `${Math.floor(m / 60)} ч${m % 60 ? ' ' + (m % 60) + ' мин' : ''}` : `${(m / 1440).toFixed(1)} дн`;
const pct = (n: number | null) => n === null ? '—' : `${n}%`;
const go = (q: { club_id?: number; month?: string }) => router.get('/reports/monthly', { club_id: props.report.club.id, month: props.report.month, ...q }, { preserveScroll: true });
const print = () => window.print();
</script>

<template>
  <Head :title="`Отчёт ${report.club.name} · ${report.label}`" />
  <Shell>
    <div class="heading mr-heading">
      <div>
        <p class="eyebrow">Отчёт за месяц</p>
        <h1>{{ report.club.name }} · {{ report.label }}</h1>
        <p class="mr-sub">Что сделала команда C+CLub: заявки, выезды, чистки и мониторинг. Время — по часовому поясу клуба.</p>
      </div>
      <div class="mr-actions no-print">
        <select v-if="clubs.length > 1" aria-label="Клуб" :value="report.club.id" @change="go({ club_id: Number(($event.target as HTMLSelectElement).value) })">
          <option v-for="c in clubs" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <select aria-label="Месяц" :value="report.month" @change="go({ month: ($event.target as HTMLSelectElement).value })">
          <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
        </select>
        <button type="button" @click="print">Сохранить PDF</button>
      </div>
    </div>

    <section class="stats mr-stats" aria-label="Главное">
      <article><span>Заявок за месяц</span><strong>{{ t.created }}</strong><small>закрыто {{ t.closed }}<template v-if="t.open_at_end"> · в работе {{ t.open_at_end }}</template></small></article>
      <article><span>Решено за 2 часа</span><strong>{{ pct(t.within_2h) }}</strong><small>за сутки — {{ pct(t.within_24h) }}</small></article>
      <article><span>Обычное время решения</span><strong>{{ minutes(t.median_resolve) }}</strong><small>первый ответ — {{ minutes(t.median_response) }}</small></article>
      <article><span>Оценка клиента</span><strong>{{ report.rating.avg ?? '—' }}<small v-if="report.rating.avg"> / 5</small></strong><small>{{ report.rating.count ? `оценок: ${report.rating.count}` : 'оценок пока нет' }}</small></article>
    </section>

    <div class="mr-grid">
      <section class="panel">
        <h2>Заявки по категориям</h2>
        <p v-if="!t.categories.length" class="muted">Заявок в этом месяце не было.</p>
        <ul v-else class="mr-bars">
          <li v-for="c in t.categories" :key="c.category">
            <span class="mr-bar-label">{{ c.category }}</span>
            <span class="mr-bar"><i :style="{ width: (Number(c.n) / maxCategory) * 100 + '%' }"></i></span>
            <b>{{ c.n }}</b>
          </li>
        </ul>
        <p v-if="Object.keys(t.priorities).length" class="mr-note">
          <template v-for="(label, key) in priorityLabel" :key="key"><span v-if="t.priorities[key]">{{ label }}: {{ t.priorities[key] }}</span></template>
          <span v-if="t.from_telegram">Из Telegram: {{ t.from_telegram }}</span>
        </p>
      </section>

      <section class="panel">
        <h2>Обслуживание</h2>
        <dl class="mr-list">
          <div><dt>Выездов специалиста</dt><dd>{{ report.service.visits }}</dd></div>
          <div><dt>Осмотров ПК</dt><dd>{{ report.service.inspections }}</dd></div>
          <div><dt>Чисток ПК</dt><dd>{{ report.service.cleanings }}</dd></div>
          <div v-if="report.service.other"><dt>Разборок и замен</dt><dd>{{ report.service.other }}</dd></div>
        </dl>
        <h2 class="mr-h2">Мониторинг</h2>
        <dl class="mr-list">
          <div><dt>Падений сетевого линка</dt><dd>{{ report.monitoring.link }}</dd></div>
          <div><dt>Перегревов CPU/GPU</dt><dd>{{ report.monitoring.temperature }}</dd></div>
          <div v-if="report.monitoring.fixed_by_staff"><dt>Кабель переподключили сами</dt><dd>{{ report.monitoring.fixed_by_staff }}</dd></div>
          <div v-if="report.monitoring.link_tickets"><dt>Заявок по сети автоматически</dt><dd>{{ report.monitoring.link_tickets }}</dd></div>
        </dl>
      </section>
    </div>

    <section class="panel">
      <h2>ПК, которым нужно внимание</h2>
      <p v-if="!report.top.length" class="muted">Ни один ПК не выделялся проблемами в этом месяце.</p>
      <table v-else class="mr-table">
        <thead><tr><th>ПК</th><th>Место</th><th class="num">Заявок</th><th class="num">Тревог</th></tr></thead>
        <tbody>
          <tr v-for="p in report.top" :key="p.id">
            <td><Link :href="`/equipment/${p.id}`">{{ p.name }}</Link></td>
            <td>{{ [p.place ? 'место ' + p.place : null, p.zone].filter(Boolean).join(' · ') || '—' }}</td>
            <td class="num">{{ p.tickets }}</td><td class="num">{{ p.alerts }}</td>
          </tr>
        </tbody>
      </table>
    </section>
    <p class="mr-foot">C+CLub · сформировано {{ new Date().toLocaleDateString('ru-RU') }}</p>
  </Shell>
</template>

<style scoped>
.mr-heading { align-items: flex-end; gap: 16px; flex-wrap: wrap }
.mr-sub { margin: 6px 0 0; color: var(--muted); max-width: 60ch }
.mr-actions { display: flex; gap: 8px; flex-wrap: wrap }
.mr-stats small { display: block; margin-top: 6px; color: var(--muted); font-size: 12.5px; font-weight: 400 }
.mr-stats strong small { display: inline; font-size: 14px }
.mr-grid { display: grid; grid-template-columns: 1.3fr 1fr; gap: 16px; margin-bottom: 16px }
.mr-grid .panel { margin: 0 }
.panel h2 { margin: 0 0 14px; font-size: 16px }
.mr-h2 { margin-top: 22px !important }
.mr-bars { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px }
.mr-bars li { display: grid; grid-template-columns: minmax(110px, 38%) 1fr 32px; align-items: center; gap: 12px; font-size: 13.5px }
.mr-bar-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
.mr-bar { height: 10px; border-radius: 0 4px 4px 0; background: transparent }
.mr-bar i { display: block; height: 100%; min-width: 3px; border-radius: 0 4px 4px 0; background: var(--chart-1) }
.mr-bars b { text-align: right; font-variant-numeric: tabular-nums }
.mr-note { margin: 16px 0 0; display: flex; gap: 14px; flex-wrap: wrap; color: var(--muted); font-size: 12.5px }
.mr-list { margin: 0; display: grid; gap: 8px }
.mr-list div { display: flex; justify-content: space-between; gap: 12px; padding-bottom: 8px; border-bottom: 1px solid var(--border) }
.mr-list dt { color: var(--muted) }
.mr-list dd { margin: 0; font-weight: 600; font-variant-numeric: tabular-nums }
.mr-table { width: 100%; border-collapse: collapse; font-size: 14px }
.mr-table th, .mr-table td { padding: 10px 8px; border-bottom: 1px solid var(--border); text-align: left }
.mr-table th { color: var(--muted); font-weight: 500; font-size: 12.5px }
.mr-table .num { text-align: right; font-variant-numeric: tabular-nums }
.mr-foot { color: var(--muted); font-size: 12px; margin-top: 16px }
@media (max-width: 860px) { .mr-grid { grid-template-columns: 1fr } }
@media print {
  :global(.sidebar), :global(.topbar), :global(.skip-link), .no-print { display: none !important }
  :global(.shell) { display: block !important }
  :global(body) { background: #fff !important; color: #111 !important }
  .mr-stats { grid-template-columns: repeat(4, 1fr) !important }
  .mr-grid { grid-template-columns: 1.3fr 1fr !important }
  .panel, .mr-stats article { break-inside: avoid; box-shadow: none !important }
}
</style>
