<script setup lang="ts">
import {ref} from 'vue';
import {useForm,usePage} from '@inertiajs/vue3';
const p=defineProps<{user:{id:number;name:string;email:string;role:string;active:boolean;clubs:{id:number}[]};clubs:{id:number;name:string}[]}>();
const page=usePage<{auth:{user:{id:number}}}>();
const editing=ref(false),confirming=ref(false);
const form=useForm({name:p.user.name,email:p.user.email,role:p.user.role,password:'',active:p.user.active,club_ids:p.user.clubs.map(c=>c.id)});
const removal=useForm({account:''});
const roles:Record<string,string>={owner:'Администратор платформы',lead:'Технический руководитель',specialist:'Выездной специалист',representative:'Представитель клуба'};
function edit(){form.name=p.user.name;form.email=p.user.email;form.role=p.user.role;form.active=p.user.active;form.club_ids=p.user.clubs.map(c=>c.id);form.password='';form.clearErrors();editing.value=true;confirming.value=false;}
function save(){form.post('/users/'+p.user.id,{preserveScroll:true,onSuccess:()=>{form.reset('password');editing.value=false;}});}
</script>
<template>
 <section class="panel user-card" :aria-label="'Пользователь '+user.email">
  <h2>{{user.name}} · {{user.email}}</h2><p>{{roles[user.role]}} · {{user.active?'Активен':'Отключён'}}</p>
  <div v-if="!editing" class="user-actions"><button type="button" class="secondary" @click="edit">Редактировать</button><button v-if="user.id!==page.props.auth.user.id" type="button" class="secondary" @click="confirming=!confirming">Удалить</button></div>
  <form v-if="editing" class="form-grid" @submit.prevent="save">
   <label>Имя<input v-model="form.name" required maxlength="100"/></label><label>Email<input v-model="form.email" type="email" required maxlength="255"/></label>
   <label>Новый пароль<input v-model="form.password" type="password" minlength="6" maxlength="200" autocomplete="new-password"/><small>Минимум 6 символов. Пустое поле сохраняет текущий пароль.</small></label>
   <label>Роль<select v-model="form.role" :disabled="user.id===page.props.auth.user.id"><option v-for="(label,value) in roles" :key="value" :value="value">{{label}}</option></select></label>
   <label class="check"><input v-model="form.active" type="checkbox" :disabled="user.id===page.props.auth.user.id"/>Активен</label>
   <fieldset><legend>Клубы</legend><label v-for="c in clubs" :key="c.id" class="check"><input type="checkbox" v-model="form.club_ids" :value="c.id"/>{{c.name}}</label></fieldset>
   <div class="user-actions"><button :disabled="form.processing">Сохранить изменения</button><button type="button" class="secondary" :disabled="form.processing" @click="editing=false;form.reset('password')">Отмена</button></div>
   <p class="error" role="alert" v-for="(error,key) in form.errors" :key="key">{{error}}</p>
  </form>
  <div v-if="confirming" class="delete-confirm" role="group" aria-label="Подтверждение удаления">
   <p>Удалить {{user.name}}? Вход будет запрещён, аккаунт исчезнет из списка. История работ сохранится. Email останется зарезервированным.</p>
   <div class="user-actions"><button type="button" :disabled="removal.processing" @click="removal.delete('/users/'+user.id,{preserveScroll:true})">Подтвердить удаление</button><button type="button" class="secondary" :disabled="removal.processing" @click="confirming=false">Отмена</button></div>
   <p class="error" role="alert" v-for="(error,key) in removal.errors" :key="key">{{error}}</p>
  </div>
 </section>
</template>
<style scoped>
.user-card h2{overflow-wrap:anywhere}.user-actions{display:flex;gap:12px;flex-wrap:wrap}.delete-confirm{border-top:1px solid var(--border);margin-top:20px;padding-top:12px}
</style>
