<script setup lang="ts">
import { computed, reactive, ref, unref, watch } from 'vue';
import { Head, Link, useRemember } from '@inertiajs/vue3';
import Shell from '../Layouts/Shell.vue';
import { type KnowledgeItem, type KnowledgeCategory, useKnowledgeNotes, normalizeSearch } from '../Components/knowledge';
import '../../css/knowledge.css';
const props = defineProps<{ articles: KnowledgeItem[]; categories: KnowledgeCategory[]; audit: { checked_at: string; pages: number; links: number; broken: number; unverified: number; guides: number } }>();
const filters = reactive(unref(useRemember({ q: '', category: '', kind: '', saved: false }, 'knowledge-filters')));
const limit = ref(12);
const { saved, toggleSaved, storageAvailable } = useKnowledgeNotes();
const tokens = computed(() => normalizeSearch(filters.q).split(' ').filter(Boolean));
const indexed = computed(() => props.articles.map(article => ({ article, text: normalizeSearch(`${article.title} ${article.original_title || ''} ${article.summary} ${article.tags.join(' ')} ${article.search}`) })));
const results = computed(() => indexed.value.filter(({ article: a, text }) =>
 (!filters.category || a.category === filters.category) && (!filters.kind || a.kind === filters.kind)
 && (!filters.saved || saved.value.includes(a.id)) && tokens.value.every(t => text.includes(t))).map(x => x.article));
watch(filters, () => { limit.value = 12; }, { deep: true });
const reset = () => { filters.q = ''; filters.category = ''; filters.kind = ''; filters.saved = false; };
const categoryNames = computed(() => Object.fromEntries(props.categories.map(c => [c.id, c.title])));
const date = (value: string) => new Date(value + 'T12:00:00').toLocaleDateString('ru-RU');
</script>
<template>
 <Head title="База знаний" />
 <Shell>
  <div class="kb-heading"><div><h1>База знаний</h1><p>Инструкции для работы с CCBoot и обслуживания клубов.</p></div><span class="kb-edition">C+Club / Руководство</span></div>
  <section class="kb-search-panel" aria-label="Поиск по базе знаний">
   <label for="knowledge-search">Что нужно настроить или исправить?</label>
   <div class="kb-search-field"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg><input id="knowledge-search" v-model="filters.q" type="search" maxlength="200" placeholder="Например: PXE, синий экран, образ, FACEIT…" autocomplete="off"/><button v-if="filters.q" class="secondary" @click="filters.q=''">Очистить</button></div>
   <div class="kb-quick"><span>Часто ищут</span><button v-for="query in ['Первый запуск','DHCP','Super Client','FACEIT','Writeback']" :key="query" @click="filters.q=query;filters.category='';filters.kind='';filters.saved=false">{{query}}</button></div>
  </section>
  <div class="kb-layout">
   <nav class="kb-categories" aria-label="Разделы базы знаний">
    <span class="kb-label">Разделы</span>
    <button :class="{active:!filters.category}" :aria-pressed="!filters.category" @click="filters.category=''">Все материалы <span>{{articles.length}}</span></button>
    <button v-for="category in categories" :key="category.id" :class="{active:filters.category===category.id}" :aria-pressed="filters.category===category.id" @click="filters.category=category.id">{{category.title}}<span>{{category.count}}</span></button>
   </nav>
   <section class="kb-results" aria-label="Материалы">
    <div class="kb-toolbar"><div class="kb-tabs" aria-label="Тип материала"><button v-for="tab in [{id:'',title:'Все'},{id:'guide',title:'Инструкции'},{id:'source',title:'Каталог CCBoot'}]" :key="tab.id" :class="{active:filters.kind===tab.id}" :aria-pressed="filters.kind===tab.id" @click="filters.kind=tab.id">{{tab.title}}</button></div><button class="secondary kb-saved-filter" :aria-pressed="filters.saved" @click="filters.saved=!filters.saved">{{filters.saved?'★':'☆'}} Избранное · {{saved.length}}</button></div>
    <div class="kb-result-count" aria-live="polite">{{results.length}} материалов<span v-if="filters.category"> · {{categoryNames[filters.category]}}</span></div>
    <p v-if="!storageAvailable" role="status" class="kb-notice">Браузер не разрешает сохранение. Избранное доступно до закрытия страницы.</p>
    <div v-if="results.length" class="kb-grid">
     <article v-for="article in results.slice(0,limit)" :key="article.id" class="kb-card">
      <div class="kb-card-top"><span :class="['kb-kind',{'is-guide':article.kind==='guide'}]">{{article.kind==='guide'?'Инструкция C+Club':'CCBoot Wiki · EN'}}</span><button class="kb-bookmark" :aria-label="saved.includes(article.id)?'Убрать из избранного: '+article.title:'В избранное: '+article.title" :aria-pressed="saved.includes(article.id)" @click="toggleSaved(article.id)"><svg viewBox="0 0 24 24" :fill="saved.includes(article.id)?'currentColor':'none'" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M6 3h12v18l-6-4-6 4Z"/></svg></button></div>
      <Link :href="'/knowledge/'+article.id" class="kb-card-link"><h2>{{article.title}}</h2><p>{{article.summary}}</p></Link>
      <div class="kb-card-bottom"><span>{{categoryNames[article.category]}}</span><span v-if="article.status!=='ok'" class="kb-warning">{{article.status==='broken'?'Источник: 404':'Требует проверки'}}</span><span v-else-if="article.minutes">{{article.minutes}} мин</span><span v-else aria-hidden="true">↗</span></div>
     </article>
    </div>
    <div v-else class="kb-empty"><h2>{{filters.saved?'В избранном пока нет подходящих материалов':'Ничего не найдено'}}</h2><p>Попробуйте название ошибки на английском или выберите другой раздел.</p><button class="secondary" @click="reset">Сбросить фильтры</button></div>
    <button v-if="results.length>limit" class="secondary kb-more" @click="limit+=12">Показать ещё · осталось {{results.length-limit}}</button>
   </section>
  </div>
  <details class="kb-audit"><summary>Проверка источников · {{date(audit.checked_at)}}</summary><p>Проверено {{audit.pages}} страниц wiki и {{audit.links}} уникальных HTTP(S)-ссылок. Ответ 404/410: {{audit.broken}}. Доступность не подтверждена: {{audit.unverified}} (блокировка, ошибка сети или содержимого). Файлы программ не запускались; проверка ссылки не гарантирует актуальность инструкции.</p><p>{{audit.guides}} русских инструкций C+Club дополнены каталогом первоисточников. Оригинальные материалы открываются на сайте CCBoot. Отметки и избранное хранятся в этом браузере отдельно для вашего аккаунта.</p><a href="https://www.ccboot.com/wikis.htm" target="_blank" rel="noopener noreferrer">Открыть официальную wiki ↗</a></details>
 </Shell>
</template>
