<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import ThemeToggle from '../Components/ThemeToggle.vue';
const page = usePage<{ auth: { user: { name: string; role: string } }; flash: { success?: string } }>();
const menuOpen = ref(false);
const roles: Record<string,string> = {owner:'Владелец',lead:'Руководитель',specialist:'Специалист',representative:'Представитель клуба'};
const links = computed(() => [
 ['/', 'Обзор','M3 10 12 3l9 7v10H3Z M9 20v-7h6v7','Рабочее пространство'],
 ['/clubs','Клубы','M4 21V3h16v18M8 7h2m4 0h2M8 11h2m4 0h2M9 21v-6h6v6',''],
 ['/equipment','Оборудование','M3 4h18v12H3Z M8 21h8m-4-5v5',''],
 ['/tickets','Заявки','M4 4h16v16H4Z M8 8h8M8 12h8M8 16h4',''],
 ['/visits','Осмотры и выезды','M4 5h16v16H4Z M8 2v6m8-6v6M4 11h16',''],
 ['/monitoring','Мониторинг','M2 12h5l3-8 4 16 3-8h5','Контроль и аналитика'],
 ['/updates','Обновления','M20 10a8 8 0 0 0-14-5L3 8m0-5v5h5m-4 6a8 8 0 0 0 14 5l3-3m0 5v-5h-5',''],
 ['/reports','Отчёты','M4 3v18h17M8 16v-5m5 5V6m5 10V9',''],
 ...(page.props.auth.user.role==='owner' ? [['/users','Пользователи и доступ','M16 21v-3a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v3M9 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8m8 0a4 4 0 0 1 0 8','Управление']] : []),
 ...(['owner','lead'].includes(page.props.auth.user.role) ? [['/integrations','Интеграции','M8 3h8v5h5v8h-5v5H8v-5H3V8h5Z','']] : []),
]);
const active = (href: string) => href==='/' ? page.url.split('?')[0]==='/' : page.url.split('?')[0].startsWith(href);
const section = computed(() => links.value.find(item => active(item[0]))?.[1] || 'Рабочее пространство');
</script>
<template>
 <div class="shell">
  <a class="skip-link" href="#main-content">Перейти к содержимому</a>
  <header class="topbar">
   <Link href="/" class="brand">C<span>+</span>CLub</Link>
   <span class="topbar-caption">Управление клубами</span>
   <div class="topbar-account"><span class="avatar">{{page.props.auth.user.name.slice(0,1).toUpperCase()}}</span><span>{{page.props.auth.user.name}}<small>{{roles[page.props.auth.user.role] || page.props.auth.user.role}}</small></span></div>
   <button class="menu-toggle secondary" :aria-expanded="menuOpen" aria-controls="sidebar" @click="menuOpen=!menuOpen">{{menuOpen?'Закрыть':'Меню'}}</button>
   <ThemeToggle />
  </header>
  <aside id="sidebar" :class="{'is-open':menuOpen}">
   <nav aria-label="Основная навигация"><template v-for="[href,title,icon,group] in links" :key="href">
    <p v-if="group" class="nav-group">{{group}}</p>
    <Link :href="href" :class="{selected:active(href)}" :aria-current="active(href)?'page':undefined" @click="menuOpen=false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="icon"/></svg>{{title}}</Link>
   </template></nav>
   <div class="account"><Link href="/logout" method="post" as="button" class="secondary">Выйти</Link></div>
  </aside>
  <main id="main-content"><div class="breadcrumb"><Link href="/">Рабочее пространство</Link><span>/</span><span>{{section}}</span></div><div v-if="page.props.flash.success" role="status" class="success">{{page.props.flash.success}}</div><slot/></main>
 </div>
</template>
