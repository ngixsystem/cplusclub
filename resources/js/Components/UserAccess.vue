<script setup lang="ts">
import {useForm} from '@inertiajs/vue3';
const p=defineProps<{user:{id:number;name:string;email:string;role:string;active:boolean;clubs:{id:number}[]};clubs:{id:number;name:string}[]}>();const form=useForm({active:p.user.active,club_ids:p.user.clubs.map(c=>c.id)});
</script>
<template><form class="panel" @submit.prevent="form.post('/users/'+user.id)"><h2>{{user.name}} · {{user.email}}</h2><p>{{user.role}}</p><label class="check"><input type="checkbox" v-model="form.active"/>Активен</label><label v-for="c in clubs" :key="c.id" class="check"><input type="checkbox" v-model="form.club_ids" :value="c.id"/>{{c.name}}</label><button :disabled="form.processing">Обновить доступ</button><p class="error" v-for="e in form.errors" :key="e">{{e}}</p></form></template>
