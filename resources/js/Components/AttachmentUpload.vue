<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
const p=defineProps<{type:string;id:number}>();const form=useForm({type:p.type,id:p.id,file:null as File|null});
</script>
<template><form class="toolbar" @submit.prevent="form.post('/attachments',{forceFormData:true})"><label>Фото или PDF, до 10 МБ<input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" required @change="form.file=($event.target as HTMLInputElement).files?.[0]??null"/></label><button :disabled="form.processing">Прикрепить</button><p class="error" v-for="e in form.errors" :key="e">{{e}}</p></form></template>
