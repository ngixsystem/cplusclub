<script setup lang="ts">
import {Head,useForm,usePage} from '@inertiajs/vue3';
import Shell from '../Layouts/Shell.vue';
defineProps<{profile:{name:string;email:string}}>();
const page=usePage<{auth:{user:{avatar_url:string|null}}}>();
const avatar=useForm<{avatar:File|null}>({avatar:null});
const password=useForm({current_password:'',password:'',password_confirmation:''});
function select(event:Event){avatar.avatar=(event.target as HTMLInputElement).files?.[0] || null;}
</script>
<template><Head title="Настройки профиля"/><Shell>
  <div class="heading"><div><h1>Настройки профиля</h1><p>{{profile.name}} · {{profile.email}}</p></div></div>
  <div class="profile-grid">
    <section class="panel"><h2>Аватарка</h2><div class="profile-avatar"><img v-if="page.props.auth.user.avatar_url" :src="page.props.auth.user.avatar_url" alt="Ваша аватарка"/><span v-else>{{profile.name.slice(0,1).toUpperCase()}}</span></div>
      <form @submit.prevent="avatar.post('/profile/avatar',{onSuccess:()=>avatar.reset()})"><label>Новое изображение<input type="file" accept="image/jpeg,image/png,image/webp" @change="select"/></label><p>JPG, PNG или WebP, до 2 МБ и 4096 × 4096 пикселей.</p><p class="error" role="alert" v-for="error in avatar.errors" :key="error">{{error}}</p><button :disabled="!avatar.avatar || avatar.processing">Сохранить аватарку</button></form>
      <button v-if="page.props.auth.user.avatar_url" class="secondary remove-avatar" :disabled="avatar.processing" @click="avatar.delete('/profile/avatar')">Удалить аватарку</button>
    </section>
    <section class="panel"><h2>Смена пароля</h2><p>Минимум 6 символов. После изменения войдите с новым паролем.</p><form class="password-form" @submit.prevent="password.post('/profile/password',{onFinish:()=>password.reset()})"><label>Текущий пароль<input v-model="password.current_password" type="password" autocomplete="current-password" required/></label><label>Новый пароль<input v-model="password.password" type="password" autocomplete="new-password" minlength="6" maxlength="72" required/></label><label>Подтверждение пароля<input v-model="password.password_confirmation" type="password" autocomplete="new-password" minlength="6" maxlength="72" required/></label><p class="error" role="alert" v-for="error in password.errors" :key="error">{{error}}</p><button :disabled="password.processing">Сменить пароль</button></form></section>
  </div>
</Shell></template>
<style scoped>
.profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start}.profile-avatar{width:100px;height:100px;border-radius:50%;overflow:hidden;background:var(--soft);color:var(--accent-text);display:grid;place-items:center;font-size:36px;margin:24px 0}.profile-avatar img{width:100%;height:100%;object-fit:cover}.password-form{display:grid;gap:16px;margin-top:24px}.remove-avatar{margin-top:12px}p{color:var(--muted);line-height:1.6}input[type=file]{max-width:100%}@media(max-width:760px){.profile-grid{grid-template-columns:1fr}} 
</style>
