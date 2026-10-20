<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { prefersReducedMotion } from './motion';

// Counts up to the value on mount and eases between later updates.
const props = withDefaults(defineProps<{ value: number; duration?: number; decimals?: number }>(), { duration: 600, decimals: 0 });
const shown = ref(prefersReducedMotion() ? props.value : 0);
let frame = 0;
const format = (n: number) => new Intl.NumberFormat('ru-RU', { maximumFractionDigits: props.decimals, minimumFractionDigits: 0 }).format(n);

// Count-up on mount; live updates retarget quicker so fresh numbers aren't held back.
function animate(from: number, to: number, ms = props.duration) {
    cancelAnimationFrame(frame);
    if (prefersReducedMotion() || from === to) { shown.value = to; return; }
    const start = performance.now();
    const step = (now: number) => {
        const t = Math.min(1, (now - start) / ms);
        const eased = 1 - Math.pow(1 - t, 4);
        shown.value = from + (to - from) * eased;
        if (t < 1) frame = requestAnimationFrame(step);
    };
    frame = requestAnimationFrame(step);
}
onMounted(() => animate(shown.value, props.value));
watch(() => props.value, (to) => animate(shown.value, to, 320));
onUnmounted(() => cancelAnimationFrame(frame));
</script>
<template><span class="tabular" :aria-label="format(value)"><span aria-hidden="true">{{ format(Number(shown.toFixed(decimals))) }}</span></span></template>
