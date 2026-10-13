<script setup lang="ts">
import {computed,ref,watch,onBeforeUnmount} from 'vue';
const props=defineProps<{attachments:{id:number;name:string;mime:string}[]}>();
const failed=ref<Record<number,boolean>>({});
const isPhoto=(mime:string)=>['image/jpeg','image/png','image/webp'].includes(mime);
const photos=computed(()=>props.attachments.filter(a=>isPhoto(a.mime)));
const dialog=ref<HTMLDialogElement|null>(null),index=ref(0),zoom=ref(1),viewer=ref<HTMLElement|null>(null);
const current=computed(()=>photos.value[index.value]);let trigger:HTMLElement|null=null;let overflow='';
function open(id:number,event:Event){index.value=photos.value.findIndex(a=>a.id===id);zoom.value=1;trigger=event.currentTarget as HTMLElement;overflow=document.body.style.overflow;document.body.style.overflow='hidden';dialog.value?.showModal();}
function closed(){document.body.style.overflow=overflow;trigger?.focus();}
function move(step:number){index.value=(index.value+step+photos.value.length)%photos.value.length;zoom.value=1;viewer.value?.scrollTo(0,0);}
function key(e:KeyboardEvent){if(e.key==='ArrowRight'||e.key==='ArrowLeft'){e.preventDefault();move(e.key==='ArrowRight'?1:-1);}}
watch(photos,()=>{if(!photos.value.length)dialog.value?.close();else index.value=Math.min(index.value,photos.value.length-1);});
onBeforeUnmount(()=>{if(dialog.value?.open)document.body.style.overflow=overflow;});
</script>
<template>
 <div class="attachment-gallery">
  <figure v-for="a in attachments" :key="a.id" class="attachment-card">
   <button v-if="isPhoto(a.mime)&&!failed[a.id]" type="button" class="photo-open" :aria-label="'Увеличить '+a.name" @click="open(a.id,$event)"><img :src="'/attachments/'+a.id+'/preview'" :alt="a.name" decoding="async" @error="failed[a.id]=true"/><span>Увеличить ↗</span></button>
   <p v-if="failed[a.id]" role="status">Не удалось загрузить фото. Попробуйте скачать файл.</p>
   <figcaption><span>{{a.name}}</span><a :href="'/attachments/'+a.id" :aria-label="'Скачать '+a.name">Скачать</a></figcaption>
  </figure>
 </div>
 <Teleport to="body"><dialog ref="dialog" class="photo-dialog" aria-label="Просмотр фотографий" @keydown="key" @close="closed" @click="($event.target===dialog)&&dialog?.close()">
  <template v-if="current"><header><div><strong>{{current.name}}</strong><small aria-live="polite">Фото {{index+1}} из {{photos.length}}</small></div><button type="button" class="secondary" autofocus @click="dialog?.close()" aria-label="Закрыть просмотр">Закрыть ×</button></header>
   <div ref="viewer" class="photo-viewer"><img :src="'/attachments/'+current.id+'/preview'" :alt="current.name" :style="zoom===1?{}:{width:zoom*100+'%',maxWidth:'none',height:'auto',maxHeight:'none'}" @error="failed[current.id]=true"/><p v-if="failed[current.id]" role="alert">Не удалось загрузить изображение.</p></div>
   <footer><button type="button" class="secondary" :disabled="photos.length<2" @click="move(-1)" aria-label="Предыдущее фото">← Назад</button><button type="button" class="secondary" :disabled="zoom<=1" @click="zoom=Math.max(1,zoom-.5)" aria-label="Уменьшить">−</button><button type="button" class="secondary" @click="zoom=1">{{Math.round(zoom*100)}}%</button><button type="button" class="secondary" :disabled="zoom>=3" @click="zoom=Math.min(3,zoom+.5)" aria-label="Увеличить масштаб">+</button><button type="button" class="secondary" :disabled="photos.length<2" @click="move(1)" aria-label="Следующее фото">Далее →</button><a :href="'/attachments/'+current.id">Скачать</a></footer>
  </template>
 </dialog></Teleport>
</template>
<style scoped>
.attachment-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,210px),1fr));gap:14px;margin-top:20px}.attachment-card{margin:0;min-width:0;border:1px solid var(--border);border-radius:10px;overflow:hidden}.photo-open{display:block;position:relative;width:100%;padding:0;border:0;border-radius:0;background:var(--hover);color:var(--text)}.photo-open img{display:block;width:100%;height:210px;object-fit:contain}.photo-open span{position:absolute;bottom:8px;right:8px;padding:6px 10px;border-radius:6px;background:var(--surface);font-size:12px}.photo-open:focus-visible{outline:3px solid var(--accent-text);outline-offset:-3px}figcaption{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 12px;font-size:12px}figcaption span{overflow-wrap:anywhere;min-width:0}figcaption a{flex-shrink:0;padding:8px 0}.attachment-card p{padding:12px;color:var(--muted)}
.photo-dialog{position:fixed;inset:0;margin:auto;width:min(1100px,96vw);max-width:96vw;height:92dvh;max-height:92dvh;padding:0;border:1px solid var(--border);border-radius:14px;background:var(--surface);color:var(--text)}.photo-dialog[open]{display:flex;flex-direction:column}.photo-dialog::backdrop{background:rgba(0,0,0,.82)}.photo-dialog header,.photo-dialog footer{display:flex;gap:12px;align-items:center;padding:14px 18px;flex-shrink:0}.photo-dialog header{justify-content:space-between;border-bottom:1px solid var(--border)}.photo-dialog header div{min-width:0;overflow-wrap:anywhere}.photo-dialog small{display:block;color:var(--muted);margin-top:4px}.photo-dialog footer{justify-content:center;flex-wrap:wrap;border-top:1px solid var(--border)}.photo-dialog button{min-height:44px}.photo-viewer{flex:1;min-height:0;overflow:auto;background:var(--bg);text-align:center}.photo-viewer img{display:block;margin:auto;width:100%;height:100%;object-fit:contain}.photo-dialog a{color:var(--accent-text);padding:10px}@media(max-width:600px){.photo-dialog header,.photo-dialog footer{padding:10px;gap:6px}.photo-dialog header strong{font-size:13px}.photo-dialog footer button{padding:8px 10px}}
</style>
