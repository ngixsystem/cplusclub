<script setup lang="ts">
import { computed, ref, watch } from 'vue';

/**
 * A number whose digits roll like an odometer. Each digit is a 0–9 strip moved with
 * transform only; digits are keyed from the right so units stay units when the length
 * changes. A short motion blur rides on the roll (filter, not layout).
 */
const props = withDefaults(defineProps<{ value: number; format?: (n: number) => string; stagger?: number }>(), { stagger: 28 });
const fmt = (n: number) => (props.format ?? ((x: number) => new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(x)))(n);
const text = computed(() => fmt(props.value));
const chars = computed(() => [...text.value].map((c, i, all) => ({ c, key: all.length - i, digit: /\d/.test(c) ? Number(c) : null })));
const direction = ref<'up' | 'down'>('up');
const tick = ref(0);
watch(() => props.value, (n, o) => { direction.value = n >= (o ?? 0) ? 'up' : 'down'; tick.value++; });
</script>

<template>
  <span class="rolling" :aria-label="text" role="text">
    <span aria-hidden="true" class="rolling-row" :data-dir="direction">
      <span v-for="ch in chars" :key="ch.key" class="slot" :class="{ sep: ch.digit === null }">
        <template v-if="ch.digit !== null">
          <span class="strip" :class="tick ? (tick % 2 ? 'blur-a' : 'blur-b') : ''" :style="{ transform: `translateY(${-ch.digit * 10}%)`, transitionDelay: `${(ch.key - 1) * stagger}ms`, animationDelay: `${(ch.key - 1) * stagger}ms` }">
            <span v-for="d in 10" :key="d">{{ d - 1 }}</span>
          </span>
          <span class="ghost">0</span>
        </template>
        <template v-else>{{ ch.c }}</template>
      </span>
    </span>
  </span>
</template>

<style scoped>
.rolling { display: inline-block; font-variant-numeric: tabular-nums; line-height: 1 }
.rolling-row { display: inline-flex; align-items: flex-start }
.slot { position: relative; display: inline-block; height: 1em; overflow: hidden }
.slot.sep { width: .28em; text-align: center }
.ghost { visibility: hidden; display: block }
.strip { position: absolute; inset: 0 0 auto 0; display: flex; flex-direction: column; will-change: transform;
  transition: transform 820ms cubic-bezier(.16, 1, .3, 1); }
.blur-a { animation: roll-blur-a 820ms cubic-bezier(.16, 1, .3, 1) both }
.blur-b { animation: roll-blur-b 820ms cubic-bezier(.16, 1, .3, 1) both }
.strip span { display: block; height: 1em; text-align: center }
/* Blur peaks while the strip moves fastest, then settles sharp. */
/* Two identical keyframes: switching the name restarts the blur on every change. */
@keyframes roll-blur-a { 0% { filter: blur(0) } 18% { filter: blur(3px) } 55% { filter: blur(.6px) } 100% { filter: blur(0) } }
@keyframes roll-blur-b { 0% { filter: blur(0) } 18% { filter: blur(3px) } 55% { filter: blur(.6px) } 100% { filter: blur(0) } }
@media (prefers-reduced-motion: reduce) { .strip { transition: none; animation: none } }
</style>
