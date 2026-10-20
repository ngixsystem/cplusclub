<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { LayoutGrid, List, Search, X } from 'lucide-vue-next';

import type { TicketFilters } from './tickets';
const props = defineProps<{ filters: TicketFilters; counts: Record<string, number>; options: { assignees: { id: number; name: string }[]; categories: string[] }; clubs: { id: number; name: string }[]; view: 'cards' | 'list' }>();
const emit = defineEmits<{ (e: 'update:view', v: 'cards' | 'list'): void; (e: 'loading', v: boolean): void }>();

const tabs = [['all', 'Все'], ['open', 'Открытые'], ['waiting', 'Ждут'], ['resolved', 'Решены'], ['closed', 'Закрыты']] as const;
const priorities = [['critical', 'Критический'], ['high', 'Высокий'], ['normal', 'Обычный'], ['low', 'Низкий']] as const;
const f = ref<TicketFilters>({ ...props.filters, priority: [...props.filters.priority] });
watch(() => props.filters, v => { const s = f.value.search; f.value = { ...v, priority: [...v.priority] }; if (document.activeElement?.id === 'ticket-search') f.value.search = s; });

// Filters live in the URL: only non-default values are written.
function params(v: TicketFilters) {
    const p: Record<string, string | number> = {};
    if (v.tab !== 'all') p.tab = v.tab;
    if (v.priority.length) p.priority = v.priority.join(',');
    if (v.club) p.club = v.club;
    if (v.assignee !== null && v.assignee !== '') p.assignee = v.assignee;
    if (v.category) p.category = v.category;
    if (v.period) p.period = v.period;
    if (v.search.trim()) p.search = v.search.trim();
    if (v.sort !== 'new') p.sort = v.sort;
    return p;
}
function apply(patch: Partial<TicketFilters> = {}) {
    f.value = { ...f.value, ...patch };
    router.get('/tickets', params(f.value), {
        preserveState: true, preserveScroll: true, replace: true, only: ['rows', 'ticketCounts', 'filters'],
        onStart: () => emit('loading', true), onFinish: () => emit('loading', false),
    });
}
let timer: ReturnType<typeof setTimeout> | undefined;
function typed() { clearTimeout(timer); timer = setTimeout(() => apply(), 350); }
onUnmounted(() => clearTimeout(timer));
const togglePriority = (p: string) => apply({ priority: f.value.priority.includes(p) ? f.value.priority.filter(x => x !== p) : [...f.value.priority, p] });
const active = computed(() => Object.keys(params({ ...f.value, tab: 'all', sort: 'new' })).length > 0);
const reset = () => apply({ priority: [], club: null, assignee: null, category: null, period: null, search: '' });
const num = (v: string) => v === '' ? null : Number(v);
</script>

<template>
  <div class="ticket-toolbar">
    <div class="tabs" role="toolbar" aria-label="Состояние заявок">
      <button v-for="[key, label] in tabs" :key="key" type="button" class="tab" :aria-pressed="f.tab === key" @click="apply({ tab: key })">
        {{ label }}<span class="count tabular">{{ counts[key] ?? 0 }}</span>
      </button>
    </div>

    <div class="filters">
      <label class="search"><Search aria-hidden="true" /><span class="sr-only">Поиск по заявкам</span>
        <input id="ticket-search" v-model="f.search" type="search" placeholder="№ заявки, ПК или текст" autocomplete="off" @input="typed" @keydown.enter.prevent="apply()" />
      </label>
      <select v-if="clubs.length > 1" id="ticket-club" aria-label="Клуб" :value="f.club ?? ''" @change="apply({ club: num(($event.target as HTMLSelectElement).value) })">
        <option value="">Все клубы</option><option v-for="c in clubs" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
      <select id="ticket-assignee" aria-label="Исполнитель" :value="f.assignee ?? ''" @change="apply({ assignee: (v => v === 'me' || v === 'none' || v === '' ? (v || null) : Number(v))(($event.target as HTMLSelectElement).value) })">
        <option value="">Все исполнители</option><option value="me">Мои заявки</option><option value="none">Без исполнителя</option>
        <option v-for="a in options.assignees" :key="a.id" :value="a.id">{{ a.name }}</option>
      </select>
      <select v-if="options.categories.length > 1" id="ticket-category" aria-label="Категория" :value="f.category ?? ''" @change="apply({ category: ($event.target as HTMLSelectElement).value || null })">
        <option value="">Все категории</option><option v-for="c in options.categories" :key="c" :value="c">{{ c }}</option>
      </select>
      <select id="ticket-period" aria-label="Период" :value="f.period ?? ''" @change="apply({ period: num(($event.target as HTMLSelectElement).value) })">
        <option value="">За всё время</option><option value="7">7 дней</option><option value="30">30 дней</option><option value="90">90 дней</option>
      </select>
      <select id="ticket-sort" aria-label="Сортировка" :value="f.sort" @change="apply({ sort: ($event.target as HTMLSelectElement).value })">
        <option value="new">Сначала новые</option><option value="urgent">Сначала срочные</option><option value="oldest">Дольше всего открыты</option>
      </select>
    </div>

    <div class="filters-row">
      <div class="prio" role="group" aria-label="Приоритет">
        <button v-for="[key, label] in priorities" :key="key" type="button" class="prio-chip" :data-p="key" :aria-pressed="f.priority.includes(key)" @click="togglePriority(key)">
          <i aria-hidden="true"></i>{{ label }}
        </button>
        <button v-if="active" type="button" class="ghost reset" @click="reset"><X aria-hidden="true" />Сбросить</button>
      </div>
      <div class="view" role="group" aria-label="Вид списка">
        <button type="button" class="icon-button ghost" :aria-pressed="view === 'cards'" title="Карточки" aria-label="Карточки" @click="emit('update:view', 'cards')"><LayoutGrid aria-hidden="true" /></button>
        <button type="button" class="icon-button ghost" :aria-pressed="view === 'list'" title="Компактный список" aria-label="Компактный список" @click="emit('update:view', 'list')"><List aria-hidden="true" /></button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.ticket-toolbar { display:grid; gap:14px; padding:18px 20px; border-bottom:1px solid var(--border) }
.tabs { display:flex; gap:4px; padding:4px; border-radius:12px; background:var(--surface-2); border:1px solid var(--border); overflow-x:auto; scrollbar-width:none; width:max-content; max-width:100% }
.tab { min-height:34px; padding:6px 12px; gap:8px; background:transparent; color:var(--muted); border-radius:9px; font-size:13px }
.tab:hover { background:var(--hover); color:var(--text); opacity:1 }
.tab[aria-pressed=true] { background:var(--surface); color:var(--text); box-shadow:0 1px 2px rgba(0,0,0,.25),0 0 0 1px var(--border) }
.count { min-width:22px; padding:1px 7px; border-radius:999px; background:var(--hover); font-size:11.5px; font-weight:600; color:var(--muted) }
.tab[aria-pressed=true] .count { background:var(--text); color:var(--bg) }
.filters { display:grid; grid-template-columns:minmax(220px,2fr) repeat(auto-fit,minmax(150px,1fr)); gap:10px }
.search { position:relative; display:block }
.search svg { position:absolute; left:12px; top:50%; width:16px; height:16px; margin-top:-8px; color:var(--subtle); pointer-events:none }
.search input { padding-left:36px }
.filters-row { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap }
.prio { display:flex; gap:6px; flex-wrap:wrap; align-items:center }
.prio-chip { min-height:32px; padding:4px 12px; gap:7px; border-radius:999px; background:transparent; color:var(--muted); border:1px solid var(--border); font-size:12.5px }
.prio-chip i { width:7px; height:7px; border-radius:50% }
.prio-chip[data-p=critical] { --c:var(--error) } .prio-chip[data-p=high] { --c:var(--warning) } .prio-chip[data-p=normal] { --c:var(--info) } .prio-chip[data-p=low] { --c:var(--subtle) }
.prio-chip i { background:var(--c) }
.prio-chip:hover { border-color:var(--border-strong); color:var(--text); opacity:1 }
.prio-chip[aria-pressed=true] { color:var(--text); border-color:color-mix(in srgb,var(--c) 60%,var(--border)); background:color-mix(in srgb,var(--c) 12%,transparent) }
.reset { min-height:32px; padding:4px 10px; font-size:12.5px }
.reset svg { width:14px; height:14px }
.view { display:flex; gap:2px; padding:3px; border-radius:10px; border:1px solid var(--border) }
.view .icon-button { width:32px; height:30px; min-height:30px }
.view .icon-button[aria-pressed=true] { background:var(--active); color:var(--text) }
.sr-only { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap }
@media (max-width:640px) { .ticket-toolbar { padding:14px } .filters { grid-template-columns:1fr 1fr } .search { grid-column:1/-1 } .view { display:none } }
</style>
