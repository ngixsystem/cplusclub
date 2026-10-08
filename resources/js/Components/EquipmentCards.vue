<script setup lang="ts">
import {Link} from '@inertiajs/vue3';
defineProps<{items:{id:number;name?:string;type?:string;zone?:string;workstation_number?:string;status:string;next_inspection_date?:string;club?:{name:string}}[]}>();
const types:Record<string,string>={pc:'Компьютер',server:'Сервер',switch:'Коммутатор',router:'Маршрутизатор',ups:'UPS',peripheral:'Периферия'};
const statuses:Record<string,string>={active:'Активно',maintenance:'Обслуживание',retired:'Выведено'};
</script>
<template>
  <div class="equipment-grid">
    <Link v-for="item in items" :key="item.id" :href="'/equipment/'+item.id" class="equipment-card" :aria-label="item.name">
      <div class="card-top"><span>{{types[item.type || 'pc'] || item.type}}</span><span class="badge" :data-status="item.status">{{statuses[item.status] || item.status}}</span></div>
      <div class="device-art" aria-hidden="true">
        <svg viewBox="0 0 160 100" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <template v-if="item.type === 'server'"><rect x="48" y="9" width="64" height="80" rx="7"/><rect v-for="y in [19,40,61]" :key="y" x="56" :y="y" width="48" height="16" rx="3"/><path d="M64 27h17m-17 21h17m-17 21h17"/><circle v-for="y in [27,48,69]" :key="y" cx="96" :cy="y" r="2" class="led"/></template>
          <template v-else-if="!item.type || item.type === 'pc'"><rect x="16" y="16" width="91" height="58" rx="5"/><path d="M23 23h77v42H23z" class="screen"/><path d="M53 75v12m17-12v12M43 88h38M44 48l10-10 12 12 15-17"/><rect x="116" y="25" width="28" height="63" rx="4"/><circle cx="130" cy="40" r="5"/><circle cx="130" cy="59" r="5"/><path d="M125 78h10"/></template>
          <template v-else><rect x="30" y="30" width="100" height="43" rx="7"/><path d="M41 58h10m8 0h10m8 0h10m8 0h10M46 30V15m68 15V15"/><circle cx="117" cy="43" r="2" class="led"/></template>
        </svg>
      </div>
      <h3>{{item.name}}</h3><p class="club-name">{{item.club?.name || 'Клуб не указан'}}</p>
      <div class="device-meta"><span>{{item.workstation_number ? 'Место '+item.workstation_number : types[item.type || 'pc']}}</span><span v-if="item.zone">{{item.zone}}</span></div>
      <div class="card-bottom"><span>Осмотр: {{item.next_inspection_date || 'не назначен'}}</span><span aria-hidden="true">↗</span></div>
    </Link>
  </div>
</template>
<style scoped>
.equipment-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;padding:24px}
.equipment-card{min-width:0;display:block;border:1px solid var(--border);border-radius:12px;padding:18px;background:var(--surface);color:var(--text);text-decoration:none;transition:border-color .18s,background .18s}
.equipment-card:hover{border-color:var(--accent-text);background:var(--hover)}
.equipment-card:focus-visible{outline:3px solid var(--accent-text);outline-offset:3px}
.card-top,.device-meta,.card-bottom{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
.card-top,.club-name,.device-meta,.card-bottom{font-size:12px;color:var(--muted)}
.device-art{margin:16px 0 12px;border-radius:8px;background:linear-gradient(135deg,var(--hover),var(--surface));display:flex;justify-content:center;padding:12px}
svg{width:160px;height:100px}.screen{fill:var(--soft)}.led{fill:var(--success);stroke:var(--success)}
h3{font-size:19px;font-weight:700;overflow-wrap:anywhere;margin:0 0 4px}.club-name{margin:0 0 16px;overflow-wrap:anywhere}.device-meta{min-height:22px}.card-bottom{margin-top:14px;padding-top:12px;border-top:1px solid var(--border)}
@media(max-width:560px){.equipment-grid{grid-template-columns:1fr;padding:14px;gap:12px}}
@media(prefers-reduced-motion:reduce){.equipment-card{transition:none}}
</style>
