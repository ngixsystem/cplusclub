<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Shell from '../Layouts/Shell.vue';
import TimeSeries from '../Components/Charts/TimeSeries.vue';

type Recent = { n: number; avg: number | null; max: number | null; loss: number | null; last: string } | null;
type Target = { id: number; name: string; host: string; port: number | null; max_rtt_ms: number; max_loss_pct: number; enabled: boolean; state: string; recent: Recent };
type S = { key: string; label: string; values: number[] };
const props = defineProps<{ club: { id: number; name: string }; clubs: { id: number; name: string }[]; days: number; labels: string[]; rtt: S[]; loss: S[]; targets: Target[]; agents: number; canManage: boolean }>();

const COLORS = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)'];
const color = (i: number) => COLORS[i % COLORS.length];
const rttSeries = computed(() => props.rtt.slice(0, 5).map((s, i) => ({ ...s, color: color(i) })));
const lossSeries = computed(() => props.loss.slice(0, 5).map((s, i) => ({ ...s, color: color(i) })));
const hasData = computed(() => props.rtt.some((s) => s.values.some((v) => v > 0)));
const go = (q: Record<string, number>) => router.get('/monitoring/latency', { club_id: props.club.id, days: props.days, ...q }, { preserveScroll: true });
const form = useForm({ club_id: props.club.id, name: '', host: '', port: '' as string | number, max_rtt_ms: 80, max_loss_pct: 5 });
const add = () => form.transform((d) => ({ ...d, club_id: props.club.id, port: d.port === '' ? null : Number(d.port) })).post('/monitoring/latency/targets', { preserveScroll: true, onSuccess: () => form.reset('name', 'host', 'port') });
const toggle = (t: Target) => router.post(`/monitoring/latency/targets/${t.id}`, { enabled: !t.enabled, max_rtt_ms: t.max_rtt_ms, max_loss_pct: t.max_loss_pct }, { preserveScroll: true });
const remove = (t: Target) => { if (window.confirm(`Удалить ${t.name}? История измерений тоже удалится.`)) router.post(`/monitoring/latency/targets/${t.id}`, { delete: true }, { preserveScroll: true }); };
const ago = (s: string) => { const m = Math.round((Date.now() - new Date(s).getTime()) / 60000); return m < 1 ? 'только что' : m < 60 ? `${m} мин назад` : `${Math.round(m / 60)} ч назад`; };
</script>

<template>
  <Head title="Пинг до игровых серверов" />
  <Shell>
    <div class="heading lt-head">
      <div>
        <p class="eyebrow"><Link href="/monitoring">Мониторинг</Link></p>
        <h1>Пинг до игровых серверов</h1>
        <p class="muted">Агент в клубе раз в минуту измеряет задержку и потери до каждого сервера. Тревога — после трёх плохих измерений подряд.</p>
      </div>
      <div class="lt-actions">
        <select v-if="clubs.length > 1" aria-label="Клуб" :value="club.id" @change="go({ club_id: Number(($event.target as HTMLSelectElement).value) })">
          <option v-for="c in clubs" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <div class="seg" role="group" aria-label="Период">
          <button v-for="d in [7, 30]" :key="d" type="button" :aria-pressed="days === d" :class="days === d ? '' : 'secondary'" @click="go({ days: d })">{{ d }} дней</button>
        </div>
        <a class="button secondary" :href="`/monitoring/latency/export?club_id=${club.id}&days=30`">CSV для провайдера</a>
      </div>
    </div>

    <p v-if="!agents" class="panel lt-warn" role="status">В клубе «{{ club.name }}» нет подключённого агента. Пинг измеряет агент C+CLub на ПК или сервере клуба — зарегистрируйте его в разделе «Мониторинг» и укажите в конфигурации <code>"latency": true</code>.</p>

    <section v-if="targets.length" class="lt-grid">
      <article class="panel">
        <h2>Средний пинг по дням, мс</h2>
        <TimeSeries v-if="hasData" :labels="labels" :series="rttSeries" :fill="false" caption="Средний пинг до серверов по дням, миллисекунды" />
        <p v-else class="muted">Измерений ещё нет.</p>
      </article>
      <article class="panel">
        <h2>Потери пакетов по дням, %</h2>
        <TimeSeries v-if="hasData" :labels="labels" :series="lossSeries" caption="Потери пакетов по дням, проценты" />
        <p v-else class="muted">Измерений ещё нет.</p>
      </article>
    </section>

    <section class="panel">
      <h2>Серверы</h2>
      <p v-if="!targets.length" class="muted">Серверы не добавлены. Добавьте адреса игровых серверов или релеев, которыми пользуются игроки клуба.</p>
      <table v-else class="lt-table">
        <thead><tr><th>Сервер</th><th>Сутки: пинг сред. / макс.</th><th>Потери</th><th>Норма</th><th>Состояние</th><th v-if="canManage"><span class="sr-only">Действия</span></th></tr></thead>
        <tbody>
          <tr v-for="(t, i) in targets" :key="t.id" :class="{ off: !t.enabled }">
            <td><i class="lt-swatch" :style="{ background: color(i) }" aria-hidden="true"></i><b>{{ t.name }}</b><small>{{ t.host }}{{ t.port ? ':' + t.port : '' }}</small></td>
            <td class="num">{{ t.recent?.avg ?? '—' }} / {{ t.recent?.max ?? '—' }} мс<small v-if="t.recent">{{ ago(t.recent.last) }}</small></td>
            <td class="num">{{ t.recent?.loss ?? '—' }}<template v-if="t.recent">%</template></td>
            <td class="num">≤ {{ t.max_rtt_ms }} мс · ≤ {{ t.max_loss_pct }}%</td>
            <td><span class="badge" :data-tone="!t.enabled ? undefined : t.state === 'problem' ? 'danger' : 'success'">{{ !t.enabled ? 'выключен' : t.state === 'problem' ? 'проблема' : 'норма' }}</span></td>
            <td v-if="canManage" class="lt-row-actions"><button type="button" class="secondary small" @click="toggle(t)">{{ t.enabled ? 'Выключить' : 'Включить' }}</button><button type="button" class="ghost small" @click="remove(t)">Удалить</button></td>
          </tr>
        </tbody>
      </table>

      <form v-if="canManage" class="lt-form" @submit.prevent="add">
        <h3>Добавить сервер</h3>
        <div class="form-grid">
          <label>Название<input v-model="form.name" required maxlength="80" placeholder="CS2 · Стокгольм" /></label>
          <label>Адрес (IP или домен)<input v-model="form.host" required maxlength="253" placeholder="203.0.113.10" /></label>
          <label>TCP-порт <small>(пусто — ping)</small><input v-model="form.port" type="number" min="1" max="65535" /></label>
          <label>Норма пинга, мс<input v-model.number="form.max_rtt_ms" type="number" min="5" max="1000" required /></label>
          <label>Допустимые потери, %<input v-model.number="form.max_loss_pct" type="number" min="0" max="50" required /></label>
        </div>
        <button :disabled="form.processing">Добавить</button>
        <p v-for="e in form.errors" :key="e" class="error" role="alert">{{ e }}</p>
      </form>
    </section>
  </Shell>
</template>

<style scoped>
.lt-head { align-items: flex-end; flex-wrap: wrap; gap: 16px }
.lt-head p.muted { margin: 6px 0 0; max-width: 62ch }
.lt-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center }
.seg { display: inline-flex; gap: 4px }
.lt-warn { border-color: var(--warning, #c98a00) }
.lt-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px }
.lt-grid .panel { margin: 0; min-width: 0 }
.panel h2 { margin: 0 0 12px; font-size: 16px }
.lt-table { width: 100%; border-collapse: collapse; font-size: 14px }
.lt-table th, .lt-table td { padding: 10px 8px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top }
.lt-table th { color: var(--muted); font-weight: 500; font-size: 12.5px }
.lt-table td small { display: block; color: var(--muted); font-size: 12px }
.lt-table .num { font-variant-numeric: tabular-nums }
.lt-table tr.off { opacity: .6 }
.lt-swatch { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 8px }
.lt-row-actions { display: flex; gap: 6px; justify-content: flex-end }
.lt-form { margin-top: 18px; display: grid; gap: 12px }
.lt-form h3 { margin: 0; font-size: 14px }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0) }
@media (max-width: 860px) { .lt-grid { grid-template-columns: 1fr } .lt-table { display: block; overflow-x: auto } }
</style>
