<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import Shell from '../Layouts/Shell.vue';
import '../../css/knowledge.css';

type State = { sessions: number; passed: number; best: { score: number; seconds: number } | null; tasks: number; pass: number };
type Task = { n: number; prompt: string; hint: string };
type Review = { prompt: string; given: string; answer: string; ok: boolean; explain: string };
type Result = { score: number; passed: boolean; correct: number; total: number; seconds: number; review: Review[] };
const props = defineProps<{ state: State }>();
const state = ref(props.state);
const attempt = ref<number | null>(null);
const tasks = ref<Task[]>([]);
const answers = ref<string[]>([]);
const result = ref<Result | null>(null);
const error = ref('');
const busy = ref(false);
const startedAt = ref(0);
const now = ref(Date.now());
const timer = window.setInterval(() => { now.value = Date.now(); }, 1000);
onBeforeUnmount(() => window.clearInterval(timer));
const elapsed = computed(() => startedAt.value ? Math.max(0, Math.floor((now.value - startedAt.value) / 1000)) : 0);
const clock = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] ?? '');
async function post(url: string, body: unknown) {
 const r = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify(body) });
 const data = await r.json().catch(() => ({}));
 if (!r.ok) throw new Error(data.message || 'Не удалось выполнить запрос.');
 return data;
}
async function start() {
 busy.value = true; error.value = ''; result.value = null;
 try {
  const data = await post('/training/subnets/start', {});
  attempt.value = data.attempt; tasks.value = data.tasks; answers.value = data.tasks.map(() => '');
  startedAt.value = new Date(data.started_at).getTime();
 } catch (e) { error.value = (e as Error).message; } finally { busy.value = false; }
}
async function submit() {
 if (!attempt.value) return;
 busy.value = true; error.value = '';
 try {
  const data = await post(`/training/subnets/${attempt.value}`, { answers: answers.value });
  result.value = data.result; state.value = data.state; attempt.value = null; tasks.value = []; startedAt.value = 0;
  window.scrollTo({ top: 0, behavior: 'smooth' });
 } catch (e) { error.value = (e as Error).message; } finally { busy.value = false; }
}
const filled = computed(() => answers.value.filter(a => a.trim()).length);
</script>

<template>
 <Head title="Тренажёр подсетей" />
 <Shell>
  <div class="kb-heading"><div><h1>Тренажёр подсетей</h1><p>{{state.tasks}} случайных задач по IPv4: адрес сети, broadcast, маска, число узлов, подбор префикса. Ответы проверяются после отправки, проходной результат — {{state.pass}} %.</p></div><Link href="/training" class="kb-source-button">← Обучение</Link></div>
  <p v-if="error" class="error" role="alert">{{error}}</p>

  <section v-if="result" class="panel lab-result" aria-label="Результат">
   <h2 :class="result.passed ? 'tr-ok' : ''">{{result.passed ? 'Зачтено' : 'Не зачтено'}}: {{result.correct}} из {{result.total}} ({{result.score}} %) за {{clock(result.seconds)}}</h2>
   <ol class="lab-review">
    <li v-for="(r, i) in result.review" :key="i" :class="r.ok ? 'ok' : 'bad'">
     <b>{{r.prompt}}</b>
     <span>Ваш ответ: {{ r.given || '—' }} · {{ r.ok ? 'верно' : 'правильно: ' + r.answer }}</span>
     <small>{{r.explain}}</small>
    </li>
   </ol>
  </section>

  <section v-if="!attempt" class="panel">
   <p v-if="state.best">Лучший результат: <b>{{state.best.score}} %</b> за {{clock(state.best.seconds)}} · тренировок {{state.sessions}}, зачтено {{state.passed}}.</p>
   <p v-else>Вы ещё не решали. Совет: держите под рукой таблицу степеней двойки — 2⁸ = 256, блок /26 = 64 адреса, /27 = 32, /28 = 16.</p>
   <button type="button" :disabled="busy" @click="start">{{ result ? 'Новая тренировка' : 'Начать тренировку' }}</button>
  </section>

  <form v-else class="panel lab-form" @submit.prevent="submit">
   <p class="lab-meta">Время: <b>{{clock(elapsed)}}</b> · заполнено {{filled}} из {{tasks.length}}</p>
   <label v-for="(t, i) in tasks" :key="t.n" class="lab-field">
    <span>{{i + 1}}. {{t.prompt}}</span>
    <input v-model="answers[i]" type="text" autocomplete="off" spellcheck="false" :placeholder="t.hint" maxlength="60" />
   </label>
   <button type="submit" :disabled="busy">Проверить ответы</button>
  </form>
 </Shell>
</template>

<style scoped>
.lab-form{display:grid;gap:12px}
.lab-field{display:grid;gap:6px}
.lab-field span{font-weight:600}
.lab-meta{margin:0;color:var(--muted)}
.lab-review{display:grid;gap:10px;padding-left:20px}
.lab-review li{display:grid;gap:3px}
.lab-review li.bad span{color:var(--danger, #c0392b)}
.lab-review small{color:var(--muted)}
.lab-result{margin-bottom:14px}
.tr-ok{color:var(--success, #1e8e3e)}
</style>
