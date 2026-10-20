import { onMounted, onUnmounted, ref } from 'vue';

export const prefersReducedMotion = () =>
    typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** Stagger for chart marks: 24ms per mark, capped at 240ms so wide charts stay quick. */
export const markDelay = (i: number, base = 0) => Math.min(base + i * 24, base + 240) + 'ms';

/** Becomes true one frame after mount, so CSS transitions can grow marks from zero. */
export function useEntered() {
    const entered = ref(prefersReducedMotion());
    let frame = 0;
    onMounted(() => { frame = requestAnimationFrame(() => { frame = requestAnimationFrame(() => { entered.value = true; }); }); });
    onUnmounted(() => cancelAnimationFrame(frame));
    return entered;
}
