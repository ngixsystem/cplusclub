<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft, Box, MousePointerClick, RefreshCw, TriangleAlert } from 'lucide-vue-next';
import Shell from '../Layouts/Shell.vue';
import AnimatedNumber from '../Components/Charts/AnimatedNumber.vue';
import { ACCENT, STATUS, ClubScene, type Hover, type Layout, type PcState } from '../Components/ClubMap/ClubScene';

type Status = {
    connected: boolean; stale: boolean; updated_at: string | null; error: string | null;
    computers: Record<string, { name: string; area: string; online: boolean; busy: boolean; enabled: boolean }>;
};
const props = defineProps<{ clubs: { id: number; name: string }[]; club: { id: number; name: string } | null; layout: Layout | null }>();

const host = ref<HTMLElement | null>(null);
const scene = shallowRef<ClubScene | null>(null);
const status = ref<Status | null>(null);
const loading = ref(false);
const zone = ref<string | null>(null);
const selected = ref<number | null>(null);
const hover = ref<{ h: Hover; x: number; y: number } | null>(null);
const webgl = ref(true);
const sceneError = ref(false);

const stateOf = (n: number): PcState => {
    const s = status.value;
    if (!s || !s.connected) return 'unknown';
    const c = s.computers[String(n)];
    if (!c) return 'unknown';
    return !c.online ? 'offline' : c.busy ? 'busy' : 'free';
};
const states = computed(() => new Map((props.layout?.computers ?? []).map(c => [c.number, stateOf(c.number)] as const)));
const totals = computed(() => {
    const t = { free: 0, busy: 0, offline: 0, unknown: 0 };
    for (const s of states.value.values()) t[s]++;
    return t;
});
const zones = computed(() => (props.layout?.zones ?? [])
    .map(z => {
        const pcs = props.layout!.computers.filter(c => c.zone === z.id);
        return { ...z, total: pcs.length, busy: pcs.filter(c => states.value.get(c.number) === 'busy').length, free: pcs.filter(c => states.value.get(c.number) === 'free').length };
    })
    .filter(z => z.total > 0 || ['room', 'mafia', 'hookah', 'stream'].includes(z.type)));
const currentZone = computed(() => zones.value.find(z => z.id === zone.value) ?? null);
const label = (s: string) => scene.value?.prettyLabel(s) ?? s;
const pcInfo = (n: number) => {
    const c = status.value?.computers[String(n)];
    const pc = props.layout?.computers.find(x => x.number === n);
    const z = pc?.zone ? props.layout?.zones.find(x => x.id === pc.zone) : null;
    return { n, state: stateOf(n), name: c?.name ?? String(n).padStart(3, '0'), area: c?.area || (z ? label(z.label) : ''), enabled: c?.enabled ?? true };
};
const stateText: Record<PcState, string> = { free: 'Свободен', busy: 'Занят', offline: 'Офлайн', unknown: 'Нет данных' };
const updated = computed(() => status.value?.updated_at ? new Date(status.value.updated_at).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : null);

async function load() {
    if (!props.club || document.hidden) return;
    loading.value = true;
    try {
        const r = await fetch(`/clubs/${props.club.id}/map/status`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (r.ok) status.value = await r.json();
    } catch { /* keep the last state; the banner shows staleness */ } finally { loading.value = false; }
}

let timer = 0;
onMounted(() => {
    load();
    timer = window.setInterval(load, 15000);
    document.addEventListener('visibilitychange', load);
    if (!props.layout || !host.value) return;
    const probe = document.createElement('canvas');
    if (!(probe.getContext('webgl2') || probe.getContext('webgl'))) { webgl.value = false; return; }
    try {
        scene.value = new ClubScene(host.value, props.layout, {
            onZone: id => { zone.value = id; },
            onSelect: n => { selected.value = n; },
            onHover: (h, x, y) => { hover.value = h ? { h, x, y } : null; },
        });
        scene.value.setStatus(states.value);
        syncCounts();
    } catch (e) {
        console.error(e);
        sceneError.value = true;
    }
});
onBeforeUnmount(() => {
    clearInterval(timer);
    document.removeEventListener('visibilitychange', load);
    scene.value?.dispose();
});
function syncCounts() {
    scene.value?.setZoneCounts(new Map(zones.value.map(z => [z.id, { busy: z.busy, total: z.total }])));
}
watch(states, s => { scene.value?.setStatus(s); syncCounts(); });

const hoverText = computed(() => {
    const h = hover.value?.h;
    if (!h) return null;
    if (h.kind === 'pc') { const i = pcInfo(h.number); return `ПК ${i.name} · ${stateText[i.state]}`; }
    const z = zones.value.find(x => x.id === h.id);
    return z ? `${label(z.label)}${z.total ? ` · свободно ${z.free} из ${z.total}` : ''}` : null;
});
const stage = ref<HTMLElement | null>(null);
function enter(id: string) {
    const r = stage.value?.getBoundingClientRect();
    if (r && (r.top < 0 || r.bottom > window.innerHeight)) {
        stage.value!.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
    }
    scene.value?.flyToZone(id);
}
const switchClub = (id: number) => router.get('/club-map', { club: id }, { preserveScroll: false });
</script>

<template>
  <Head title="Карта клуба" />
  <Shell>
    <div class="page-header">
      <div>
        <h1>Карта клуба</h1>
        <p>Компьютеры в реальном времени по данным iCafeCloud. Нажмите на зону, чтобы войти внутрь и осмотреться.</p>
      </div>
      <div v-if="clubs.length > 1" class="header-actions">
        <label class="sr-only" for="club-map-club">Клуб</label>
        <select id="club-map-club" :value="club?.id" @change="switchClub(Number(($event.target as HTMLSelectElement).value))">
          <option v-for="c in clubs" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
    </div>

    <section v-if="!club || !layout" class="panel empty-state">
      <Box aria-hidden="true" />
      <h2>Карта ещё не подключена</h2>
      <p>Пришлите план помещения в PDF — мы соберём по нему 3D-карту клуба.</p>
    </section>

    <template v-else>
      <div class="map-stats">
        <div class="map-stat"><i :style="{ background: STATUS.free }" /><span>Свободен</span><b><AnimatedNumber :value="totals.free" /></b></div>
        <div class="map-stat"><i :style="{ background: STATUS.busy }" /><span>Занят</span><b><AnimatedNumber :value="totals.busy" /></b></div>
        <div class="map-stat"><i :style="{ background: STATUS.offline }" /><span>Офлайн</span><b><AnimatedNumber :value="totals.offline" /></b></div>
        <div v-if="totals.unknown" class="map-stat"><i :style="{ background: STATUS.unknown }" /><span>Нет данных</span><b><AnimatedNumber :value="totals.unknown" /></b></div>
        <div class="map-sync">
          <RefreshCw :class="{ spin: loading }" aria-hidden="true" />
          <span v-if="status && !status.connected">iCafeCloud не подключён</span>
          <span v-else-if="updated">Обновлено в {{ updated }}</span>
          <span v-else>Загружаем состояние…</span>
        </div>
      </div>
      <p v-if="status?.error" class="map-warning" role="status"><TriangleAlert aria-hidden="true" />{{ status.error }}</p>

      <div ref="stage" class="map-stage">
        <div ref="host" class="map-host" :class="{ 'is-inside': zone }" />
        <div v-if="!webgl || sceneError" class="map-fallback">
          <TriangleAlert aria-hidden="true" />
          <p>{{ sceneError ? 'Не удалось построить 3D-сцену.' : 'Браузер не поддерживает WebGL, поэтому 3D-карта недоступна.' }} Состояние ПК по зонам — ниже.</p>
        </div>

        <div class="map-title">
          <small>{{ club.name }}</small>
          <strong>{{ currentZone ? label(currentZone.label) : layout.name }}</strong>
        </div>
        <button v-if="zone" class="button secondary map-back" type="button" @click="scene?.flyToOverview()"><ArrowLeft aria-hidden="true" />Весь клуб <kbd>Esc</kbd></button>
        <p class="map-hint"><MousePointerClick aria-hidden="true" />{{ zone ? 'Зажмите мышь и ведите, чтобы осмотреться · колесо — приблизить · клик по ПК — подробности' : 'Нажмите на зону, чтобы войти · вращайте и приближайте карту мышью' }}</p>

        <div v-if="hoverText && !selected" class="map-tip" :style="{ left: hover!.x + 'px', top: hover!.y + 'px' }">{{ hoverText }}</div>

        <aside v-if="selected !== null" class="map-pc" aria-live="polite">
          <template v-for="i in [pcInfo(selected)]" :key="i.n">
            <div class="map-pc-head">
              <span class="map-pc-dot" :style="{ background: STATUS[i.state] }" />
              <div><small>{{ i.area }}</small><strong>ПК {{ i.name }}</strong></div>
              <button class="ghost icon" type="button" aria-label="Закрыть" @click="scene?.select(null)">×</button>
            </div>
            <p class="map-pc-state" :data-state="i.state">{{ stateText[i.state] }}</p>
            <p v-if="!i.enabled" class="muted">Отключён в iCafeCloud</p>
          </template>
        </aside>
      </div>

      <div class="map-zones" role="list">
        <button v-for="z in zones" :key="z.id" type="button" role="listitem" class="map-zone" :class="{ active: z.id === zone }"
                :style="{ '--accent': ACCENT[z.type] }" :disabled="!scene" @click="enter(z.id)">
          <i />
          <span>{{ label(z.label) }}</span>
          <small v-if="z.total">свободно {{ z.free }} / {{ z.total }}</small>
          <small v-else>комната</small>
        </button>
      </div>
    </template>
  </Shell>
</template>

<style scoped>
.map-stats { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:12px }
.map-stat { display:flex; align-items:center; gap:8px; padding:8px 12px; border:1px solid var(--border); border-radius:10px; background:var(--surface) }
.map-stat i { width:8px; height:8px; border-radius:50% }
.map-stat span { color:var(--muted) }
.map-stat b { font-variant-numeric:tabular-nums; font-weight:600 }
.map-sync { margin-left:auto; display:flex; align-items:center; gap:6px; color:var(--muted); font-size:12px }
.map-sync svg { width:14px; height:14px }
.spin { animation:spin 1s linear infinite }
@keyframes spin { to { transform:rotate(360deg) } }
.map-warning { display:flex; gap:8px; align-items:center; padding:8px 12px; border-radius:10px; background:color-mix(in srgb, #f59e0b 12%, transparent); color:#f59e0b; margin:0 0 12px }
.map-warning svg { width:16px; height:16px; flex:none }

.map-stage { position:relative; height:min(72vh, 760px); min-height:420px; border:1px solid var(--border); border-radius:14px; overflow:hidden; background:#07070a; isolation:isolate }
.map-host { position:absolute; inset:0 }
.map-host :deep(.club-canvas) { display:block; width:100%; height:100%; touch-action:none; outline:none }
.map-host :deep(.club-labels) { position:absolute; inset:0; pointer-events:none }
.map-host :deep(.zone-label) { display:flex; flex-direction:column; align-items:center; gap:2px; padding:4px 9px; border-radius:8px; border:1px solid color-mix(in srgb, var(--accent) 45%, transparent);
  background:rgba(9,9,11,.72); backdrop-filter:blur(6px); color:#fafafa; font:500 11px/1.2 var(--font-sans); white-space:nowrap; transition:opacity .3s, transform .2s; box-shadow:0 0 18px color-mix(in srgb, var(--accent) 25%, transparent) }
.map-host :deep(.zone-label b) { font-weight:600; color:var(--accent); letter-spacing:.02em }
.map-host :deep(.zone-label span) { color:#d4d4d8; font-variant-numeric:tabular-nums }
.map-host :deep(.zone-label span:empty) { display:none }
.map-host :deep(.zone-label.is-enterable) { pointer-events:auto; cursor:pointer }
.map-host :deep(.zone-label.is-enterable:hover), .map-host :deep(.zone-label.is-enterable:focus-visible) { transform:scale(1.06); outline:none; border-color:var(--accent) }
.map-host :deep(.zone-label.is-dim) { opacity:0; pointer-events:none }
.map-host :deep(.zone-label.is-hidden) { opacity:0; pointer-events:none }
.map-host :deep(.pc-label) { padding:1px 5px; border-radius:5px; background:rgba(9,9,11,.75); color:#e4e4e7; font:600 10px/1.4 var(--font-mono, ui-monospace, monospace); pointer-events:none }
.map-host :deep(.pc-label.is-selected) { background:#fafafa; color:#09090b }

.map-title { position:absolute; top:14px; left:16px; display:flex; flex-direction:column; color:#fafafa; pointer-events:none; text-shadow:0 1px 8px #000 }
.map-title small { color:#a1a1aa }
.map-title strong { font-size:18px; font-weight:600; letter-spacing:-.01em }
.map-back { position:absolute; top:14px; right:14px; background:rgba(18,18,19,.8) !important; color:#fafafa !important; border-color:#34343a !important; backdrop-filter:blur(6px) }
.map-back kbd { font:500 10px var(--font-sans); padding:1px 5px; border:1px solid #34343a; border-radius:4px; color:#a1a1aa }
.map-hint { position:absolute; left:50%; bottom:12px; transform:translateX(-50%); display:flex; gap:6px; align-items:center; margin:0; padding:6px 12px; border-radius:999px; background:rgba(9,9,11,.7);
  color:#a1a1aa; font-size:12px; white-space:nowrap; pointer-events:none; backdrop-filter:blur(6px); max-width:calc(100% - 24px); overflow:hidden; text-overflow:ellipsis }
.map-hint svg { width:14px; height:14px; flex:none }
.map-tip { position:fixed; transform:translate(12px, 12px); z-index:50; padding:5px 9px; border-radius:7px; background:#fafafa; color:#09090b; font-size:12px; font-weight:500; pointer-events:none; white-space:nowrap }
.map-pc { position:absolute; right:14px; bottom:56px; width:240px; padding:14px; border-radius:12px; background:rgba(18,18,19,.9); border:1px solid #34343a; color:#fafafa; backdrop-filter:blur(8px); animation:rise .25s ease-out }
.map-pc-head { display:flex; gap:10px; align-items:center }
.map-pc-head small { color:#a1a1aa; display:block }
.map-pc-head strong { font-size:16px }
.map-pc-head button { margin-left:auto; color:#a1a1aa; font-size:18px; padding:2px 8px }
.map-pc-dot { width:10px; height:10px; border-radius:50%; box-shadow:0 0 10px currentColor }
.map-pc-state { margin:10px 0 0; font-weight:600 }
.map-pc-state[data-state=free] { color:#4ade80 } .map-pc-state[data-state=busy] { color:#8ea2ff } .map-pc-state[data-state=offline], .map-pc-state[data-state=unknown] { color:#a1a1aa }
@keyframes rise { from { opacity:0; transform:translateY(6px) } }
.map-fallback { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px; color:#a1a1aa; text-align:center; padding:24px }

.map-zones { display:grid; grid-template-columns:repeat(auto-fill, minmax(min(150px, 100%), 1fr)); gap:8px; margin-top:12px }
.map-zone { display:grid; grid-template-columns:auto 1fr; column-gap:8px; align-items:center; text-align:left; padding:10px 12px; border:1px solid var(--border); border-radius:10px; background:var(--surface); color:var(--text); cursor:pointer; transition:border-color .15s, transform .15s }
.map-zone:hover:not(:disabled), .map-zone.active { border-color:var(--accent); transform:translateY(-1px) }
.map-zone:disabled { cursor:default }
.map-zone i { width:8px; height:8px; border-radius:2px; background:var(--accent); grid-row:span 2 }
.map-zone span { font-weight:500; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.map-zone small { grid-column:2 }
.empty-state { display:flex; flex-direction:column; align-items:center; text-align:center; padding:48px 24px }
.empty-state svg { width:28px; height:28px; color:var(--muted) }
.sr-only { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0) }
@media (max-width: 720px) {
  .map-stage { height:62vh; min-height:360px }
  .map-sync { margin-left:0; width:100% }
  .map-pc { left:12px; right:12px; width:auto }
  .map-hint { display:none }
  .map-host :deep(.zone-label) { padding:2px 6px; font-size:10px }
  .map-host :deep(.zone-label span), .map-host :deep(.zone-service), .map-host :deep(.zone-exit) { display:none }
}
@media (prefers-reduced-motion: reduce) { .spin, .map-pc { animation:none } .map-zone { transition:none } }
</style>
