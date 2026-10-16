import { onMounted, onUnmounted, ref } from 'vue';

export const prefersReducedMotion = () =>
    typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** Becomes true one frame after mount, so CSS transitions can grow marks from zero. */
export function useEntered() {
    const entered = ref(prefersReducedMotion());
    let frame = 0;
    onMounted(() => { frame = requestAnimationFrame(() => { frame = requestAnimationFrame(() => { entered.value = true; }); }); });
    onUnmounted(() => cancelAnimationFrame(frame));
    return entered;
}
