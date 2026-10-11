import { computed, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

export type KnowledgeItem = {
 id: string; title: string; original_title?: string; category: string; kind: 'guide' | 'source';
 summary: string; tags: string[]; search: string; updated_at: string; source_date?: string;
 status: 'ok' | 'broken' | 'unverified'; url?: string; minutes?: number; local_guide_id?: string | null;
 sections?: { title: string; paragraphs?: string[]; steps?: string[]; note?: string;
  commands?: { label: string; code: string }[];
  diagnostics?: { symptom: string; check: string; next: string }[] }[];
 checklist?: string[]; sources?: { title: string; url: string }[];
};
export type KnowledgeCategory = { id: string; title: string; description: string; count: number };
export function useKnowledgeNotes() {
 const page = usePage<{ auth: { user: { id: number } } }>();
 const key = `cclub:knowledge:v1:${page.props.auth.user.id}`;
 const saved = ref<string[]>([]);
 const checks = ref<Record<string, number[]>>({});
 const storageAvailable = ref(true);
 const ready = ref(false);
 onMounted(() => {
  try {
   const data = JSON.parse(localStorage.getItem(key) || '{}');
   saved.value = Array.isArray(data.saved) ? data.saved.filter((x: unknown) => typeof x === 'string') : [];
   if (data.checks && typeof data.checks === 'object' && !Array.isArray(data.checks)) {
    checks.value = Object.fromEntries(Object.entries(data.checks).filter(([, value]) => Array.isArray(value)).map(([id, value]) => [id, (value as unknown[]).filter((n): n is number => Number.isInteger(n) && Number(n) >= 0)]));
   }
  } catch { storageAvailable.value = false; }
  ready.value = true;
 });
 watch([saved, checks], () => {
  if (!ready.value) return;
  try { localStorage.setItem(key, JSON.stringify({ saved: saved.value, checks: checks.value })); }
  catch { storageAvailable.value = false; }
 }, { deep: true });
 const toggleSaved = (id: string) => { saved.value = saved.value.includes(id) ? saved.value.filter(x => x !== id) : [...saved.value, id]; };
 const toggleCheck = (id: string, index: number) => {
  const list = checks.value[id] || [];
  checks.value[id] = list.includes(index) ? list.filter(x => x !== index) : [...list, index];
 };
 return { saved, checks, storageAvailable, toggleSaved, toggleCheck, savedCount: computed(() => saved.value.length) };
}
export const normalizeSearch = (value: string) => value.toLocaleLowerCase('ru').replaceAll('ё', 'е').replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
