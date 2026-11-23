import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { overflowing } from './layout';

type Q = { id: string; q: string; options: string[]; answer: number };
const bank: Record<string, Q[]> = JSON.parse(readFileSync('resources/knowledge/quizzes.json', 'utf8')).chapters;

test('intro, start, chapter test with review and retake, mobile fit', async ({ page }) => {
 const errors: string[] = [];
 page.on('pageerror', e => errors.push(e.message));
 await page.goto('/login');
 await page.getByLabel('Email', { exact: true }).fill('approval@example.test');
 await page.getByLabel('Пароль', { exact: true }).fill('Isolated-E2E-Password-2026');
 await page.getByRole('button', { name: 'Войти', exact: true }).click();
 await page.waitForURL(url => !url.pathname.startsWith('/login'));

 // A club representative sees the intro of the club admin program before starting.
 await page.goto('/training');
 await expect(page.getByRole('heading', { name: 'Как устроено обучение' })).toBeVisible();
 await expect(page.getByText(/Время обучения считается с момента/)).toBeVisible();
 await page.getByRole('button', { name: 'Начать обучение', exact: true }).click();
 await expect(page.getByText('Обучение началось. Удачи!', { exact: true })).toBeVisible();
 await expect(page.getByText(/0 из 6 глав · идёт/)).toBeVisible();

 // Chapter test: wrong answers show the key and explanations; a retake with right answers passes.
 await page.goto('/knowledge/license');
 const quiz = page.locator('#quiz');
 await quiz.getByRole('button', { name: 'Начать тест', exact: true }).click();
 const questions = bank['license'];
 for (const q of questions) {
  const group = quiz.getByRole('group', { name: q.q });
  const wrong = q.options.find((_, i) => i !== q.answer)!;
  await group.getByLabel(wrong, { exact: true }).check();
 }
 await quiz.getByRole('button', { name: 'Проверить ответы', exact: true }).click();
 await expect(quiz.getByText('0 из 5 · 0%', { exact: true })).toBeVisible();
 await expect(quiz.getByText('✓ верно').first()).toBeVisible();
 await expect(quiz.getByText(/Неверно\./).first()).toBeVisible();

 await quiz.getByRole('button', { name: 'Пройти ещё раз', exact: true }).click();
 for (const q of questions) await quiz.getByRole('group', { name: q.q }).getByLabel(q.options[q.answer], { exact: true }).check();
 await quiz.getByRole('button', { name: 'Проверить ответы', exact: true }).click();
 await expect(quiz.getByText('5 из 5 · 100%', { exact: true })).toBeVisible();
 await expect(quiz.getByText(/Тест сдан\./)).toBeVisible();
 await expect(quiz.getByText('Сдан · лучший 100%', { exact: true })).toBeVisible();

 // Checklist plus test complete the chapter.
 for (const item of ['Проверены продукт и число ПК', 'Назначен ответственный за продление', 'Новый срок отображается в кабинете', 'Подтверждение сохранено']) {
  await page.getByLabel(item, { exact: true }).check();
  await expect(page.getByLabel(item, { exact: true })).toBeEnabled();
 }
 await expect(page.getByText(/Глава изучена ✓/)).toBeVisible();
 await page.goto('/training');
 await expect(page.getByText(/1 из 6 глав · идёт/)).toBeVisible();

 await page.setViewportSize({ width: 390, height: 844 });
 await expect.poll(() => overflowing(page)).toEqual([]);
 await page.screenshot({ path: 'test-results/training-mobile.png', fullPage: true });
 await page.goto('/knowledge/license#quiz');
 await expect.poll(() => overflowing(page)).toEqual([]);
 await page.setViewportSize({ width: 1440, height: 1000 });
 expect(errors).toEqual([]);
});
