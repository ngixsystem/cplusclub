<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import Shell from '../Layouts/Shell.vue';
import '../../css/knowledge.css';

type Quiz = { best_percent: number | null; passed: boolean } | null;
type Chapter = { id: string; kind: 'guide' | 'lesson'; url: string; title: string; minutes: number | null; checked: number; total: number; checklist_done: boolean; watch_percent: number | null; quiz: Quiz; done: boolean; completed_at: string | null };
type Enrollment = { started_at: string; completed_at: string | null; duration_seconds: number } | null;
type Best = { points: number; max_points: number; percent: number; passed: boolean; finished_at: string; duration_seconds: number } | null;
type Exam = { questions: number; points_per_answer: number; max_points: number; pass_percent: number; pass_points: number; retake_minutes: number;
 available: boolean; locked_reason: string | null; attempts: number; in_progress_id: number | null; best: Best; passed_at: string | null } | null;
type Track = { id: string; kind: 'guides' | 'video'; title: string; summary: string; chapters: Chapter[]; done: number; total: number; next: { id: string; title: string; url: string } | null; enrollment: Enrollment; exam: Exam };
type MemberTrack = { id: string; title: string; done: number; total: number; enrollment: Enrollment; quiz_average: number | null; exam: { best: Best; attempts: number; max_points: number } | null };
type PracticeScenario = { id: string; title: string; difficulty: string; minutes_target: number; demo_done: boolean; open_demo: number | null; open_exam: number | null;
 attempts: number; best: number | null; best_run: number | null; passed_at: string | null; last_run: number | null };
type Practice = { scenarios: PracticeScenario[]; passed: number; total: number; pass_points: number; started_at: string | null; completed_at: string | null; duration_seconds: number | null };
type Labs = { subnet_best: number | null; subnet_sessions: number; ios_passed: number; ios_total: number; labs_accepted: number; labs_total: number; labs_pending: number; labs_grade: number | null };
type Member = { id: number; name: string; role: string; tracks: MemberTrack[]; last_activity: string | null; practice: Practice | null; labs: Labs | null };
const props = defineProps<{ tracks: Track[]; team: Member[] | null; chapterPassPercent: number; practice: Practice | null; labs: Labs | null }>();
const practiceOpen = ref(!props.practice?.started_at || (props.practice.passed < props.practice.total));
function practiceStart(id: string, mode: 'demo' | 'exam') {
 busy.value = id + mode;
 router.post(`/training/practice/${id}/start`, { mode }, { onFinish: () => { busy.value = null; } });
}
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
function chapterStatus(c: Chapter) {
 if (c.done) return 'Изучено ' + date(c.completed_at);
 const parts = [];
 if (c.kind === 'lesson' && c.watch_percent) parts.push(c.checklist_done ? 'просмотрено' : `просмотр ${c.watch_percent} %`);
 if (c.kind === 'guide' && c.checked) parts.push(`чек-лист ${c.checked}/${c.total}`);
 if (c.quiz && c.quiz.best_percent !== null) parts.push(c.quiz.passed ? 'тест сдан' : `тест ${c.quiz.best_percent}%`);
 return parts.length ? parts.join(' · ') : (c.minutes ? c.minutes + ' мин' : '');
}
</script>

<template>
 <Head title="Обучение" />
 <Shell>
  <div class="kb-heading"><div><h1>Обучение</h1><p>Программы по вашей роли собраны из глав базы знаний: чтение, практическая проверка, тест по каждой главе и итоговый экзамен.</p></div><Link href="/knowledge" class="kb-source-button">База знаний →</Link></div>
  <p v-if="page.props.errors.exam" class="error" role="alert">{{page.props.errors.exam}}</p>

  <section v-if="practice" class="tr-track tr-practice" aria-labelledby="practice-title">
   <div class="tr-intro">
    <span class="tr-eyebrow">Обязательно для инженеров · {{practice.passed}} из {{practice.total}} испытаний пройдено<template v-if="practice.duration_seconds !== null"> · {{ practice.completed_at ? 'пройдено за ' : 'идёт ' }}{{duration(practice.duration_seconds)}}</template></span>
    <h2 id="practice-title">Практикум: работа с заявками в C+CLub</h2>
    <p class="tr-summary">Главный навык инженера — довести заявку от сообщения клуба до закрытия так, чтобы клуб был доволен, а следующий инженер всё понял из заявки. В практикуме учебный клуб присылает сообщение об инциденте, вы оформляете заявку и ведёте её по тем же статусам и с теми же полями, что в настоящем C+CLub.</p>
    <button type="button" class="tr-toggle" :aria-expanded="practiceOpen" @click="practiceOpen = !practiceOpen">{{ practiceOpen ? 'Скрыть, как это работает' : 'Как это работает' }}</button>
    <div v-if="practiceOpen" class="tr-practice-rules">
     <ol class="tr-steps">
      <li><b>Оформите заявку</b> из сообщения клуба: оборудование, категория, описание, приоритет по масштабу сбоя.</li>
      <li><b>Ведите по статусам:</b> Новая → Принята → В работе → Решена → Закрыта. Платные работы — сначала «Предложение работ» на согласование клубу.</li>
      <li><b>Работайте на объекте:</b> выбирайте действия диагностики, устранения и проверки. Каждое даёт результат — как в жизни. Есть и вредные действия: они бьют по клубу и по оценке.</li>
      <li><b>Закройте как положено:</b> трудозатраты, выполненные работы, проверка, причина и решение. Если причина не устранена, клуб вернёт заявку на доработку.</li>
     </ol>
     <table class="tr-criteria"><caption>Оценка испытания — 100 баллов, проходной {{practice.pass_points}}</caption><tbody>
      <tr><th>Оформление заявки</th><td>20</td><th>Проверка результата после исправления</th><td>10</td></tr>
      <tr><th>Диагностика: ключевые проверки до исправления</th><td>25</td><th>Верная причина</th><td>10</td></tr>
      <tr><th>Устранение причины</th><td>10</td><th>Поля закрытия и трудозатраты</th><td>10</td></tr>
      <tr><th>Согласование платных работ</th><td>5</td><th>Без возврата на доработку · в норму времени</th><td>5 + 5</td></tr>
     </tbody></table>
     <p class="tr-facts-note">Каждое вредное действие — минус 10 баллов, и испытание с ним не засчитывается. <b>Демо</b> показывает подсказки на каждом шаге и не оценивается — начните с него. <b>Испытание</b> без подсказок, результат и время видят владелец и технический руководитель. Учебные заявки живут отдельно: они не попадают в настоящие заявки, отчёты, статистику и Telegram клубов.</p>
    </div>
    <ul class="tr-scenarios">
     <li v-for="sc in practice.scenarios" :key="sc.id" :class="{ passed: sc.passed_at }">
      <div><b>{{sc.title}}</b><small>{{sc.difficulty}} · норма {{sc.minutes_target}} мин<template v-if="sc.attempts"> · лучший результат {{sc.best}} из 100 · попыток {{sc.attempts}}</template><template v-if="sc.passed_at"> · пройдено {{date(sc.passed_at)}} ✓</template><template v-else-if="sc.demo_done"> · демо пройдено</template></small></div>
      <div class="tr-scenario-buttons">
       <Link v-if="sc.open_demo" :href="'/training/practice/run/'+sc.open_demo" class="button secondary">Продолжить демо</Link>
       <button v-else type="button" class="secondary" :disabled="busy===sc.id+'demo'" @click="practiceStart(sc.id, 'demo')">Демо</button>
       <Link v-if="sc.open_exam" :href="'/training/practice/run/'+sc.open_exam" class="button">Продолжить испытание</Link>
       <button v-else type="button" :disabled="busy===sc.id+'exam'" @click="practiceStart(sc.id, 'exam')">{{ sc.attempts ? 'Пройти ещё раз' : 'Испытание' }}</button>
       <Link v-if="sc.last_run" :href="'/training/practice/run/'+sc.last_run" class="tr-link">Разбор</Link>
      </div>
     </li>
    </ul>
   </div>
  </section>

  <section v-if="labs" class="tr-track tr-labs" aria-labelledby="labs-title">
   <div class="tr-intro">
    <span class="tr-eyebrow">Практика к курсу Cisco · тренажёры проверяются автоматически, лабораторные — руководителем</span>
    <h2 id="labs-title">Практика Cisco</h2>
    <ul class="tr-lab-cards">
     <li><Link href="/training/subnets" class="tr-lab-card"><b>Тренажёр подсетей</b><span>10 случайных задач: сеть, broadcast, маска, число узлов. Проходной 80 %.</span><small>{{ labs.subnet_best !== null ? 'Лучший результат ' + labs.subnet_best + ' % · тренировок ' + labs.subnet_sessions : 'Ещё не решали' }}</small></Link></li>
     <li><Link href="/training/ios" class="tr-lab-card"><b>Тренажёр команд IOS</b><span>Введите команды для задачи — система проверит конфигурацию по пунктам.</span><small>Решено {{labs.ios_passed}} из {{labs.ios_total}}</small></Link></li>
     <li><Link href="/training/labs" class="tr-lab-card"><b>Лабораторные Packet Tracer</b><span>Соберите схему, приложите .pkt и скриншоты — руководитель проверит и поставит оценку.</span><small>Принято {{labs.labs_accepted}} из {{labs.labs_total}}<template v-if="labs.labs_pending"> · на проверке {{labs.labs_pending}}</template></small></Link></li>
    </ul>
   </div>
  </section>

  <section aria-label="Мои программы" class="tr-tracks">
   <p v-if="!tracks.length" class="panel">Для вашей роли программ пока нет.</p>
   <article v-for="track in tracks" :key="track.id" class="tr-track">

    <!-- Intro: shown until the employee starts the program -->
    <div v-if="!track.enrollment" class="tr-intro">
     <span class="tr-eyebrow">Новая программа · {{track.total}} {{ track.kind==='video' ? 'видеоуроков' : 'глав' }} · ~{{minutesAll(track)}} мин {{ track.kind==='video' ? 'видео' : 'чтения' }}</span>
     <h2>{{track.title}}</h2>
     <p class="tr-summary">{{track.summary}}</p>
     <h3>Как устроено обучение</h3>
     <ol v-if="track.kind==='video'" class="tr-steps">
      <li><b>Смотрите видеоурок</b> прямо в C+CLub. Урок засчитывается, когда просмотрено не меньше 90 % видео: перемотка вперёд не считается, скорость до 2× — можно.</li>
      <li><b>Сдаёте тест по уроку.</b> 3–4 вопроса, нужно не меньше {{chapterPassPercent}}% правильных, после проверки — разбор с пояснениями. Тест открывается только после просмотра. У вводных уроков теста нет.</li>
      <li v-if="track.exam"><b>Сдаёте итоговый экзамен.</b> Он откроется, когда пройдены все уроки. {{track.exam.questions}} вопросов из всех уроков, {{track.exam.points_per_answer}} балла за каждый правильный ответ, максимум {{track.exam.max_points}}, проходной {{track.exam.pass_points}}. Правильные ответы не показываются. Повторная попытка — через {{track.exam.retake_minutes}} минут.</li>
      <li><b>Получаете сертификат C+CLub</b> с баллами и сроком прохождения — его можно распечатать или сохранить в PDF.</li>
     </ol>
     <ol v-else class="tr-steps">
      <li><b>Читаете главу.</b> Каждая глава — реальная рабочая инструкция: порядок проверки, диагностика, что записать в заявку.</li>
      <li><b>Выполняете «Проверку результата».</b> В конце главы — чек-лист из 4 пунктов. Отмечайте пункты, которые поняли и можете выполнить на объекте.</li>
      <li><b>Сдаёте тест по главе.</b> 5 вопросов, нужно не меньше {{chapterPassPercent}}% правильных. После проверки увидите правильные ответы с пояснениями. Пересдавать можно без ограничений.</li>
      <li v-if="track.exam"><b>Сдаёте итоговый экзамен.</b> Он откроется, когда все главы будут изучены. {{track.exam.questions}} вопросов из всех глав программы, {{track.exam.points_per_answer}} баллов за каждый правильный ответ, максимум {{track.exam.max_points}}. Экзамен сдан при {{track.exam.pass_points}} баллах и выше ({{track.exam.pass_percent}}%). Правильные ответы на экзамене не показываются — только главы, где были ошибки. Повторная попытка — через {{track.exam.retake_minutes}} минут.</li>
      <li v-else><b>Программа завершена,</b> когда все главы изучены: чек-лист и тест в каждой.</li>
     </ol>
     <h3>Сроки и результаты</h3>
     <ul class="tr-facts">
      <li>Время обучения считается с момента, когда вы нажмёте «Начать обучение», и до {{track.exam ? 'сдачи экзамена' : 'изучения последней главы'}}. Таймера на главы нет — учитесь в своём темпе, но без долгих перерывов.</li>
      <li>Баллы, результаты тестов и срок прохождения видят владелец сервиса и технический руководитель.</li>
      <li>Прогресс сохраняется в вашем профиле: можно продолжить с любого устройства.</li>
     </ul>
     <details class="tr-preview"><summary>{{ track.kind==='video' ? 'Список уроков' : 'Список глав' }}</summary><ol><li v-for="c in track.chapters" :key="c.id">{{c.title}}</li></ol></details>
     <button type="button" class="kb-source-button tr-start" :disabled="busy===track.id" @click="startTrack(track)">Начать обучение</button>
    </div>

    <template v-else>
     <button class="tr-track-head" :aria-expanded="open===track.id" @click="open = open===track.id ? null : track.id">
      <span>
       <span class="tr-eyebrow">{{ track.enrollment.completed_at ? 'Программа пройдена ✓ за ' + duration(track.enrollment.duration_seconds) : `${track.done} из ${track.total} ${track.kind==='video' ? 'уроков' : 'глав'} · идёт ${duration(track.enrollment.duration_seconds)} · осталось ~${minutesLeft(track)} мин` }}</span>
       <b>{{track.title}}</b>
       <small>Начато {{date(track.enrollment.started_at)}}<template v-if="track.enrollment.completed_at"> · завершено {{date(track.enrollment.completed_at)}}</template><template v-if="track.exam?.best"> · экзамен: {{track.exam.best.points}} из {{track.exam.best.max_points}} баллов</template></small>
      </span>
      <span class="tr-percent">{{percent(track.done, track.total)}}%</span>
     </button>
     <span class="kb-bar" aria-hidden="true"><i :style="{width: percent(track.done, track.total)+'%'}"></i></span>
     <div v-if="open===track.id" class="tr-chapters">
      <Link v-if="track.next" :href="track.next.url" class="kb-source-button">{{track.done ? 'Продолжить' : 'Начать'}}: {{track.next.title}} →</Link>
      <ol>
       <li v-for="chapter in track.chapters" :key="chapter.id" :class="{done: chapter.done}">
        <Link :href="chapter.url">{{chapter.title}}</Link>
        <span>{{chapterStatus(chapter)}}</span>
       </li>
      </ol>

      <p v-if="!track.exam && track.enrollment.completed_at" class="tr-exam-ok">Программа пройдена ✓ · <a :href="'/training/certificate/'+track.id">Сертификат</a></p>
      <div v-if="track.exam" class="tr-exam" :class="{ passed: track.exam.passed_at }">
       <div>
        <h3>Итоговый экзамен</h3>
        <p>{{track.exam.questions}} вопросов · {{track.exam.points_per_answer}} баллов за правильный ответ · проходной балл {{track.exam.pass_points}} из {{track.exam.max_points}}</p>
        <p v-if="track.exam.best" class="tr-exam-best">Лучший результат: <b>{{track.exam.best.points}} из {{track.exam.best.max_points}}</b> ({{track.exam.best.percent}}%) за {{duration(track.exam.best.duration_seconds)}} · попыток: {{track.exam.attempts}}</p>
        <p v-if="track.exam.passed_at" class="tr-exam-ok">Сдан {{date(track.exam.passed_at)}} ✓ · <a :href="'/training/certificate/'+track.id">Сертификат</a></p>
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
     <thead><tr><th scope="col">Сотрудник</th><th scope="col">Программа и главы</th><th scope="col">Тесты глав</th><th scope="col">Экзамен</th><th scope="col">Срок обучения</th><th scope="col">Практикум по заявкам</th><th scope="col">Практика Cisco</th></tr></thead>
     <tbody>
      <template v-for="member in members" :key="member.id">
       <tr v-for="(t, i) in member.tracks" :key="t.id">
        <th v-if="i===0" scope="row" :rowspan="member.tracks.length">{{member.name}}<small>{{roles[member.role] || member.role}}</small><small>Активность: {{date(member.last_activity)}}</small></th>
        <td><span class="tr-cell-title">{{t.title}}</span><span class="kb-bar" aria-hidden="true"><i :style="{width: percent(t.done, t.total)+'%'}"></i></span><small>{{t.done}} из {{t.total}} глав</small></td>
        <td>{{ t.quiz_average !== null ? 'в среднем ' + t.quiz_average + '%' : '—' }}</td>
        <td><template v-if="t.exam?.best"><b :class="{ 'tr-ok': t.exam.best.passed }">{{t.exam.best.points}} / {{t.exam.best.max_points}}</b><small>{{t.exam.best.passed ? 'сдан' : 'не сдан'}} · попыток {{t.exam.attempts}}</small></template><template v-else-if="t.exam">не сдавал</template><template v-else>без экзамена</template></td>
        <td><template v-if="t.enrollment"><b v-if="t.enrollment.completed_at" class="tr-ok">{{duration(t.enrollment.duration_seconds)}}</b><span v-else>идёт {{duration(t.enrollment.duration_seconds)}}</span><small>{{date(t.enrollment.started_at)}} → {{t.enrollment.completed_at ? date(t.enrollment.completed_at) : '…'}}</small></template><template v-else>не начато</template></td>
        <td v-if="i===0" :rowspan="member.tracks.length"><template v-if="member.practice"><b :class="{ 'tr-ok': member.practice.passed === member.practice.total }">{{member.practice.passed}} из {{member.practice.total}}</b><small v-for="sc in member.practice.scenarios" :key="sc.id">{{sc.title}}: {{ sc.attempts ? sc.best + ' б.' + (sc.passed_at ? ' ✓' : '') + ' · попыток ' + sc.attempts : (sc.demo_done ? 'только демо' : '—') }}</small><small v-if="member.practice.duration_seconds !== null">{{ member.practice.completed_at ? 'за ' : 'идёт ' }}{{duration(member.practice.duration_seconds)}}</small></template><template v-else>—</template></td>
        <td v-if="i===0" :rowspan="member.tracks.length"><template v-if="member.labs"><small>Подсети: {{ member.labs.subnet_best !== null ? member.labs.subnet_best + ' %' : '—' }}</small><small>IOS: {{member.labs.ios_passed}} из {{member.labs.ios_total}}</small><small>Лабораторные: {{member.labs.labs_accepted}} из {{member.labs.labs_total}}<template v-if="member.labs.labs_grade !== null"> · ср. {{member.labs.labs_grade}}</template></small><Link v-if="member.labs.labs_pending" href="/training/labs" class="tr-link">На проверке: {{member.labs.labs_pending}}</Link></template><template v-else>—</template></td>
       </tr>
       <tr v-if="!member.tracks.length"><th scope="row">{{member.name}}<small>{{roles[member.role] || member.role}}</small></th><td colspan="6">—</td></tr>
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
.tr-team-head{display:flex;justify-content:space-between;align-items:end;gap:16px;margin-bottom:14px}
.tr-team-head h2{margin:0;font-size:19px}
.tr-table table{table-layout:auto;min-width:900px}
.tr-practice{margin-bottom:14px;border-color:var(--accent-text)}
.tr-labs{margin-bottom:14px}
.tr-lab-cards{list-style:none;padding:0;margin:12px 0 0;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
.tr-lab-card{display:grid;gap:6px;height:100%;padding:12px 14px;border:1px solid var(--border);border-radius:6px;color:inherit;text-decoration:none;background:var(--surface-2, var(--surface))}
.tr-lab-card:hover,.tr-lab-card:focus-visible{border-color:var(--accent-text)}
.tr-lab-card span{font-size:13px;color:var(--muted)}
.tr-lab-card small{font-size:12px;font-weight:600}
.tr-toggle{background:transparent;border:0;padding:0;margin-top:12px;color:var(--accent-text);font-weight:400;height:auto;min-height:0}
.tr-practice-rules{margin-top:10px}
.tr-criteria{margin:18px 0 8px;border-collapse:collapse;font-size:12px;width:100%;max-width:820px}
.tr-criteria caption{text-align:left;font-weight:600;font-size:13px;margin-bottom:8px}
.tr-criteria th{font-weight:400;text-align:left;padding:6px 8px;border-top:1px solid var(--border);color:var(--muted)}
.tr-criteria td{padding:6px 8px;border-top:1px solid var(--border);font-variant-numeric:tabular-nums;font-weight:600;white-space:nowrap}
.tr-facts-note{font-size:12px;line-height:1.7;color:var(--muted);max-width:820px}
.tr-scenarios{list-style:none;margin:18px 0 0;padding:0;display:grid;gap:8px}
.tr-scenarios li{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;padding:12px 14px;border:1px solid var(--border);border-radius:7px;background:var(--surface-2)}
.tr-scenarios li.passed{border-color:var(--accent-text)}
.tr-scenarios b{display:block;font-size:14px}
.tr-scenarios small{display:block;color:var(--muted);font-size:12px;margin-top:2px}
.tr-scenario-buttons{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.tr-link{font-size:12px}
.tr-table th small,.tr-table td small{display:block;color:var(--muted);font-weight:400;font-size:11px}
.tr-table td .kb-bar{display:block;margin:6px 0 4px;max-width:260px}
.tr-cell-title{font-weight:600}
@media(max-width:760px){.tr-intro{padding:18px}.tr-track-head{padding:16px}.tr-chapters{padding:16px}.tr-chapters li{flex-direction:column;gap:2px}.tr-percent{font-size:19px}}
</style>
