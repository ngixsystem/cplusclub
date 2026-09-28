<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import Shell from '../Layouts/Shell.vue';
import InspectionForm from '../Components/InspectionForm.vue';
type Inspection={id:number;status:string;equipment:{name:string};approved_at:string|null;findings:string|null;ticket_id:number|null;work_done:string|null;verification:string|null;skip_reason:string|null;rescheduled_date:string|null};
defineProps<{visit:{id:number;status:string;club:{name:string};report:string|null;inspections:Inspection[]};items:string[];canEdit:boolean}>();
const page=usePage<{auth:{user:{role:string}}}>();
</script>
<template><Head :title="'Выезд #'+visit.id"/><Shell><div class="heading"><h1>Выезд #{{visit.id}} · {{visit.club.name}}</h1><Link v-if="canEdit&&visit.status==='planned'&&visit.inspections.every(i=>i.status!=='pending')" :href="'/visits/'+visit.id+'/complete'" method="post" as="button">Завершить выезд</Link></div><section v-if="visit.report" class="panel"><h2>Отчёт по выезду</h2><p class="prewrap">{{visit.report}}</p></section><InspectionForm v-for="i in visit.inspections" :key="i.id" :inspection="i" :items="items" :can-edit="canEdit" :can-approve="['owner','lead','representative'].includes(page.props.auth.user.role)"/></Shell></template>
