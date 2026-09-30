<script setup lang="ts">
import { computed } from 'vue';
const props = defineProps<{labels:string[];values:number[];currency:string}>();
const max = computed(()=>Math.max(1,...props.values.map(v=>Math.abs(Number(v)))));
const money = (n:number)=>new Intl.NumberFormat('ru-RU',{maximumFractionDigits:0}).format(n)+' '+props.currency;
</script>
<template>
 <div class="revenue-chart">
  <div class="chart-scale"><span>{{money(max)}}</span><span>0</span></div>
  <div class="chart-plot" role="img" aria-label="Поступления за выбранный период. Точные значения доступны в таблице под графиком.">
   <div v-for="(label,i) in labels" :key="i" class="chart-column">
    <div class="chart-track"><div class="chart-bar" :class="{negative:Number(values[i])<0}" :style="{height:Math.max(Number(values[i])===0?0:1,Math.abs(Number(values[i]))/max*100)+'%'}" :title="label+': '+money(Number(values[i]))"></div></div>
    <span>{{label}}</span>
   </div>
  </div>
 </div>
 <details class="chart-values"><summary>Точные значения графика</summary><div class="table-scroll"><table><thead><tr><th>Период</th><th>Сумма</th></tr></thead><tbody><tr v-for="(label,i) in labels" :key="i"><td>{{label}}</td><td>{{money(Number(values[i]))}}</td></tr></tbody></table></div></details>
</template>
<style scoped>
.revenue-chart{display:flex;gap:16px;min-height:240px;padding:20px 0 0}.chart-scale{display:flex;flex-direction:column;justify-content:space-between;padding-bottom:27px;font-size:11px;color:var(--muted);min-width:60px}.chart-plot{display:flex;flex:1;gap:clamp(2px,1.5vw,18px);min-width:0;overflow-x:auto;background:repeating-linear-gradient(to top,transparent 0,transparent 51px,var(--border) 52px,transparent 53px)}.chart-column{flex:1;min-width:24px;display:flex;flex-direction:column;justify-content:flex-end;text-align:center;font-size:11px;font-variant-numeric:tabular-nums}.chart-track{height:195px;display:flex;align-items:flex-end}.chart-bar{width:100%;background:#278b79;border-radius:3px 3px 0 0;min-height:0;transition:height .4s}.chart-bar.negative{background:#c77b35}.chart-column>span{height:27px;padding-top:7px;color:var(--muted);white-space:nowrap}.chart-values{margin-top:14px;font-size:12px;color:var(--muted)}summary{cursor:pointer;padding:8px 0}:global([data-theme=dark]) .chart-bar{background:#b6ff00}:global([data-theme=dark]) .chart-bar.negative{background:#ffb66b}@media(prefers-reduced-motion:reduce){.chart-bar{transition:none}}
</style>
