<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import Shell from '../Layouts/Shell.vue';
import '../../css/knowledge.css';

type Check = { results: { label: string; ok: boolean }[]; violations: string[]; score: number; passed: boolean; commands: number };
type Task = { id: string; title: string; level: string; task: string; hint: string; requirements: number; attempts: number; passed: boolean; best: number | null;
 last: { input: string; result: Check } | null; solution: string[] | null };
const props = defineProps<{ tasks: Task[] }>();
const tasks = ref(props.tasks);
const current = ref(tasks.value.find(t => !t.passed)?.id ?? tasks.value[0]?.id);
const task = computed(() => tasks.value.find(t => t.id === current.value)!);
const drafts = ref<Record<string, string>>(Object.fromEntries(tasks.value.map(t => [t.id, t.last?.input ?? ''])));
const checks = ref<Record<string, Check | null>>(Object.fromEntries(tasks.value.map(t => [t.id, t.last?.result ?? null])));
const showHint = ref(false);
const busy = ref(false);
const error = ref('');
const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] ?? '');
async function check() {
 busy.value = true; error.value = '';
 try {
  const r = await fetch(`/training/ios/${task.value.id}`, { method: 'POST', credentials: 'same-origin',
   headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify({ commands: drafts.value[task.value.id] }) });
  const data = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(data.message || 'Не удалось проверить.');
  checks.value[task.value.id] = data.result;
  tasks.value = tasks.value.map(t => t.id === data.task.id ? data.task : t);
 } catch (e) { error.value = (e as Error).message; } finally { busy.value = false; }
}
function select(id: string) { current.value = id; showHint.value = false; error.value = ''; }
const solved = computed(() => tasks.value.filter(t => t.passed).length);
</script>

<template>
 <Head title="Тренажёр команд IOS" />
 <Shell>
  <div class="kb-heading"><div><h1>Тренажёр команд IOS</h1><p>Введите команды так, как набирали бы их в консоли Cisco (можно вставить вместе с приглашением SW1(config)#). Система разберёт режимы и проверит каждый пункт задания. Решено {{solved}} из {{tasks.length}}.</p></div><Link href="/training" class="kb-source-button">← Обучение</Link></div>
  <div class="ios-layout">
   <nav aria-label="Задачи" class="ios-list">
    <button v-for="t in tasks" :key="t.id" type="button" :class="{ active: t.id === current, passed: t.passed }" :aria-current="t.id === current ? 'true' : undefined" @click="select(t.id)">
     <b>{{t.title}}</b><small>{{t.level}} · {{ t.passed ? 'решено ✓' : t.attempts ? 'лучший ' + t.best + ' %' : 'не начато' }}</small>
    </button>
   </nav>
   <section v-if="task" class="panel ios-task" :aria-label="task.title">
    <h2>{{task.title}}</h2>
    <p>{{task.task}}</p>
    <button type="button" class="secondary" @click="showHint = !showHint">{{ showHint ? 'Скрыть подсказку' : 'Подсказка' }}</button>
    <p v-if="showHint" class="ios-hint">{{task.hint}}</p>
    <label class="ios-editor"><span>Команды</span>
     <textarea v-model="drafts[task.id]" rows="14" spellcheck="false" autocomplete="off" autocapitalize="off" placeholder="SW1(config)# vlan 10&#10;SW1(config-vlan)# name STAFF"></textarea>
    </label>
    <p v-if="error" class="error" role="alert">{{error}}</p>
    <button type="button" :disabled="busy || !drafts[task.id]?.trim()" @click="check">Проверить</button>

    <div v-if="checks[task.id]" class="ios-result" role="status">
     <h3 :class="{ 'tr-ok': checks[task.id]!.passed }">{{ checks[task.id]!.passed ? 'Задача решена' : 'Есть замечания' }} · {{checks[task.id]!.score}} %</h3>
     <ul>
      <li v-for="r in checks[task.id]!.results" :key="r.label" :class="r.ok ? 'ok' : 'bad'">{{ r.ok ? '✓' : '✗' }} {{r.label}}</li>
      <li v-for="v in checks[task.id]!.violations" :key="v" class="bad">⚠ {{v}}</li>
     </ul>
    </div>
    <details v-if="task.solution" class="ios-solution"><summary>Эталонное решение</summary><pre>{{task.solution.join('\n')}}</pre></details>
    <p v-else-if="task.attempts" class="lab-muted">Эталон откроется после решения или после 3 неудачных попыток.</p>
   </section>
  </div>
 </Shell>
</template>

<style scoped>
.ios-layout{display:grid;grid-template-columns:minmax(200px,260px) 1fr;gap:14px;align-items:start}
.ios-list{display:grid;gap:6px}
.ios-list button{display:grid;gap:2px;text-align:left;background:var(--surface);color:inherit;border:1px solid var(--border);padding:10px 12px;border-radius:6px}
.ios-list button.active{border-color:var(--accent-text)}
.ios-list button.passed small{color:var(--success, #1e8e3e)}
.ios-task{display:grid;gap:10px;min-width:0}
.ios-task h2{margin:0}
.ios-hint{margin:0;color:var(--muted)}
.ios-editor{display:grid;gap:6px}
.ios-editor textarea{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;width:100%;box-sizing:border-box}
.ios-result ul{list-style:none;padding:0;margin:0;display:grid;gap:4px}
.ios-result .bad{color:var(--danger, #c0392b)}
.ios-result .ok{color:var(--success, #1e8e3e)}
.ios-solution pre{white-space:pre-wrap;font-size:13px;overflow-x:auto}
.lab-muted{color:var(--muted);margin:0}
.tr-ok{color:var(--success, #1e8e3e)}
@media (max-width: 760px){.ios-layout{grid-template-columns:1fr}}
</style>
