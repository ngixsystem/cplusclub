<script setup lang="ts">
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
type Sw = { id: number; name: string; zone: string | null; host: string | null; polled_at: string | null; last_error: string | null; sys_descr: string | null; ports_up: number; ports_total: number; slow: number; pcs: number };
type Collector = { id: number; name: string; token_hint: string; hostname: string | null; version: string | null; last_seen_at: string | null; online: boolean };
const props = defineProps<{ clubId: number; network: { switches: Sw[]; collectors: Collector[] }; install: string | null; canEdit: boolean; tz: string }>();
const adding = ref(false);
const form = useForm({ name: '', zone: '', host: '', community: '', version: '2c' });
const issue = useForm({});
const revoke = useForm({});
const copied = ref(false);
function add() { form.post('/clubs/' + props.clubId + '/switches', { preserveScroll: true, onSuccess: () => { form.reset(); adding.value = false; } }); }
function ago(v: string | null) {
  if (!v) return 'ещё не было';
  const s = Math.round((Date.now() - new Date(v).getTime()) / 1000);
  return s < 60 ? 'только что' : s < 3600 ? Math.round(s / 60) + ' мин назад' : new Date(v).toLocaleString('ru-RU', { timeZone: props.tz });
}
async function copy() { try { await navigator.clipboard.writeText(props.install ?? ''); copied.value = true; setTimeout(() => (copied.value = false), 2000); } catch { /* manual copy */ } }
</script>

<template>
  <section class="panel net">
    <div class="net-head">
      <div><h2>Сеть клуба</h2><p>Коммутаторы опрашиваются по SNMP: видно, в какой порт включён каждый ПК и на какой скорости.</p></div>
      <button v-if="canEdit" class="secondary" @click="adding = !adding">{{ adding ? 'Отменить' : '+ Коммутатор' }}</button>
    </div>

    <form v-if="adding" class="form-grid add" @submit.prevent="add">
      <label>Название<input v-model="form.name" placeholder="Свитч 1 — зал A" required /></label>
      <label>Зона / где стоит<input v-model="form.zone" placeholder="Серверная, стойка 1" /></label>
      <label>IP-адрес<input v-model="form.host" placeholder="192.168.1.11" required /></label>
      <label>SNMP community (только чтение)<input v-model="form.community" type="password" autocomplete="new-password" required /></label>
      <label>Версия SNMP<select v-model="form.version"><option value="2c">v2c</option><option value="1">v1</option></select></label>
      <button :disabled="form.processing">Добавить</button>
      <p class="error" v-for="e in form.errors" :key="e">{{ e }}</p>
    </form>

    <p v-if="!network.switches.length" class="muted">Коммутаторов пока нет.</p>
    <div v-else class="sw-list">
      <Link v-for="s in network.switches" :key="s.id" :href="'/equipment/' + s.id" class="sw">
        <span class="dot" :class="s.last_error ? 'bad' : s.polled_at ? 'ok' : 'idle'" aria-hidden="true"></span>
        <div class="sw-main"><strong>{{ s.name }}</strong><small>{{ s.host }}{{ s.zone ? ' · ' + s.zone : '' }}</small></div>
        <div class="sw-stats">
          <template v-if="s.last_error"><span class="bad-text">{{ s.last_error }}</span></template>
          <template v-else-if="s.polled_at">
            <span>{{ s.ports_up }}/{{ s.ports_total }} портов</span><span>{{ s.pcs }} ПК</span>
            <span v-if="s.slow" class="warn-text">{{ s.slow }} на 100 Мбит/с</span>
          </template>
          <span v-else class="muted">ждёт первого опроса</span>
        </div>
      </Link>
    </div>

    <div v-if="canEdit" class="collector">
      <h3>Сборщик в клубе</h3>
      <p class="muted">Сервер не видит локальную сеть клуба, поэтому коммутаторы опрашивает маленькая программа на компьютере, который всегда включён (например, сервер CCBoot). Она только читает данные и отправляет их сюда по HTTPS.</p>
      <div v-for="c in network.collectors" :key="c.id" class="col-row">
        <span class="dot" :class="c.online ? 'ok' : 'bad'" aria-hidden="true"></span>
        <span><strong>{{ c.hostname || c.name }}</strong> <small class="muted">· …{{ c.token_hint }}{{ c.version ? ' · v' + c.version : '' }} · {{ c.online ? 'на связи' : 'нет связи' }}, {{ ago(c.last_seen_at) }}</small></span>
        <button class="secondary small" :disabled="revoke.processing" @click="revoke.post('/clubs/' + clubId + '/snmp-collectors/' + c.id + '/revoke', { preserveScroll: true })">Отключить</button>
      </div>
      <div v-if="install" class="install">
        <p>Выполните в PowerShell <b>от имени администратора</b> на компьютере в сети клуба. Команда показывается один раз.</p>
        <pre>{{ install }}</pre>
        <button class="secondary" @click="copy">{{ copied ? 'Скопировано' : 'Копировать' }}</button>
      </div>
      <button v-else class="secondary" :disabled="issue.processing" @click="issue.post('/clubs/' + clubId + '/snmp-collectors', { preserveScroll: true })">{{ network.collectors.length ? 'Установить ещё один' : 'Установить сборщик' }}</button>
    </div>
  </section>
</template>

<style scoped>
.net { display: grid; gap: 18px }
.net-head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start }
.net-head h2 { margin: 0 0 4px } .net-head p { margin: 0; color: var(--muted) }
.add { padding: 18px; border: 1px solid var(--border); border-radius: 12px }
.sw-list { display: grid; gap: 8px }
.sw { display: flex; align-items: center; gap: 14px; padding: 14px 16px; border: 1px solid var(--border); border-radius: 12px; color: inherit; text-decoration: none }
.sw:hover { background: var(--hover) }
.sw-main { display: grid; min-width: 0; flex: 1 } .sw-main small { color: var(--muted) }
.sw-stats { display: flex; gap: 14px; flex-wrap: wrap; justify-content: flex-end; font-size: 14px; color: var(--muted) }
.dot { width: 9px; height: 9px; border-radius: 50%; flex: none; background: var(--border-strong) }
.dot.ok { background: #16a34a } .dot.bad { background: #dc2626 }
.bad-text { color: #dc2626 } .warn-text { color: #b45309; font-weight: 500 }
.collector { border-top: 1px solid var(--border); padding-top: 18px; display: grid; gap: 10px; justify-items: start }
.collector h3 { margin: 0; font-size: 16px } .collector p { margin: 0 }
.col-row { display: flex; align-items: center; gap: 10px; width: 100% } .col-row > span:nth-child(2) { flex: 1 }
.install { display: grid; gap: 8px; width: 100% } .install pre { white-space: pre-wrap; word-break: break-all; background: var(--hover); padding: 12px; border-radius: 8px; margin: 0; font-size: 13px }
.small { padding: 6px 10px; font-size: 13px }
.muted { color: var(--muted) }
</style>
