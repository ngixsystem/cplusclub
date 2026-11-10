<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Copy, Ellipsis, KeyRound, Monitor, MonitorSmartphone, Plus, Trash2, RotateCw, ArrowUpRight } from 'lucide-vue-next';
import Shell from '../Layouts/Shell.vue';

type Host = { id: number; club_id: number; name: string; rustdesk_id: string | null; hostname: string | null; enrolled_at: string | null; pending: boolean; online: boolean | null; last_seen_at: string | null; status_changed_at: string | null; last: { at: string; by: string | null } | null };
const props = defineProps<{ clubs: { id: number; name: string }[]; hosts: Host[]; canManage: boolean; server: string; installCommand: string | null; installFor: number | null }>();

function ago(s: string) { const m = Math.round((Date.now() - new Date(s).getTime()) / 60000); return m < 1 ? 'только что' : m < 60 ? `${m} мин назад` : m < 1440 ? `${Math.round(m / 60)} ч назад` : new Date(s).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); }
const KEY = 'cclub-remote-club';
const stored = (() => { try { return Number(localStorage.getItem(KEY)) || 0; } catch { return 0; } })();
const clubId = ref<number>(props.clubs.some((c) => c.id === stored) ? stored : (props.clubs[0]?.id ?? 0));
watch(clubId, (v) => { try { localStorage.setItem(KEY, String(v)); } catch { /* optional */ } });
/* Online status: stored by the scheduler every minute, refreshed live while the page is open. */
type P = { online: boolean | null; last_seen_at: string | null; status_changed_at: string | null };
const presence = ref<Record<number, P>>({});
const st = (h: Host): P => presence.value[h.id] ?? h;
async function pollPresence() {
    if (document.hidden) return;
    try {
        const r = await fetch('/remote/presence', { headers: { Accept: 'application/json' } });
        if (r.ok) presence.value = Object.fromEntries((await r.json()).hosts.map((x: P & { id: number }) => [x.id, x]));
    } catch { /* keep the last known state */ }
}
pollPresence();
const presenceTimer = window.setInterval(pollPresence, 15000);
const onVisible = () => { if (!document.hidden) pollPresence(); };
document.addEventListener('visibilitychange', onVisible);
onUnmounted(() => { window.clearInterval(presenceTimer); document.removeEventListener('visibilitychange', onVisible); });
const STATUS = { on: 'В сети', off: 'Не в сети', wait: 'Установка', unknown: 'Нет данных' } as const;
const hhmm = (s: string) => new Date(s).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
/* One quiet line under the name: since when it's online / when it was last seen. */
const statusDetail = (h: Host) => {
    const p = st(h);
    if (h.pending) return 'Ждёт запуска команды установки';
    if (p.online === true) return p.status_changed_at ? `В сети с ${hhmm(p.status_changed_at)}` : '';
    if (p.online === false) { const since = p.last_seen_at ?? p.status_changed_at; return since ? `Был ${ago(since)}` : 'Ещё не выходил на связь'; }
    return '';
};
const lastLine = (h: Host) => h.last ? `Подключались ${ago(h.last.at)}${h.last.by ? ' · ' + h.last.by : ''}` : 'Ещё не подключались';

/* «⋯» menu with the rarely used actions. */
const menu = ref<number | null>(null);
const closeMenu = (e: MouseEvent) => { if (!(e.target as HTMLElement).closest?.('.rm-more')) menu.value = null; };
const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') menu.value = null; };
onMounted(() => { document.addEventListener('click', closeMenu); document.addEventListener('keydown', onKey); });
onUnmounted(() => { document.removeEventListener('click', closeMenu); document.removeEventListener('keydown', onKey); });
const statusClass = (h: Host): keyof typeof STATUS => h.pending ? 'wait' : st(h).online === null ? 'unknown' : st(h).online ? 'on' : 'off';

const list = computed(() => props.hosts.filter((h) => h.club_id === clubId.value)
    .slice().sort((a, b) => Number(!!st(b).online) - Number(!!st(a).online) || a.name.localeCompare(b.name, 'ru')));


const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] ?? '');
const post = (url: string) => fetch(url, { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() } });

/* Connect: fetch the rustdesk:// link and hand it to the OS. If the window never loses focus, RustDesk probably isn't installed. */
const busy = ref<number | null>(null), hint = ref<number | null>(null), error = ref('');
async function connect(h: Host) {
    busy.value = h.id; hint.value = null; error.value = '';
    try {
        const r = await post(`/remote/hosts/${h.id}/connect`);
        const j = await r.json().catch(() => ({}));
        if (!r.ok) { error.value = j.message || 'Не удалось получить ссылку подключения.'; return; }
        let left = false;
        const blur = () => { left = true; };
        window.addEventListener('blur', blur, { once: true });
        window.location.href = j.url;
        setTimeout(() => { window.removeEventListener('blur', blur); if (!left && !document.hidden) hint.value = h.id; }, 2500);
    } finally { busy.value = null; }
}

const shownPassword = ref<Record<number, string>>({});
async function copyPassword(h: Host) {
    const r = await post(`/remote/hosts/${h.id}/password`);
    if (!r.ok) return;
    const pw = (await r.json()).password as string;
    shownPassword.value = { ...shownPassword.value, [h.id]: pw };
    await copy(pw, 'pw' + h.id);
}

const copied = ref('');
async function copy(text: string, what: string) {
    try { await navigator.clipboard.writeText(text); copied.value = what; setTimeout(() => { if (copied.value === what) copied.value = ''; }, 1800); } catch { /* manual copy */ }
}

/* Adding a computer: name → install command → poll until the installer reports the ID. */
const adding = ref(false), manual = ref(false);
const form = useForm({ club_id: 0, name: '', rustdesk_id: '' });
const add = () => form.transform((d) => ({ ...d, club_id: clubId.value, rustdesk_id: manual.value ? d.rustdesk_id : '' }))
    .post('/remote/hosts', { preserveScroll: true, onSuccess: () => { form.reset(); adding.value = false; manual.value = false; } });
const reinstall = (h: Host) => router.post(`/remote/hosts/${h.id}`, { reinstall: true }, { preserveScroll: true });
const remove = (h: Host) => { if (window.confirm(`Убрать «${h.name}» из удалённого доступа? Сам RustDesk на компьютере останется.`)) router.post(`/remote/hosts/${h.id}`, { delete: true }, { preserveScroll: true }); };

let timer: number | undefined;
const waiting = computed(() => props.installFor && props.hosts.find((h) => h.id === props.installFor)?.pending ? props.installFor : null);
watch(waiting, (id) => {
    window.clearInterval(timer);
    if (!id) return;
    timer = window.setInterval(async () => {
        const r = await fetch(`/remote/hosts/${id}/status`, { headers: { Accept: 'application/json' } });
        if (r.ok && (await r.json()).enrolled) { window.clearInterval(timer); router.reload({ only: ['hosts'] }); }
    }, 4000);
}, { immediate: true });
onUnmounted(() => window.clearInterval(timer));
watch(() => props.installFor, (id) => { const h = props.hosts.find((x) => x.id === id); if (h) clubId.value = h.club_id; }, { immediate: true });


</script>

<template>
  <Head title="Удалённый доступ" />
  <Shell>
    <div class="heading rm-head">
      <div>
        <h1>Удалённый доступ</h1>
        <p class="muted">Подключение к серверам и ПК клубов через RustDesk. Весь трафик идёт через сервер C+CLub — открывать порты в клубе не нужно.</p>
      </div>
      <div class="rm-actions">
        <select v-if="clubs.length" v-model.number="clubId" aria-label="Клуб" class="rm-club">
          <option v-for="c in clubs" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <button v-if="canManage && clubs.length" type="button" @click="adding = !adding"><Plus aria-hidden="true" /> Добавить компьютер</button>
      </div>
    </div>

    <p v-if="error" class="error" role="alert">{{ error }}</p>

    <form v-if="adding" class="panel rm-add" @submit.prevent="add">
      <h2>Новый компьютер</h2>
      <label>Название<input v-model="form.name" required maxlength="80" placeholder="Сервер iCafe" /></label>
      <label class="rm-check"><input v-model="manual" type="checkbox" /> RustDesk уже установлен и подключён к нашему серверу — введу ID вручную</label>
      <label v-if="manual">RustDesk ID<input v-model="form.rustdesk_id" inputmode="numeric" placeholder="123 456 789" /></label>
      <p v-if="form.errors.name || form.errors.rustdesk_id" class="error">{{ form.errors.name || form.errors.rustdesk_id }}</p>
      <div class="rm-row"><button type="submit" :disabled="form.processing">{{ manual ? 'Добавить' : 'Получить команду установки' }}</button><button type="button" class="secondary" @click="adding = false">Отмена</button></div>
    </form>

    <section v-if="installCommand" class="panel rm-install" aria-live="polite">
      <h2><MonitorSmartphone aria-hidden="true" /> Установка на компьютер клуба</h2>
      <ol>
        <li>На сервере клуба откройте <b>PowerShell от имени администратора</b>.</li>
        <li>Вставьте команду и нажмите Enter. Она поставит RustDesk, подключит его к серверу C+CLub и зарегистрирует компьютер здесь.</li>
      </ol>
      <div class="rm-cmd"><code>{{ installCommand }}</code><button type="button" class="secondary" @click="copy(installCommand!, 'cmd')"><Copy aria-hidden="true" /> {{ copied === 'cmd' ? 'Скопировано' : 'Копировать' }}</button></div>
      <p class="muted small">Команда одноразовая и действует 24 часа. <span v-if="waiting" class="rm-wait"><span class="dot" /> Жду, пока компьютер зарегистрируется…</span><span v-else class="ok">Компьютер зарегистрирован.</span></p>
    </section>

    <p v-if="!clubs.length" class="panel muted">Нет клубов, к которым у вас есть доступ.</p>
    <p v-else-if="!list.length" class="panel muted">В этом клубе пока нет компьютеров для удалённого доступа.<template v-if="canManage"> Нажмите «Добавить компьютер».</template></p>

    <TransitionGroup name="rm-move" tag="div" class="rm-grid">
      <article v-for="h in list" :key="h.id" class="rm-card" :class="['is-' + statusClass(h), { 'menu-open': menu === h.id }]">
        <header class="rm-card-head">
          <span class="rm-glyph" aria-hidden="true"><Monitor /></span>
          <span class="rm-state" role="status" :aria-label="STATUS[statusClass(h)]">
            <span class="rm-dot" aria-hidden="true" />
            <Transition name="rm-swap" mode="out-in"><span :key="statusClass(h)">{{ STATUS[statusClass(h)] }}</span></Transition>
          </span>
        </header>

        <div class="rm-body">
          <h3 class="rm-name">{{ h.name }}</h3>
          <p class="rm-id"><template v-if="h.rustdesk_id"><span class="rm-num">{{ h.rustdesk_id }}</span><template v-if="h.hostname"> · {{ h.hostname }}</template></template><template v-else>ID появится после установки</template></p>
        </div>

        <dl class="rm-meta">
          <Transition name="rm-swap" mode="out-in"><dd :key="statusDetail(h)" :class="statusClass(h)">{{ statusDetail(h) || '\u00a0' }}</dd></Transition>
          <dd>{{ lastLine(h) }}</dd>
        </dl>

        <footer class="rm-actions-row">
          <button type="button" class="rm-connect" :disabled="h.pending || busy === h.id" @click="connect(h)">
            {{ busy === h.id ? 'Открываю…' : 'Подключиться' }}<ArrowUpRight aria-hidden="true" />
          </button>
          <div v-if="canManage" class="rm-more">
            <button type="button" class="rm-more-btn" :aria-expanded="menu === h.id" aria-label="Ещё" @click="menu = menu === h.id ? null : h.id"><Ellipsis aria-hidden="true" /></button>
            <Transition name="rm-pop">
              <div v-if="menu === h.id" class="rm-menu" role="menu">
                <button v-if="h.rustdesk_id" type="button" role="menuitem" @click="copyPassword(h)">
                  <KeyRound aria-hidden="true" /><span>{{ copied === 'pw' + h.id ? 'Пароль скопирован' : 'Скопировать пароль' }}<small v-if="shownPassword[h.id]">{{ shownPassword[h.id] }}</small></span>
                </button>
                <button type="button" role="menuitem" @click="menu = null; reinstall(h)"><RotateCw aria-hidden="true" /><span>Новая команда установки</span></button>
                <hr />
                <button type="button" role="menuitem" class="destructive" @click="menu = null; remove(h)"><Trash2 aria-hidden="true" /><span>Убрать</span></button>
              </div>
            </Transition>
          </div>
        </footer>

        <p v-if="hint === h.id" class="rm-hint" role="status">
          RustDesk не открылся? Установите его с <a href="https://rustdesk.com/download" target="_blank" rel="noopener">rustdesk.com</a> и нажмите «Подключиться» снова — сервер передаётся в ссылке, настраивать ничего не нужно.
        </p>
      </article>
    </TransitionGroup>
  </Shell>
</template>

<style scoped>
.rm-head { display: flex; gap: 16px; justify-content: space-between; align-items: flex-end; flex-wrap: wrap }
.rm-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap }
.rm-actions button svg, .rm-tools svg, .rm-cmd svg { width: 16px; height: 16px }
.rm-club { min-width: 200px }
.rm-add { display: grid; gap: 12px; max-width: 520px }
.rm-add h2, .rm-install h2 { margin: 0; font-size: 16px; display: flex; gap: 8px; align-items: center }
.rm-install h2 svg { width: 18px; height: 18px }
.rm-add label { display: grid; gap: 6px }
.rm-check { display: flex !important; gap: 8px; align-items: center; font-weight: 400 }
.rm-row { display: flex; gap: 8px }
.rm-install { display: grid; gap: 10px }
.rm-install ol { margin: 0; padding-left: 20px; display: grid; gap: 4px }
.rm-cmd { display: flex; gap: 8px; align-items: stretch; min-width: 0 }
.rm-cmd code { flex: 1; min-width: 0; overflow-x: auto; white-space: nowrap; padding: 10px 12px; border-radius: 10px; background: var(--surface-2, rgba(127,127,127,.12)); font-size: 12.5px }
.rm-wait { display: inline-flex; gap: 6px; align-items: center }
.rm-wait .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--amber, #f59e0b); animation: rm-pulse 1.2s ease-in-out infinite }
.ok { color: var(--green, #16a34a) }
@keyframes rm-pulse { 50% { opacity: .3 } }
.small { font-size: 12.5px }
/* ── Host cards ─────────────────────────────────────────────
   Quiet surfaces, hierarchy through type, one small status light. */
.rm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; margin-top: 20px }
.rm-card { --st: 142 142 147; position: relative; display: flex; flex-direction: column; gap: 18px; padding: 20px;
  background: var(--surface); border: 1px solid var(--border); border-radius: 20px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
  transition: transform .5s cubic-bezier(.2, .8, .2, 1), box-shadow .5s cubic-bezier(.2, .8, .2, 1), border-color .3s }
.rm-card:hover { transform: translateY(-2px); box-shadow: 0 1px 2px rgba(0, 0, 0, .04), 0 12px 32px -12px rgba(0, 0, 0, .18) }
:root[data-theme=dark] .rm-card:hover { box-shadow: 0 12px 32px -12px rgba(0, 0, 0, .7); border-color: var(--border-strong, #2e2e32) }
.rm-card.menu-open { z-index: 5 }
.rm-card.is-on { --st: 52 199 89 }       /* systemGreen */
.rm-card.is-off { --st: 255 69 58 }      /* systemRed */
.rm-card.is-wait { --st: 255 159 10 }    /* systemOrange */

.rm-card-head { display: flex; align-items: center; justify-content: space-between }
.rm-glyph { display: grid; place-items: center; width: 36px; height: 36px; border-radius: 10px; background: var(--surface-2); color: var(--muted) }
.rm-glyph svg { width: 18px; height: 18px; stroke-width: 1.75 }

.rm-state { display: inline-flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 500; color: var(--muted); letter-spacing: -.005em }
.rm-dot { position: relative; width: 8px; height: 8px; border-radius: 50%; background: rgb(var(--st)); transition: background-color .6s ease }
.rm-dot::after { content: ''; position: absolute; inset: 0; border-radius: inherit; background: rgb(var(--st)); opacity: 0 }
.is-on .rm-dot::after { animation: rm-beacon 2.8s cubic-bezier(.2, .6, .35, 1) infinite }
.is-wait .rm-dot { animation: rm-blink 1.6s ease-in-out infinite }
.is-on .rm-state { color: var(--text) }

.rm-body { display: grid; gap: 4px; min-width: 0 }
.rm-name { margin: 0; font-size: 21px; line-height: 1.15; font-weight: 600; letter-spacing: -.022em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis }
.rm-id { margin: 0; font-size: 13px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis }
.rm-num { font-variant-numeric: tabular-nums; color: var(--text); opacity: .72 }

.rm-meta { margin: 0; display: grid; gap: 2px; font-size: 13px; color: var(--muted) }
.rm-meta dd { margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-variant-numeric: tabular-nums }

.rm-actions-row { display: flex; gap: 8px; align-items: stretch; margin-top: auto }
.rm-connect { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 6px; height: 44px; border-radius: 12px;
  font-size: 15px; font-weight: 600; letter-spacing: -.01em;
  transition: transform .2s cubic-bezier(.2, .8, .2, 1), opacity .2s, background-color .3s }
.rm-connect svg { width: 16px; height: 16px; transition: transform .35s cubic-bezier(.2, .8, .2, 1) }
.rm-connect:hover:not(:disabled) svg { transform: translate(2px, -2px) }
.rm-connect:active:not(:disabled), .rm-more-btn:active { transform: scale(.97) }
.is-off .rm-connect, .is-wait .rm-connect { background: var(--surface-2); color: var(--text); border-color: var(--border) }
.rm-connect:disabled { opacity: 1; color: var(--subtle, #71717a) }

.rm-more { position: relative }
.rm-more-btn { width: 44px; height: 44px; padding: 0; display: grid; place-items: center; border-radius: 12px;
  background: var(--surface-2); color: var(--text); border-color: var(--border); transition: transform .2s cubic-bezier(.2, .8, .2, 1) }
.rm-more-btn svg { width: 18px; height: 18px }
.rm-menu { position: absolute; right: 0; bottom: calc(100% + 8px); min-width: 236px; padding: 6px; border-radius: 14px; z-index: 10;
  background: var(--surface);
  border: 1px solid var(--border); box-shadow: var(--shadow-pop, 0 16px 48px rgba(0, 0, 0, .2)); transform-origin: bottom right }
:root[data-theme=dark] .rm-menu { background: var(--surface-2); border-color: var(--border-strong, #2e2e32) }
.rm-menu button { width: 100%; display: flex; gap: 10px; align-items: center; justify-content: flex-start; padding: 8px 10px; border: 0; border-radius: 8px;
  background: transparent; color: var(--text); font-size: 13.5px; font-weight: 450; text-align: left }
.rm-menu button:hover { background: var(--hover); opacity: 1 }
.rm-menu button svg { width: 16px; height: 16px; color: var(--muted) }
.rm-menu button span { display: grid; gap: 1px }
.rm-menu small { font-size: 12px; color: var(--muted); font-variant-numeric: tabular-nums; user-select: all }
.rm-menu hr { margin: 5px 8px; border: 0; border-top: 1px solid var(--border) }
.rm-menu .destructive, .rm-menu .destructive svg { color: rgb(255 69 58) }

.rm-hint { margin: 0; font-size: 13px; line-height: 1.45; color: var(--muted) }

/* motion */
.rm-swap-enter-active, .rm-swap-leave-active { transition: opacity .25s ease, transform .45s cubic-bezier(.2, .8, .2, 1), filter .25s ease }
.rm-swap-enter-from { opacity: 0; transform: translateY(4px); filter: blur(2px) }
.rm-swap-leave-to { opacity: 0; transform: translateY(-4px); filter: blur(2px) }
.rm-pop-enter-active { transition: opacity .18s ease, transform .32s cubic-bezier(.2, .9, .25, 1.15) }
.rm-pop-leave-active { transition: opacity .14s ease, transform .14s ease }
.rm-pop-enter-from, .rm-pop-leave-to { opacity: 0; transform: scale(.94) translateY(4px) }
.rm-move-move { transition: transform .8s cubic-bezier(.2, .8, .2, 1) }
.rm-move-enter-active { transition: opacity .45s ease, transform .6s cubic-bezier(.2, .8, .2, 1) }
.rm-move-enter-from { opacity: 0; transform: translateY(10px) scale(.985) }
.rm-move-leave-active { display: none }
@keyframes rm-beacon { 0% { opacity: .55; transform: scale(1) } 70%, 100% { opacity: 0; transform: scale(2.6) } }
@keyframes rm-blink { 0%, 100% { opacity: 1 } 50% { opacity: .35 } }
@media (max-width: 640px) { .rm-club { min-width: 0; flex: 1 } .rm-cmd { flex-direction: column } }
@media (prefers-reduced-motion: reduce) { .rm-wait .dot, .rm-dot, .rm-dot::after { animation: none !important } .rm-card, .rm-card:hover { transform: none } }
</style>
