<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Gamepad2 } from 'lucide-vue-next';
import TimeSeries from './Charts/TimeSeries.vue';

type Pc = { id: number; name: string; zone: string | null };
type Live = { on: number; total: number; playing: number; apps: number; idle: number; games: { name: string; count: number; pcs: Pc[] }[];
  machines: (Pc & { kind: string; title: string | null })[]; updated_at: string };
type Stats = { period: string; period_label: string; total_hours: number; top: { name: string; hours: number; share: number }[];
  trend: { mode: string; labels: string[]; series: { key: string; label: string; values: number[] }[]; leaders: { bucket: string; game: string | null; hours: number }[] } };

const props = defineProps<{ clubs: { id: number; name: string }[] }>();
const KEY = 'cclub-games';
const saved = (() => { try { return JSON.parse(localStorage.getItem(KEY) ?? '{}'); } catch { return {}; } })();
const club = ref<number>(props.clubs.some(c => c.id === saved.club) ? saved.club : props.clubs[0]?.id ?? 0);
const period = ref<string>(saved.period ?? 'today');
const trend = ref<string>(saved.trend ?? 'days');
const view = ref<'games' | 'pcs'>('games');
const live = ref<Live | null>(null), stats = ref<Stats | null>(null), open = ref<string | null>(null), error = ref('');
watch([club, period, trend], () => { try { localStorage.setItem(KEY, JSON.stringify({ club: club.value, period: period.value, trend: trend.value })); } catch { /* private mode */ } });

const PERIODS = [{ v: 'today', l: 'Сегодня' }, { v: 'yesterday', l: 'Вчера' }, { v: 'week', l: 'Эта неделя' }, { v: 'last_week', l: 'Прошлая неделя' }, { v: 'month', l: '30 дней' }];
const COLORS = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)'];
const maxLive = computed(() => Math.max(1, ...(live.value?.games.map(g => g.count) ?? [1])));
const maxTop = computed(() => Math.max(1, ...(stats.value?.top.map(g => g.hours) ?? [1])));
const series = computed(() => (stats.value?.trend.series ?? []).map((s, i) => ({ ...s, color: COLORS[i % COLORS.length] })));
const hasTrend = computed(() => series.value.some(s => s.values.some(v => v > 0)));
const hours = (h: number) => new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 }).format(h) + ' ч';
const bucket = (b: string) => {
  const d = new Date(b + 'T00:00:00');
  return trend.value === 'weeks' ? 'нед. с ' + d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }).replace('.', '')
    : d.toLocaleDateString('ru-RU', { weekday: 'short', day: 'numeric' });
};
const ago = computed(() => live.value ? new Date(live.value.updated_at).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }) : '');

let timer: ReturnType<typeof setTimeout> | undefined, liveCtl: AbortController | undefined, statsCtl: AbortController | undefined;
async function get<T>(url: string, ctl: AbortController): Promise<T | null> {
  const r = await fetch(url, { signal: ctl.signal, headers: { Accept: 'application/json' } });
  if (r.status === 401) { window.location.assign('/login'); return null; }
  if (!r.ok) throw new Error();
  return r.json();
}
async function loadLive() {
  clearTimeout(timer);
  if (!club.value || document.hidden) return;
  liveCtl?.abort(); const c = new AbortController(); liveCtl = c;
  try { const d = await get<Live>('/games/live?club_id=' + club.value, c); if (d && !c.signal.aborted) { live.value = d; error.value = ''; } }
  catch { if (!c.signal.aborted) error.value = 'Не удалось обновить данные об играх.'; }
  finally { if (liveCtl === c) timer = setTimeout(loadLive, 30000); }
}
async function loadStats() {
  if (!club.value) return;
  statsCtl?.abort(); const c = new AbortController(); statsCtl = c;
  try { const d = await get<Stats>(`/games/stats?club_id=${club.value}&period=${period.value}&trend=${trend.value}`, c); if (d && !c.signal.aborted) stats.value = d; }
  catch { if (!c.signal.aborted) error.value = 'Не удалось загрузить статистику игр.'; }
}
watch(club, () => { live.value = null; stats.value = null; open.value = null; void loadLive(); void loadStats(); });
watch([period, trend], () => void loadStats());
const visible = () => { if (!document.hidden) void loadLive(); };
onMounted(() => { void loadLive(); void loadStats(); document.addEventListener('visibilitychange', visible); });
onUnmounted(() => { clearTimeout(timer); liveCtl?.abort(); statsCtl?.abort(); document.removeEventListener('visibilitychange', visible); });
</script>

<template>
  <section class="card games-card" aria-labelledby="games-title">
    <div class="card-head">
      <div class="title-with-tile"><span class="icon-tile"><Gamepad2 aria-hidden="true" /></span>
        <div><h2 id="games-title">Игры в клубе</h2><p class="games-sub">Что запущено на компьютерах сейчас и во что играют больше всего</p></div></div>
      <label v-if="clubs.length > 1" class="games-club"><span class="sr-only">Клуб</span>
        <select v-model.number="club"><option v-for="c in clubs" :key="c.id" :value="c.id">{{ c.name }}</option></select></label>
    </div>
    <p v-if="error" class="games-error" role="alert">{{ error }}</p>

    <div class="games-grid">
      <!-- Now -->
      <article class="games-panel" aria-labelledby="games-now">
        <header class="games-panel-head">
          <h3 id="games-now"><span class="live-dot" aria-hidden="true"></span>Сейчас</h3>
          <div class="seg seg-sm" role="group" aria-label="Вид">
            <button type="button" :aria-pressed="view === 'games'" @click="view = 'games'">По играм</button>
            <button type="button" :aria-pressed="view === 'pcs'" @click="view = 'pcs'">По ПК</button>
          </div>
        </header>
        <template v-if="live">
          <p class="games-kpi"><strong>{{ live.playing }}</strong><span>играют из {{ live.on }} включённых ПК<template v-if="live.total > live.on"> · всего {{ live.total }}</template></span></p>
          <p v-if="live.games.length" class="games-leader">Больше всего сейчас: <b>{{ live.games[0].name }}</b> — {{ live.games[0].count }} ПК</p>

          <p v-if="!live.on" class="muted games-empty">Нет свежих данных с компьютеров. Данные приходят из iCafeCloud Boot раз в минуту.</p>
          <p v-else-if="view === 'games' && !live.games.length" class="muted games-empty">Сейчас никто не играет.</p>

          <ol v-if="view === 'games' && live.games.length" class="rank">
            <li v-for="g in live.games" :key="g.name">
              <button type="button" class="rank-row" :aria-expanded="open === g.name" @click="open = open === g.name ? null : g.name">
                <span class="rank-name">{{ g.name }}</span>
                <span class="rank-bar" aria-hidden="true"><i :style="{ transform: `scaleX(${g.count / maxLive})` }"></i></span>
                <b class="rank-val">{{ g.count }} ПК</b>
              </button>
              <div class="expand" :data-open="open === g.name">
                <div><ul class="pc-chips"><li v-for="p in g.pcs" :key="p.id"><a :href="`/equipment/${p.id}`">{{ p.name }}</a></li></ul></div>
              </div>
            </li>
          </ol>
          <ul v-if="view === 'pcs' && live.machines.length" class="pc-list">
            <li v-for="m in live.machines" :key="m.id" :data-kind="m.kind">
              <a :href="`/equipment/${m.id}`">{{ m.name }}</a><span>{{ m.title ?? 'Ничего не запущено' }}</span>
            </li>
          </ul>
          <p class="games-foot">Ещё {{ live.apps }} ПК в лаунчерах, браузере или меню · {{ live.idle }} без запущенных программ · обновлено {{ ago }}</p>
        </template>
        <p v-else class="muted games-empty">Загружаем…</p>
      </article>

      <!-- Top -->
      <article class="games-panel" aria-labelledby="games-top">
        <header class="games-panel-head"><h3 id="games-top">Топ игр</h3>
          <span v-if="stats" class="muted games-total">{{ hours(stats.total_hours) }} игры · {{ stats.period_label }}</span></header>
        <div class="seg seg-sm seg-wrap" role="group" aria-label="Период">
          <button v-for="p in PERIODS" :key="p.v" type="button" :aria-pressed="period === p.v" @click="period = p.v">{{ p.l }}</button>
        </div>
        <p v-if="stats && !stats.top.length" class="muted games-empty">За этот период данных нет. Статистика собирается с момента подключения.</p>
        <ol v-else-if="stats" class="rank rank-top">
          <li v-for="(g, i) in stats.top.slice(0, 10)" :key="g.name" class="rank-row static">
            <span class="rank-no">{{ i + 1 }}</span>
            <span class="rank-name">{{ g.name }}</span>
            <span class="rank-bar" aria-hidden="true"><i :style="{ transform: `scaleX(${g.hours / maxTop})` }"></i></span>
            <b class="rank-val">{{ hours(g.hours) }}<small>{{ g.share }}%</small></b>
          </li>
        </ol>
      </article>
    </div>

    <!-- Trend -->
    <article class="games-panel games-trend" aria-labelledby="games-trend">
      <header class="games-panel-head"><h3 id="games-trend">Динамика топ-5 игр, часов игры</h3>
        <div class="seg seg-sm" role="group" aria-label="Шаг">
          <button type="button" :aria-pressed="trend === 'days'" @click="trend = 'days'">По дням</button>
          <button type="button" :aria-pressed="trend === 'weeks'" @click="trend = 'weeks'">По неделям</button>
        </div>
      </header>
      <TimeSeries v-if="stats && hasTrend" :labels="stats.trend.labels" :series="series" :fill="false" :height="200"
        :caption="trend === 'weeks' ? 'Часы игры по неделям за 8 недель' : 'Часы игры по дням за 14 дней'" />
      <p v-else-if="stats" class="muted games-empty">Данных пока мало — график появится после первых часов сбора.</p>
      <ol v-if="stats && hasTrend" class="leaders" :aria-label="trend === 'weeks' ? 'Лидер каждой недели' : 'Лидер каждого дня'">
        <li v-for="l in stats.trend.leaders.filter(x => x.game).slice().reverse()" :key="l.bucket"><span>{{ bucket(l.bucket) }}</span><b>{{ l.game }}</b><small>{{ hours(l.hours) }}</small></li>
      </ol>
    </article>
  </section>
</template>

<style scoped>
.games-card { margin-bottom: 20px }
.title-with-tile { display: flex; align-items: center; gap: 12px }
.title-with-tile .icon-tile { width: 34px; height: 34px; border-radius: 10px }
.title-with-tile h2 { font-size: 16px; margin: 0 }
.expand { display: grid; grid-template-rows: 0fr; opacity: 0; transition: grid-template-rows 220ms var(--ease-out), opacity 180ms var(--ease-out) }
.expand[data-open=true] { grid-template-rows: 1fr; opacity: 1 }
.expand > div { overflow: hidden }
.games-sub { margin: 4px 0 0; color: var(--muted); font-size: 13px }
.games-club select { min-width: 180px }
.games-error { color: var(--error); font-size: 13px }
.games-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 16px; margin-bottom: 16px }
.games-panel { background: var(--surface-2); border: 1px solid var(--border); border-radius: 14px; padding: 16px 18px; min-width: 0 }
.games-panel-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px }
.games-panel h3 { margin: 0; font-size: 14px; font-weight: 600; display: flex; align-items: center; gap: 8px }
.live-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--success); box-shadow: 0 0 0 3px color-mix(in srgb, var(--success) 25%, transparent) }
.games-kpi { display: flex; align-items: baseline; gap: 10px; margin: 0 0 4px }
.games-kpi strong { font-size: 34px; font-weight: 600; font-variant-numeric: tabular-nums; line-height: 1 }
.games-kpi span { color: var(--muted); font-size: 13px }
.games-leader { margin: 0 0 14px; font-size: 13.5px }
.games-empty { font-size: 13px; margin: 8px 0 }
.games-total { font-size: 12.5px }
.games-foot { margin: 12px 0 0; color: var(--muted); font-size: 12px }
.seg { display: inline-flex; gap: 2px; padding: 2px; border-radius: 10px; background: var(--surface); border: 1px solid var(--border) }
.seg-wrap { flex-wrap: wrap; margin-bottom: 12px }
.seg button { background: transparent; color: var(--muted); border: 0; padding: 5px 10px; font-size: 12.5px; border-radius: 8px; min-height: 0; box-shadow: none }
.seg button[aria-pressed=true] { background: var(--surface-2); color: var(--text); box-shadow: 0 1px 2px rgb(0 0 0 / .12) }
.rank { list-style: none; margin: 0; padding: 0; display: grid; gap: 4px }
.rank-row { width: 100%; display: grid; grid-template-columns: minmax(90px, 38%) 1fr auto; align-items: center; gap: 12px; padding: 7px 8px;
  border-radius: 8px; background: transparent; border: 0; color: var(--text); font: inherit; text-align: left; cursor: pointer; min-height: 0; box-shadow: none }
.rank-top .rank-row { grid-template-columns: 22px minmax(90px, 34%) 1fr auto }
.rank-row.static { cursor: default }
@media (hover: hover) and (pointer: fine) { button.rank-row:hover { background: var(--hover) } }
.rank-no { color: var(--muted); font-variant-numeric: tabular-nums; font-size: 12.5px; text-align: right }
.rank-name { font-size: 13.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
.rank-bar { height: 8px; border-radius: 0 4px 4px 0; overflow: hidden }
.rank-bar i { display: block; height: 100%; width: 100%; transform-origin: left; background: var(--chart-1); border-radius: 0 4px 4px 0; transition: transform 400ms var(--ease-out) }
.rank-val { font-size: 13px; font-variant-numeric: tabular-nums; white-space: nowrap; font-weight: 600 }
.rank-val small { margin-left: 6px; color: var(--muted); font-weight: 400 }
.pc-chips { list-style: none; margin: 4px 0 8px; padding: 0 8px; display: flex; flex-wrap: wrap; gap: 6px }
.pc-chips a { display: inline-block; font-size: 12px; padding: 3px 8px; border-radius: 999px; border: 1px solid var(--border); background: var(--surface); color: var(--text) }
.pc-list { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 6px; max-height: 320px; overflow: auto }
.pc-list li { display: grid; gap: 2px; padding: 8px 10px; border-radius: 10px; border: 1px solid var(--border); background: var(--surface); font-size: 12.5px }
.pc-list li a { color: var(--text); font-weight: 600 }
.pc-list li span { color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
.pc-list li[data-kind=game] { border-color: color-mix(in srgb, var(--chart-1) 45%, var(--border)) }
.pc-list li[data-kind=game] span { color: var(--text) }
.leaders { list-style: none; margin: 14px 0 0; padding: 0; display: flex; gap: 8px; overflow-x: auto; scrollbar-width: thin }
.leaders li { flex: 0 0 auto; display: grid; gap: 2px; padding: 8px 12px; border-radius: 10px; border: 1px solid var(--border); background: var(--surface); font-size: 12px; min-width: 120px }
.leaders span, .leaders small { color: var(--muted) }
.leaders b { font-size: 13px; font-weight: 600; white-space: nowrap }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0) }
@media (max-width: 900px) { .games-grid { grid-template-columns: 1fr } }
@media (prefers-reduced-motion: reduce) { .rank-bar i, .expand { transition: none } }
</style>
