<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps<{ certificate: {
 number: string; name: string; track: string; kind: string; chapters: number; started_at: string; completed_at: string; duration_seconds: number;
 exam: { points: number; max_points: number; percent: number } | null; source: string | null;
} }>();
const c = props.certificate;
const print = () => window.print();
const date = (iso: string) => new Date(iso).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Asia/Tashkent' });
function duration(seconds: number) {
 const d = Math.floor(seconds / 86400), h = Math.floor(seconds % 86400 / 3600), m = Math.max(1, Math.floor(seconds % 3600 / 60));
 return d ? `${d} дн. ${h} ч` : h ? `${h} ч ${m} мин` : `${m} мин`;
}
</script>

<template>
 <Head :title="'Сертификат · ' + c.track" />
 <div class="cert-tools"><Link href="/training">← К обучению</Link><button type="button" @click="print">Печать или сохранить в PDF</button></div>
 <main class="cert">
  <div class="cert-inner">
   <p class="cert-brand">C+CLub · сервисная команда</p>
   <h1>Сертификат</h1>
   <p class="cert-lead">подтверждает, что</p>
   <p class="cert-name">{{c.name}}</p>
   <p class="cert-lead">успешно прошёл(ла) программу обучения</p>
   <p class="cert-track">«{{c.track}}»</p>
   <dl>
    <div><dt>{{ c.kind === 'video' ? 'Уроков' : 'Глав' }}</dt><dd>{{c.chapters}}</dd></div>
    <div v-if="c.exam"><dt>Итоговый экзамен</dt><dd>{{c.exam.points}} из {{c.exam.max_points}} баллов</dd></div>
    <div><dt>Срок прохождения</dt><dd>{{duration(c.duration_seconds)}}</dd></div>
    <div><dt>Период</dt><dd>{{date(c.started_at)}} — {{date(c.completed_at)}}</dd></div>
   </dl>
   <p v-if="c.source" class="cert-source">Видеоматериалы: {{c.source}}. Сертификат выдан C+CLub по результатам внутреннего обучения и не является сертификатом Cisco.</p>
   <p class="cert-number">№ {{c.number}} · выдан {{date(c.completed_at)}}</p>
  </div>
 </main>
</template>

<style scoped>
.cert-tools{display:flex;justify-content:space-between;align-items:center;gap:12px;max-width:900px;margin:24px auto 12px;padding:0 16px;font-size:13px}
.cert{max-width:900px;margin:0 auto 40px;padding:0 16px}
.cert-inner{aspect-ratio:1.414/1;background:#fff;color:#1b1f24;border:10px double #1d4f91;padding:48px 56px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:6px;font-family:Georgia,'Times New Roman',serif}
.cert-brand{font:600 12px/1.4 system-ui,sans-serif;letter-spacing:.2em;text-transform:uppercase;color:#1d4f91;margin:0 0 6px}
.cert h1{font-size:44px;letter-spacing:.08em;text-transform:uppercase;margin:0 0 8px;color:#1d4f91}
.cert-lead{margin:0;font-size:15px;color:#555}
.cert-name{font-size:30px;font-weight:700;margin:6px 0 10px;border-bottom:1px solid #c9d3e0;padding:0 24px 6px}
.cert-track{font-size:22px;margin:6px 0 18px}
.cert dl{display:flex;gap:28px;flex-wrap:wrap;justify-content:center;margin:0 0 18px;font-family:system-ui,sans-serif}
.cert dt{font-size:11px;color:#6b7480;text-transform:uppercase;letter-spacing:.06em}
.cert dd{margin:4px 0 0;font-size:15px;font-weight:600}
.cert-source{font:11px/1.5 system-ui,sans-serif;color:#6b7480;max-width:560px;margin:0 0 8px}
.cert-number{font:12px system-ui,sans-serif;color:#6b7480;margin:0}
@media (max-width:640px){.cert-inner{aspect-ratio:auto;padding:28px 18px}.cert h1{font-size:30px}.cert-name{font-size:22px}}
@media print{.cert-tools{display:none}.cert{margin:0;padding:0;max-width:none}.cert-inner{border-width:8px;aspect-ratio:auto;height:100vh;box-sizing:border-box}@page{size:A4 landscape;margin:12mm}}
</style>
