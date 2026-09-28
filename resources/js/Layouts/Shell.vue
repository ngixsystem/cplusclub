<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
const page = usePage<{ auth: { user: { name: string; role: string } }; flash: { success?: string } }>();
const links = [['/', 'Обзор'], ['/clubs', 'Клубы'], ['/equipment', 'Оборудование'], ['/tickets', 'Заявки']];
</script>
<template>
  <div class="shell">
    <aside>
      <Link href="/" class="brand">C<span>+</span>CLub</Link>
      <p class="eyebrow">СЕРВИСНАЯ ПЛАТФОРМА</p>
      <nav><Link v-for="[href, title] in links" :key="href" :href="href" :class="{ selected: page.url.split('?')[0] === href }">{{ title }}</Link></nav>
      <div class="account"><strong>{{ page.props.auth.user.name }}</strong><Link href="/logout" method="post" as="button">Выйти</Link></div>
    </aside>
    <main>
      <header><span>Обслуживание компьютерных клубов</span><span class="badge">C+CLub · разработка</span></header>
      <div v-if="page.props.flash.success" role="status" class="success">{{ page.props.flash.success }}</div>
      <slot />
    </main>
  </div>
</template>
