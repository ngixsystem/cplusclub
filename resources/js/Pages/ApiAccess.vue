<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Copy, KeyRound, ShieldCheck } from 'lucide-vue-next';
import Shell from '../Layouts/Shell.vue';

type ApiClientRow = { id: number; name: string; club: string | null; hint: string; ips: string[]; scopes: string[]; last_used_at: string | null; last_ip: string | null; revoked_at: string | null; created_at: string; requests_24h: number; errors_24h: number };
type LogRow = { id: number; name: string; method: string; path: string; status: number; ip: string | null; ms: number; created_at: string };
const props = defineProps<{ clients: ApiClientRow[]; log: LogRow[]; clubs: { id: number; name: string }[]; scopes: string[]; baseUrl: string; newToken: { id: number; token: string } | null }>();

const SCOPE_LABEL: Record<string, string> = { pcs: 'Статус ПК', shifts: 'Смены и касса', billing: 'Логи биллинга' };
const form = useForm({ name: '', club_id: props.clubs[0]?.id ?? 0, allowed_ips: '', scopes: [...props.scopes] });
const create = () => form.post('/api-access', { preserveScroll: true, onSuccess: () => form.reset('name', 'allowed_ips') });
const editing = ref<number | null>(null), editScopes = ref<string[]>([]);
const startEdit = (c: ApiClientRow) => { editing.value = c.id; editScopes.value = [...c.scopes]; };
const saveScopes = (c: ApiClientRow) => router.post(`/api-access/${c.id}/scopes`, { scopes: editScopes.value }, { preserveScroll: true, onSuccess: () => { editing.value = null; } });
const revoke = (c: ApiClientRow) => { if (window.confirm(`Отозвать токен «${c.name}»? Внешняя система сразу потеряет доступ.`)) router.post(`/api-access/${c.id}/revoke`, {}, { preserveScroll: true }); };

const copied = ref('');
async function copy(text: string, what: string) {
    try { await navigator.clipboard.writeText(text); copied.value = what; setTimeout(() => { if (copied.value === what) copied.value = ''; }, 1800); } catch { /* select manually */ }
}
const when = (s: string | null) => s ? new Date(s).toLocaleString('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—';
</script>

<template>
  <Head title="API для внешних систем" />
  <Shell>
    <div class="heading">
      <h1>API для внешних систем</h1>
      <p class="muted">Токены только на чтение для систем клиентов, например панели собственника. Каждый токен видит один клуб, может быть ограничен IP-адресами, все запросы записываются в журнал.</p>
    </div>

    <section v-if="newToken" class="panel api-new" role="status">
      <h2><ShieldCheck aria-hidden="true" /> Новый токен — скопируйте сейчас</h2>
      <p class="muted">Повторно токен не показывается: в базе хранится только его хеш. Передавайте его лично, не в общих чатах.</p>
      <div class="api-copy"><code>{{ newToken.token }}</code><button type="button" class="secondary" @click="copy(newToken.token, 'token')"><Copy aria-hidden="true" />{{ copied === 'token' ? 'Скопировано' : 'Копировать' }}</button></div>
    </section>

    <section class="panel api-base">
      <div>
        <p class="eyebrow">Адрес API</p>
        <div class="api-copy"><code>{{ baseUrl }}</code><button type="button" class="secondary" @click="copy(baseUrl, 'url')"><Copy aria-hidden="true" />{{ copied === 'url' ? 'Скопировано' : 'Копировать' }}</button></div>
      </div>
      <p class="muted small">Заголовок <code>Authorization: Bearer &lt;токен&gt;</code>. Эндпоинты: <code>GET /me</code>, <code>GET /pcs</code>, <code>GET /shifts</code>, <code>GET /billing-logs</code>. Лимит — 120 запросов в минуту.</p>
    </section>

    <form class="panel form-grid api-form" @submit.prevent="create">
      <h2><KeyRound aria-hidden="true" /> Выдать токен</h2>
      <label>Кому (система или человек)<input v-model="form.name" required maxlength="120" placeholder="Панель собственника SPOT" /></label>
      <label>Клуб<select v-model.number="form.club_id" required><option v-for="c in clubs" :key="c.id" :value="c.id">{{ c.name }}</option></select></label>
      <label>Разрешённые IP (через запятую, пусто — любые)<input v-model="form.allowed_ips" maxlength="500" placeholder="2.29.219.88" /></label>
      <fieldset class="api-scopes"><legend>Доступ</legend>
        <label v-for="s in scopes" :key="s" class="check"><input v-model="form.scopes" type="checkbox" :value="s" />{{ SCOPE_LABEL[s] ?? s }}</label>
      </fieldset>
      <p v-if="Object.keys(form.errors).length" class="error">{{ Object.values(form.errors)[0] }}</p>
      <button type="submit" :disabled="form.processing || !form.scopes.length">Создать токен</button>
    </form>

    <section class="panel">
      <h2>Токены</h2>
      <p v-if="!clients.length" class="empty">Токенов пока нет.</p>
      <div v-else class="table-scroll">
        <table>
          <thead><tr><th>Кому</th><th>Клуб</th><th>Доступ</th><th>IP</th><th>Последний запрос</th><th>За 24 ч</th><th /></tr></thead>
          <tbody>
            <tr v-for="c in clients" :key="c.id" :class="{ revoked: c.revoked_at }">
              <td><strong>{{ c.name }}</strong><br /><span class="muted small mono">{{ c.hint }}</span></td>
              <td>{{ c.club ?? '—' }}</td>
              <td>
                <template v-if="editing === c.id">
                  <label v-for="s in scopes" :key="s" class="check api-scope"><input v-model="editScopes" type="checkbox" :value="s" />{{ SCOPE_LABEL[s] ?? s }}</label>
                  <span class="api-edit-actions"><button type="button" class="small" :disabled="!editScopes.length" @click="saveScopes(c)">Сохранить</button><button type="button" class="ghost small" @click="editing = null">Отмена</button></span>
                </template>
                <template v-else>{{ c.scopes.map(s => SCOPE_LABEL[s] ?? s).join(', ') }}<button v-if="!c.revoked_at" type="button" class="ghost small api-edit" @click="startEdit(c)">Изменить</button></template>
              </td>
              <td class="mono small">{{ c.ips.length ? c.ips.join(', ') : 'любые' }}</td>
              <td>{{ when(c.last_used_at) }}<br v-if="c.last_ip" /><span v-if="c.last_ip" class="muted small mono">{{ c.last_ip }}</span></td>
              <td class="mono">{{ c.requests_24h }}<span v-if="c.errors_24h" class="api-err"> · {{ c.errors_24h }} ош.</span></td>
              <td><span v-if="c.revoked_at" class="muted small">отозван {{ when(c.revoked_at) }}</span><button v-else type="button" class="ghost small danger" @click="revoke(c)">Отозвать</button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel">
      <h2>Журнал запросов</h2>
      <p v-if="!log.length" class="empty">Запросов ещё не было.</p>
      <div v-else class="table-scroll">
        <table class="api-log">
          <thead><tr><th>Время</th><th>Токен</th><th>Запрос</th><th>Ответ</th><th>IP</th><th>мс</th></tr></thead>
          <tbody>
            <tr v-for="l in log" :key="l.id">
              <td class="small">{{ when(l.created_at) }}</td><td>{{ l.name }}</td>
              <td class="mono small">{{ l.method }} {{ l.path.replace('/api/ext/v1', '') || '/' }}</td>
              <td><span class="api-status" :class="l.status < 400 ? 'ok' : 'bad'">{{ l.status }}</span></td>
              <td class="mono small">{{ l.ip ?? '—' }}</td><td class="mono small">{{ l.ms }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </Shell>
</template>

<style scoped>
.api-new, .api-base { display: grid; gap: 10px }
.api-new { border-color: rgb(52 199 89 / .45) }
h2 { display: flex; gap: 8px; align-items: center; margin: 0; font-size: 16px }
h2 svg { width: 18px; height: 18px }
.api-copy { display: flex; gap: 8px; align-items: stretch; min-width: 0 }
.api-copy code { flex: 1; min-width: 0; overflow-x: auto; white-space: nowrap; padding: 10px 12px; border-radius: 10px; background: var(--surface-2); font-size: 13px }
.api-copy button { display: inline-flex; gap: 6px; align-items: center }
.api-copy svg { width: 16px; height: 16px }
.api-form { max-width: 640px }
.api-scopes { display: flex; gap: 18px; flex-wrap: wrap; border: 0; padding: 0; margin: 0 }
.api-scopes legend { font-size: 13px; color: var(--muted); margin-bottom: 6px }
.small { font-size: 12.5px }
.mono { font-variant-numeric: tabular-nums }
tr.revoked td { opacity: .55 }
.api-err { color: rgb(255 69 58) }
.api-status { font-variant-numeric: tabular-nums; font-weight: 600 }
.api-status.ok { color: rgb(52 199 89) }
.api-status.bad { color: rgb(255 69 58) }
.danger { color: rgb(255 69 58) }
.api-scope { display: flex; gap: 6px; align-items: center; white-space: nowrap; font-size: 13.5px }
.api-edit-actions { display: flex; gap: 6px; margin-top: 6px }
.api-edit { margin-left: 6px }
@media (max-width: 640px) { .api-copy { flex-direction: column } }
</style>
