<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import Shell from '../Layouts/Shell.vue';
import ChapterQuiz, { type QuizState } from '../Components/ChapterQuiz.vue';
import '../../css/knowledge.css';

type Lesson = { id: string; code: string; title: string; index: number; seconds: number; match: string; quiz: boolean; number: number };
type Watch = { percent: number; watched: boolean; required_percent: number };
const props = defineProps<{
 course: { title: string; author: string; source: string; playlist: string; note: string; total: number };
 lesson: Lesson; prev: { id: string; title: string } | null; next: { id: string; title: string } | null;
 watch: Watch; quiz: QuizState | null; enrolled: boolean;
}>();

const watch = ref<Watch>({ ...props.watch });
const quiz = ref<QuizState | null>(props.quiz);
const state = ref<'loading' | 'ready' | 'unmatched' | 'error' | 'other'>('loading');
const holder = ref<HTMLDivElement | null>(null);
const saveError = ref('');
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let player: any = null;
let timer: number | undefined;
let candidates: number[] = [];
let attempt = 0;

const normalize = (s: string) => ' ' + s.toLowerCase().replace(/[^0-9a-zа-яё]+/gi, ' ').replace(/\s+/g, ' ').trim() + ' ';
const matches = (title: string) => normalize(title).includes(' ' + props.lesson.match);
const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] ?? '');

async function report() {
 if (!player || state.value !== 'ready') return;
 const duration = Number(player.getDuration?.() || 0);
 const position = Number(player.getCurrentTime?.() || 0);
 if (duration < 1) return;
 try {
  const r = await fetch(`/training/lesson/${props.lesson.id}/watch`, { method: 'POST', credentials: 'same-origin',
   headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify({ position, duration }) });
  if (r.status === 401) { window.location.assign('/login'); return; }
  if (!r.ok) throw new Error();
  watch.value = await r.json(); saveError.value = '';
 } catch { saveError.value = 'Не удалось сохранить прогресс просмотра. Проверьте соединение — видео можно продолжать смотреть.'; }
}

function verifyCued(retry = 0) {
 const title = String(player?.getVideoData?.()?.title ?? '');
 if (!title && retry < 6) { window.setTimeout(() => verifyCued(retry + 1), 400); return; }
 if (title && matches(title)) { state.value = 'ready'; return; }
 tryNext();
}

function tryNext() {
 const index = candidates[attempt++];
 if (index === undefined) { state.value = 'unmatched'; return; }
 player.cuePlaylist({ listType: 'playlist', list: props.course.playlist, index });
}

function onStateChange(e: { data: number }) {
 // 5 = cued, 1 = playing, 2 = paused, 0 = ended
 if (state.value === 'loading' && e.data === 5) { verifyCued(); return; }
 if (e.data === 1 && state.value !== 'loading') {
  const title = String(player.getVideoData?.()?.title ?? '');
  if (title && !matches(title)) { player.pauseVideo(); state.value = 'other'; return; }
  if (state.value === 'other') state.value = 'ready';
 }
 if (e.data === 2 || e.data === 0) report();
}

function loadApi(): Promise<void> {
 // eslint-disable-next-line @typescript-eslint/no-explicit-any
 const w = window as any;
 if (w.YT?.Player) return Promise.resolve();
 return new Promise(resolve => {
  const previous = w.onYouTubeIframeAPIReady;
  w.onYouTubeIframeAPIReady = () => { previous?.(); resolve(); };
  if (!document.querySelector('script[src="https://www.youtube.com/iframe_api"]')) {
   const s = document.createElement('script'); s.src = 'https://www.youtube.com/iframe_api'; s.async = true;
   s.onerror = () => { state.value = 'error'; };
   document.head.appendChild(s);
  }
 });
}

onMounted(async () => {
 const i = props.lesson.index;
 candidates = [i, i + 1, i - 1, i + 2, i - 2, i + 3, i - 3].filter(n => n >= 0);
 const iframe = document.createElement('iframe');
 iframe.src = `https://www.youtube.com/embed/videoseries?list=${props.course.playlist}&enablejsapi=1&rel=0&playsinline=1&origin=${encodeURIComponent(location.origin)}`;
 iframe.title = props.lesson.code + ' ' + props.lesson.title;
 iframe.allow = 'accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen';
 iframe.referrerPolicy = 'strict-origin-when-cross-origin';
 iframe.allowFullscreen = true;
 holder.value?.appendChild(iframe);
 const failsafe = window.setTimeout(() => { if (state.value === 'loading') state.value = 'error'; }, 20000);
 try { await loadApi(); } catch { state.value = 'error'; return; }
 // eslint-disable-next-line @typescript-eslint/no-explicit-any
 player = new (window as any).YT.Player(iframe, {
  events: { onReady: () => { window.clearTimeout(failsafe); tryNext(); }, onStateChange, onError: () => { if (state.value === 'loading') tryNext(); } },
 });
 timer = window.setInterval(() => { if (player?.getPlayerState?.() === 1) report(); }, 10000);
});
onBeforeUnmount(() => { window.clearInterval(timer); report(); player?.destroy?.(); });

const minutes = computed(() => Math.max(1, Math.round(props.lesson.seconds / 60)));
</script>

<template>
 <Head :title="lesson.code + ' ' + lesson.title" />
 <Shell>
  <div class="kb-heading"><div>
   <span class="tl-eyebrow">{{course.title}} · урок {{lesson.number}} из {{course.total}} · ~{{minutes}} мин</span>
   <h1>{{lesson.code}} {{lesson.title}}</h1>
  </div><Link href="/training" class="kb-source-button">← К обучению</Link></div>
  <p v-if="!enrolled" class="kb-notice" role="status">Программа «{{course.title}}» ещё не начата — нажмите «Начать обучение» в разделе <Link href="/training">Обучение</Link>, чтобы считался срок прохождения. Просмотр урока засчитывается в любом случае.</p>

  <div class="tl-player" ref="holder"></div>
  <p v-if="state==='loading'" class="tl-state" role="status">Загружаем урок из плейлиста YouTube…</p>
  <p v-else-if="state==='unmatched'" class="error" role="alert">Не удалось найти этот урок в плейлисте автоматически — возможно, порядок видео на канале изменился. Откройте <a :href="course.source" target="_blank" rel="noopener">плейлист на YouTube</a> и сообщите руководителю; просмотр здесь не засчитывается.</p>
  <p v-else-if="state==='error'" class="error" role="alert">Плеер YouTube не загрузился. Проверьте доступ к YouTube и обновите страницу.</p>
  <p v-else-if="state==='other'" class="kb-notice" role="status">Урок закончился, YouTube перешёл к следующему видео. <Link v-if="next" :href="'/training/lesson/'+next.id">Открыть следующий урок →</Link></p>

  <section class="tl-progress" aria-label="Просмотр урока">
   <div><b>{{ watch.watched ? 'Урок просмотрен ✓' : `Просмотрено ${watch.percent} %` }}</b><span>Нужно не меньше {{watch.required_percent}} %. Засчитывается только реальный просмотр: перемотка вперёд не считается, можно смотреть на скорости до 2×.</span></div>
   <span class="kb-bar" aria-hidden="true"><i :style="{ width: watch.percent + '%' }"></i></span>
   <p v-if="saveError" class="error" role="alert">{{saveError}}</p>
  </section>

  <template v-if="lesson.quiz && quiz">
   <ChapterQuiz v-if="watch.watched" :article-id="lesson.id" :quiz="quiz" :checklist-done="watch.watched" :start-url="`/training/lesson/${lesson.id}/quiz`" submit-url="/training/lesson-quiz/" @updated="q => quiz = q" />
   <section v-else class="kb-checklist tl-locked"><div class="kb-checklist-head"><h2>Тест по уроку</h2><span>{{quiz.questions}} вопроса</span></div>
    <p>Тест откроется, когда вы досмотрите урок не меньше чем на {{watch.required_percent}} %. Нужно {{quiz.pass_percent}} % правильных ответов, после проверки будет разбор.</p></section>
  </template>
  <p v-else-if="!lesson.quiz" class="kb-notice tl-noquiz" role="status">У этого вводного урока нет теста — он засчитывается после просмотра.</p>

  <nav class="tl-nav" aria-label="Уроки">
   <Link v-if="prev" :href="'/training/lesson/'+prev.id">← {{prev.title}}</Link><span v-else></span>
   <Link v-if="next" :href="'/training/lesson/'+next.id">{{next.title}} →</Link>
  </nav>
  <p class="tl-source">{{course.note}} Автор: {{course.author}} · <a :href="course.source" target="_blank" rel="noopener">плейлист на YouTube</a></p>
 </Shell>
</template>

<style scoped>
.tl-eyebrow{display:block;font-size:11px;letter-spacing:.06em;text-transform:uppercase;font-weight:600;color:var(--muted);margin-bottom:8px}
.tl-player{position:relative;width:100%;max-width:960px;aspect-ratio:16/9;background:#000;border-radius:8px;overflow:hidden}
.tl-player :deep(iframe){position:absolute;inset:0;width:100%;height:100%;border:0}
.tl-state{font-size:12px;color:var(--muted)}
.tl-progress{max-width:960px;margin:16px 0;padding:14px 16px;border:1px solid var(--border);border-radius:7px;background:var(--surface);display:grid;gap:10px}
.tl-progress div{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:baseline}
.tl-progress b{font-size:14px}
.tl-progress span:not(.kb-bar){font-size:12px;color:var(--muted)}
.tl-progress .kb-bar{display:block}
.tl-locked p{font-size:13px;line-height:1.7;color:var(--muted);margin:0}
.tl-noquiz{max-width:960px}
.tl-nav{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;max-width:960px;margin:20px 0;font-size:13px}
.tl-source{font-size:11px;color:var(--muted);max-width:960px}
:deep(.kb-checklist){max-width:960px}
</style>
