<script setup lang="ts">
import { computed, ref } from 'vue';
import { useEntered } from './motion';
// Step sparkline with a hover readout; a single series, so no legend.
const props = defineProps<{ values: number[]; label: string; color?: string; days?: string[] }>();
const W = 220, H = 80, P = 6;
const max = computed(() => Math.max(1, ...props.values));
const points = computed(() => props.values.map((v, i) => [P + i * (W - 2 * P) / Math.max(1, props.values.length - 1), H - P - v / max.value * (H - 2 * P)]));
const path = computed(() => points.value.map(([x, y], i) => (i ? 'L' : 'M') + x.toFixed(1) + ' ' + y.toFixed(1)).join(' '));
const hover = ref<number | null>(null);
const entered = useEntered();
const clipId = 'spark-' + Math.random().toString(36).slice(2, 9);
function move(e: PointerEvent) {
    const box = (e.currentTarget as SVGElement).getBoundingClientRect();
    const x = (e.clientX - box.left) / box.width * W;
    hover.value = Math.max(0, Math.min(props.values.length - 1, Math.round((x - P) / ((W - 2 * P) / Math.max(1, props.values.length - 1)))));
}
</script>
<template>
  <div class="spark">
    <svg :viewBox="`0 0 ${W} ${H}`" preserveAspectRatio="none" role="img" :aria-label="label + ': ' + values.join(', ')"
      @pointermove="move" @pointerleave="hover = null">
      <defs><clipPath :id="clipId"><rect x="-4" y="-10" :width="W + 8" :height="H + 20" class="reveal" :class="{ shown: entered }" /></clipPath></defs>
      <path :clip-path="`url(#${clipId})`" :d="path" fill="none" :stroke="color ?? 'var(--chart-4)'" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
      <g v-if="hover !== null">
        <line :x1="points[hover][0]" :x2="points[hover][0]" :y1="0" :y2="H" stroke="var(--border-strong)" vector-effect="non-scaling-stroke" />
      </g>
    </svg>
    <span v-if="hover !== null" class="readout" :style="{ left: (points[hover][0] / W * 100) + '%' }">{{ days?.[hover] ?? '' }} · {{ values[hover] }}</span>
  </div>
</template>
<style scoped>
.spark { position:relative; height:84px }
svg { width:100%; height:100%; overflow:visible; display:block; cursor:crosshair }
/* The line draws left to right once, then stays put. */
.reveal { transform:scaleX(0); transform-origin:left center; transform-box:fill-box; transition:transform 700ms var(--ease-out) 120ms }
.reveal.shown { transform:scaleX(1) }
.readout { position:absolute; top:-6px; transform:translate(-50%,-100%); white-space:nowrap; padding:4px 8px; border-radius:8px; background:var(--surface-2); border:1px solid var(--border-strong); font-size:11.5px; font-variant-numeric:tabular-nums; pointer-events:none }
</style>
