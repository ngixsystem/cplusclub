<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
const props = defineProps<{ clubs: { id: number; name: string }[]; filter: { club: number | 'all'; type: string | null; clubCounts: Record<string, number>; typeCounts: Record<string, number> }; search: string; total: number }>();
const q = ref(props.search);
function go(patch: Record<string, string | number | null>) {
  const params: Record<string, string | number> = {};
  const next = { club: props.filter.club, type: props.filter.type, search: q.value, ...patch };
  for (const [k, v] of Object.entries(next)) if (v !== null && v !== '') params[k] = v as string | number;
  router.get('/equipment', params, { preserveState: true, preserveScroll: true });
}
const all = () => Object.values(props.filter.clubCounts).reduce((a, b) => a + Number(b), 0);
const types: [string | null, string][] = [[null, 'Все'], ['pc', 'Компьютеры'], ['switch', 'Коммутаторы'], ['other', 'Другое']];
const typeCount = (t: string | null) => t ? Number(props.filter.typeCounts[t] ?? 0) : Object.values(props.filter.typeCounts).reduce((a, b) => a + Number(b), 0);
</script>

<template>
  <div class="eq-bar">
    <div class="tabs" role="tablist" aria-label="Клуб">
      <button v-for="c in clubs" :key="c.id" role="tab" :aria-selected="filter.club === c.id" :class="{ on: filter.club === c.id }" @click="go({ club: c.id, type: null })">
        {{ c.name }} <small>{{ filter.clubCounts[c.id] ?? 0 }}</small>
      </button>
      <button v-if="clubs.length > 1" role="tab" :aria-selected="filter.club === 'all'" :class="{ on: filter.club === 'all' }" @click="go({ club: 'all', type: null })">Все <small>{{ all() }}</small></button>
    </div>
    <div class="row">
      <div class="chips">
        <button v-for="[t, label] in types" :key="label" :class="{ on: filter.type === t }" :disabled="!!t && !typeCount(t)" @click="go({ type: t })">{{ label }} <small>{{ typeCount(t) }}</small></button>
      </div>
      <form class="search" @submit.prevent="go({})"><input v-model="q" placeholder="Название, место, IP или MAC" aria-label="Поиск оборудования" /><button class="secondary">Найти</button></form>
    </div>
  </div>
</template>

<style scoped>
.eq-bar { display: grid; gap: 14px; padding: 20px 24px 0 }
.tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); overflow-x: auto }
.tabs button { background: none; border: 0; border-bottom: 2px solid transparent; border-radius: 0; color: var(--muted); padding: 10px 14px; font-weight: 600; font-size: 15px; white-space: nowrap; margin-bottom: -1px }
.tabs button.on { color: var(--text); border-bottom-color: var(--text) }
.tabs button:hover { color: var(--text); background: none }
.tabs small, .chips small { color: var(--muted); font-weight: 500; margin-left: 4px }
.row { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; align-items: center }
.chips { display: flex; gap: 6px; flex-wrap: wrap }
.chips button { background: transparent; color: var(--text); border: 1px solid var(--border); border-radius: 999px; padding: 6px 12px; font-size: 14px; font-weight: 500 }
.chips button.on { background: var(--text); color: var(--bg); border-color: var(--text) } .chips button.on small { color: inherit; opacity: .7 }
.chips button:disabled { opacity: .4 }
.search { display: flex; gap: 8px } .search input { min-width: 240px }
@media (max-width: 560px) { .eq-bar { padding: 14px 14px 0 } .search { width: 100% } .search input { min-width: 0; flex: 1 } }
</style>
