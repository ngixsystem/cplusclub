<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
const props = defineProps<{club:{id:number;icafe_license?:number|null;icafe_currency?:string}}>();
const form = useForm({icafe_license:props.club.icafe_license ?? '',icafe_token:'',icafe_currency:props.club.icafe_currency ?? 'UZS'});
</script>
<template>
 <section class="panel"><h2>Подключение iCafeCloud</h2><p>Лицензия клуба и API-токен. Токен хранится в зашифрованном виде и не возвращается в браузер. Вводите его только через HTTPS или SSH-туннель.</p>
 <form class="form-grid" @submit.prevent="form.post('/clubs/'+club.id+'/icafe',{onSuccess:()=>form.reset('icafe_token')})">
 <label>Номер лицензии<input v-model="form.icafe_license" type="number" min="1" required /></label>
 <label>Валюта<select v-model="form.icafe_currency"><option>UZS</option><option>USD</option><option>RUB</option><option>KZT</option><option>EUR</option></select></label>
 <label>API-токен<input v-model="form.icafe_token" type="password" autocomplete="new-password" :placeholder="club.icafe_license?'Оставьте пустым, чтобы сохранить токен':''" /></label>
 <button :disabled="form.processing">{{form.processing?'Проверяем подключение…':'Проверить и сохранить'}}</button>
 <p v-for="(error,key) in form.errors" :key="key" class="error" role="alert">{{error}}</p>
 </form></section>
</template>
