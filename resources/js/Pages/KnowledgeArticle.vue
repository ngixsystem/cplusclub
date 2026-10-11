<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import Shell from '../Layouts/Shell.vue';
import { type KnowledgeItem, type KnowledgeCategory, useKnowledgeNotes } from '../Components/knowledge';
import '../../css/knowledge.css';
type ChapterLink = Pick<KnowledgeItem,'id'|'title'|'kind'>;
const props = defineProps<{ article: KnowledgeItem; category: KnowledgeCategory; related: ChapterLink[];
 reference: { title: string; url: string } | null; previous: ChapterLink | null; next: ChapterLink | null;
 chapter: { number: number; total: number } | null }>();
const { saved, checks, toggleSaved, toggleCheck, storageAvailable } = useKnowledgeNotes();
const progressKey = computed(() => `${props.article.id}:${props.article.updated_at}`);
const completed = computed(() => (checks.value[progressKey.value] || []).filter(i => i < (props.article.checklist?.length || 0)));
const date = (value: string) => new Date(value + 'T12:00:00').toLocaleDateString('ru-RU');
</script>
<template>
 <Head :title="article.title+' — База знаний'"/>
 <Shell>
  <Link href="/knowledge" class="kb-back">← База знаний</Link>
  <div class="kb-heading kb-article-heading"><div><div class="kb-label">{{category.title}}</div><h1>{{article.title}}</h1><p>{{article.summary}}</p></div><button class="secondary" :aria-pressed="saved.includes(article.id)" @click="toggleSaved(article.id)">{{saved.includes(article.id)?'★ Сохранено':'☆ В избранное'}}</button></div>
  <div class="kb-article-meta"><span v-if="chapter">Глава {{chapter.number}} из {{chapter.total}}</span><span>{{article.kind==='guide'?'Редакция C+Club':'Каталог CCBoot Wiki · оригинал на английском'}}</span><span>{{article.kind==='guide'?'Обновлено':'Ссылка проверена'}} {{date(article.updated_at)}}</span><span v-if="article.minutes">{{article.minutes}} мин чтения</span></div>
  <p v-if="reference" class="kb-fine kb-reference">По теме «{{reference.title}}» открыта самостоятельная глава C+Club. Первоисточник указан в конце.</p>
  <p v-if="!storageAvailable" class="kb-notice" role="status">Сохранение в браузере недоступно. Отметки не сохранятся после закрытия страницы.</p>
  <div class="kb-reading-layout">
   <article class="kb-reading">
    <template v-if="article.kind==='guide'">
     <section v-for="(section,index) in article.sections" :id="'section-'+index" :key="index" class="kb-section">
      <h2>{{section.title}}</h2>
      <p v-for="(paragraph,i) in section.paragraphs" :key="i">{{paragraph}}</p>
      <ol v-if="section.steps"><li v-for="(step,i) in section.steps" :key="i">{{step}}</li></ol>
      <div v-for="(command,i) in section.commands" :key="'command-'+i" class="kb-command"><span>{{command.label}}</span><pre tabindex="0" :aria-label="command.label"><code>{{command.code}}</code></pre></div>
      <div v-if="section.diagnostics?.length" class="kb-diagnostics" role="region" :aria-label="section.title+' — диагностика'" tabindex="0"><table><thead><tr><th scope="col">Симптом</th><th scope="col">Что проверить</th><th scope="col">Следующий шаг</th></tr></thead><tbody><tr v-for="(row,i) in section.diagnostics" :key="i"><th scope="row">{{row.symptom}}</th><td>{{row.check}}</td><td>{{row.next}}</td></tr></tbody></table></div>
      <div v-if="section.note" class="kb-note">{{section.note}}</div>
     </section>
     <section v-if="article.checklist?.length" id="checklist" class="kb-checklist"><div class="kb-checklist-head"><h2>Проверка результата</h2><span>{{completed.length}} / {{article.checklist.length}}</span></div><label v-for="(item,index) in article.checklist" :key="index"><input type="checkbox" :checked="completed.includes(index)" @change="toggleCheck(progressKey,index)"/><span>{{item}}</span></label><small>Личные отметки в этом браузере. Они не изменяют оборудование и заявки.</small></section>
    </template>
    <section v-else class="kb-source-page"><span class="kb-label">Первоисточник</span><h2>{{article.original_title || article.title}}</h2><p>{{category.description}}</p><p>Подробные действия, скриншоты и файлы находятся в официальном руководстве CCBoot. Перед применением сравните версию продукта и конфигурацию оборудования с вашей системой.</p><p v-if="article.status!=='ok'" class="kb-notice">{{article.status==='broken'?'Адрес вернул 404/410 при проверке. Используйте главную wiki или связанные инструкции ниже.':'Автоматическая проверка не подтвердила доступность страницы. Проверьте источник вручную.'}}</p><a v-if="article.url" :href="article.url" target="_blank" rel="noopener noreferrer" class="kb-source-button">Открыть материал CCBoot ↗</a><a v-if="article.status!=='ok'" href="https://www.ccboot.com/wikis.htm" target="_blank" rel="noopener noreferrer">Главная wiki ↗</a><p v-if="article.source_date" class="kb-fine">Дата в оригинале: {{date(article.source_date)}}. Это дата публикации источника, а не подтверждение совместимости.</p></section>
    <details v-if="article.sources?.length" id="sources" class="kb-sources"><summary>Источники и дополнительные материалы</summary><a v-if="reference && !article.sources.some(s=>s.url===reference?.url)" :href="reference.url" target="_blank" rel="noopener noreferrer">{{reference.title}} ↗</a><a v-for="source in article.sources" :key="source.url" :href="source.url" target="_blank" rel="noopener noreferrer">{{source.title}} ↗</a><p>Самостоятельная инструкция C+Club. Ссылки позволяют сверить особенности конкретной версии с документацией производителя.</p></details>
    <nav v-if="previous || next" class="kb-chapter-nav" aria-label="Главы руководства"><Link v-if="previous" :href="'/knowledge/'+previous.id"><small>← Предыдущая глава</small><span>{{previous.title}}</span></Link><Link v-if="next" :href="'/knowledge/'+next.id"><small>Следующая глава →</small><span>{{next.title}}</span></Link></nav>
   </article>
   <div class="kb-article-side"><nav v-if="article.sections?.length" aria-label="В этой инструкции"><span class="kb-label">В этой инструкции</span><a v-for="(section,index) in article.sections" :key="index" :href="'#section-'+index">{{section.title}}</a><a v-if="article.checklist?.length" href="#checklist">Проверка результата</a><a href="#sources">Источники</a></nav><section v-if="related.length" class="kb-related"><span class="kb-label">По этой теме</span><Link v-for="item in related" :key="item.id" :href="'/knowledge/'+item.id"><small>{{item.kind==='guide'?'Инструкция':'CCBoot Wiki'}}</small>{{item.title}}</Link></section><div class="kb-note">Если проблема повторяется, приложите к заявке номер ПК, время сбоя, код ошибки и ссылку на эту инструкцию.<Link href="/tickets">Перейти к заявкам →</Link></div></div>
  </div>
 </Shell>
</template>
