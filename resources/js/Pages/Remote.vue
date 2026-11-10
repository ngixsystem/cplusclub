<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';
import { Copy, KeyRound, MonitorSmartphone, Plus, ScreenShare, Trash2, RotateCw } from 'lucide-vue-next';
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
const STATUS = { on: 'В сети', off: 'Не в сети', wait: 'Ждёт установки', unknown: 'Нет данных' } as const;
const statusDetail = (h: Host) => {
    const p = st(h);
    if (p.online === true && p.status_changed_at) return `в сети с ${new Date(p.status_changed_at).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}`;
    if (p.online === false) { const since = p.last_seen_at ?? p.status_changed_at; return since ? `был в сети ${ago(since)}` : 'ещё не выходил на связь'; }
    return '';
};
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
async function showPassword(h: Host) {
    const r = await post(`/remote/hosts/${h.id}/password`);
    if (r.ok) shownPassword.value = { ...shownPassword.value, [h.id]: (await r.json()).password };
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
      <article v-for="h in list" :key="h.id" class="panel rm-card" :class="'is-' + statusClass(h)">
        <span class="rm-sweep" aria-hidden="true" />
        <div class="rm-top">
          <span class="rm-icon" aria-hidden="true"><span class="rm-halo" /><ScreenShare /></span>
          <div class="rm-title">
            <h3>{{ h.name }}</h3>
            <p class="muted small">
              <template v-if="h.rustdesk_id">ID <b class="mono">{{ h.rustdesk_id }}</b><template v-if="h.hostname"> · {{ h.hostname }}</template></template>
              <template v-else>ID появится после установки</template>
            </p>
          </div>
        </div>
        <div class="rm-state">
          <span class="rm-sig" :class="statusClass(h)" role="status" :aria-label="STATUS[statusClass(h)] + (statusDetail(h) ? ', ' + statusDetail(h) : '')">
            <span class="rm-bars" aria-hidden="true"><i v-for="n in 4" :key="n" :style="{ '--i': n - 1 }" /></span>
            <Transition name="rm-swap" mode="out-in"><span :key="statusClass(h)" class="rm-sig-text" aria-hidden="true">{{ STATUS[statusClass(h)] }}</span></Transition>
          </span>
          <Transition name="rm-swap" mode="out-in"><span v-if="statusDetail(h)" :key="statusDetail(h)" class="rm-detail" :class="statusClass(h)" aria-hidden="true">{{ statusDetail(h) }}</span></Transition>
        </div>
        <p class="muted small rm-last">{{ h.last ? `Последнее подключение: ${ago(h.last.at)}${h.last.by ? ', ' + h.last.by : ''}` : 'Ещё не подключались' }}</p>
        <button type="button" class="rm-connect" :class="{ secondary: st(h).online === false }" :disabled="h.pending || busy === h.id" @click="connect(h)">
          <ScreenShare aria-hidden="true" /> {{ busy === h.id ? 'Открываю…' : 'Подключиться' }}
        </button>
        <p v-if="hint === h.id" class="rm-hint" role="status">
          RustDesk не открылся? Установите его на свой компьютер с <a href="https://rustdesk.com/download" target="_blank" rel="noopener">rustdesk.com</a> и нажмите «Подключиться» снова — настраивать сервер не нужно, он передаётся в ссылке.
        </p>
        <div v-if="canManage" class="rm-tools">
          <button type="button" class="ghost small" @click="showPassword(h)"><KeyRound aria-hidden="true" /> {{ shownPassword[h.id] ? shownPassword[h.id] : 'Пароль' }}</button>
          <button type="button" class="ghost small" title="Новая команда установки" @click="reinstall(h)"><RotateCw aria-hidden="true" /> Переустановить</button>
          <button type="button" class="ghost small danger" @click="remove(h)"><Trash2 aria-hidden="true" /> Убрать</button>
        </div>
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
.rm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; margin-top: 14px }
.rm-card { display: grid; gap: 10px; margin: 0; align-content: start }
.rm-top { display: flex; gap: 12px; align-items: center; min-width: 0 }
.rm-title { min-width: 0 }
.rm-title h3 { margin: 0; font-size: 16px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
.rm-title p { margin: 2px 0 0 }
.mono { font-variant-numeric: tabular-nums; letter-spacing: .02em }
.small { font-size: 12.5px }
.rm-last { margin: 0 }
.rm-connect { width: 100%; justify-content: center; padding: 12px; font-size: 15px; display: inline-flex; gap: 8px; align-items: center }
.rm-connect svg { width: 18px; height: 18px }
.rm-hint { margin: 0; font-size: 13px; padding: 10px 12px; border-radius: 10px; background: rgba(245, 158, 11, .12) }
.rm-tools { display: flex; gap: 4px; flex-wrap: wrap; border-top: 1px solid var(--border, rgba(127,127,127,.2)); padding-top: 8px }
.rm-tools button { display: inline-flex; gap: 6px; align-items: center }
.rm-tools .danger { color: var(--red, #dc2626) }
/* ── Card status: live signal ───────────────────────────── */
.rm-card { position: relative; overflow: hidden; isolation: isolate; --st: 120 120 128;
  transition: border-color .6s ease, box-shadow .6s ease }
.rm-card.is-on { --st: 34 197 94; border-color: rgb(var(--st) / .28); box-shadow: 0 0 0 1px rgb(var(--st) / .06), 0 18px 40px -24px rgb(var(--st) / .55) }
.rm-card.is-off { --st: 239 68 68 }
.rm-card.is-wait { --st: 245 158 11 }
/* soft corner glow, breathing while online */
.rm-card::after { content: ''; position: absolute; z-index: -1; inset: -40% -30% auto auto; width: 70%; aspect-ratio: 1; border-radius: 50%;
  background: radial-gradient(closest-side, rgb(var(--st) / .22), transparent 70%); opacity: 0; transition: opacity .8s ease; pointer-events: none }
.rm-card.is-on::after { opacity: 1; animation: rm-breathe 4.8s ease-in-out infinite }
.rm-card.is-off::after { opacity: .45 }
/* light sweeping along the top edge */
.rm-sweep { position: absolute; inset: 0 0 auto; height: 2px; overflow: hidden; opacity: 0; transition: opacity .6s }
.rm-sweep::before { content: ''; position: absolute; inset: 0; width: 45%;
  background: linear-gradient(90deg, transparent, rgb(var(--st)), transparent); transform: translateX(-110%) }
.rm-card.is-on .rm-sweep { opacity: 1 }
.rm-card.is-on .rm-sweep::before { animation: rm-sweep 3.6s cubic-bezier(.45, 0, .2, 1) infinite }

.rm-icon { position: relative; flex: none; display: grid; place-items: center; width: 44px; height: 44px; border-radius: 13px;
  color: rgb(var(--st)); background: rgb(var(--st) / .12); border: 1px solid rgb(var(--st) / .22); transition: color .6s, background .6s, border-color .6s }
.rm-icon svg { width: 20px; height: 20px; position: relative }
.rm-card.is-unknown .rm-icon, .rm-card.is-off .rm-icon { color: var(--muted-fg, #8a8a93); background: rgba(127, 127, 127, .1); border-color: rgba(127, 127, 127, .2) }
.rm-halo { position: absolute; inset: -1px; border-radius: inherit; border: 1.5px solid rgb(var(--st) / .7); opacity: 0 }
.rm-card.is-on .rm-halo { animation: rm-halo 2.4s cubic-bezier(.2, .7, .3, 1) infinite }

.rm-title { flex: 1 }
.rm-title p { white-space: nowrap; overflow: hidden; text-overflow: ellipsis }
.rm-state { display: flex; align-items: center; gap: 10px; min-width: 0; min-height: 28px }
.rm-sig { display: inline-flex; align-items: center; gap: 8px; height: 28px; padding: 0 11px 0 9px; border-radius: 999px; flex: none;
  font-size: 12px; font-weight: 650; letter-spacing: .01em; color: rgb(var(--st)); background: rgb(var(--st) / .1); border: 1px solid rgb(var(--st) / .25);
  transition: color .5s, background .5s, border-color .5s; --st: 120 120 128 }
.rm-sig.on { --st: 34 197 94 } .rm-sig.off { --st: 239 68 68 } .rm-sig.wait { --st: 245 158 11 }
.rm-sig.unknown { color: var(--muted-fg, #8a8a93) }
.rm-bars { display: inline-flex; align-items: flex-end; gap: 2px; height: 12px }
.rm-bars i { width: 3px; height: 100%; border-radius: 2px; background: currentColor; transform-origin: bottom;
  transform: scaleY(calc(.3 + var(--i) * .233)); transition: transform .6s cubic-bezier(.34, 1.56, .64, 1) calc(var(--i) * 60ms), opacity .4s }
/* online: an equalizer-like live wave */
.rm-sig.on .rm-bars i { animation: rm-wave 1.25s ease-in-out infinite; animation-delay: calc(var(--i) * -.18s) }
/* offline: signal collapses, only the first bar left */
.rm-sig.off .rm-bars i { transform: scaleY(.22); opacity: .35 }
.rm-sig.off .rm-bars i:first-child { opacity: 1; transform: scaleY(.45) }
/* waiting: bars light up one by one, like a search */
.rm-sig.wait .rm-bars i { animation: rm-scan 1.6s ease-in-out infinite; animation-delay: calc(var(--i) * .2s); opacity: .25 }
.rm-sig.unknown .rm-bars i { opacity: .3 }
.rm-sig-text { display: inline-block }

.rm-detail { min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 12.5px; color: var(--muted-fg, #8a8a93); font-variant-numeric: tabular-nums }
.rm-detail.off { color: rgb(239 68 68 / .85) }

/* status text swaps with a blur-slide */
.rm-swap-enter-active, .rm-swap-leave-active { transition: opacity .28s ease, transform .38s cubic-bezier(.16, 1, .3, 1), filter .28s ease }
.rm-swap-enter-from { opacity: 0; transform: translateY(6px); filter: blur(4px) }
.rm-swap-leave-to { opacity: 0; transform: translateY(-6px); filter: blur(4px) }

.rm-move-move { transition: transform .7s cubic-bezier(.16, 1, .3, 1) }
.rm-move-enter-active { transition: opacity .4s, transform .5s cubic-bezier(.16, 1, .3, 1) }
.rm-move-enter-from { opacity: 0; transform: translateY(12px) scale(.98) }
.rm-move-leave-active { display: none }
@keyframes rm-wave { 0%, 100% { transform: scaleY(.35) } 50% { transform: scaleY(1) } }
@keyframes rm-scan { 0%, 100% { opacity: .25 } 40% { opacity: 1 } }
@keyframes rm-halo { 0% { opacity: .8; transform: scale(1) } 100% { opacity: 0; transform: scale(1.45) } }
@keyframes rm-sweep { 0% { transform: translateX(-110%) } 70%, 100% { transform: translateX(260%) } }
@keyframes rm-breathe { 0%, 100% { transform: scale(1); opacity: .85 } 50% { transform: scale(1.12); opacity: 1 } }
@media (max-width: 640px) { .rm-club { min-width: 0; flex: 1 } .rm-cmd { flex-direction: column } }
@media (prefers-reduced-motion: reduce) { .rm-wait .dot, .rm-bars i, .rm-halo, .rm-sweep::before, .rm-card::after { animation: none !important } .rm-sig.on .rm-bars i { transform: scaleY(calc(.3 + var(--i) * .233)) } }
</style>
