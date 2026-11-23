import { test, expect } from '@playwright/test';
import { overflowing } from './layout';

test('ticket practice demo: message → ticket → diagnostics → fix → close with a score', async ({ page }) => {
 test.setTimeout(120000);
 const errors: string[] = [];
 page.on('pageerror', e => errors.push(e.message));
 await page.goto('/login');
 await page.getByLabel('Email', { exact: true }).fill('e2e@example.test');
 await page.getByLabel('Пароль', { exact: true }).fill('Isolated-E2E-Password-2026');
 await page.getByRole('button', { name: 'Войти', exact: true }).click();
 await expect(page.getByRole('heading', { name: 'Дашборд клуба', exact: true })).toBeVisible();

 await page.goto('/training');
 await expect(page.getByRole('heading', { name: 'Практикум: работа с заявками в C+CLub' })).toBeVisible();
 await expect(page.getByText(/Оценка испытания — 100 баллов/)).toBeVisible();
 const row = page.locator('.tr-scenarios li', { hasText: 'Один ПК не загружается' });
 await row.getByRole('button', { name: 'Демо', exact: true }).click();
 await expect(page.getByRole('heading', { name: /Практикум: Один ПК не загружается/ })).toBeVisible();
 await expect(page.getByText('Подсказка', { exact: true })).toBeVisible();
 await expect(page.getByText(/PXE-E51: No DHCP/).first()).toBeVisible();

 // Ticket intake with the same fields as the real form.
 await page.getByLabel('Оборудование клуба', { exact: true }).selectOption({ label: 'PC-07 · место 07 · Standard' });
 await page.getByLabel('Категория', { exact: true }).fill('Диагностика');
 await page.getByLabel('Описание', { exact: true }).fill('Место 7, PC-07: PXE-E51, не получает IP по DHCP. Перезагрузка дважды не помогла, остальные ПК работают.');
 await page.getByLabel('Приоритет', { exact: true }).selectOption('normal');
 await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
 await expect(page.getByText('Новая', { exact: true })).toBeVisible();
 await page.getByRole('button', { name: 'Принять', exact: true }).click();
 await page.getByRole('button', { name: 'В работу', exact: true }).click();
 await expect(page.getByRole('heading', { name: 'Действия на объекте' })).toBeVisible();

 for (const action of ['Попросить фото экрана и записать точный текст ошибки', 'Сравнить с соседним ПК-08 на этом же коммутаторе',
  'Проверить индикаторы порта, кабель и включённую сетевую загрузку в BIOS', 'Заменить патч-корд PC-07 и проложить его вне ножек кресла',
  'Холодная загрузка PC-07', 'Проверить игровой диск и запуск популярной игры']) {
  await page.getByRole('button', { name: action, exact: true }).click();
  await expect(page.getByRole('button', { name: '✓ ' + action, exact: true })).toBeVisible();
 }
 await expect(page.getByText(/Патч-корд под столом пережат/)).toBeVisible();

 await page.getByLabel('Минуты', { exact: true }).fill('25');
 await page.getByLabel('Выполненные действия', { exact: true }).fill('Диагностика линка, замена патч-корда, проверка загрузки и игры');
 await page.getByRole('button', { name: 'Добавить запись', exact: true }).click();
 await expect(page.getByText('Трудозатраты: 25 мин', { exact: true })).toBeVisible();

 await page.getByRole('button', { name: 'Решена…', exact: true }).click();
 await page.getByLabel('Выполненные работы', { exact: true }).fill('Заменён повреждённый патч-корд PC-07, кабель проложен вне ножек кресла.');
 await page.getByLabel('Проверка', { exact: true }).fill('Холодная загрузка: IP 192.168.10.57, рабочий стол за 38 с; Dota 2 запускается.');
 await page.getByLabel('Основная причина', { exact: true }).selectOption({ label: 'Повреждён патч-корд — нет линка на сетевой карте клиента' });
 await page.getByLabel('Причина (подробно)', { exact: true }).fill('Патч-корд пережат ножкой кресла, коннектор надломлен — нет линка.');
 await page.getByLabel('Решение', { exact: true }).fill('Замена патч-корда и прокладка кабеля вне зоны кресел.');
 await page.getByRole('button', { name: 'Сохранить переход', exact: true }).click();
 await expect(page.getByText('Решена', { exact: true })).toBeVisible();
 await page.getByRole('button', { name: 'Закрыть', exact: true }).click();

 await expect(page.getByText('Демо завершено — результат для ориентира')).toBeVisible();
 await expect(page.locator('.tp-score b')).toHaveText('100');

 await page.setViewportSize({ width: 390, height: 844 });
 await expect.poll(() => overflowing(page)).toEqual([]);
 await page.screenshot({ path: 'test-results/practice-mobile.png', fullPage: true });
 await page.goto('/training');
 await expect.poll(() => overflowing(page)).toEqual([]);
 await page.setViewportSize({ width: 1440, height: 1000 });
 expect(errors).toEqual([]);
});
