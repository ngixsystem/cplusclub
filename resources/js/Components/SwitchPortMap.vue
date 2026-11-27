<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
type P = { if_index: number; port: number; name: string; alias: string | null; up: boolean; speed_mbps: number | null; uplink: boolean; macs: number; changed_at: string | null; pcs: { id: number; name: string }[] };
const props = defineProps<{ switchId: number; network: { snmp: { host: string; port: number; version: string; polled_at: string | null; last_error: string | null; sys_descr: string | null; model?: string | null; firmware?: string | null; serial?: string | null } | null; ports: P[]; canManage: boolean }; tz: string }>();
const sel = ref<P | null>(null);
const access = computed(() => props.network.ports.filter(p => p.port <= 48 && !(p.speed_mbps && p.speed_mbps >= 10000)));
const top = computed(() => access.value.filter((_, i) => i % 2 === 0));
const bottom = computed(() => access.value.filter((_, i) => i % 2 === 1));
const extra = computed(() => props.network.ports.filter(p => !access.value.includes(p)));
const slow = computed(() => props.network.ports.filter(p => p.up && !p.uplink && p.speed_mbps !== null && p.speed_mbps < 1000));
const form = useForm({ host: props.network.snmp?.host ?? '', port: props.network.snmp?.port ?? 161, version: props.network.snmp?.version ?? '2c', community: '' });
const editing = ref(!props.network.snmp);
function state(p: P) { return !p.up ? 'down' : p.uplink ? 'uplink' : p.speed_mbps !== null && p.speed_mbps < 1000 ? 'slow' : 'ok'; }
function speed(p: P) { return !p.up ? 'выключен' : p.speed_mbps === null ? 'включён' : p.speed_mbps >= 1000 ? p.speed_mbps / 1000 + ' Гбит/с' : p.speed_mbps + ' Мбит/с'; }
function when(v: string | null) { return v ? new Date(v).toLocaleString('ru-RU', { timeZone: props.tz }) : '—'; }
</script>

<template>
  <section class="panel pm">
    <div class="pm-head">
      <div>
        <h2>Порты</h2>
        <p v-if="network.snmp?.last_error" class="bad-text">Опрос не удался: {{ network.snmp.last_error }}</p>
        <p v-else-if="network.snmp?.polled_at" class="muted">{{ network.snmp.model || network.snmp.sys_descr }}<template v-if="network.snmp.serial"> · S/N {{ network.snmp.serial }}</template><template v-if="network.snmp.firmware"> · прошивка {{ network.snmp.firmware }}</template> · опрос {{ when(network.snmp.polled_at) }}</p>
        <p v-else class="muted">Данных ещё нет — сборщик опросит коммутатор в течение 2 минут.</p>
      </div>
      <ul class="legend"><li><i class="ok"></i>1 Гбит/с</li><li><i class="slow"></i>100 Мбит/с</li><li><i class="uplink"></i>Аплинк</li><li><i class="down"></i>Выключен</li></ul>
    </div>

    <div v-if="network.ports.length" class="face" role="list">
      <div v-for="(row, ri) in [top, bottom]" :key="ri" class="row">
        <button v-for="p in row" :key="p.if_index" type="button" role="listitem" class="port" :class="[state(p), { sel: sel?.if_index === p.if_index }]"
          :title="p.name + ' · ' + speed(p) + (p.pcs.length ? ' · ' + p.pcs.map(x => x.name).join(', ') : '')" @click="sel = sel?.if_index === p.if_index ? null : p">
          <span class="num">{{ p.port }}</span><span class="pc">{{ p.pcs[0]?.name ?? (p.uplink ? '↑' : '') }}</span>
        </button>
      </div>
      <div v-if="extra.length" class="row extra">
        <button v-for="p in extra" :key="p.if_index" type="button" class="port" :class="[state(p), { sel: sel?.if_index === p.if_index }]" :title="p.name + ' · ' + speed(p)" @click="sel = sel?.if_index === p.if_index ? null : p">
          <span class="num">{{ p.port }}</span><span class="pc">{{ p.uplink ? '↑' : '' }}</span>
        </button>
      </div>
    </div>

    <div v-if="sel" class="detail">
      <strong>Порт {{ sel.port }}</strong> <span class="muted">{{ sel.name }}{{ sel.alias ? ' · ' + sel.alias : '' }}</span>
      <p>{{ speed(sel) }}<template v-if="sel.uplink"> · аплинк, {{ sel.macs }} MAC-адресов</template><template v-if="sel.changed_at"> · изменился {{ when(sel.changed_at) }}</template></p>
      <p v-if="sel.pcs.length">Подключено: <template v-for="(pc, i) in sel.pcs" :key="pc.id"><template v-if="i">, </template><Link :href="'/equipment/' + pc.id">{{ pc.name }}</Link></template></p>
      <p v-else-if="!sel.uplink" class="muted">ПК на этом порту не опознан (нет MAC в карточке ПК или ПК давно не включался).</p>
    </div>

    <div v-if="slow.length" class="slow-list">
      <h3>На 100 Мбит/с сейчас</h3>
      <p v-for="p in slow" :key="p.if_index">Порт {{ p.port }} — <template v-if="p.pcs.length"><Link :href="'/equipment/' + p.pcs[0].id">{{ p.pcs[0].name }}</Link></template><span v-else class="muted">ПК не опознан</span>: обжать кабель с обеих сторон</p>
    </div>

    <details v-if="network.canManage" :open="editing" class="snmp">
      <summary>Настройки SNMP</summary>
      <form class="form-grid" @submit.prevent="form.post('/switches/' + switchId + '/snmp', { preserveScroll: true, onSuccess: () => form.reset('community') })">
        <label>IP-адрес<input v-model="form.host" required /></label>
        <label>Порт<input v-model.number="form.port" type="number" min="1" max="65535" /></label>
        <label>Версия<select v-model="form.version"><option value="2c">v2c</option><option value="1">v1</option></select></label>
        <label>Community<input v-model="form.community" type="password" autocomplete="new-password" :placeholder="network.snmp ? 'не меняется, если пусто' : ''" :required="!network.snmp" /></label>
        <button :disabled="form.processing">Сохранить</button>
        <p class="error" v-for="e in form.errors" :key="e">{{ e }}</p>
      </form>
    </details>
  </section>
</template>

<style scoped>
.pm { display: grid; gap: 18px }
.pm-head { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap }
.pm-head h2 { margin: 0 0 4px } .pm-head p { margin: 0 }
.legend { display: flex; gap: 14px; list-style: none; margin: 0; padding: 0; font-size: 13px; color: var(--muted); align-items: center }
.legend li { display: flex; align-items: center; gap: 6px } .legend i { width: 12px; height: 12px; border-radius: 3px; display: inline-block }
.face { display: grid; gap: 6px; padding: 14px; background: var(--hover); border-radius: 12px; overflow-x: auto }
.row { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(44px, 1fr); gap: 6px }
.row.extra { margin-top: 8px; justify-content: start; grid-auto-columns: 52px }
.port { display: grid; justify-items: center; gap: 2px; padding: 6px 2px; border-radius: 6px; border: 1.5px solid transparent; font-size: 11px; line-height: 1.1; color: #fff; cursor: pointer; min-width: 0 }
.port .num { font-weight: 600; font-size: 12px } .port .pc { opacity: .9; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-height: 12px }
.ok, .legend .ok { background: #16a34a } .slow, .legend .slow { background: #f59e0b; color: #1c1917 } .uplink, .legend .uplink { background: #2563eb } .down, .legend .down { background: var(--border-strong); color: var(--muted) }
.port.slow { animation: pulse 1.6s ease-in-out infinite }
.port.sel { border-color: var(--text); outline: 2px solid var(--bg); outline-offset: -3px }
@keyframes pulse { 50% { box-shadow: 0 0 0 4px rgba(245, 158, 11, .35) } }
@media (prefers-reduced-motion: reduce) { .port.slow { animation: none } }
.detail { padding: 14px 16px; border: 1px solid var(--border); border-radius: 12px } .detail p { margin: 6px 0 0 }
.slow-list h3 { margin: 0 0 6px; font-size: 16px } .slow-list p { margin: 4px 0 }
.snmp summary { cursor: pointer; color: var(--muted) } .snmp form { margin-top: 14px }
.muted { color: var(--muted) } .bad-text { color: #dc2626 }
</style>
