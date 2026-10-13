<script setup lang="ts">
import {ref,onBeforeUnmount} from 'vue';
import {useForm} from '@inertiajs/vue3';
const p=defineProps<{type:string;id:number;stage?:'before'|'after'}>();
type Item={key:number;file:File;url:string;status:string;error:string};
const items=ref<Item[]>([]),busy=ref(false),dragging=ref(false),message=ref('');let sequence=0;
const form=useForm({type:p.type,id:p.id,stage:p.stage??null,file:null as File|null});
function add(files:File[]){
  if(busy.value)return;message.value='';
  for(const file of files){
    if(items.value.length>=10){message.value='Не более 10 файлов за раз.';break;}
    if(!['image/jpeg','image/png','image/webp',...(p.stage?[]:['application/pdf'])].includes(file.type)||file.size>10*1024*1024){message.value='Допустимы JPG, PNG, WebP'+(p.stage?'':' или PDF')+' до 10 МБ каждый.';continue;}
    items.value.push({key:++sequence,file,url:file.type.startsWith('image/')?URL.createObjectURL(file):'',status:'Ожидает',error:''});
  }
}
function select(e:Event){const input=e.target as HTMLInputElement;add(Array.from(input.files??[]));input.value='';}
function drop(e:DragEvent){dragging.value=false;add(Array.from(e.dataTransfer?.files??[]));}
function remove(item:Item){URL.revokeObjectURL(item.url);items.value=items.value.filter(x=>x.key!==item.key);}
async function upload(){
  busy.value=true;
  try{for(const item of items.value.filter(x=>x.status!=='Загружено')){
    form.file=item.file;item.status='Загрузка';item.error='';form.clearErrors();
    await new Promise<void>(resolve=>form.post('/attachments',{forceFormData:true,preserveScroll:true,
      onSuccess:()=>{item.status='Загружено';},onError:errors=>{item.status='Ошибка';item.error=Object.values(errors).join(' ');},onCancel:()=>{item.status='Ошибка';item.error='Загрузка отменена.';},onFinish:()=>{if(item.status==='Загрузка'){item.status='Ошибка';item.error='Попробуйте снова.';}resolve();}}));
  }}finally{busy.value=false;}
}
onBeforeUnmount(()=>items.value.forEach(x=>URL.revokeObjectURL(x.url)));
</script>
<template><form class="upload-box" @submit.prevent="upload">
  <label class="dropzone" :class="{dragging}" @dragover.prevent="dragging=true" @dragleave.prevent="dragging=false" @drop.prevent="drop">
    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 16V3m-5 5 5-5 5 5M4 15v6h16v-6"/></svg>
    <strong>Перетащите фотографии сюда</strong><span>или выберите несколько файлов</span>
    <input type="file" multiple :disabled="busy" :accept="stage?'image/jpeg,image/png,image/webp':'image/jpeg,image/png,image/webp,application/pdf'" :aria-label="stage==='before'?'Фото до чистки':stage==='after'?'Фото после чистки':'Фото или PDF'" @change="select"/>
    <small>До 10 файлов за раз · до 10 МБ каждый{{stage?'':' · PDF также поддерживается'}}</small>
  </label>
  <p v-if="message" class="error" role="alert">{{message}}</p>
  <div class="upload-queue"><article v-for="item in items" :key="item.key"><img v-if="item.url" :src="item.url" alt="Предпросмотр выбранного фото"/><div><strong>{{item.file.name}}</strong><span role="status">{{item.status}}</span><p v-if="item.error" class="error">{{item.error}}</p></div><button type="button" class="secondary" :disabled="busy" :aria-label="'Убрать '+item.file.name" @click="remove(item)">×</button></article></div>
  <div class="upload-actions"><button :disabled="busy||!items.some(x=>x.status!=='Загружено')">{{busy?'Загрузка…':'Прикрепить'}}</button><span v-if="busy&&form.progress" role="status">{{form.progress.percentage}}%</span><small v-if="items.some(x=>x.status==='Загружено')">Загруженные файлы уже сохранены.</small></div>
</form></template>
<style scoped>
.upload-box{margin-top:16px}.dropzone{display:flex;flex-direction:column;align-items:center;gap:10px;text-align:center;padding:24px 16px;border:2px dashed var(--border);border-radius:12px;background:var(--hover);cursor:pointer}.dropzone.dragging,.dropzone:focus-within{border-color:var(--accent-text);background:var(--soft)}.dropzone svg{color:var(--accent-text)}.dropzone span,.dropzone small,.upload-actions small{color:var(--muted)}input{max-width:100%;font-size:12px}.upload-queue{display:grid;gap:8px;margin:12px 0}.upload-queue article{display:flex;gap:12px;align-items:center;border:1px solid var(--border);border-radius:8px;padding:10px;min-width:0}.upload-queue img{width:56px;height:56px;object-fit:cover;border-radius:6px}.upload-queue article div{flex:1;min-width:0;overflow-wrap:anywhere}.upload-queue strong,.upload-queue span{display:block;font-size:12px}.upload-actions{display:flex;align-items:center;flex-wrap:wrap;gap:12px}
</style>
