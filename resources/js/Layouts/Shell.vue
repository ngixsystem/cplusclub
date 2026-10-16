<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch, type Component } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Activity, Bell, BookOpen, Building2, CalendarCheck, ChartColumn, Inbox, LayoutDashboard, LogOut,
    Map as MapIcon, Monitor, PanelLeft, Plug, RefreshCw, Search, Settings, Ticket, TriangleAlert, Users, X,
} from 'lucide-vue-next';
import ThemeToggle from '../Components/ThemeToggle.vue';
import BrandLogo from '../Components/BrandLogo.vue';

type User = { id: number; name: string; role: string; avatar_url?: string | null };
type NavItem = { href: string; title: string; icon: Component; hint?: string };
const page = usePage<{ auth: { user: User }; flash: { success?: string }; attention?: { critical: number; alerts: number } | null }>();
const user = computed(() => page.props.auth.user);
const role = computed(() => user.value.role);
const roles: Record<string, string> = { owner: 'Владелец', lead: 'Технический руководитель', specialist: 'Выездной специалист', representative: 'Представитель клуба' };

const groups = computed<{ title: string; items: NavItem[] }[]>(() => {
    const admin = ['owner', 'lead'].includes(role.value);
    return [
        { title: 'Работа', items: [
            { href: '/', title: 'Обзор', icon: LayoutDashboard },
            { href: '/tickets', title: 'Заявки', icon: Ticket },
            { href: '/visits', title: 'Осмотры и выезды', icon: CalendarCheck },
            { href: '/clubs', title: 'Клубы', icon: Building2 },
            { href: '/equipment', title: 'Оборудование', icon: Monitor },
            { href: '/club-map', title: 'Карта клуба', icon: MapIcon },
        ] },
        { title: 'Контроль', items: [
            { href: '/monitoring', title: 'Мониторинг', icon: Activity },
            { href: '/updates', title: 'Обновления', icon: RefreshCw },
            { href: '/reports', title: 'Отчёты', icon: ChartColumn },
            { href: '/knowledge', title: 'База знаний', icon: BookOpen },
        ] },
        ...(admin ? [{ title: 'Администрирование', items: [
            { href: '/connection-requests', title: 'Запросы на подключение', icon: Inbox },
            ...(role.value === 'owner' ? [{ href: '/users', title: 'Пользователи и доступ', icon: Users }] : []),
            { href: '/integrations', title: 'Интеграции', icon: Plug },
        ] }] : []),
    ];
});
const allLinks = computed<NavItem[]>(() => [...groups.value.flatMap(g => g.items), { href: '/profile', title: 'Настройки профиля', icon: Settings }]);
const path = computed(() => page.url.split('?')[0]);
const active = (href: string) => href === '/' ? path.value === '/' : path.value === href || path.value.startsWith(href + '/');

/* Sidebar: collapsible on desktop (remembered), drawer on mobile. */
const collapsed = ref(false), menuOpen = ref(false), mobile = ref(false);
const media = window.matchMedia('(max-width: 900px)');
const syncMedia = () => { mobile.value = media.matches; };
function toggleSidebar() {
    if (mobile.value) { menuOpen.value = !menuOpen.value; return; }
    collapsed.value = !collapsed.value;
    try { localStorage.setItem('cclub-sidebar', collapsed.value ? 'collapsed' : 'open'); } catch { /* optional */ }
}
watch(() => page.url, () => { menuOpen.value = false; bellOpen.value = false; });

/* Command palette (⌘K / Ctrl+K). */
const palette = ref<HTMLDialogElement | null>(null), query = ref(''), cursor = ref(0), queryInput = ref<HTMLInputElement | null>(null);
const results = computed(() => {
    const q = query.value.trim().toLowerCase();
    return allLinks.value.filter(item => !q || item.title.toLowerCase().includes(q) || item.href.includes(q));
});
watch(query, () => { cursor.value = 0; });
async function openPalette() { query.value = ''; cursor.value = 0; palette.value?.showModal(); await nextTick(); queryInput.value?.focus(); }
function go(item?: NavItem) { if (!item) return; palette.value?.close(); router.visit(item.href); }
function paletteKey(e: KeyboardEvent) {
    if (e.key === 'ArrowDown') { e.preventDefault(); cursor.value = Math.min(cursor.value + 1, results.value.length - 1); }
    if (e.key === 'ArrowUp') { e.preventDefault(); cursor.value = Math.max(cursor.value - 1, 0); }
    if (e.key === 'Enter') { e.preventDefault(); go(results.value[cursor.value]); }
}

/* Notifications popover. */
const bellOpen = ref(false), bell = ref<HTMLElement | null>(null);
const attention = computed(() => page.props.attention ?? { critical: 0, alerts: 0 });
const attentionTotal = computed(() => attention.value.critical + attention.value.alerts);

function globalKey(e: KeyboardEvent) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); void openPalette(); }
    if (e.key === 'Escape') { bellOpen.value = false; menuOpen.value = false; }
}
function outside(e: MouseEvent) { if (bellOpen.value && bell.value && !bell.value.contains(e.target as Node)) bellOpen.value = false; }
onMounted(() => {
    try { collapsed.value = localStorage.getItem('cclub-sidebar') === 'collapsed'; } catch { /* optional */ }
    syncMedia(); media.addEventListener('change', syncMedia);
    document.addEventListener('keydown', globalKey); document.addEventListener('click', outside);
});
onUnmounted(() => { media.removeEventListener('change', syncMedia); document.removeEventListener('keydown', globalKey); document.removeEventListener('click', outside); });
const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);
</script>

<template>
  <div class="shell" :class="{ collapsed, 'menu-open': menuOpen }">
    <a class="skip-link" href="#main-content">Перейти к содержимому</a>

    <aside id="sidebar" class="sidebar" aria-label="Боковая панель">
      <Link href="/" class="sidebar-brand" aria-label="C+club — обзор">
        <BrandLogo :width="118" />
      </Link>
      <div class="sidebar-scroll">
        <nav aria-label="Основная навигация">
          <template v-for="group in groups" :key="group.title">
            <p class="nav-group">{{ group.title }}</p>
            <Link v-for="item in group.items" :key="item.href" :href="item.href" class="nav-link"
              :class="{ selected: active(item.href) }" :aria-current="active(item.href) ? 'page' : undefined">
              <component :is="item.icon" aria-hidden="true" />{{ item.title }}
              <span v-if="item.href==='/tickets' && attention.critical" class="count" :aria-label="'Критических: '+attention.critical">{{ attention.critical }}</span>
              <span v-if="item.href==='/monitoring' && attention.alerts" class="count" :aria-label="'Тревог: '+attention.alerts">{{ attention.alerts }}</span>
            </Link>
          </template>
        </nav>
      </div>
      <div class="sidebar-footer">
        <Link href="/profile" class="nav-link" :class="{ selected: active('/profile') }"><Settings aria-hidden="true" />Настройки профиля</Link>
        <Link href="/logout" method="post" as="button" class="nav-link"><LogOut aria-hidden="true" />Выйти</Link>
      </div>
    </aside>
    <div class="sidebar-scrim" aria-hidden="true" @click="menuOpen=false"></div>

    <div class="main">
      <div class="main-inner">
        <header class="topbar">
          <button type="button" class="icon-button ghost" aria-controls="sidebar" :aria-expanded="mobile ? menuOpen : !collapsed"
            :aria-label="'Боковая панель'" title="Показать или скрыть меню" @click="toggleSidebar"><PanelLeft aria-hidden="true" /></button>
          <span class="divider" aria-hidden="true"></span>
          <button type="button" class="search-trigger" aria-label="Поиск по разделам" @click="openPalette">
            <Search aria-hidden="true" /><span>Поиск по разделам…</span><kbd>{{ isMac ? '⌘' : 'Ctrl' }} K</kbd>
          </button>
          <div class="topbar-actions">
            <Link href="/monitoring" class="button icon-button ghost" aria-label="Мониторинг" title="Мониторинг"><Activity aria-hidden="true" /></Link>
            <div ref="bell" class="bell">
              <button type="button" class="icon-button ghost" :class="{ 'has-dot': attentionTotal > 0 }" :aria-expanded="bellOpen"
                :aria-label="attentionTotal ? 'Требует внимания: ' + attentionTotal : 'Уведомлений нет'" @click.stop="bellOpen=!bellOpen"><Bell aria-hidden="true" /></button>
              <div v-if="bellOpen" class="bell-panel" role="dialog" aria-label="Требует внимания">
                <p class="bell-title">Требует внимания</p>
                <Link href="/tickets" class="bell-row"><span class="icon-tile rose"><Ticket aria-hidden="true" /></span><span><b>{{ attention.critical }}</b> критических заявок<small>Весь клуб или группа ПК</small></span></Link>
                <Link href="/monitoring" class="bell-row"><span class="icon-tile amber"><TriangleAlert aria-hidden="true" /></span><span><b>{{ attention.alerts }}</b> активных тревог<small>Пороги мониторинга</small></span></Link>
              </div>
            </div>
            <ThemeToggle />
            <Link href="/profile" class="avatar" :aria-label="'Профиль: ' + user.name" :title="user.name + ' · ' + (roles[role] || role)">
              <img v-if="user.avatar_url" :src="user.avatar_url" alt="" /><span v-else>{{ user.name.slice(0, 1).toUpperCase() }}</span>
            </Link>
          </div>
        </header>

        <main id="main-content">
          <div v-if="page.props.flash.success" role="status" class="success">{{ page.props.flash.success }}</div>
          <slot />
        </main>
      </div>
    </div>

    <dialog ref="palette" class="palette" aria-label="Поиск по разделам" @click="($event.target===palette)&&palette?.close()">
      <div class="palette-field"><Search aria-hidden="true" />
        <input ref="queryInput" v-model="query" type="search" placeholder="Куда перейти?" aria-label="Название раздела"
          role="combobox" aria-controls="palette-list" :aria-activedescendant="results[cursor] ? 'palette-' + cursor : undefined" @keydown="paletteKey" />
        <button type="button" class="icon-button ghost" aria-label="Закрыть поиск" @click="palette?.close()"><X aria-hidden="true" /></button>
      </div>
      <ul id="palette-list" class="palette-list" role="listbox">
        <li v-for="(item, i) in results" :key="item.href">
          <a :id="'palette-' + i" :href="item.href" role="option" :aria-selected="i === cursor" @mouseenter="cursor=i" @click.prevent="go(item)">
            <component :is="item.icon" aria-hidden="true" />{{ item.title }}<small>{{ item.href }}</small>
          </a>
        </li>
      </ul>
      <p v-if="!results.length" class="palette-empty">Раздел не найден. Попробуйте «заявки» или «отчёты».</p>
    </dialog>
  </div>
</template>

<style scoped>
.sidebar-brand { padding:22px 20px 16px }
.bell { position:relative }
.bell-panel { position:absolute; right:0; top:calc(100% + 10px); width:300px; padding:8px; background:var(--surface); border:1px solid var(--border); border-radius:14px; box-shadow:var(--shadow-pop); z-index:50 }
.bell-title { margin:4px 8px 8px; font-size:12px; color:var(--muted); font-weight:500 }
.bell-row { display:flex; align-items:center; gap:12px; padding:8px; border-radius:10px }
.bell-row:hover { background:var(--hover) }
.bell-row b { font-variant-numeric:tabular-nums }
.bell-row small { display:block; margin-top:2px }
.bell-row .icon-tile { width:36px; height:36px }
</style>
