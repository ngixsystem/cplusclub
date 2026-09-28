<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
const form = useForm({ email: '', password: '' });
</script>
<template>
  <Head title="Вход" />
  <div class="login"><form class="panel" @submit.prevent="form.post('/login', { onFinish: () => form.reset('password') })">
    <div class="brand">C<span>+</span>CLub</div><h1>Вход в рабочее пространство</h1><p>Клубы, оборудование и сервисная команда.</p>
    <label>Email<input v-model="form.email" type="email" autocomplete="username" required /></label>
    <label>Пароль<input v-model="form.password" type="password" autocomplete="current-password" required /></label>
    <p v-if="form.errors.email" role="alert" class="error">{{ form.errors.email }}</p>
    <button :disabled="form.processing">{{ form.processing ? 'Входим…' : 'Войти' }}</button>
  </form></div>
</template>
