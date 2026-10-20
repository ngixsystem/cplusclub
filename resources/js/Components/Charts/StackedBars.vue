<script setup lang="ts">
import { computed, ref } from 'vue';
import { markDelay, useEntered } from './motion';

type Series = { key: string; label: string; color: string; values: number[] };
const props = defineProps<{ labels: string[]; series: Series[]; caption: string; unit?: string }>();

const totals = computed(() => props.labels.map((_, i) => props.series.reduce((n, s) => n + (s.values[i] ?? 0), 0)));
const niceMax = computed(() => {
    const max = Math.max(...totals.value, 0);
    if (max <= 4) return 4;
    const step = Math.pow(10, Math.floor(Math.log10(max)));
    const nice = [1, 2, 2.5, 5, 10].map(m => m * step).find(v => v * 1 >= max / 4) ?? step;
    return Math.ceil(max / nice) * nice;
});
const ticks = computed(() => [0, 1, 2, 3, 4].map(i => Math.round(niceMax.value / 4 * i)).reverse());
const empty = computed(() => totals.value.every(t => t === 0));
const hover = ref<number | null>(null);
const entered = useEntered();
const fmt = (n: number) => new Intl.NumberFormat('ru-RU').format(n);
</script>

<template>
  <figure class="stacked">
    <ul class="legend" aria-label="Легенда">
      <li v-for="s in series" :key="s.key"><i :style="{ background: s.color }" aria-hidden="true"></i>{{ s.label }}</li>
    </ul>
    <div class="plot" role="img" :aria-label="caption + '. Точные значения — в таблице под графиком.'">
      <div class="axis" aria-hidden="true"><span v-for="t in ticks" :key="t">{{ fmt(t) }}</span></div>
      <div class="area">
        <div class="grid" aria-hidden="true"><i v-for="t in ticks" :key="t"></i></div>
        <div class="columns">
          <div v-for="(label, i) in labels" :key="label + i" class="column" @mouseenter="hover = i" @mouseleave="hover = null"
            @focus="hover = i" @blur="hover = null" tabindex="0" :aria-label="label + ': ' + series.map(s => s.label + ' ' + s.values[i]).join(', ')">
            <div class="stack grow-y" :class="{ in: entered }" :style="{ height: totals[i] / niceMax * 100 + '%', transitionDelay: markDelay(i, 40) }">
              <span v-for="s in series" v-show="s.values[i] > 0" :key="s.key" class="seg"
                :style="{ flexGrow: s.values[i], background: s.color }"></span>
            </div>
            <span class="x">{{ label }}</span>
            <div v-if="hover === i" class="tip" role="tooltip">
              <strong>{{ label }}</strong>
              <span v-for="s in series" :key="s.key"><i :style="{ background: s.color }"></i>{{ s.label }}<b>{{ fmt(s.values[i]) }}</b></span>
              <span class="total">Всего<b>{{ fmt(totals[i]) }}{{ unit ? ' ' + unit : '' }}</b></span>
            </div>
          </div>
        </div>
        <p v-if="empty" class="empty-note">За этот период данных нет</p>
      </div>
    </div>
    <details class="values"><summary>Таблица значений</summary>
      <div class="table-scroll"><table><thead><tr><th>Период</th><th v-for="s in series" :key="s.key">{{ s.label }}</th><th>Всего</th></tr></thead>
        <tbody><tr v-for="(label, i) in labels" :key="label + i"><td>{{ label }}</td><td v-for="s in series" :key="s.key">{{ fmt(s.values[i]) }}</td><td>{{ fmt(totals[i]) }}</td></tr></tbody></table></div>
    </details>
  </figure>
</template>

<style scoped>
.stacked { margin:0 }
.legend { display:flex; flex-wrap:wrap; gap:6px 16px; list-style:none; padding:0; margin:0 0 14px; font-size:12.5px; color:var(--muted) }
.legend li { display:flex; align-items:center; gap:7px }
.legend i { width:9px; height:9px; border-radius:3px }
.plot { display:flex; gap:12px; height:300px }
.axis { display:flex; flex-direction:column; justify-content:space-between; padding-bottom:28px; font-size:12px; color:var(--muted); text-align:right; min-width:22px; font-variant-numeric:tabular-nums }
.axis span { transform:translateY(-50%); line-height:1 }
.axis span:first-child { transform:none } .axis span:last-child { transform:translateY(0) }
.area { position:relative; flex:1; min-width:0 }
.grid { position:absolute; inset:0 0 28px; display:flex; flex-direction:column; justify-content:space-between; pointer-events:none }
.grid i { border-top:1px dashed var(--chart-grid) }
.grid i:last-child { border-top-style:solid }
.columns { position:absolute; inset:0; display:flex; justify-content:space-around; gap:4px }
.column { position:relative; flex:1; max-width:64px; display:flex; flex-direction:column; justify-content:flex-end; align-items:center; outline:none; border-radius:8px }
.column { transition:background-color var(--dur-fast) ease }
.column:hover,.column:focus-visible { background:color-mix(in srgb,var(--text) 4%,transparent) }
.column:hover .stack { filter:brightness(1.12) }
/* Tooltips follow the pointer across columns, so they appear instantly. */
.stack { width:14px; display:flex; flex-direction:column-reverse; gap:2px; margin-bottom:28px }
.seg { display:block; min-height:2px; flex-basis:0 }
.seg:last-child { border-radius:4px 4px 0 0 }
.x { position:absolute; bottom:4px; font-size:12px; color:var(--muted) }
.tip { position:absolute; bottom:calc(100% - 20px); left:50%; transform:translateX(-50%); z-index:5; display:grid; gap:5px; min-width:170px; padding:10px 12px; background:var(--surface-2); border:1px solid var(--border-strong); border-radius:12px; box-shadow:var(--shadow-pop); font-size:12.5px; pointer-events:none }
.tip span { display:flex; align-items:center; gap:7px; color:var(--muted) }
.tip i { width:8px; height:8px; border-radius:2px }
.tip b { margin-left:auto; color:var(--text); font-variant-numeric:tabular-nums }
.tip .total { border-top:1px solid var(--border); padding-top:5px; margin-top:2px }
.empty-note { position:absolute; inset:0 0 28px; display:grid; place-items:center; margin:0; color:var(--muted); font-size:13px }
.values { margin-top:8px; font-size:12.5px; color:var(--muted) }
.values summary { cursor:pointer; padding:6px 0; width:max-content }
.values table { margin-top:8px }
@media (max-width:640px) { .plot { height:240px } .stack { width:10px } }
</style>
