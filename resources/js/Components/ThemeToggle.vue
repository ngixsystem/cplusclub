<script setup lang="ts">
import { ref } from 'vue';
import { Moon, Sun } from 'lucide-vue-next';
const dark = ref(document.documentElement.dataset.theme !== 'light');
function toggle() {
    dark.value = !dark.value;
    const theme = dark.value ? 'dark' : 'light';
    document.documentElement.dataset.theme = theme;
    try { localStorage.setItem('cclub-theme', theme); } catch { /* Theme still works when storage is unavailable. */ }
}
</script>
<template>
    <button type="button" class="icon-button ghost theme-toggle" :aria-pressed="dark" aria-label="Тёмная тема"
        :title="dark ? 'Включить светлую тему' : 'Включить тёмную тему'" @click="toggle">
        <Sun v-if="dark" aria-hidden="true" /><Moon v-else aria-hidden="true" />
    </button>
</template>
