<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useEntered } from './motion';

/* Grafana-style time series: 2px lines over a soft gradient fill, dashed grid,
 * one y-axis, crosshair that snaps to the nearest day, one tooltip listing every
 * visible series (value first). Keyboard: focus the chart, then ← →. */
type Series = { key: string; label: string; color: string; values: number[] };
const props = withDefaults(defineProps<{ labels: string[]; series: Series[]; caption: string; height?: number; fill?: boolean }>(), { height: 220, fill: true });

const box = ref<HTMLElement | null>(null);
const width = ref(600);
let ro: ResizeObserver | undefined;
onMounted(() => { if (!box.value) return; width.value = box.value.clientWidth || 600; ro = new ResizeObserver(e => { width.value = Math.max(240, Math.round(e[0].contentRect.width)); }); ro.observe(box.value); });
onUnmounted(() => ro?.disconnect());

const P = { l: 34, r: 14, t: 12, b: 26 };
const plotW = computed(() => Math.max(10, width.value - P.l - P.r));
const plotH = computed(() => props.height - P.t - P.b);
const n = computed(() => props.labels.length);
const step = computed(() => {
    const max = Math.max(1, ...props.series.flatMap(s => s.values));
    const raw = max / 4, mag = Math.pow(10, Math.floor(Math.log10(raw || 1)));
    return Math.max(1, [1, 2, 2.5, 5, 10].map(m => m * mag).find(v => v >= raw) ?? mag * 10);
});
const yMax = computed(() => step.value * 4);
const ticks = computed(() => [0, 1, 2, 3, 4].map(i => +(step.value * i).toFixed(2)));
const x = (i: number) => P.l + (n.value <= 1 ? plotW.value / 2 : i * plotW.value / (n.value - 1));
const y = (v: number) => P.t + (1 - v / yMax.value) * plotH.value;
const line = (vals: number[]) => vals.map((v, i) => (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(v).toFixed(1)).join(' ');
const area = (vals: number[]) => vals.length ? line(vals) + ` L${x(vals.length - 1).toFixed(1)} ${y(0)} L${x(0).toFixed(1)} ${y(0)} Z` : '';

const fmtDay = (d: string) => new Date(d + 'T00:00:00').toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }).replace('.', '');
const fmtLong = (d: string) => new Date(d + 'T00:00:00').toLocaleDateString('ru-RU', { weekday: 'short', day: 'numeric', month: 'long' });
const xTicks = computed(() => {
    const count = Math.max(2, Math.min(n.value, Math.floor(plotW.value / 74)));
    if (n.value <= 1) return [0];
    return [...new Set(Array.from({ length: count }, (_, k) => Math.round(k * (n.value - 1) / (count - 1))))];
});

const hover = ref<number | null>(null);
function move(e: PointerEvent) {
    const r = (e.currentTarget as SVGElement).getBoundingClientRect();
    const px = e.clientX - r.left;
    hover.value = Math.max(0, Math.min(n.value - 1, Math.round((px - P.l) / (plotW.value / Math.max(1, n.value - 1)))));
}
function key(e: KeyboardEvent) {
    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight' && e.key !== 'Home' && e.key !== 'End') return;
    e.preventDefault();
    const cur = hover.value ?? n.value - 1;
    hover.value = e.key === 'Home' ? 0 : e.key === 'End' ? n.value - 1 : Math.max(0, Math.min(n.value - 1, cur + (e.key === 'ArrowLeft' ? -1 : 1)));
}
const tipLeft = computed(() => hover.value === null ? 0 : x(hover.value));
const tipFlip = computed(() => tipLeft.value > width.value - 190);

const uid = 'ts' + Math.random().toString(36).slice(2, 8);
const entered = useEntered();
const summary = computed(() => props.caption + ': ' + props.series.map(s => `${s.label} — всего ${s.values.reduce((a, b) => a + b, 0)}`).join('; ') + '. Таблица значений под графиком.');
</script>

<template>
  <figure class="ts">
    <div ref="box" class="ts-box" :style="{ height: height + 'px' }">
      <svg :width="width" :height="height" :viewBox="`0 0 ${width} ${height}`" role="img" :aria-label="summary" tabindex="0"
        @pointermove="move" @pointerleave="hover = null" @keydown="key" @focus="hover = n - 1" @blur="hover = null">
        <defs>
          <linearGradient v-for="s in series" :id="`${uid}-${s.key}`" :key="s.key" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" :style="{ stopColor: s.color, stopOpacity: .3 }" />
            <stop offset="100%" :style="{ stopColor: s.color, stopOpacity: 0 }" />
          </linearGradient>
          <clipPath :id="`${uid}-clip`"><rect :x="P.l - 6" y="0" :width="plotW + 12" :height="height" class="reveal" :class="{ shown: entered }" /></clipPath>
        </defs>
        <g class="grid" aria-hidden="true">
          <g v-for="t in ticks" :key="t">
            <line :x1="P.l" :x2="P.l + plotW" :y1="y(t)" :y2="y(t)" :class="{ base: t === 0 }" />
            <text :x="P.l - 8" :y="y(t)" text-anchor="end" dominant-baseline="middle">{{ t }}</text>
          </g>
          <text v-for="i in xTicks" :key="'x' + i" :x="x(i)" :y="height - 6" :text-anchor="i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle'">{{ fmtDay(labels[i]) }}</text>
        </g>
        <g :clip-path="`url(#${uid}-clip)`">
          <template v-for="s in series" :key="s.key">
            <path v-if="fill" :d="area(s.values)" :fill="`url(#${uid}-${s.key})`" />
            <path :d="line(s.values)" fill="none" :stroke="s.color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
            <circle v-if="n" :cx="x(n - 1)" :cy="y(s.values[n - 1] ?? 0)" r="3.5" :fill="s.color" class="end" />
          </template>
        </g>
        <g v-if="hover !== null" aria-hidden="true">
          <line class="cross" :x1="x(hover)" :x2="x(hover)" :y1="P.t" :y2="P.t + plotH" />
          <circle v-for="s in series" :key="s.key" :cx="x(hover)" :cy="y(s.values[hover] ?? 0)" r="4.5" :fill="s.color" class="end" />
        </g>
      </svg>
      <div v-if="hover !== null" class="tip" :class="{ flip: tipFlip }" :style="{ left: tipLeft + 'px' }" role="status">
        <span class="tip-date">{{ fmtLong(labels[hover]) }}</span>
        <span v-for="s in series" :key="s.key" class="tip-row"><i :style="{ background: s.color }"></i><b class="tabular">{{ s.values[hover] ?? 0 }}</b>{{ s.label }}</span>
      </div>
    </div>
    <details class="ts-table"><summary>Таблица значений</summary>
      <div class="table-scroll"><table><thead><tr><th>День</th><th v-for="s in series" :key="s.key">{{ s.label }}</th></tr></thead>
        <tbody><tr v-for="(d, i) in labels" :key="d"><td>{{ fmtLong(d) }}</td><td v-for="s in series" :key="s.key" class="tabular">{{ s.values[i] }}</td></tr></tbody></table></div>
    </details>
  </figure>
</template>

<style scoped>
.ts { margin:0; min-width:0 }
.ts-box { position:relative; width:100%; min-width:0 }
svg { display:block; overflow:visible; outline:none; cursor:crosshair }
svg:focus-visible { outline:2px solid var(--ring); outline-offset:4px; border-radius:8px }
.grid line { stroke:var(--chart-grid); stroke-dasharray:3 4 }
.grid line.base { stroke-dasharray:none; stroke:var(--border-strong) }
.grid text { fill:var(--muted); font-size:11px; font-variant-numeric:tabular-nums }
.end { stroke:var(--surface); stroke-width:2 }
.cross { stroke:var(--border-strong); stroke-width:1 }
.reveal { transform:scaleX(0); transform-origin:left center; transform-box:fill-box; transition:transform 800ms var(--ease-out) 80ms }
.reveal.shown { transform:none }
.tip { position:absolute; top:6px; transform:translateX(12px); display:grid; gap:5px; min-width:150px; padding:9px 11px; border-radius:10px; background:var(--surface-2); border:1px solid var(--border-strong); box-shadow:var(--shadow-pop); font-size:12.5px; pointer-events:none; z-index:3 }
.tip.flip { transform:translateX(calc(-100% - 12px)) }
.tip-date { color:var(--muted); font-size:11.5px; text-transform:capitalize }
.tip-row { display:flex; align-items:center; gap:8px; color:var(--muted) }
.tip-row i { width:12px; height:2px; border-radius:1px }
.tip-row b { color:var(--text); font-weight:600; min-width:22px }
.ts-table { margin-top:6px; font-size:12.5px; color:var(--muted) }
.ts-table summary { cursor:pointer; padding:4px 0; width:max-content }
.ts-table table { margin-top:6px }
@media (prefers-reduced-motion:reduce) { .reveal { transform:none; transition:none } }
</style>
