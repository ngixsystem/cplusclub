<script setup lang="ts">
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
const props = defineProps<{ ticketId: number; version: number; pc: string | null; linkState: string | null }>();
const form = useForm({ version: props.version, pc_side: false, switch_side: false, comment: '' });
const ready = computed(() => form.pc_side || form.switch_side);
function submit() { form.version = props.version; form.post('/tickets/' + props.ticketId + '/link-fix', { preserveScroll: true }); }
</script>

<template>
  <form class="panel linkfix" @submit.prevent="submit">
    <div class="lf-head">
      <h2>Быстрое закрытие</h2>
      <p>Линк{{ pc ? ' ' + pc : '' }} падал до 100 Мбит/с. Отметьте, что сделали, и закройте заявку.</p>
      <p v-if="linkState === 'active'" class="state warn">Сейчас мониторинг всё ещё видит 100 Мбит/с — после обжима ПК стоит перезагрузить.</p>
      <p v-else-if="linkState" class="state ok">Мониторинг: скорость уже в норме.</p>
    </div>
    <div class="lf-checks">
      <label class="lf-check" :class="{ on: form.pc_side }"><input v-model="form.pc_side" type="checkbox" /><span class="box" aria-hidden="true"></span>Обжат со стороны ПК</label>
      <label class="lf-check" :class="{ on: form.switch_side }"><input v-model="form.switch_side" type="checkbox" /><span class="box" aria-hidden="true"></span>Обжат со стороны сетевого коммутатора</label>
    </div>
    <label class="lf-comment">Комментарий <span class="muted">(необязательно)</span><input v-model="form.comment" placeholder="Например: заменил патч-корд" /></label>
    <div class="error" role="alert" v-for="(e, k) in form.errors" :key="k">{{ e }}</div>
    <div class="lf-foot"><button :disabled="!ready || form.processing">{{ form.processing ? 'Закрываем…' : 'Закрыть заявку' }}</button><span v-if="!ready" class="muted">Отметьте хотя бы одну сторону</span></div>
  </form>
</template>

<style scoped>
.linkfix { display: grid; gap: 18px }
.lf-head h2 { margin: 0 0 4px } .lf-head p { margin: 0; color: var(--muted) }
.state { margin-top: 8px !important; font-size: 14px }
.state.warn { color: #b45309 } .state.ok { color: #15803d }
.lf-checks { display: grid; grid-template-columns: 1fr 1fr; gap: 12px }
.lf-check { position: relative; display: flex; flex-direction: row; align-items: center; gap: 14px; padding: 18px 18px; border: 1.5px solid var(--border); border-radius: 12px; cursor: pointer; font-weight: 500; font-size: 16px; transition: border-color .15s, background .15s }
.lf-check:hover { background: var(--hover) }
.lf-check.on { border-color: var(--text); background: var(--hover) }
.lf-check input { position: absolute; opacity: 0; inset: 0; cursor: pointer }
.lf-check input:focus-visible + .box { outline: 2px solid var(--text); outline-offset: 2px }
.box { flex: none; width: 22px; height: 22px; border: 1.5px solid var(--border-strong); border-radius: 6px; display: grid; place-items: center; transition: background .15s, border-color .15s }
.lf-check.on .box { background: var(--text); border-color: var(--text) }
.lf-check.on .box::after { content: ''; width: 6px; height: 11px; border: solid var(--bg, #fff); border-width: 0 2px 2px 0; transform: translateY(-1px) rotate(45deg) }
.lf-foot { display: flex; align-items: center; gap: 14px; flex-wrap: wrap }
.muted { color: var(--muted) }
@media (max-width: 700px) { .lf-checks { grid-template-columns: 1fr } }
</style>
