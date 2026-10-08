<script setup lang="ts">
import {ref} from 'vue';
import {useForm} from '@inertiajs/vue3';const p=defineProps<{ticketId:number}>();const form=useForm({minutes:30,cost_minor:0,currency:'USD',actions:'',consumables:''});
const dollars=ref('0.00');
function submit(){form.cost_minor=Math.round(Number(dollars.value)*100);form.post('/tickets/'+p.ticketId+'/work');}
</script>
<template><form class="panel form-grid" @submit.prevent="submit()"><h2>Трудозатраты и расходы</h2><label>Минуты<input v-model="form.minutes" type="number" min="1" max="1440" required/></label><label>Стоимость, USD<input v-model="dollars" type="number" min="0" max="1000000000" step="0.01" inputmode="decimal" required/></label><label>Валюта<input v-model="form.currency" readonly/></label><label>Выполненные действия<textarea v-model="form.actions" required/></label><label>Расходники<textarea v-model="form.consumables"/></label><button :disabled="form.processing">Добавить запись</button><p v-for="e in form.errors" :key="e" class="error">{{e}}</p></form></template>
