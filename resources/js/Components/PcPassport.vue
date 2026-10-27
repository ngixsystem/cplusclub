<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import TimeSeries from './Charts/TimeSeries.vue';

type Item = { kind: string; at: string; title: string; text: string | null; meta: string | null; href: string | null; tone: string };
export type Passport = {
  timeline: Item[];
  charts: { labels: string[]; cpu: number[]; gpu: number[]; link: number[]; has_link: boolean };
  hardware: Record<string, string>;
  hardware_checked_at: string | null;
  stats: { tickets: number; recent: number; open: number; alerts: number };
};
const props = defineProps<{ passport: Passport; tz?: string }>();

const HW: Record<string, string> = { cpu: 'Процессор', motherboard: 'Плата', gpu: 'Видеокарта', ram: 'Память', disks: 'Диски', nic: 'Сеть' };
const KIND: Record<string, string> = { ticket: 'Заявка', fix: 'Решение', alert: 'Мониторинг', service: 'Обслуживание', change: 'Железо' };
const temps = computed(() => [
  { key: 'cpu', label: 'CPU, °C', color: 'var(--chart-2)', values: props.passport.charts.cpu },
  { key: 'gpu', label: 'GPU, °C', color: 'var(--chart-1)', values: props.passport.charts.gpu },
]);
const link = computed(() => [{ key: 'link', label: 'Мин. линк, Мбит/с', color: 'var(--chart-3)', values: props.passport.charts.link }]);
const when = (s: string) => new Date(s).toLocaleString('ru-RU', { timeZone: props.tz ?? 'Asia/Tashkent', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
</script>

<template>
  <section class="panel passport" aria-labelledby="passport-title">
    <header class="pp-head">
      <h2 id="passport-title">Паспорт ПК</h2>
      <p v-if="passport.hardware_checked_at" class="muted">Железо сверено с iCafeCloud Boot {{ when(passport.hardware_checked_at) }}</p>
    </header>
    <dl class="pp-stats">
      <div><dt>Заявок всего</dt><dd>{{ passport.stats.tickets }}</dd></div>
      <div><dt>За 90 дней</dt><dd>{{ passport.stats.recent }}</dd></div>
      <div><dt>Открыто сейчас</dt><dd :data-tone="passport.stats.open ? 'warn' : undefined">{{ passport.stats.open }}</dd></div>
      <div><dt>Тревог за 90 дней</dt><dd>{{ passport.stats.alerts }}</dd></div>
    </dl>
    <dl v-if="Object.keys(passport.hardware).length" class="pp-hw">
      <div v-for="(v, k) in passport.hardware" :key="k"><dt>{{ HW[k] ?? k }}</dt><dd>{{ v }}</dd></div>
    </dl>

    <div v-if="passport.charts.labels.length > 1" class="pp-charts">
      <article><h3>Температура, максимум за день</h3><TimeSeries :labels="passport.charts.labels" :series="temps" :fill="false" :height="180" caption="Максимальная температура CPU и GPU по дням за 30 дней" /></article>
      <article v-if="passport.charts.has_link"><h3>Сетевой линк, минимум за день</h3><TimeSeries :labels="passport.charts.labels" :series="link" :height="180" caption="Минимальная скорость сетевого линка по дням" /></article>
    </div>

    <h3 class="pp-h3">История</h3>
    <p v-if="!passport.timeline.length" class="muted">Событий по этому ПК пока нет.</p>
    <ol v-else class="pp-timeline">
      <li v-for="(i, n) in passport.timeline" :key="n" :data-tone="i.tone">
        <span class="pp-dot" aria-hidden="true"></span>
        <div>
          <p class="pp-row"><span class="pp-kind">{{ KIND[i.kind] ?? i.kind }}</span><time :datetime="i.at">{{ when(i.at) }}</time></p>
          <p class="pp-title"><Link v-if="i.href" :href="i.href">{{ i.title }}</Link><template v-else>{{ i.title }}</template><small v-if="i.meta"> · {{ i.meta }}</small></p>
          <p v-if="i.text" class="pp-text">{{ i.text }}</p>
        </div>
      </li>
    </ol>
  </section>
</template>

<style scoped>
.passport { display: grid; gap: 18px }
.pp-head { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; flex-wrap: wrap }
.pp-head h2 { margin: 0 }
.pp-head p { margin: 0; font-size: 12.5px }
.pp-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin: 0 }
.pp-stats div { background: var(--surface-2); border: 1px solid var(--border); border-radius: 12px; padding: 12px 14px }
.pp-stats dt { font-size: 12px; color: var(--muted) }
.pp-stats dd { margin: 4px 0 0; font-size: 22px; font-weight: 600; font-variant-numeric: tabular-nums }
.pp-stats dd[data-tone=warn] { color: var(--warning, #c98a00) }
.pp-hw { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 8px 20px; margin: 0 }
.pp-hw div { border-bottom: 1px solid var(--border); padding-bottom: 8px }
.pp-hw dt { font-size: 12px; color: var(--muted) }
.pp-hw dd { margin: 2px 0 0; font-size: 13.5px }
.pp-charts { display: grid; grid-template-columns: 1fr 1fr; gap: 16px }
.pp-charts article { min-width: 0 }
.pp-charts h3, .pp-h3 { margin: 0 0 8px; font-size: 14px }
.pp-timeline { list-style: none; margin: 0; padding: 0; display: grid }
.pp-timeline li { display: grid; grid-template-columns: 18px 1fr; gap: 10px; position: relative; padding-bottom: 14px }
.pp-timeline li::before { content: ''; position: absolute; left: 8px; top: 16px; bottom: 0; width: 2px; background: var(--border) }
.pp-timeline li:last-child::before { display: none }
.pp-dot { width: 10px; height: 10px; border-radius: 50%; margin: 5px 0 0 4px; background: var(--muted); box-shadow: 0 0 0 3px var(--surface) }
li[data-tone=ok] .pp-dot { background: var(--success, #2e9d5b) }
li[data-tone=bad] .pp-dot { background: var(--danger, #d64545) }
li[data-tone=warn] .pp-dot { background: var(--warning, #c98a00) }
.pp-timeline p { margin: 0 }
.pp-row { display: flex; gap: 10px; font-size: 12px; color: var(--muted) }
.pp-kind { text-transform: uppercase; letter-spacing: .04em; font-size: 11px }
.pp-title { font-weight: 600; font-size: 14px; margin-top: 2px !important }
.pp-title small { font-weight: 400; color: var(--muted) }
.pp-text { color: var(--muted); font-size: 13px; margin-top: 2px !important; white-space: pre-line }
@media (max-width: 760px) { .pp-stats { grid-template-columns: repeat(2, minmax(0, 1fr)) } .pp-charts { grid-template-columns: 1fr } }
</style>
