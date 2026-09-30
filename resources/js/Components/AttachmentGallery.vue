<script setup lang="ts">
import { ref } from 'vue';
defineProps<{attachments:{id:number;name:string;mime:string}[]}>();
const failed = ref<Record<number,boolean>>({});
const isPhoto = (mime:string)=>['image/jpeg','image/png','image/webp'].includes(mime);
</script>
<template>
 <div class="attachment-gallery">
  <figure v-for="a in attachments" :key="a.id" class="attachment-card">
   <img v-if="isPhoto(a.mime)&&!failed[a.id]" :src="'/attachments/'+a.id+'/preview'" :alt="a.name" decoding="async" @error="failed[a.id]=true" />
   <p v-if="failed[a.id]" role="status">Не удалось загрузить фото. Попробуйте скачать файл.</p>
   <figcaption><span>{{a.name}}</span><a :href="'/attachments/'+a.id" :aria-label="'Скачать '+a.name">Скачать</a></figcaption>
  </figure>
 </div>
</template>
<style scoped>
.attachment-gallery{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:18px;margin-top:20px}.attachment-card{margin:0;min-width:0;border:1px solid var(--border);border-radius:8px;overflow:hidden}.attachment-card img{display:block;width:100%;height:320px;object-fit:contain;background:rgba(127,127,127,.08)}figcaption{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:12px 16px;font-size:13px}figcaption span{overflow-wrap:anywhere;min-width:0}figcaption a{flex-shrink:0;text-decoration:underline;text-underline-offset:3px;padding:8px 0}.attachment-card p{padding:12px 16px;color:var(--muted)}@media(max-width:600px){.attachment-card img{height:260px}}
</style>
