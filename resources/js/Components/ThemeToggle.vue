<script setup lang="ts">
import { ref } from 'vue';
const dark = ref(document.documentElement.dataset.theme === 'dark');
function toggle() {
    dark.value = !dark.value;
    const theme = dark.value ? 'dark' : 'light';
    document.documentElement.dataset.theme = theme;
    try { localStorage.setItem('cclub-theme', theme); } catch { /* Theme still works when storage is unavailable. */ }
}
</script>
<template>
    <button type="button" class="secondary theme-toggle" :aria-pressed="dark" aria-label="Тёмная тема" :title="dark ? 'Включить светлую тему' : 'Включить тёмную тему'" @click="toggle">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path v-if="!dark" d="M20 15.5A8.5 8.5 0 0 1 8.5 4 8.5 8.5 0 1 0 20 15.5Z"/><template v-else><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l2 2m10 10 2 2M5 19l2-2M17 7l2-2"/></template></svg>
    </button>
</template>
