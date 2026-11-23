<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';

type Check = { id: string; label: string; done: boolean; finding: string | null; harmful: boolean | null; key: boolean | null };
type Item = { key: string; label: string; max: number; points: number; note: string };
export type Training = {
 run_id: number; status: 'active' | 'finished'; started_at: string; can_check: boolean;
 scenario: { id: string; title: string; summary: string; level: string; target_minutes: number; chapters: { id: string; title: string }[] };
 checks: Check[];
 result: { score: number; max_score: number; passed: boolean; pass_score: number; duration_seconds: number; target_minutes: number; breakdown: Item[];
  model: { cause: string; solution: string; verification: string; work: string } } | null;
};
const props = defineProps<{ training: Training; status: string }>();
const page = usePage<{ errors: Record<string, string> }>();
const busy = ref<string | null>(null);
const findings = computed(() => props.training.checks.filter(c => c.done));
function check(id: string) {
 busy.value = id;
 router.post(`/training/practice/run/${props.training.run_id}/check`, { check: id }, { preserveScroll: true, onFinish: () => { busy.value = null; } });
}
function duration(seconds: number) {
 const h = Math.floor(seconds / 3600), m = Math.floor(seconds % 3600 / 60);
 return h ? `${h} ч ${m} мин` : `${Math.max(1, m)} мин`;
}
const nextStep = computed(() => ({
 new: 'Примите заявку: выберите статус «Принята» и сохраните переход. Скорость реакции тоже оценивается.',
 accepted: 'Переведите заявку в «В работе» — после этого станут доступны действия на объекте.',
 working: 'Проведите диагностику ниже, запишите трудозатраты, при необходимости отправьте предложение работ, заполните результат и переведите в «Решена».',
 parts: 'Заявка ждёт запчасти. Верните её в «В работе», когда деталь будет на месте.',
 approval: 'Предложение отправлено клиенту.',
 resolved: 'Заявка у клиента на проверке.',
 closed: 'Заявка закрыта.',
} as Record<string, string>)[props.status] ?? '');
</script>

<template>
 <section class="panel tp" :class="{ done: training.result }" aria-labelledby="tp-title">
  <div class="tp-head">
   <div>
    <span class="tp-eyebrow">Учебная заявка · практикум · {{training.scenario.level}}</span>
    <h2 id="tp-title">{{training.scenario.title}}</h2>
    <p>{{training.scenario.summary}}</p>
   </div>
   <Link href="/training" class="secondary tp-back">← К обучению</Link>
  </div>

  <template v-if="!training.result">
   <p class="tp-rules">Это тренировка на учебном клубе: клиентам и в отчёты ничего не уходит. Работайте как с настоящей заявкой — все шаги делаются обычными кнопками на этой странице. Клиент в симуляции отвечает сам: согласует смету и принимает работу. Оценка — 100 баллов, зачёт от {{70}}. Ориентир по времени — {{training.scenario.target_minutes}} мин.</p>
   <p class="tp-next"><b>Сейчас:</b> {{nextStep}}</p>

   <h3>Действия на объекте</h3>
   <p v-if="!training.can_check" class="tp-muted">Станут доступны, когда заявка будет «В работе».</p>
   <div class="tp-checks">
    <button v-for="c in training.checks" :key="c.id" type="button" class="secondary" :class="{ used: c.done }"
     :disabled="!training.can_check || c.done || busy !== null" @click="check(c.id)">{{ c.done ? '✓ ' : '' }}{{c.label}}</button>
   </div>
   <p v-if="page.props.errors.check" class="error" role="alert">{{page.props.errors.check}}</p>
   <div v-if="findings.length" class="tp-findings" aria-live="polite">
    <h3>Что обнаружено</h3>
    <article v-for="c in findings" :key="c.id" :class="{ bad: c.harmful }"><b>{{c.label}}</b><p>{{c.finding}}</p></article>
   </div>
   <p class="tp-muted">Материалы: <template v-for="(ch, i) in training.scenario.chapters" :key="ch.id"><Link :href="'/knowledge/'+ch.id">{{ch.title}}</Link><template v-if="i < training.scenario.chapters.length - 1"> · </template></template></p>
  </template>

  <div v-else class="tp-result">
   <p class="tp-score" :class="{ ok: training.result.passed }"><b>{{training.result.score}}</b> из {{training.result.max_score}} · {{ training.result.passed ? 'зачёт' : 'нужно ' + training.result.pass_score }} · время {{duration(training.result.duration_seconds)}} (ориентир {{training.result.target_minutes}} мин)</p>
   <table class="tp-table">
    <tbody>
     <tr v-for="item in training.result.breakdown" :key="item.key" :class="{ miss: item.points < item.max }">
      <th scope="row">{{item.label}}<small>{{item.note}}</small></th>
      <td>{{item.points}} / {{item.max}}</td>
     </tr>
    </tbody>
   </table>
   <h3>Эталонное решение</h3>
   <dl class="tp-model">
    <dt>Причина</dt><dd>{{training.result.model.cause}}</dd>
    <dt>Решение</dt><dd>{{training.result.model.solution}}</dd>
    <dt>Проверка</dt><dd>{{training.result.model.verification}}</dd>
    <dt>Ход работ</dt><dd>{{training.result.model.work}}</dd>
   </dl>
   <h3>Ваши действия на объекте</h3>
   <ul class="tp-review"><li v-for="c in training.checks" :key="c.id" :class="{ bad: c.done && c.harmful, missed: !c.done && c.key }">
    {{ c.done ? '✓' : '—' }} {{c.label}}<small v-if="!c.done && c.key"> · ключевая проверка пропущена</small><small v-if="c.done && c.harmful"> · лишнее действие</small>
   </li></ul>
   <Link href="/training" class="kb-source-button">Вернуться к обучению</Link>
  </div>
 </section>
</template>

<style scoped>
.tp{border-color:var(--primary)}
.tp.done{border-color:var(--border)}
.tp-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}
.tp-head h2{margin:4px 0 6px}
.tp-head p{margin:0;color:var(--muted);font-size:13px;line-height:1.6;max-width:760px}
.tp-eyebrow{font-size:11px;letter-spacing:.06em;text-transform:uppercase;font-weight:600;color:var(--accent-text)}
.tp-back{padding:8px 12px;border:1px solid var(--border);border-radius:8px;white-space:nowrap}
.tp-rules{font-size:13px;line-height:1.7;margin:14px 0 8px}
.tp-next{font-size:13px;line-height:1.6;padding:10px 12px;border-radius:6px;background:var(--hover);margin:0 0 6px}
.tp h3{font-size:14px;margin:18px 0 8px}
.tp-muted{font-size:12px;color:var(--muted);line-height:1.7}
.tp-checks{display:flex;flex-wrap:wrap;gap:8px}
.tp-checks button{font-size:12px;text-align:left}
.tp-checks button.used{opacity:.6}
.tp-findings article{border-left:3px solid var(--accent-text);padding:6px 12px;margin:8px 0;font-size:13px;line-height:1.6}
.tp-findings article.bad{border-left-color:var(--error)}
.tp-findings p{margin:2px 0 0;color:var(--muted)}
.tp-score{font-size:14px;margin:14px 0}
.tp-score b{font-size:34px;color:var(--warning)}
.tp-score.ok b{color:var(--accent-text)}
.tp-table{width:100%;border-collapse:collapse;font-size:13px}
.tp-table th,.tp-table td{text-align:left;padding:10px 8px;border-bottom:1px solid var(--border);vertical-align:top}
.tp-table th{font-weight:600}
.tp-table th small{display:block;font-weight:400;color:var(--muted);font-size:12px;line-height:1.6;margin-top:2px}
.tp-table td{white-space:nowrap;font-variant-numeric:tabular-nums;color:var(--accent-text);width:80px}
.tp-table tr.miss td{color:var(--warning)}
.tp-model{display:grid;grid-template-columns:120px 1fr;gap:6px 12px;font-size:13px;line-height:1.6;margin:0}
.tp-model dt{color:var(--muted)}
.tp-model dd{margin:0}
.tp-review{list-style:none;padding:0;margin:0 0 12px;font-size:13px;line-height:1.9}
.tp-review li.bad{color:var(--error)}
.tp-review li.missed{color:var(--warning)}
.tp-review small{color:var(--muted)}
@media(max-width:640px){.tp-model{grid-template-columns:1fr}.tp-model dt{margin-top:6px}}
</style>
