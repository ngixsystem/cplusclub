<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import Shell from '../Layouts/Shell.vue';
import '../../css/knowledge.css';

type Quiz = { best_percent: number | null; passed: boolean } | null;
type Chapter = { id: string; title: string; minutes: number | null; checked: number; total: number; checklist_done: boolean; quiz: Quiz; done: boolean; completed_at: string | null };
type Enrollment = { started_at: string; completed_at: string | null; duration_seconds: number } | null;
type Best = { points: number; max_points: number; percent: number; passed: boolean; finished_at: string; duration_seconds: number } | null;
type Exam = { questions: number; points_per_answer: number; max_points: number; pass_percent: number; pass_points: number; retake_minutes: number;
 available: boolean; locked_reason: string | null; attempts: number; in_progress_id: number | null; best: Best; passed_at: string | null } | null;
type Scenario = { id: string; title: string; summary: string; level: string; target_minutes: number; chapters: { id: string; title: string }[];
 attempts: number; best: { score: number; passed: boolean; run_id: number; ticket_id: number; duration_seconds: number } | null; active_ticket_id: number | null };
type PracticeBlock = { pass_score: number; scenarios: Scenario[]; passed: number; total: number; average: number | null } | null;
type Track = { id: string; title: string; summary: string; chapters: Chapter[]; done: number; total: number; next: { id: string; title: string } | null; enrollment: Enrollment; practice: PracticeBlock; exam: Exam };
type MemberTrack = { id: string; title: string; done: number; total: number; enrollment: Enrollment; quiz_average: number | null; practice: { passed: number; total: number; average: number | null } | null; exam: { best: Best; attempts: number; max_points: number } | null };
type Member = { id: number; name: string; role: string; tracks: MemberTrack[]; last_activity: string | null };
const props = defineProps<{ tracks: Track[]; team: Member[] | null; chapterPassPercent: number }>();
const page = usePage<{ errors: Record<string, string> }>();

const roles: Record<string, string> = { owner: 'Владелец', lead: 'Технический руководитель', specialist: 'Выездной специалист', representative: 'Представитель клуба' };
const percent = (done: number, total: number) => total ? Math.round(done / total * 100) : 0;
const open = ref<string | null>(props.tracks.find(t => t.enrollment && !t.enrollment.completed_at)?.id ?? props.tracks.find(t => !t.enrollment)?.id ?? props.tracks[0]?.id ?? null);
const roleFilter = ref('');
const members = computed(() => (props.team ?? []).filter(m => !roleFilter.value || m.role === roleFilter.value));
const teamRoles = computed(() => [...new Set((props.team ?? []).map(m => m.role))]);
const date = (value: string | null) => value ? new Date(value).toLocaleDateString('ru-RU', { timeZone: 'Asia/Tashkent' }) : '—';
const minutesLeft = (track: Track) => track.chapters.filter(c => !c.done).reduce((sum, c) => sum + (c.minutes || 0), 0);
const minutesAll = (track: Track) => track.chapters.reduce((sum, c) => sum + (c.minutes || 0), 0);
function duration(seconds: number) {
 const d = Math.floor(seconds / 86400), h = Math.floor(seconds % 86400 / 3600), m = Math.max(1, Math.floor(seconds % 3600 / 60));
 if (d) return `${d} дн. ${h} ч`;
 if (h) return `${h} ч ${m} мин`;
 return `${m} мин`;
}
const busy = ref<string | null>(null);
function startTrack(track: Track) {
 busy.value = track.id;
 router.post(`/training/${track.id}/start`, {}, { preserveScroll: true, onFinish: () => { busy.value = null; open.value = track.id; } });
}
function startExam(track: Track) {
 busy.value = track.id;
 router.post(`/training/${track.id}/exam`, {}, { onFinish: () => { busy.value = null; } });
}
function startPractice(id: string) {
 busy.value = id;
 router.post(`/training/practice/${id}`, {}, { onFinish: () => { busy.value = null; } });
}
function chapterStatus(c: Chapter) {
 if (c.done) return 'Изучено ' + date(c.completed_at);
 const parts = [];
 if (c.checked) parts.push(`чек-лист ${c.checked}/${c.total}`);
 if (c.quiz && c.quiz.best_percent !== null) parts.push(c.quiz.passed ? 'тест сдан' : `тест ${c.quiz.best_percent}%`);
 return parts.length ? parts.join(' · ') : (c.minutes ? c.minutes + ' мин' : '');
}
</script>

<template>
 <Head title="Обучение" />
 <Shell>
  <div class="kb-heading"><div><h1>Обучение</h1><p>Программы по вашей роли собраны из глав базы знаний: чтение, практическая проверка, тест по каждой главе и итоговый экзамен.</p></div><Link href="/knowledge" class="kb-source-button">База знаний →</Link></div>
  <p v-if="page.props.errors.exam" class="error" role="alert">{{page.props.errors.exam}}</p>

  <section aria-label="Мои программы" class="tr-tracks">
   <p v-if="!tracks.length" class="panel">Для вашей роли программ пока нет.</p>
   <article v-for="track in tracks" :key="track.id" class="tr-track">

    <!-- Intro: shown until the employee starts the program -->
    <div v-if="!track.enrollment" class="tr-intro">
     <span class="tr-eyebrow">Новая программа · {{track.total}} глав · ~{{minutesAll(track)}} мин чтения</span>
     <h2>{{track.title}}</h2>
     <p class="tr-summary">{{track.summary}}</p>
     <h3>Как устроено обучение</h3>
     <ol class="tr-steps">
      <li><b>Читаете главу.</b> Каждая глава — реальная рабочая инструкция: порядок проверки, диагностика, что записать в заявку.</li>
      <li><b>Выполняете «Проверку результата».</b> В конце главы — чек-лист из 4 пунктов. Отмечайте пункты, которые поняли и можете выполнить на объекте.</li>
      <li><b>Сдаёте тест по главе.</b> 5 вопросов, нужно не меньше {{chapterPassPercent}}% правильных. После проверки увидите правильные ответы с пояснениями. Пересдавать можно без ограничений.</li>
      <li v-if="track.practice"><b>Проходите практикум в C+CLub.</b> {{track.practice.total}} учебные заявки с имитацией реальных инцидентов: заявка приходит от «клиента», вы принимаете её, диагностируете на объекте, при необходимости согласуете платные работы, заполняете причину, решение и проверку и сдаёте клиенту. Всё — в настоящем интерфейсе заявок, но на учебном клубе: клиентам и в отчёты ничего не уходит. Каждая заявка оценивается в 100 баллов (зачёт от {{track.practice.pass_score}}) с разбором и эталонным решением.</li>
      <li v-if="track.exam"><b>Сдаёте итоговый экзамен.</b> Он откроется, когда все главы будут изучены<template v-if="track.practice"> и практикум сдан</template>. {{track.exam.questions}} вопросов из всех глав программы, {{track.exam.points_per_answer}} баллов за каждый правильный ответ, максимум {{track.exam.max_points}}. Экзамен сдан при {{track.exam.pass_points}} баллах и выше ({{track.exam.pass_percent}}%). Правильные ответы на экзамене не показываются — только главы, где были ошибки. Повторная попытка — через {{track.exam.retake_minutes}} минут.</li>
      <li v-else><b>Программа завершена,</b> когда все главы изучены: чек-лист и тест в каждой.</li>
     </ol>
     <h3>Сроки и результаты</h3>
     <ul class="tr-facts">
      <li>Время обучения считается с момента, когда вы нажмёте «Начать обучение», и до {{track.exam ? 'сдачи экзамена' : 'изучения последней главы'}}. Таймера на главы нет — учитесь в своём темпе, но без долгих перерывов.</li>
      <li>Баллы, результаты тестов и срок прохождения видят владелец сервиса и технический руководитель.</li>
      <li>Прогресс сохраняется в вашем профиле: можно продолжить с любого устройства.</li>
     </ul>
     <details class="tr-preview"><summary>Список глав</summary><ol><li v-for="c in track.chapters" :key="c.id">{{c.title}}</li></ol></details>
     <button type="button" class="kb-source-button tr-start" :disabled="busy===track.id" @click="startTrack(track)">Начать обучение</button>
    </div>

    <template v-else>
     <button class="tr-track-head" :aria-expanded="open===track.id" @click="open = open===track.id ? null : track.id">
      <span>
       <span class="tr-eyebrow">{{ track.enrollment.completed_at ? 'Программа пройдена ✓ за ' + duration(track.enrollment.duration_seconds) : `${track.done} из ${track.total} глав · идёт ${duration(track.enrollment.duration_seconds)} · осталось ~${minutesLeft(track)} мин чтения` }}</span>
       <b>{{track.title}}</b>
       <small>Начато {{date(track.enrollment.started_at)}}<template v-if="track.enrollment.completed_at"> · завершено {{date(track.enrollment.completed_at)}}</template><template v-if="track.exam?.best"> · экзамен: {{track.exam.best.points}} из {{track.exam.best.max_points}} баллов</template></small>
      </span>
      <span class="tr-percent">{{percent(track.done, track.total)}}%</span>
     </button>
     <span class="kb-bar" aria-hidden="true"><i :style="{width: percent(track.done, track.total)+'%'}"></i></span>
     <div v-if="open===track.id" class="tr-chapters">
      <Link v-if="track.next" :href="'/knowledge/'+track.next.id" class="kb-source-button">{{track.done ? 'Продолжить' : 'Начать'}}: {{track.next.title}} →</Link>
      <ol>
       <li v-for="chapter in track.chapters" :key="chapter.id" :class="{done: chapter.done}">
        <Link :href="'/knowledge/'+chapter.id">{{chapter.title}}</Link>
        <span>{{chapterStatus(chapter)}}</span>
       </li>
      </ol>

      <div v-if="track.practice" class="tr-practice">
       <h3>Практикум: учебные заявки в C+CLub <span>{{track.practice.passed}} из {{track.practice.total}} зачтено<template v-if="track.practice.average !== null"> · средний балл {{track.practice.average}}</template></span></h3>
       <p>Инцидент приходит как настоящая заявка на «Учебный клуб». Работайте с ней обычными кнопками: примите, переведите в работу, проведите диагностику, запишите трудозатраты, при необходимости согласуйте платные работы, заполните результат и переведите в «Решена». Клиент в симуляции ответит сам. Зачёт — от {{track.practice.pass_score}} баллов из 100.</p>
       <article v-for="s in track.practice.scenarios" :key="s.id" class="tr-scenario" :class="{ passed: s.best?.passed }">
        <div>
         <span class="tr-eyebrow">{{s.level}} · ~{{s.target_minutes}} мин<template v-if="s.attempts"> · попыток {{s.attempts}}</template></span>
         <b>{{s.title}}</b>
         <small>{{s.summary}}</small>
         <small v-if="s.best">Лучший результат: <span :class="s.best.passed ? 'tr-ok' : 'tr-warn'">{{s.best.score}} / 100</span> · <Link :href="'/tickets/'+s.best.ticket_id">разбор</Link></small>
        </div>
        <Link v-if="s.active_ticket_id" :href="'/tickets/'+s.active_ticket_id" class="kb-source-button">Продолжить заявку →</Link>
        <button v-else type="button" class="kb-source-button" :disabled="busy===s.id" @click="startPractice(s.id)">{{ s.attempts ? 'Пройти ещё раз' : 'Получить заявку' }}</button>
       </article>
      </div>

      <div v-if="track.exam" class="tr-exam" :class="{ passed: track.exam.passed_at }">
       <div>
        <h3>Итоговый экзамен</h3>
        <p>{{track.exam.questions}} вопросов · {{track.exam.points_per_answer}} баллов за правильный ответ · проходной балл {{track.exam.pass_points}} из {{track.exam.max_points}}</p>
        <p v-if="track.exam.best" class="tr-exam-best">Лучший результат: <b>{{track.exam.best.points}} из {{track.exam.best.max_points}}</b> ({{track.exam.best.percent}}%) за {{duration(track.exam.best.duration_seconds)}} · попыток: {{track.exam.attempts}}</p>
        <p v-if="track.exam.passed_at" class="tr-exam-ok">Сдан {{date(track.exam.passed_at)}} ✓</p>
        <p v-else-if="track.exam.locked_reason && !track.exam.in_progress_id" class="tr-exam-lock">{{track.exam.locked_reason}}</p>
       </div>
       <Link v-if="track.exam.in_progress_id" :href="'/training/exam/'+track.exam.in_progress_id" class="kb-source-button">Продолжить экзамен →</Link>
       <button v-else-if="track.exam.available" type="button" class="kb-source-button" :disabled="busy===track.id" @click="startExam(track)">{{track.exam.attempts ? 'Пересдать экзамен' : 'Начать экзамен'}}</button>
      </div>
     </div>
    </template>
   </article>
  </section>

  <section v-if="team" class="tr-team" aria-label="Обучение команды">
   <div class="tr-team-head"><h2>Обучение команды</h2><label>Роль<select v-model="roleFilter"><option value="">Все</option><option v-for="r in teamRoles" :key="r" :value="r">{{roles[r] || r}}</option></select></label></div>
   <p v-if="!members.length" class="panel">Нет активных сотрудников с этой ролью.</p>
   <div v-else class="kb-diagnostics tr-table" role="region" aria-label="Прогресс сотрудников" tabindex="0">
    <table>
     <thead><tr><th scope="col">Сотрудник</th><th scope="col">Программа и главы</th><th scope="col">Тесты глав</th><th scope="col">Практикум</th><th scope="col">Экзамен</th><th scope="col">Срок обучения</th></tr></thead>
     <tbody>
      <template v-for="member in members" :key="member.id">
       <tr v-for="(t, i) in member.tracks" :key="t.id">
        <th v-if="i===0" scope="row" :rowspan="member.tracks.length">{{member.name}}<small>{{roles[member.role] || member.role}}</small><small>Активность: {{date(member.last_activity)}}</small></th>
        <td><span class="tr-cell-title">{{t.title}}</span><span class="kb-bar" aria-hidden="true"><i :style="{width: percent(t.done, t.total)+'%'}"></i></span><small>{{t.done}} из {{t.total}} глав</small></td>
        <td>{{ t.quiz_average !== null ? 'в среднем ' + t.quiz_average + '%' : '—' }}</td>
        <td><template v-if="t.practice"><b :class="{ 'tr-ok': t.practice.passed === t.practice.total }">{{t.practice.passed}} / {{t.practice.total}}</b><small>{{ t.practice.average !== null ? 'средний балл ' + t.practice.average : 'не начат' }}</small></template><template v-else>—</template></td>
        <td><template v-if="t.exam?.best"><b :class="{ 'tr-ok': t.exam.best.passed }">{{t.exam.best.points}} / {{t.exam.best.max_points}}</b><small>{{t.exam.best.passed ? 'сдан' : 'не сдан'}} · попыток {{t.exam.attempts}}</small></template><template v-else-if="t.exam">не сдавал</template><template v-else>без экзамена</template></td>
        <td><template v-if="t.enrollment"><b v-if="t.enrollment.completed_at" class="tr-ok">{{duration(t.enrollment.duration_seconds)}}</b><span v-else>идёт {{duration(t.enrollment.duration_seconds)}}</span><small>{{date(t.enrollment.started_at)}} → {{t.enrollment.completed_at ? date(t.enrollment.completed_at) : '…'}}</small></template><template v-else>не начато</template></td>
       </tr>
       <tr v-if="!member.tracks.length"><th scope="row">{{member.name}}<small>{{roles[member.role] || member.role}}</small></th><td colspan="5">—</td></tr>
      </template>
     </tbody>
    </table>
   </div>
  </section>
 </Shell>
</template>

<style scoped>
.tr-tracks{display:grid;gap:14px;margin-bottom:40px}
.tr-track{background:var(--surface);border:1px solid var(--border);border-radius:7px;overflow:hidden}
.tr-eyebrow{display:block;font-size:11px;letter-spacing:.06em;text-transform:uppercase;font-weight:600;color:var(--muted);margin-bottom:8px}
.tr-intro{padding:24px 26px}
.tr-intro h2{margin:0 0 8px;font-size:21px}
.tr-intro h3{margin:22px 0 10px;font-size:14px}
.tr-summary{color:var(--muted);font-size:13px;line-height:1.7;margin:0;max-width:760px}
.tr-steps,.tr-facts{margin:0;padding-left:20px;display:grid;gap:10px;font-size:13px;line-height:1.7;max-width:820px}
.tr-steps b{color:var(--text)}
.tr-facts{color:var(--muted)}
.tr-preview{margin-top:18px;font-size:13px}
.tr-preview summary{cursor:pointer;color:var(--accent-text)}
.tr-preview ol{margin:10px 0 0;padding-left:22px;line-height:1.9;color:var(--muted)}
.tr-start{margin-top:22px}
.tr-start:disabled{opacity:.6}
.tr-track-head{width:100%;display:flex;justify-content:space-between;align-items:center;gap:20px;text-align:left;background:transparent;border:0;border-radius:0;padding:20px 22px;color:var(--text);font-weight:400}
.tr-track-head b{display:block;font-size:17px;margin-bottom:6px}
.tr-track-head small{display:block;color:var(--muted);font-size:12px;line-height:1.6}
.tr-percent{font-size:24px;font-weight:600;font-variant-numeric:tabular-nums;flex-shrink:0}
.tr-track>.kb-bar{display:block;border-radius:0}
.tr-chapters{padding:20px 22px;border-top:1px solid var(--border)}
.tr-chapters ol{margin:12px 0 0;padding-left:22px}
.tr-chapters li{display:flex;justify-content:space-between;gap:16px;padding:9px 0;border-bottom:1px solid var(--border);font-size:13px;line-height:1.5}
.tr-chapters li>span{color:var(--muted);font-size:11px;white-space:nowrap}
.tr-chapters li.done>a{color:var(--muted)}
.tr-chapters li.done>span{color:var(--accent-text)}
.tr-exam{display:flex;justify-content:space-between;align-items:center;gap:20px;flex-wrap:wrap;margin-top:22px;padding:18px 20px;border:1px solid var(--border);border-radius:7px;background:var(--surface-2)}
.tr-exam.passed{border-color:var(--accent-text)}
.tr-exam h3{margin:0 0 6px;font-size:15px}
.tr-exam p{margin:4px 0 0;font-size:12px;line-height:1.6;color:var(--muted)}
.tr-exam-best b,.tr-exam-ok,.tr-ok{color:var(--accent-text)}
.tr-exam-ok{font-weight:600}
.tr-exam-lock{color:var(--warning)!important}
.tr-exam .kb-source-button{margin:0}
.tr-practice{margin-top:22px}
.tr-practice h3{margin:0 0 6px;font-size:15px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
.tr-practice h3 span{font-size:12px;font-weight:400;color:var(--muted)}
.tr-practice>p{margin:0 0 12px;font-size:12px;line-height:1.7;color:var(--muted);max-width:860px}
.tr-scenario{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:14px 16px;margin-top:8px;border:1px solid var(--border);border-radius:7px}
.tr-scenario.passed{border-color:var(--accent-text)}
.tr-scenario b{display:block;font-size:14px;margin-bottom:4px}
.tr-scenario small{display:block;color:var(--muted);font-size:12px;line-height:1.6}
.tr-scenario .kb-source-button{margin:0;flex-shrink:0}
.tr-warn{color:var(--warning)}
.tr-team-head{display:flex;justify-content:space-between;align-items:end;gap:16px;margin-bottom:14px}
.tr-team-head h2{margin:0;font-size:19px}
.tr-table table{table-layout:auto;min-width:860px}
.tr-table th small,.tr-table td small{display:block;color:var(--muted);font-weight:400;font-size:11px}
.tr-table td .kb-bar{display:block;margin:6px 0 4px;max-width:260px}
.tr-cell-title{font-weight:600}
@media(max-width:760px){.tr-scenario{flex-direction:column;align-items:stretch}.tr-intro{padding:18px}.tr-track-head{padding:16px}.tr-chapters{padding:16px}.tr-chapters li{flex-direction:column;gap:2px}.tr-percent{font-size:19px}}
</style>
