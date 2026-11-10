<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue';
import { ChevronUp, ChevronDown } from 'lucide-vue-next';
import RollingNumber from './RollingNumber.vue';

/**
 * «Смены за 7 дней» as a vertical day wheel: the selected day sits in the bend of a
 * glowing line, its cash total rolls in on the right. Wheel, swipe, arrow keys and
 * clicks move the wheel; the glow grows with the day's share of the week's best day.
 */
export type Day = { date: string; total: number; cash: number; card: number; qr: number; shifts: number };
const props = defineProps<{ days: Day[]; currency: string; timezone?: string }>();

const ROW = 62;
const index = ref(Math.max(0, props.days.length - 1));
watch(() => props.days.length, n => { if (index.value > n - 1 || index.value === n - 2) index.value = Math.max(0, n - 1); });
const day = computed<Day | undefined>(() => props.days[index.value]);
const prev = computed<Day | undefined>(() => props.days[index.value - 1]);
const best = computed(() => Math.max(1, ...props.days.map(d => d.total)));
const share = computed(() => (day.value?.total ?? 0) / best.value);
const delta = computed(() => {
  if (!day.value || !prev.value || prev.value.total <= 0) return null;
  return Math.round(((day.value.total - prev.value.total) / prev.value.total) * 100);
});
const weekTotal = computed(() => props.days.reduce((n, d) => n + d.total, 0));

const today = new Date().toLocaleDateString('sv-SE', { timeZone: props.timezone || 'Asia/Tashkent' });
const date = (iso: string) => new Date(iso + 'T12:00:00Z');
const short = (iso: string) => {
  const d = date(iso);
  const wd = d.toLocaleDateString('ru-RU', { weekday: 'short', timeZone: 'UTC' }).replace('.', '');
  return wd.charAt(0).toUpperCase() + wd.slice(1) + ', ' + d.getUTCDate() + '.' + String(d.getUTCMonth() + 1).padStart(2, '0');
};
const long = (iso: string) => { const t = date(iso).toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }); return t.charAt(0).toUpperCase() + t.slice(1); };
const plural = (n: number, one: string, few: string, many: string) => { const m = n % 10, h = n % 100; return m === 1 && h !== 11 ? one : m >= 2 && m <= 4 && (h < 12 || h > 14) ? few : many; };

function go(to: number) { index.value = Math.max(0, Math.min(props.days.length - 1, to)); }

// Wheel: one notch per day, with a short cooldown so a trackpad fling does not skip the week.
let acc = 0, cooldown = 0;
function onWheel(e: WheelEvent) {
  const dir = Math.sign(e.deltaY);
  if (!dir) return;
  const canMove = dir > 0 ? index.value < props.days.length - 1 : index.value > 0;
  if (!canMove) return;               // at the ends the page scrolls on
  e.preventDefault();
  if (performance.now() < cooldown) return;
  acc += e.deltaY;
  if (Math.abs(acc) >= 30) { go(index.value + dir); acc = 0; cooldown = performance.now() + 220; }
}
// Touch / pen drag on the wheel.
let startY: number | null = null, startIndex = 0;
const drag = ref(0);
function down(e: PointerEvent) { if (e.pointerType === 'mouse') return; startY = e.clientY; startIndex = index.value; (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId); }
function move(e: PointerEvent) {
  if (startY === null) return;
  const dy = e.clientY - startY;
  const target = Math.max(0, Math.min(props.days.length - 1, startIndex - Math.round(dy / ROW)));
  drag.value = dy - (startIndex - target) * -ROW;
  index.value = target;
}
function up() { startY = null; drag.value = 0; }
function key(e: KeyboardEvent) {
  const map: Record<string, number> = { ArrowUp: -1, ArrowLeft: -1, ArrowDown: 1, ArrowRight: 1 };
  if (e.key in map) { e.preventDefault(); go(index.value + map[e.key]); }
  else if (e.key === 'Home') { e.preventDefault(); go(0); }
  else if (e.key === 'End') { e.preventDefault(); go(props.days.length - 1); }
}
onUnmounted(() => { startY = null; });

const money = (n: number) => new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(Math.round(n));
</script>

<template>
  <div class="stage" :style="{ '--share': share.toFixed(3) }" tabindex="0" role="group"
       aria-roledescription="шкала дней" :aria-label="'Касса по дням. ' + (day ? long(day.date) + ': ' + money(day.total) + ' ' + currency : '') + '. Стрелки вверх и вниз меняют день.'"
       @wheel="onWheel" @keydown="key">
    <div class="glow" aria-hidden="true"><i class="g1"></i><i class="g2"></i><i class="dots"></i></div>

    <!-- Day wheel -->
    <div class="wheel" @pointerdown="down" @pointermove="move" @pointerup="up" @pointercancel="up">
      <svg class="curve" viewBox="0 0 120 420" preserveAspectRatio="none" aria-hidden="true">
        <defs>
          <linearGradient id="st-line" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#3a6b2a" stop-opacity="0" />
            <stop offset=".3" stop-color="#3f8f2f" stop-opacity=".55" />
            <stop offset=".5" stop-color="#b6ff3b" />
            <stop offset=".7" stop-color="#3f8f2f" stop-opacity=".55" />
            <stop offset="1" stop-color="#3a6b2a" stop-opacity="0" />
          </linearGradient>
        </defs>
        <path class="curve-glow" d="M70 0 V150 C70 182 42 180 42 210 C42 240 70 238 70 270 V420" />
        <path class="curve-line" d="M70 0 V150 C70 182 42 180 42 210 C42 240 70 238 70 270 V420" />
      </svg>

      <ol class="days" :style="{ transform: `translateY(${-index * ROW + drag}px)` }">
        <li v-for="(d, i) in days" :key="d.date" :data-dist="Math.min(3, Math.abs(i - index))" :aria-current="i === index ? 'date' : undefined">
          <button type="button" tabindex="-1" @click="go(i)">
            <span class="pill">{{ d.date === today ? 'Сегодня' : short(d.date) }}</span><span class="tick" aria-hidden="true"></span>
          </button>
        </li>
      </ol>

      <div class="knob">
        <button type="button" class="knob-half" aria-label="Предыдущий день" :disabled="index === 0" @click="go(index - 1)"><ChevronUp aria-hidden="true" /></button>
        <button type="button" class="knob-half" aria-label="Следующий день" :disabled="index === days.length - 1" @click="go(index + 1)"><ChevronDown aria-hidden="true" /></button>
      </div>
    </div>

    <!-- Selected day -->
    <div v-if="day" class="readout" aria-live="polite">
      <p class="when"><span class="badge">{{ day.shifts }} {{ plural(day.shifts, 'смена', 'смены', 'смен') }}</span>{{ long(day.date) }}</p>
      <p class="amount"><RollingNumber :value="Math.round(day.total)" /><span class="cur">{{ currency }}</span></p>
      <p class="caption">Касса за день
        <span v-if="delta !== null" class="delta" :data-sign="delta >= 0 ? 'up' : 'down'">{{ delta >= 0 ? '▲' : '▼' }} {{ Math.abs(delta) }}% к прошлому дню</span>
      </p>
      <dl class="split">
        <div><dt>Наличные</dt><dd><RollingNumber :value="Math.round(day.cash)" :stagger="18" /></dd></div>
        <div><dt>Карты</dt><dd><RollingNumber :value="Math.round(day.card)" :stagger="18" /></dd></div>
        <div><dt>QR</dt><dd><RollingNumber :value="Math.round(day.qr)" :stagger="18" /></dd></div>
      </dl>
      <p class="week">За 7 дней: <b>{{ money(weekTotal) }} {{ currency }}</b></p>
    </div>
  </div>
</template>

<style scoped>
/* A dark stage in both themes, like a hero card. */
.stage { --lime: #b6ff3b; --ink: #f2f5ee; --dim: rgba(242, 245, 238, .46); --line: rgba(242, 245, 238, .1);
  position: relative; isolation: isolate; overflow: hidden; display: grid; grid-template-columns: 262px 1fr; align-items: center;
  height: 420px; border-radius: 20px; background: #0a0c0b; color: var(--ink); outline: none; touch-action: pan-x; user-select: none }
.stage:focus-visible { box-shadow: 0 0 0 2px var(--lime) }

/* Glow: two soft blobs on the right; they swell with the selected day's share of the best day. */
.glow { position: absolute; inset: 0; z-index: -1; pointer-events: none }
.glow i { position: absolute; border-radius: 50%; filter: blur(60px); transition: transform 900ms cubic-bezier(.16, 1, .3, 1), opacity 900ms cubic-bezier(.16, 1, .3, 1) }
.g1 { width: 520px; height: 520px; right: -170px; top: -150px; background: radial-gradient(circle, #e9ff4d 0%, #7bd62e 45%, transparent 70%);
  opacity: calc(.35 + var(--share) * .6); transform: scale(calc(.7 + var(--share) * .45)) }
.g2 { width: 420px; height: 420px; right: 60px; bottom: -260px; background: radial-gradient(circle, #2fbf5a 0%, transparent 70%);
  opacity: calc(.25 + var(--share) * .35); transform: translateY(calc((1 - var(--share)) * 60px)) }
.dots { inset: 0; border-radius: 0 !important; filter: none !important; background-image: radial-gradient(rgba(255, 255, 255, .09) 1px, transparent 1.2px);
  background-size: 14px 14px; -webkit-mask-image: radial-gradient(70% 90% at 85% 30%, #000, transparent 70%); mask-image: radial-gradient(70% 90% at 85% 30%, #000, transparent 70%) }

/* Wheel */
.wheel { position: relative; height: 100%; overflow: hidden; cursor: ns-resize; -webkit-mask-image: linear-gradient(transparent, #000 18%, #000 82%, transparent); mask-image: linear-gradient(transparent, #000 18%, #000 82%, transparent) }
.curve { position: absolute; left: 120px; top: 0; width: 120px; height: 100%; overflow: visible }
.curve path { fill: none; vector-effect: non-scaling-stroke }
.curve-line { stroke: url(#st-line); stroke-width: 2 }
.curve-glow { stroke: url(#st-line); stroke-width: 8; opacity: .45; filter: blur(6px) }
.days { list-style: none; margin: 0; padding: 0; position: absolute; left: 0; right: 0; top: calc(50% - 31px); transition: transform 560ms cubic-bezier(.16, 1, .3, 1) }
.days li { height: 62px; display: flex; align-items: center; transition: opacity 400ms ease, transform 560ms cubic-bezier(.16, 1, .3, 1) }
.days button { all: unset; display: flex; align-items: center; gap: 0; cursor: pointer; padding-left: 40px }
.pill { min-width: 72px; padding: 6px 12px; border-radius: 999px; font-size: 12.5px; font-weight: 500; text-align: center; color: var(--dim);
  border: 1px solid var(--line); background: rgba(255, 255, 255, .02); transition: color 300ms ease, background 300ms ease, border-color 300ms ease, transform 300ms ease }
.tick { width: 14px; height: 1px; background: var(--line); margin-left: 8px; transition: background 300ms ease, width 300ms ease }
/* Distance from the bend fades and bows the labels like a drum. */
li[data-dist="1"] { opacity: .78; transform: translateX(-6px) }
li[data-dist="2"] { opacity: .45; transform: translateX(-16px) }
li[data-dist="3"] { opacity: .2; transform: translateX(-30px) }
li[aria-current] .pill { color: var(--ink); border-color: rgba(182, 255, 59, .45); background: rgba(182, 255, 59, .08); transform: scale(1.04) }
li[aria-current] .tick { background: var(--lime); width: 22px; box-shadow: 0 0 10px var(--lime) }

.knob { position: absolute; left: 182px; top: calc(50% - 30px); width: 60px; height: 60px; border-radius: 50%; display: grid; grid-template-rows: 1fr 1fr;
  background: radial-gradient(circle at 50% 35%, #2a302c, #141815 70%); box-shadow: 0 0 0 8px rgba(255, 255, 255, .025), 0 0 0 1px rgba(255, 255, 255, .08) inset, 0 10px 30px rgba(0, 0, 0, .5) }
.knob-half { all: unset; display: grid; place-items: center; color: var(--lime); cursor: pointer; transition: transform 160ms cubic-bezier(.16, 1, .3, 1), opacity 200ms ease }
.knob-half svg { width: 18px; height: 18px; filter: drop-shadow(0 0 6px rgba(182, 255, 59, .6)) }
.knob-half:first-child svg { margin-top: 8px } .knob-half:last-child svg { margin-bottom: 8px; opacity: .55 }
.knob-half:active { transform: scale(.88) }
.knob-half:disabled { opacity: .25; cursor: default }
.knob-half:focus-visible svg { filter: drop-shadow(0 0 0 2px var(--lime)) }

/* Readout */
.readout { padding: 0 40px 0 44px; display: grid; gap: 10px; min-width: 0 }
.readout p { margin: 0 }
.when { display: flex; align-items: center; gap: 10px; font-size: 14px; color: var(--dim) }
.badge { font-size: 12px; font-weight: 600; color: #0a0c0b; background: var(--lime); padding: 3px 9px; border-radius: 999px }
.amount { display: flex; align-items: baseline; gap: 12px; font-size: clamp(44px, 6vw, 76px); font-weight: 600; letter-spacing: -.035em; color: #fff; filter: drop-shadow(0 0 26px rgba(182, 255, 59, .16)) }
.cur { font-size: 18px; font-weight: 500; letter-spacing: 0; color: var(--dim) }
.caption { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; font-size: 14px; color: var(--dim) }
.delta { font-size: 12px; font-weight: 600; padding: 3px 8px; border-radius: 999px }
.delta[data-sign=up] { color: var(--lime); background: rgba(182, 255, 59, .1) }
.delta[data-sign=down] { color: #ff8a7a; background: rgba(255, 138, 122, .1) }
.split { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin: 14px 0 0; max-width: 520px }
.split div { padding: 10px 12px; border-radius: 12px; border: 1px solid var(--line); background: rgba(10, 12, 11, .45); backdrop-filter: blur(6px) }
.split dt { font-size: 11.5px; color: var(--dim) }
.split dd { margin: 4px 0 0; font-size: 16px; font-weight: 600; color: var(--ink) }
.week { font-size: 12.5px; color: var(--dim) } .week b { color: var(--ink); font-weight: 600 }

@media (max-width: 760px) {
  .stage { grid-template-columns: 1fr; grid-template-rows: 230px auto; height: auto; padding-bottom: 22px }
  .wheel { height: 230px }
  .g1 { width: 300px; height: 300px; right: -150px; top: -120px; opacity: calc(.18 + var(--share) * .35) }
  .g2 { width: 260px; height: 260px; right: -60px; bottom: -200px }
  .curve { left: 108px } .knob { left: 166px; width: 46px; height: 46px; top: calc(50% - 23px) }
  .days button { padding-left: 34px } .pill { min-width: 0; padding: 5px 8px; font-size: 11px } .tick { width: 0; margin: 0 }
  li[aria-current] .tick { width: 0 }
  li[data-dist="2"], li[data-dist="3"] { transform: translateX(-12px) }
  .readout { padding: 6px 18px 0 } .split { grid-template-columns: 1fr 1fr } .split div:last-child { display: none }
}
@media (prefers-reduced-motion: reduce) { .days, .days li, .glow i, .pill, .tick { transition: none } }
</style>
