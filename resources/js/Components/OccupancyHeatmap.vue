<script setup lang="ts">
import { computed, ref, watch } from 'vue';

/**
 * Hall load by weekday × hour (average share of busy PCs over 4 weeks).
 * One hue, light → strong; empty cells are hatched-neutral so "no data" never reads as "0%".
 */
type Cell = { dow: number; hour: number; pct: number };
type Data = { grid: Record<number, (number | null)[]>; peak: Cell | null; quiet: Cell | null; days: number; samples: number };
const props = defineProps<{ clubId: number }>();
const data = ref<Data | null>(null), error = ref(false);
const DAYS = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
const DAY_FULL = ['понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота', 'воскресенье'];
async function load() {
    error.value = false;
    try {
        const r = await fetch(`/clubs/${props.clubId}/occupancy`, { headers: { Accept: 'application/json' } });
        if (!r.ok) throw new Error();
        data.value = await r.json();
    } catch { error.value = true; }
}
watch(() => props.clubId, () => { data.value = null; load(); }, { immediate: true });

const rows = computed(() => [1, 2, 3, 4, 5, 6, 7].map((d) => ({ dow: d, cells: data.value?.grid[d] ?? Array(24).fill(null) })));
const hasData = computed(() => (data.value?.samples ?? 0) > 0);
// 5 steps of one hue; the level picks the step so neighbouring values stay distinguishable.
const level = (p: number | null) => p === null ? -1 : p < 10 ? 0 : p < 30 ? 1 : p < 50 ? 2 : p < 70 ? 3 : 4;
const hh = (h: number) => String(h).padStart(2, '0') + ':00';
const hover = ref<{ dow: number; hour: number; pct: number | null } | null>(null);
const label = (c: { dow: number; hour: number; pct: number | null } | null) =>
    !c ? '' : `${DAYS[c.dow - 1]}, ${hh(c.hour)}–${hh((c.hour + 1) % 24)} · ${c.pct === null ? 'нет данных' : 'занято ' + c.pct + '%'}`;
const sentence = (c: Cell | null) => c ? `${DAY_FULL[c.dow - 1]}, ${hh(c.hour)} — ${c.pct}%` : '—';
</script>

<template>
  <section class="card occ" aria-labelledby="occ-title">
    <div class="occ-head">
      <div>
        <p class="eyebrow">Загрузка зала</p>
        <h3 id="occ-title">Когда клуб полон</h3>
        <p class="chart-note">Средняя доля занятых ПК по дням недели и часам за последние 4 недели.</p>
      </div>
      <dl v-if="hasData && data?.peak" class="occ-facts">
        <div><dt>Пик</dt><dd>{{ sentence(data.peak) }}</dd></div>
        <div><dt>Тише всего</dt><dd>{{ sentence(data.quiet) }}</dd></div>
      </dl>
    </div>

    <p v-if="error" class="empty">Не удалось загрузить загрузку зала.</p>
    <p v-else-if="data && !hasData" class="empty">Собираем данные: замер каждые 5 минут. Первая картина появится к вечеру, полная — через неделю.</p>
    <template v-else-if="data">
      <div class="occ-scroll">
        <div class="occ-grid" role="grid" :aria-label="'Загрузка зала по дням недели и часам, данные за ' + data.days + ' дн.'" @mouseleave="hover = null">
          <span />
          <span v-for="h in 24" :key="'h' + h" class="occ-hour" aria-hidden="true">{{ (h - 1) % 3 === 0 ? String(h - 1).padStart(2, '0') : '' }}</span>
          <template v-for="r in rows" :key="r.dow">
            <span class="occ-day" :class="{ weekend: r.dow >= 6 }">{{ DAYS[r.dow - 1] }}</span>
            <span v-for="(p, h) in r.cells" :key="h" role="gridcell" tabindex="-1" class="occ-cell" :data-l="level(p)"
              :class="{ peak: data.peak && data.peak.dow === r.dow && data.peak.hour === h }"
              :aria-label="label({ dow: r.dow, hour: h, pct: p })"
              @mouseenter="hover = { dow: r.dow, hour: h, pct: p }" @focus="hover = { dow: r.dow, hour: h, pct: p }" />
          </template>
        </div>
      </div>
      <div class="occ-foot">
        <span class="occ-readout" aria-live="polite">{{ hover ? label(hover) : 'Наведите на клетку, чтобы увидеть час' }}</span>
        <span class="occ-legend" aria-hidden="true">0%<i v-for="l in 5" :key="l" class="occ-cell" :data-l="l - 1" />70%+<i class="occ-cell" data-l="-1" />нет данных</span>
      </div>
      <p v-if="data.days < 7" class="chart-note occ-note">Данных пока {{ data.days }} дн. — картина станет точнее через неделю.</p>
    </template>
    <p v-else class="empty">Загружаем…</p>
  </section>
</template>

<style scoped>
.occ { display: grid; gap: 14px }
.occ-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px 32px; flex-wrap: wrap }
.occ-head h3 { margin: 2px 0 4px }
.occ-facts { display: flex; gap: 28px; margin: 0 }
.occ-facts dt { font-size: 12px; color: var(--muted) }
.occ-facts dd { margin: 2px 0 0; font-size: 14px; font-weight: 600; font-variant-numeric: tabular-nums }
.occ-scroll { overflow-x: auto; margin: 0 -4px; padding: 4px }
.occ-grid { --cell: minmax(14px, 1fr); display: grid; grid-template-columns: 28px repeat(24, var(--cell)); gap: 3px; min-width: 520px }
.occ-hour { font-size: 10.5px; color: var(--muted); font-variant-numeric: tabular-nums; height: 14px }
.occ-day { font-size: 12px; color: var(--muted); align-self: center }
.occ-day.weekend { color: var(--text) }
.occ-cell { aspect-ratio: 1.25; min-height: 14px; border-radius: 4px; background: rgb(var(--occ) / var(--a)); --occ: 34 160 84; --a: .1;
  transition: transform .18s cubic-bezier(.2, .8, .2, 1), box-shadow .18s }
:root[data-theme=dark] .occ-cell { --occ: 74 222 128 }
.occ-cell[data-l="0"] { --a: .1 } .occ-cell[data-l="1"] { --a: .28 } .occ-cell[data-l="2"] { --a: .48 } .occ-cell[data-l="3"] { --a: .7 } .occ-cell[data-l="4"] { --a: 1 }
.occ-cell[data-l="-1"] { background: repeating-linear-gradient(135deg, transparent 0 3px, rgba(127, 127, 127, .14) 3px 4px); }
.occ-grid .occ-cell:hover, .occ-grid .occ-cell:focus { transform: scale(1.18); box-shadow: 0 0 0 2px var(--surface), 0 0 0 3px var(--text); outline: none; position: relative; z-index: 1 }
.occ-cell.peak { box-shadow: inset 0 0 0 1.5px var(--surface) }
.occ-foot { display: flex; justify-content: space-between; align-items: center; gap: 10px 24px; flex-wrap: wrap; font-size: 12.5px; color: var(--muted) }
.occ-readout { font-variant-numeric: tabular-nums; color: var(--text); min-height: 1.2em }
.occ-legend { display: inline-flex; align-items: center; gap: 4px }
.occ-legend .occ-cell { width: 14px; min-height: 10px; display: inline-block; aspect-ratio: 1.4 }
.occ-legend .occ-cell[data-l="-1"] { margin-left: 10px }
.occ-note { margin: 0 }
@media (prefers-reduced-motion: reduce) { .occ-cell { transition: none } }
</style>
