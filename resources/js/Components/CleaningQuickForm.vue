<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { compressPhoto } from './photos';

type Pc = { id: number; club_id: number; name: string; type: string; workstation_number: string | null; zone: string | null };
const props = defineProps<{ clubs: { id: number; name: string }[]; computers: Pc[]; clubId: number | string }>();
const emit = defineEmits<{ (e: 'update:clubId', v: number | string): void; (e: 'other'): void; (e: 'done'): void }>();

const TASKS = ['Корпус и фильтры очищены', 'Вентиляторы очищены', 'Радиаторы очищены', 'Пыль удалена с комплектующих', 'Термопаста заменена'];
const MAX = 6;
const checked = reactive<Record<string, boolean>>(Object.fromEntries(TASKS.map(t => [t, t !== 'Термопаста заменена'])));
const form = useForm({
  club_id: props.clubId, equipment_id: '' as number | '', comment: '',
  verification_result: 'ПК загружается, игры запускаются, вентиляторы работают тихо, температуры в норме.',
  work_result: '', before: [] as File[], after: [] as File[],
});
type Shot = { key: number; file: File; url: string };
const shots = reactive<{ before: Shot[]; after: Shot[] }>({ before: [], after: [] });
const preparing = ref(0); const note = ref(''); let seq = 0;

const pcs = computed(() => props.computers.filter(p => p.club_id === Number(form.club_id)).sort((a, b) => a.name.localeCompare(b.name, 'ru', { numeric: true })));
watch(() => form.club_id, v => { form.equipment_id = ''; emit('update:clubId', v); });
const tasksDone = computed(() => TASKS.filter(t => checked[t]));
const ready = computed(() => form.equipment_id !== '' && tasksDone.value.length > 0 && form.verification_result.trim() !== '' && shots.before.length > 0 && shots.after.length > 0 && !preparing.value);
const missing = computed(() => [
  form.equipment_id === '' && 'выберите ПК', !tasksDone.value.length && 'отметьте работы',
  !shots.before.length && 'фото до', !shots.after.length && 'фото после',
].filter(Boolean).join(' · '));

async function pick(stage: 'before' | 'after', e: Event) {
  const input = e.target as HTMLInputElement; const files = Array.from(input.files ?? []); input.value = ''; note.value = '';
  for (const f of files) {
    if (shots[stage].length >= MAX) { note.value = `Не больше ${MAX} фото на этап.`; break; }
    if (!f.type.startsWith('image/')) { note.value = 'Нужны фотографии (JPG, PNG, WebP).'; continue; }
    preparing.value++;
    try { const file = await compressPhoto(f); shots[stage].push({ key: ++seq, file, url: URL.createObjectURL(file) }); }
    finally { preparing.value--; }
  }
}
function drop(stage: 'before' | 'after', s: Shot) { URL.revokeObjectURL(s.url); shots[stage] = shots[stage].filter(x => x.key !== s.key); }

function submit() {
  if (!ready.value) return;
  form.work_result = tasksDone.value.join('\n');
  form.before = shots.before.map(s => s.file); form.after = shots.after.map(s => s.file);
  form.post('/tickets/cleaning', { forceFormData: true, onSuccess: () => emit('done') });
}
onBeforeUnmount(() => [...shots.before, ...shots.after].forEach(s => URL.revokeObjectURL(s.url)));
</script>

<template>
  <form class="panel quick" @submit.prevent="submit">
    <header class="quick-head">
      <div><h2>Чистка ПК</h2><p>Заявка создастся и сразу закроется. Нужны только ПК и фото до/после.</p></div>
      <button type="button" class="secondary" @click="emit('other')">Другая заявка</button>
    </header>

    <div class="quick-grid">
      <label>Клуб<select v-model="form.club_id" required><option v-for="c in clubs" :key="c.id" :value="c.id">{{ c.name }}</option></select></label>
      <label>ПК<select v-model="form.equipment_id" required><option value="" disabled>Выберите ПК</option><option v-for="pc in pcs" :key="pc.id" :value="pc.id">{{ pc.name }}{{ pc.workstation_number ? ' · место ' + pc.workstation_number : '' }}{{ pc.zone ? ' · ' + pc.zone : '' }}</option></select></label>
    </div>
    <p v-if="!pcs.length" class="muted">В этом клубе нет ПК в реестре оборудования — сначала добавьте их.</p>

    <fieldset class="tasks"><legend>Что сделано</legend>
      <label v-for="t in TASKS" :key="t" class="task"><input v-model="checked[t]" type="checkbox" />{{ t }}</label>
    </fieldset>

    <div class="shots">
      <section v-for="stage in (['before', 'after'] as const)" :key="stage">
        <h3>{{ stage === 'before' ? 'До чистки' : 'После чистки' }} <small>{{ shots[stage].length }}/{{ MAX }}</small></h3>
        <div class="tiles">
          <figure v-for="s in shots[stage]" :key="s.key"><img :src="s.url" :alt="stage === 'before' ? 'Фото до чистки' : 'Фото после чистки'" /><button type="button" :aria-label="'Убрать фото'" @click="drop(stage, s)">×</button></figure>
          <label v-if="shots[stage].length < MAX" class="add" :class="{ empty: !shots[stage].length }">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z" /><circle cx="12" cy="13" r="3.5" /></svg>
            <span>{{ shots[stage].length ? 'Ещё' : 'Добавить фото' }}</span>
            <input type="file" accept="image/*" multiple :aria-label="stage === 'before' ? 'Фото до чистки' : 'Фото после чистки'" @change="pick(stage, $event)" />
          </label>
        </div>
      </section>
    </div>
    <p v-if="note" class="error" role="alert">{{ note }}</p>

    <details class="more"><summary>Результат проверки и комментарий</summary>
      <label>Результат проверки<textarea v-model="form.verification_result" rows="2" required /></label>
      <label>Комментарий <span class="muted">(необязательно)</span><textarea v-model="form.comment" rows="2" /></label>
    </details>

    <div class="error" role="alert" v-for="(e, k) in form.errors" :key="k">{{ e }}</div>
    <footer class="quick-foot">
      <button :disabled="!ready || form.processing">{{ form.processing ? (form.progress ? `Отправка ${form.progress.percentage}%` : 'Закрываем…') : 'Закрыть заявку' }}</button>
      <span v-if="preparing" class="muted" role="status">Подготавливаем фото…</span>
      <span v-else-if="!ready" class="muted">Осталось: {{ missing }}</span>
    </footer>
  </form>
</template>

<style scoped>
.quick { display: grid; gap: 22px; margin-bottom: 24px }
.quick-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px }
.quick-head h2 { margin: 0 0 4px } .quick-head p { margin: 0; color: var(--muted) }
.quick-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px 20px }
.tasks { border: 0; padding: 0; margin: 0; display: flex; flex-wrap: wrap; gap: 8px }
.tasks legend { font-weight: 600; margin-bottom: 10px }
.task { display: inline-flex; flex-direction: row; align-items: center; gap: 8px; padding: 8px 12px; border: 1px solid var(--border); border-radius: 999px; cursor: pointer; font-weight: 400 }
.task:has(input:checked) { border-color: var(--text); background: var(--hover) }
.task input { width: 16px; height: 16px; margin: 0 }
.shots { display: grid; grid-template-columns: 1fr 1fr; gap: 24px }
.shots h3 { font-size: 16px; font-weight: 600; margin: 0 0 10px } .shots h3 small { color: var(--muted); font-weight: 400 }
.tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 10px }
.tiles figure { position: relative; margin: 0; aspect-ratio: 1; border-radius: 10px; overflow: hidden; background: var(--hover) }
.tiles img { width: 100%; height: 100%; object-fit: cover; display: block }
.tiles figure button { position: absolute; top: 6px; right: 6px; width: 26px; height: 26px; padding: 0; border-radius: 50%; border: 0; background: rgba(0,0,0,.6); color: #fff; line-height: 1; font-size: 16px }
.add { position: relative; aspect-ratio: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; border: 1.5px dashed var(--border-strong); border-radius: 10px; color: var(--muted); cursor: pointer; font-size: 13px; text-align: center }
.add.empty { grid-column: span 2; aspect-ratio: auto; min-height: 120px }
.add:hover { background: var(--hover); color: var(--text) }
.add input { position: absolute; inset: 0; opacity: 0; cursor: pointer }
.more summary { cursor: pointer; color: var(--muted) } .more label { margin-top: 12px }
.quick-foot { display: flex; align-items: center; gap: 14px; flex-wrap: wrap }
.muted { color: var(--muted) }
@media (max-width: 700px) { .quick-grid, .shots { grid-template-columns: 1fr } .quick-head { flex-direction: column } }
</style>
