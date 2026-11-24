<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import Shell from '../Layouts/Shell.vue';
import '../../css/knowledge.css';

type FileRef = { n: number; name: string; size: number };
type Submission = { id: number; lab_id: string; comment: string; status: 'submitted' | 'accepted' | 'rework'; grade: number | null; review_comment: string | null;
 reviewer: string | null; reviewed_at: string | null; created_at: string; files: FileRef[] };
type Lab = { id: string; title: string; level: string; goal: string; steps: string[]; check: string[]; deliverables: string[]; submissions: Submission[]; status: string | null; accepted: boolean };
type Queued = Submission & { user: { id: number; name: string }; lab_title: string };
const props = defineProps<{ labs: Lab[]; review: Queued[] | null }>();
const page = usePage<{ errors: Record<string, string>; flash?: { success?: string } }>();
const statusText: Record<string, string> = { submitted: 'На проверке', accepted: 'Принято', rework: 'На доработку' };
const date = (v: string | null) => v ? new Date(v).toLocaleString('ru-RU', { timeZone: 'Asia/Tashkent', dateStyle: 'short', timeStyle: 'short' }) : '';
const size = (b: number) => b > 1048576 ? (b / 1048576).toFixed(1) + ' МБ' : Math.max(1, Math.round(b / 1024)) + ' КБ';
const fileUrl = (s: { id: number }, f: FileRef) => `/training/labs/submissions/${s.id}/files/${f.n}`;

const tab = ref<'mine' | 'review'>(props.review?.some(s => s.status === 'submitted') ? 'review' : 'mine');
const pending = computed(() => (props.review ?? []).filter(s => s.status === 'submitted').length);
const openLab = ref<string | null>(props.labs.find(l => !l.accepted)?.id ?? null);

const form = useForm<{ comment: string; files: File[] }>({ comment: '', files: [] });
function pick(e: Event) { form.files = Array.from((e.target as HTMLInputElement).files ?? []).slice(0, 4); }
function submit(lab: Lab) {
 form.post(`/training/labs/${lab.id}/submit`, { forceFormData: true, preserveScroll: true, onSuccess: () => { form.reset(); } });
}
const reviewForms = ref<Record<number, { status: 'accepted' | 'rework'; grade: number | null; review_comment: string }>>({});
const rf = (id: number) => (reviewForms.value[id] ??= { status: 'accepted', grade: 90, review_comment: '' });
const reviewForm = useForm({ status: 'accepted', grade: null as number | null, review_comment: '' });
function sendReview(s: Queued) {
 const data = rf(s.id);
 reviewForm.status = data.status; reviewForm.grade = data.status === 'accepted' ? data.grade : null; reviewForm.review_comment = data.review_comment;
 reviewForm.post(`/training/labs/submissions/${s.id}/review`, { preserveScroll: true });
}
</script>

<template>
 <Head title="Лабораторные Packet Tracer" />
 <Shell>
  <div class="kb-heading"><div><h1>Лабораторные Packet Tracer</h1><p>Соберите схему в Cisco Packet Tracer, проверьте её по списку, приложите файл .pkt и скриншоты. Руководитель проверит работу, поставит оценку 0–100 или вернёт на доработку с комментарием.</p></div><Link href="/training" class="kb-source-button">← Обучение</Link></div>
  <p v-if="page.props.flash?.success" class="success" role="status">{{page.props.flash.success}}</p>

  <div v-if="review" class="lab-tabs" role="tablist">
   <button type="button" role="tab" :aria-selected="tab==='mine'" :class="{ active: tab==='mine' }" @click="tab='mine'">Мои работы</button>
   <button type="button" role="tab" :aria-selected="tab==='review'" :class="{ active: tab==='review' }" @click="tab='review'">Проверка работ<template v-if="pending"> ({{pending}})</template></button>
  </div>

  <section v-if="tab==='mine'" class="lab-list" aria-label="Мои лабораторные">
   <article v-for="lab in labs" :key="lab.id" class="panel lab-card">
    <button type="button" class="lab-head" :aria-expanded="openLab===lab.id" @click="openLab = openLab===lab.id ? null : lab.id">
     <span><b>{{lab.title}}</b><small>{{lab.level}}</small></span>
     <span :class="['lab-status', lab.accepted ? 'accepted' : lab.status]">{{ lab.accepted ? 'Принято' : lab.status ? statusText[lab.status] : 'Не сдано' }}</span>
    </button>
    <div v-if="openLab===lab.id" class="lab-body">
     <p>{{lab.goal}}</p>
     <h3>Что сделать</h3><ol><li v-for="s in lab.steps" :key="s">{{s}}</li></ol>
     <h3>Как проверить</h3><ul><li v-for="s in lab.check" :key="s">{{s}}</li></ul>
     <h3>Что приложить</h3><ul><li v-for="s in lab.deliverables" :key="s">{{s}}</li></ul>

     <div v-for="s in lab.submissions" :key="s.id" :class="['lab-sub', s.status]">
      <p><b>{{statusText[s.status]}}</b><template v-if="s.grade !== null"> · оценка {{s.grade}}</template> · отправлено {{date(s.created_at)}}</p>
      <p class="lab-comment">{{s.comment}}</p>
      <p class="lab-files"><a v-for="f in s.files" :key="f.n" :href="fileUrl(s, f)">{{f.name}} ({{size(f.size)}})</a></p>
      <p v-if="s.review_comment" class="lab-review-comment">{{s.reviewer}}: {{s.review_comment}}</p>
     </div>

     <form v-if="!lab.accepted && lab.status !== 'submitted'" class="lab-form" @submit.prevent="submit(lab)">
      <label><span>Описание: что сделали и как проверяли</span><textarea v-model="form.comment" rows="4" maxlength="3000" required></textarea></label>
      <label><span>Файлы (до 4 шт., до 20 МБ): .pkt, .pka, скриншоты, PDF</span><input type="file" multiple accept=".pkt,.pka,.png,.jpg,.jpeg,.webp,.pdf" @change="pick" /></label>
      <p v-for="(e, k) in form.errors" :key="k" class="error" role="alert">{{e}}</p>
      <button type="submit" :disabled="form.processing || !form.files.length">Отправить на проверку</button>
     </form>
    </div>
   </article>
  </section>

  <section v-else class="lab-list" aria-label="Проверка работ">
   <p v-if="!review?.length" class="panel">Работ на проверку пока нет.</p>
   <article v-for="s in review" :key="s.id" :class="['panel', 'lab-card', 'lab-sub', s.status]">
    <p><b>{{s.user.name}}</b> · {{s.lab_title}} · {{date(s.created_at)}} · <span :class="['lab-status', s.status]">{{statusText[s.status]}}</span><template v-if="s.grade !== null"> · оценка {{s.grade}}</template></p>
    <p class="lab-comment">{{s.comment}}</p>
    <p class="lab-files"><a v-for="f in s.files" :key="f.n" :href="fileUrl(s, f)">{{f.name}} ({{size(f.size)}})</a></p>
    <p v-if="s.review_comment" class="lab-review-comment">{{s.reviewer}}: {{s.review_comment}}</p>
    <form v-if="s.status==='submitted'" class="lab-form lab-review-form" @submit.prevent="sendReview(s)">
     <fieldset><legend>Решение</legend>
      <label><input v-model="rf(s.id).status" type="radio" value="accepted" /> Принять</label>
      <label><input v-model="rf(s.id).status" type="radio" value="rework" /> На доработку</label>
     </fieldset>
     <label v-if="rf(s.id).status==='accepted'"><span>Оценка 0–100</span><input v-model.number="rf(s.id).grade" type="number" min="0" max="100" required /></label>
     <label><span>Комментарий{{ rf(s.id).status==='rework' ? ' (что исправить)' : '' }}</span><textarea v-model="rf(s.id).review_comment" rows="3" maxlength="3000" :required="rf(s.id).status==='rework'"></textarea></label>
     <p v-for="(e, k) in reviewForm.errors" :key="k" class="error" role="alert">{{e}}</p>
     <button type="submit" :disabled="reviewForm.processing">Сохранить решение</button>
    </form>
   </article>
  </section>
 </Shell>
</template>

<style scoped>
.lab-tabs{display:flex;gap:8px;margin-bottom:14px}
.lab-tabs button{background:var(--surface);color:inherit;border:1px solid var(--border)}
.lab-tabs button.active{border-color:var(--accent-text);font-weight:600}
.lab-list{display:grid;gap:12px;margin-bottom:40px}
.lab-head{display:flex;justify-content:space-between;align-items:center;gap:12px;width:100%;background:none;border:0;color:inherit;padding:0;text-align:left}
.lab-head span:first-child{display:grid;gap:2px}
.lab-head small{color:var(--muted)}
.lab-status{font-size:12px;font-weight:600;white-space:nowrap}
.lab-status.accepted{color:var(--success, #1e8e3e)}
.lab-status.rework{color:var(--danger, #c0392b)}
.lab-body{margin-top:12px}
.lab-body h3{font-size:15px;margin:14px 0 6px}
.lab-sub{border-left:3px solid var(--border);padding-left:10px;margin:10px 0}
.lab-sub.accepted{border-left-color:var(--success, #1e8e3e)}
.lab-sub.rework{border-left-color:var(--danger, #c0392b)}
.lab-comment{white-space:pre-wrap}
.lab-files{display:flex;flex-wrap:wrap;gap:10px}
.lab-review-comment{color:var(--muted);white-space:pre-wrap}
.lab-form{display:grid;gap:10px;margin-top:12px}
.lab-form label{display:grid;gap:4px}
.lab-form fieldset{display:flex;gap:16px;border:0;padding:0;margin:0}
.lab-form fieldset label{display:flex;gap:6px;align-items:center}
</style>
