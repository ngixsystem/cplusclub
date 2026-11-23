<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Shell from '../Layouts/Shell.vue';
import '../../css/knowledge.css';

type Question = { id: string; q: string; options: string[] };
type ChapterScore = { id: string; title: string; correct: number; total: number };
type Result = { points: number; max_points: number; correct: number; total: number; percent: number; passed: boolean; pass_percent: number; duration_seconds: number; chapters: ChapterScore[] };
const props = defineProps<{
 attempt: { id: number; started_at: string; finished: boolean };
 track: { id: string; title: string; questions: number; points_per_answer: number; pass_percent: number; retake_minutes: number };
 questions: Question[] | null; result: Result | null;
}>();

const maxPoints = computed(() => props.track.questions * props.track.points_per_answer);
const passPoints = computed(() => Math.ceil(maxPoints.value * props.track.pass_percent / 100));
const form = useForm<{ answers: Record<string, number> }>({ answers: {} });
const answered = computed(() => (props.questions ?? []).filter(q => form.answers[q.id] !== undefined).length);
const now = ref(Date.now());
let timer: number | undefined;
onMounted(() => { timer = window.setInterval(() => { now.value = Date.now(); }, 1000); });
onBeforeUnmount(() => window.clearInterval(timer));
const elapsed = computed(() => Math.max(0, Math.floor((now.value - new Date(props.attempt.started_at).getTime()) / 1000)));
const clock = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
function duration(seconds: number) {
 const h = Math.floor(seconds / 3600), m = Math.floor(seconds % 3600 / 60), s = seconds % 60;
 return h ? `${h} ч ${m} мин` : m ? `${m} мин ${s} с` : `${s} с`;
}
function submit() {
 const left = (props.questions?.length ?? 0) - answered.value;
 if (left && !window.confirm(`Без ответа осталось ${left} — они будут засчитаны как неправильные. Отправить?`)) return;
 form.post(`/training/exam/${props.attempt.id}`, { preserveScroll: false });
}
</script>

<template>
 <Head title="Экзамен" />
 <Shell>
  <div class="kb-heading"><div><h1>Экзамен: {{track.title}}</h1>
   <p>{{track.questions}} вопросов · {{track.points_per_answer}} баллов за правильный ответ · максимум {{maxPoints}} · проходной балл {{passPoints}}</p></div>
   <Link href="/training" class="kb-source-button">← К обучению</Link></div>

  <section v-if="result" class="ex-result" :class="{ ok: result.passed }" role="status">
   <span class="ex-eyebrow">{{ result.passed ? 'Экзамен сдан' : 'Экзамен не сдан' }}</span>
   <p class="ex-score"><b>{{result.points}}</b> из {{result.max_points}} баллов</p>
   <p>{{result.correct}} правильных из {{result.total}} ({{result.percent}}%) · время {{duration(result.duration_seconds)}}</p>
   <p v-if="result.passed">Поздравляем! Программа пройдена — результат и срок обучения сохранены в вашем профиле.</p>
   <p v-else>Нужно {{passPoints}} баллов ({{result.pass_percent}}%). Повторите главы с ошибками — пересдать можно через {{track.retake_minutes}} минут.</p>
   <h2>Результат по главам</h2>
   <ul class="ex-chapters">
    <li v-for="c in result.chapters" :key="c.id" :class="{ miss: c.correct < c.total }">
     <Link :href="'/knowledge/'+c.id">{{c.title}}</Link><span>{{c.correct}} / {{c.total}}</span>
    </li>
   </ul>
   <p class="ex-note">Правильные ответы экзамена не показываются, чтобы следующая попытка проверяла знания, а не память. Разбор с пояснениями есть в тестах глав.</p>
   <Link href="/training" class="kb-source-button">Вернуться к обучению</Link>
  </section>

  <form v-else-if="questions" class="ex-form" @submit.prevent="submit">
   <div class="ex-bar" role="status"><span>Отвечено {{answered}} из {{questions.length}}</span><span>Идёт {{clock(elapsed)}}</span></div>
   <p class="ex-note">Время экзамена фиксируется. Можно закрыть страницу и вернуться — попытка продолжится, но время идёт. Ответы проверяются только после отправки.</p>
   <fieldset v-for="(q, n) in questions" :key="q.id" class="ex-question">
    <legend><span>{{n + 1}}.</span> {{q.q}}</legend>
    <label v-for="(option, i) in q.options" :key="i"><input type="radio" :name="q.id" :value="i" v-model="form.answers[q.id]" /><span>{{option}}</span></label>
   </fieldset>
   <p v-if="Object.keys(form.errors).length" class="error" role="alert">Не удалось отправить ответы. Попробуйте ещё раз.</p>
   <button type="submit" class="kb-source-button" :disabled="form.processing">Завершить экзамен</button>
  </form>
 </Shell>
</template>

<style scoped>
.ex-result,.ex-form{background:var(--surface);border:1px solid var(--border);border-radius:7px;padding:24px 26px;max-width:860px}
.ex-result.ok{border-color:var(--accent-text)}
.ex-eyebrow{display:block;font-size:11px;letter-spacing:.06em;text-transform:uppercase;font-weight:600;color:var(--warning)}
.ex-result.ok .ex-eyebrow{color:var(--accent-text)}
.ex-score{font-size:15px;margin:8px 0}
.ex-score b{font-size:40px;font-variant-numeric:tabular-nums;margin-right:4px}
.ex-result p{font-size:13px;line-height:1.7;margin:4px 0}
.ex-result h2{font-size:15px;margin:22px 0 8px}
.ex-chapters{list-style:none;margin:0;padding:0}
.ex-chapters li{display:flex;justify-content:space-between;gap:16px;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px}
.ex-chapters li span{font-variant-numeric:tabular-nums;color:var(--accent-text)}
.ex-chapters li.miss span{color:var(--warning)}
.ex-note{font-size:12px!important;color:var(--muted)}
.ex-bar{position:sticky;top:76px;z-index:5;display:flex;justify-content:space-between;gap:12px;padding:10px 14px;margin:-8px 0 10px;border:1px solid var(--border);border-radius:6px;background:var(--surface-2);font-size:12px;font-variant-numeric:tabular-nums}
.ex-question{border:0;border-top:1px solid var(--border);margin:0;padding:16px 0;min-width:0}
.ex-question legend{font-size:14px;font-weight:600;line-height:1.6;padding:0;margin-bottom:10px;float:left;width:100%}
.ex-question legend+label{clear:left}
.ex-question legend span{color:var(--muted)}
.ex-question label{display:flex;align-items:flex-start;gap:10px;padding:9px 12px;margin:4px 0;border:1px solid var(--border);border-radius:6px;font-size:13px;line-height:1.6;cursor:pointer}
.ex-question label:hover{background:var(--hover)}
.ex-question label input{margin-top:4px;flex-shrink:0}
@media(max-width:760px){.ex-result,.ex-form{padding:18px}.ex-score b{font-size:32px}}
</style>
