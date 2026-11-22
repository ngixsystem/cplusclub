<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import Shell from '../Layouts/Shell.vue';
import '../../css/knowledge.css';

type Chapter = { id: string; title: string; minutes: number | null; checked: number; total: number; done: boolean; completed_at: string | null };
type Track = { id: string; title: string; summary: string; chapters: Chapter[]; done: number; total: number; next: { id: string; title: string } | null };
type Member = { id: number; name: string; role: string; tracks: { id: string; title: string; done: number; total: number }[]; last_activity: string | null };
const props = defineProps<{ tracks: Track[]; team: Member[] | null }>();

const roles: Record<string, string> = { owner: 'Владелец', lead: 'Технический руководитель', specialist: 'Выездной специалист', representative: 'Представитель клуба' };
const percent = (done: number, total: number) => total ? Math.round(done / total * 100) : 0;
const open = ref<string | null>(props.tracks.find(t => t.done < t.total)?.id ?? props.tracks[0]?.id ?? null);
const roleFilter = ref('');
const members = computed(() => (props.team ?? []).filter(m => !roleFilter.value || m.role === roleFilter.value));
const teamRoles = computed(() => [...new Set((props.team ?? []).map(m => m.role))]);
const date = (value: string | null) => value ? new Date(value).toLocaleDateString('ru-RU', { timeZone: 'Asia/Tashkent' }) : '—';
const minutes = (track: Track) => track.chapters.filter(c => !c.done).reduce((sum, c) => sum + (c.minutes || 0), 0);
</script>

<template>
 <Head title="Обучение" />
 <Shell>
  <div class="kb-heading"><div><h1>Обучение</h1><p>Программы по вашей роли собраны из глав базы знаний. Глава засчитывается, когда отмечены все пункты «Проверки результата» в её конце.</p></div><Link href="/knowledge" class="kb-source-button">База знаний →</Link></div>

  <section aria-label="Мои программы" class="tr-tracks">
   <p v-if="!tracks.length" class="panel">Для вашей роли программ пока нет.</p>
   <article v-for="track in tracks" :key="track.id" class="tr-track">
    <button class="tr-track-head" :aria-expanded="open===track.id" @click="open = open===track.id ? null : track.id">
     <span><span class="kb-label">{{track.done===track.total ? 'Пройдено ✓' : `${track.done} из ${track.total} глав · осталось ~${minutes(track)} мин`}}</span><b>{{track.title}}</b><small>{{track.summary}}</small></span>
     <span class="tr-percent">{{percent(track.done, track.total)}}%</span>
    </button>
    <span class="kb-bar" aria-hidden="true"><i :style="{width: percent(track.done, track.total)+'%'}"></i></span>
    <div v-if="open===track.id" class="tr-chapters">
     <Link v-if="track.next" :href="'/knowledge/'+track.next.id" class="kb-source-button">{{track.done ? 'Продолжить' : 'Начать'}}: {{track.next.title}} →</Link>
     <ol>
      <li v-for="chapter in track.chapters" :key="chapter.id" :class="{done: chapter.done}">
       <Link :href="'/knowledge/'+chapter.id">{{chapter.title}}</Link>
       <span>{{chapter.done ? 'Изучено '+date(chapter.completed_at) : chapter.checked ? `${chapter.checked} / ${chapter.total}` : (chapter.minutes ? chapter.minutes+' мин' : '')}}</span>
      </li>
     </ol>
    </div>
   </article>
  </section>

  <section v-if="team" class="tr-team" aria-label="Обучение команды">
   <div class="tr-team-head"><h2>Обучение команды</h2><label>Роль<select v-model="roleFilter"><option value="">Все</option><option v-for="r in teamRoles" :key="r" :value="r">{{roles[r] || r}}</option></select></label></div>
   <p v-if="!members.length" class="panel">Нет активных сотрудников с этой ролью.</p>
   <div v-else class="kb-diagnostics" role="region" aria-label="Прогресс сотрудников" tabindex="0">
    <table>
     <thead><tr><th scope="col">Сотрудник</th><th scope="col">Программы</th><th scope="col">Последняя отметка</th></tr></thead>
     <tbody>
      <tr v-for="member in members" :key="member.id">
       <th scope="row">{{member.name}}<small>{{roles[member.role] || member.role}}</small></th>
       <td><div v-for="track in member.tracks" :key="track.id" class="tr-member-track"><span>{{track.title}} · {{track.done}}/{{track.total}}</span><span class="kb-bar" aria-hidden="true"><i :style="{width: percent(track.done, track.total)+'%'}"></i></span></div><span v-if="!member.tracks.length">—</span></td>
       <td>{{date(member.last_activity)}}</td>
      </tr>
     </tbody>
    </table>
   </div>
  </section>
 </Shell>
</template>

<style scoped>
.tr-tracks{display:grid;gap:14px;margin-bottom:40px}
.tr-track{background:var(--surface);border:1px solid var(--border);border-radius:7px;overflow:hidden}
.tr-track-head{width:100%;display:flex;justify-content:space-between;align-items:center;gap:20px;text-align:left;background:transparent;border:0;border-radius:0;padding:20px 22px;color:var(--text);font-weight:400}
.tr-track-head b{display:block;font-size:17px;margin-bottom:6px}
.tr-track-head small{display:block;color:var(--muted);font-size:12px;line-height:1.6;max-width:760px}
.tr-track-head .kb-label{margin-bottom:8px}
.tr-percent{font-size:24px;font-weight:600;font-variant-numeric:tabular-nums;flex-shrink:0}
.tr-track>.kb-bar{display:block;border-radius:0}
.tr-chapters{padding:20px 22px;border-top:1px solid var(--border)}
.tr-chapters ol{margin:12px 0 0;padding-left:22px}
.tr-chapters li{display:flex;justify-content:space-between;gap:16px;padding:9px 0;border-bottom:1px solid var(--border);font-size:13px;line-height:1.5}
.tr-chapters li::marker{color:var(--muted)}
.tr-chapters li>span{color:var(--muted);font-size:11px;white-space:nowrap}
.tr-chapters li.done>a{color:var(--muted)}
.tr-chapters li.done>span{color:var(--accent-text)}
.tr-team-head{display:flex;justify-content:space-between;align-items:end;gap:16px;margin-bottom:14px}
.tr-team-head h2{margin:0;font-size:19px}
.tr-team th small{display:block;color:var(--muted);font-weight:400;font-size:11px}
.tr-member-track{display:grid;gap:5px;margin-bottom:10px;max-width:420px}
.tr-member-track:last-child{margin-bottom:0}
@media(max-width:760px){.tr-track-head{padding:16px}.tr-chapters{padding:16px}.tr-chapters li{flex-direction:column;gap:2px}.tr-percent{font-size:19px}}
</style>
