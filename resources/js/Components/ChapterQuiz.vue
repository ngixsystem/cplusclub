<script setup lang="ts">
import { computed, ref } from 'vue';

export type QuizState = { questions: number; pass_percent: number; best_percent: number | null; passed: boolean; attempts: number };
type Question = { id: string; q: string; options: string[] };
type Review = { id: string; chosen: number | null; correct: number; ok: boolean; explain: string };
type Result = { correct: number; total: number; percent: number; passed: boolean; pass_percent: number; review: Review[]; quiz: QuizState };

const props = withDefaults(defineProps<{ articleId: string; quiz: QuizState; checklistDone: boolean; startUrl?: string; submitUrl?: string; requirement?: string }>(), { startUrl: '', submitUrl: '/knowledge/quiz/', requirement: '' });
const startEndpoint = computed(() => props.startUrl || `/knowledge/${props.articleId}/quiz`);
const emit = defineEmits<{ updated: [quiz: QuizState] }>();

const phase = ref<'idle' | 'running' | 'result'>('idle');
const attempt = ref<number | null>(null);
const questions = ref<Question[]>([]);
const answers = ref<Record<string, number>>({});
const result = ref<Result | null>(null);
const busy = ref(false);
const error = ref('');
const answered = computed(() => questions.value.filter(q => answers.value[q.id] !== undefined).length);
const review = computed(() => Object.fromEntries((result.value?.review ?? []).map(r => [r.id, r])));

const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] ?? '');
async function post<T>(url: string, body: unknown): Promise<T> {
 const r = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify(body) });
 if (r.status === 401) { window.location.assign('/login'); throw new Error('auth'); }
 if (!r.ok) throw new Error(String(r.status));
 return r.json();
}

async function start() {
 busy.value = true; error.value = '';
 try {
  const data = await post<{ attempt: number; questions: Question[] }>(startEndpoint.value, {});
  attempt.value = data.attempt; questions.value = data.questions; answers.value = {}; result.value = null; phase.value = 'running';
 } catch { error.value = 'Не удалось начать тест. Проверьте соединение и попробуйте ещё раз.'; }
 finally { busy.value = false; }
}

async function submit() {
 if (!attempt.value) return;
 busy.value = true; error.value = '';
 try {
  result.value = await post<Result>(`${props.submitUrl}${attempt.value}`, { answers: answers.value });
  phase.value = 'result';
  emit('updated', result.value.quiz);
 } catch { error.value = 'Не удалось отправить ответы. Проверьте соединение и попробуйте ещё раз.'; }
 finally { busy.value = false; }
}

const optionClass = (q: Question, i: number) => {
 const r = review.value[q.id];
 if (!r) return '';
 if (i === r.correct) return 'right';
 if (i === r.chosen) return 'wrong';
 return '';
};
</script>

<template>
 <section id="quiz" class="kb-checklist cq" aria-labelledby="quiz-title">
  <div class="kb-checklist-head">
   <h2 id="quiz-title">Тест по главе</h2>
   <span v-if="quiz.passed" class="cq-badge ok">Сдан · лучший {{quiz.best_percent}}%</span>
   <span v-else-if="quiz.attempts">Лучший результат {{quiz.best_percent ?? 0}}%</span>
  </div>

  <template v-if="phase==='idle'">
   <p class="cq-lead">{{quiz.questions}} вопросов по материалу этой главы. Чтобы глава засчиталась, нужно ответить правильно минимум на {{quiz.pass_percent}}% и отметить все пункты «Проверки результата». После проверки вы увидите правильные ответы с пояснениями. Пересдавать можно сколько угодно.</p>
   <p v-if="!checklistDone && !quiz.passed" class="cq-hint">{{ requirement || 'Совет: сначала прочитайте главу и выполните чек-лист выше.' }}</p>
   <button type="button" class="kb-source-button cq-button" :disabled="busy" @click="start">{{ quiz.attempts ? 'Пройти тест ещё раз' : 'Начать тест' }}</button>
  </template>

  <form v-else @submit.prevent="submit">
   <fieldset v-for="(q, n) in questions" :key="q.id" class="cq-question" :disabled="phase==='result'">
    <legend><span class="cq-num">{{n + 1}}.</span> {{q.q}}</legend>
    <label v-for="(option, i) in q.options" :key="i" :class="optionClass(q, i)">
     <input type="radio" :name="q.id" :value="i" v-model="answers[q.id]" />
     <span>{{option}}</span>
     <b v-if="optionClass(q, i)==='right'" class="cq-mark">✓ верно</b>
     <b v-else-if="optionClass(q, i)==='wrong'" class="cq-mark">✗ ваш ответ</b>
    </label>
    <p v-if="review[q.id]" class="cq-explain" :class="{ ok: review[q.id].ok }">{{ review[q.id].ok ? 'Правильно.' : 'Неверно.' }} {{review[q.id].explain}}</p>
   </fieldset>

   <div v-if="phase==='running'" class="cq-actions">
    <span>Отвечено {{answered}} из {{questions.length}}</span>
    <button type="submit" class="kb-source-button cq-button" :disabled="busy || answered < questions.length">Проверить ответы</button>
   </div>

   <div v-else-if="result" class="cq-result" :class="{ ok: result.passed }" role="status">
    <b>{{result.correct}} из {{result.total}} · {{result.percent}}%</b>
    <span v-if="result.passed">Тест сдан.{{ checklistDone ? ' Засчитано.' : (requirement ? ' ' + requirement : ' Отметьте все пункты «Проверки результата», чтобы глава засчиталась.') }}</span>
    <span v-else>Нужно минимум {{result.pass_percent}}%. Перечитайте пояснения выше и попробуйте снова.</span>
    <button type="button" class="kb-source-button cq-button" :disabled="busy" @click="start">Пройти ещё раз</button>
   </div>
  </form>
  <p v-if="error" class="error" role="alert">{{error}}</p>
 </section>
</template>

<style scoped>
.cq-lead{font-size:13px;line-height:1.7;color:var(--muted);margin:0 0 8px}
.cq-hint{font-size:12px;color:var(--muted);margin:0}
.cq-badge.ok{color:var(--accent-text);font-weight:600}
.cq-question{border:0;border-top:1px solid var(--border);margin:0;padding:16px 0;min-width:0}
.cq-question legend{font-size:14px;font-weight:600;line-height:1.6;padding:0;margin-bottom:10px;float:left;width:100%}
.cq-question legend+label{clear:left}
.cq-num{color:var(--muted);font-variant-numeric:tabular-nums}
.cq-question label{display:flex;align-items:flex-start;gap:10px;padding:9px 12px;margin:4px 0;border:1px solid var(--border);border-radius:6px;font-size:13px;line-height:1.6;cursor:pointer;border-top:1px solid var(--border)}
.cq-question label:hover{background:var(--hover)}
.cq-question label input{margin-top:4px;flex-shrink:0}
.cq-question label span{flex:1}
.cq-question label.right{border-color:var(--accent-text);background:color-mix(in srgb,var(--accent-text) 10%,transparent)}
.cq-question label.wrong{border-color:var(--error);background:color-mix(in srgb,var(--error) 10%,transparent)}
.cq-mark{font-size:11px;white-space:nowrap}
.cq-explain{font-size:12px;line-height:1.7;color:var(--muted);margin:8px 0 0;padding-left:12px;border-left:2px solid var(--error)}
.cq-explain.ok{border-left-color:var(--accent-text)}
.cq-actions{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;border-top:1px solid var(--border);padding-top:12px}
.cq-actions>span{font-size:12px;color:var(--muted)}
.cq-result{display:flex;flex-direction:column;gap:6px;border-top:1px solid var(--border);padding-top:16px;font-size:13px;line-height:1.6}
.cq-result b{font-size:18px}
.cq-result.ok b{color:var(--accent-text)}
.cq-button{margin:10px 0 0}
.cq-button:disabled{opacity:.5;cursor:not-allowed}
</style>
