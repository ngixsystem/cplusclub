import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { overflowing } from './layout';

const scenario = JSON.parse(readFileSync('resources/knowledge/scenarios.json', 'utf8')).find((s: { id: string }) => s.id === 'pc-no-ip');

test('practice ticket: intro, real ticket workflow, diagnostics, simulated client and score', async ({ page }) => {
 test.setTimeout(150000);
 const errors: string[] = [];
 page.on('pageerror', e => errors.push(e.message));
 await page.goto('/login');
 await page.getByLabel('Email', { exact: true }).fill('e2e@example.test');
 await page.getByLabel('Пароль', { exact: true }).fill('Isolated-E2E-Password-2026');
 await page.getByRole('button', { name: 'Войти', exact: true }).click();
 await expect(page.getByRole('heading', { name: 'Дашборд клуба', exact: true })).toBeVisible();

 await page.goto('/training');
 const program = page.locator('.tr-track').filter({ has: page.getByRole('heading', { name: 'Инженер: первая неделя' }) });
 await expect(program.getByText(/Проходите практикум в C\+CLub/)).toBeVisible();
 await program.getByRole('button', { name: 'Начать обучение', exact: true }).click();
 await expect(page.getByText('Обучение началось. Удачи!', { exact: true })).toBeVisible();
 await page.locator('.tr-scenario').filter({ hasText: scenario.title }).getByRole('button', { name: 'Получить заявку' }).click();

 // The incident arrives as a real ticket in the training club.
 await expect(page).toHaveURL(/\/tickets\/\d+$/);
 const panel = page.locator('.tp');
 await expect(panel.getByRole('heading', { name: scenario.title })).toBeVisible();
 await expect(page.getByText(scenario.ticket.description)).toBeVisible();
 await expect(panel.getByRole('button', { name: scenario.checks[0].label })).toBeDisabled();

 const save = page.getByRole('button', { name: 'Сохранить переход', exact: true });
 await page.getByLabel('Следующий статус', { exact: true }).selectOption({ label: 'Принята' });
 await save.click();
 await expect(page.locator('.badge').filter({ hasText: 'Принята' })).toBeVisible();
 await page.getByLabel('Следующий статус', { exact: true }).selectOption({ label: 'В работе' });
 await save.click();
 await expect(page.locator('.badge').filter({ hasText: 'В работе' })).toBeVisible();

 for (const check of scenario.checks.filter((c: { key?: boolean }) => c.key)) {
  await panel.getByRole('button', { name: check.label }).click();
  await expect(panel.locator('.tp-findings').getByText(check.finding)).toBeVisible();
 }
 await page.getByLabel('Выполненные действия', { exact: true }).fill('Диагностика PXE, проверка VLAN, исправление порта');
 await page.getByRole('button', { name: 'Добавить запись', exact: true }).click();
 await expect(page.getByText('Время и стоимость записаны.', { exact: true })).toBeVisible();

 await page.getByLabel('Выполненные работы', { exact: true }).fill(scenario.model.work);
 await page.getByLabel('Результат проверки', { exact: true }).fill(scenario.model.verification);
 await page.getByLabel('Причина', { exact: true }).fill(scenario.model.cause);
 await page.getByLabel('Решение', { exact: true }).fill(scenario.model.solution);
 await page.getByLabel('Следующий статус', { exact: true }).selectOption({ label: 'Решена' });
 await save.click();

 // The simulated client accepts the work, the run is graded with a breakdown and the model answer.
 await expect(page.locator('.badge').filter({ hasText: 'Закрыта' })).toBeVisible();
 await expect(panel.locator('.tp-score')).toContainText('100 из 100 · зачёт');
 await expect(panel.getByRole('heading', { name: 'Эталонное решение' })).toBeVisible();
 await expect(page.getByText(/Клиент подтвердил результат/)).toBeVisible();
 await page.setViewportSize({ width: 390, height: 844 });
 await expect.poll(() => overflowing(page)).toEqual([]);
 await page.screenshot({ path: 'test-results/practice-ticket-mobile.png', fullPage: true });
 await page.setViewportSize({ width: 1440, height: 1000 });

 await page.goto('/training');
 await expect(page.locator('.tr-scenario').filter({ hasText: scenario.title })).toContainText('100 / 100');
 // The practice ticket is the trainee's own: the club list does not show the training club.
 await page.goto('/clubs');
 await expect(page.getByText('Учебный клуб C+CLub')).toHaveCount(0);
 expect(errors).toEqual([]);
});
