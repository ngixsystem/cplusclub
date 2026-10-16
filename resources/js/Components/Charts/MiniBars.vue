<script setup lang="ts">
import { computed, ref } from 'vue';
// Compact daily bars: the latest day ends at the right, today is highlighted.
const props = defineProps<{ values: number[]; label: string; color?: string; days?: string[] }>();
const max = computed(() => Math.max(1, ...props.values));
const hover = ref<number | null>(null);
</script>
<template>
  <div class="mini" role="img" :aria-label="label + ': ' + values.join(', ')">
    <span v-for="(v, i) in values" :key="i" class="bar" :class="{ dim: i < values.length - 7 }"
      :style="{ height: Math.max(v ? 8 : 3, v / max * 100) + '%', background: color ?? 'var(--chart-1)' }"
      @mouseenter="hover = i" @mouseleave="hover = null">
      <span v-if="hover === i" class="tip">{{ days?.[i] ?? '' }} · {{ v }}</span>
    </span>
  </div>
</template>
<style scoped>
.mini { display:flex; align-items:flex-end; gap:5px; height:112px }
.bar { position:relative; flex:1; min-width:4px; border-radius:6px; transition:opacity .15s }
.bar.dim { opacity:.45 }
.bar:hover { opacity:1 }
.tip { position:absolute; bottom:calc(100% + 6px); left:50%; transform:translateX(-50%); white-space:nowrap; padding:4px 8px; border-radius:8px; background:var(--surface-2); border:1px solid var(--border-strong); font-size:11.5px; color:var(--text); z-index:5; font-variant-numeric:tabular-nums }
</style>
