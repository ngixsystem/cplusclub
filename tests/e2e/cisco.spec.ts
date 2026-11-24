import { test, expect } from '@playwright/test';
import { overflowing } from './layout';

test('cisco course: intro, start, lesson page with player area, locked test, certificate unavailable', async ({ page }) => {
 const errors: string[] = [];
 page.on('pageerror', e => errors.push(e.message));
 await page.goto('/login');
 await page.getByLabel('Email', { exact: true }).fill('e2e@example.test');
 await page.getByLabel('Пароль', { exact: true }).fill('Isolated-E2E-Password-2026');
 await page.getByRole('button', { name: 'Войти', exact: true }).click();
 await expect(page.getByRole('heading', { name: 'Дашборд клуба', exact: true })).toBeVisible();

 await page.goto('/training');
 const course = page.locator('.tr-track', { hasText: 'Курс Cisco CCNA (видео)' });
 await expect(course.getByText(/Смотрите видеоурок/)).toBeVisible();
 await expect(course.getByText(/Новая программа · 37 видеоуроков/)).toBeVisible();
 await course.getByRole('button', { name: 'Начать обучение', exact: true }).click();
 await expect(page.getByText('Обучение началось. Удачи!', { exact: true })).toBeVisible();

 await page.goto('/training/lesson/ccna-2-3');
 await expect(page.getByRole('heading', { name: '2.3 Структура команд', exact: true })).toBeVisible();
 await expect(page.getByText(/урок 12 из 37/i)).toBeVisible();
 await expect(page.locator('.tl-player iframe')).toHaveAttribute('src', /youtube\.com\/embed\/videoseries\?list=PLHpWwR6fSBN5gTzVFoNbKfYi7ziZfxjwf/);
 await expect(page.getByText('Просмотрено 0 %')).toBeVisible();
 await expect(page.getByText(/Тест откроется, когда вы досмотрите урок/)).toBeVisible();
 await expect(page.getByRole('link', { name: /2\.4 Базовая настройка устройств →/ })).toBeVisible();

 const certificate = await page.request.get('/training/certificate/cisco-ccna');
 expect(certificate.status()).toBe(404);

 await page.setViewportSize({ width: 390, height: 844 });
 await expect.poll(() => overflowing(page)).toEqual([]);
 await page.screenshot({ path: 'test-results/cisco-lesson-mobile.png', fullPage: true });
 await page.setViewportSize({ width: 1440, height: 1000 });
 expect(errors).toEqual([]);
});

test('cisco practice: subnet trainer, IOS trainer and Packet Tracer labs', async ({ page }) => {
 const errors: string[] = [];
 page.on('pageerror', e => errors.push(e.message));
 await page.goto('/login');
 await page.getByLabel('Email', { exact: true }).fill('e2e@example.test');
 await page.getByLabel('Пароль', { exact: true }).fill('Isolated-E2E-Password-2026');
 await page.getByRole('button', { name: 'Войти', exact: true }).click();
 await expect(page.getByRole('heading', { name: 'Дашборд клуба', exact: true })).toBeVisible();

 await page.goto('/training');
 const labs = page.locator('.tr-labs');
 await expect(labs.getByRole('heading', { name: 'Практика Cisco', exact: true })).toBeVisible();
 await labs.getByRole('link', { name: /Тренажёр подсетей/ }).click();
 await expect(page.getByRole('heading', { name: 'Тренажёр подсетей', exact: true })).toBeVisible();
 await page.getByRole('button', { name: 'Начать тренировку', exact: true }).click();
 const fields = page.locator('.lab-field input');
 await expect(fields).toHaveCount(10);
 await fields.first().fill('10.0.0.0');
 await page.getByRole('button', { name: 'Проверить ответы', exact: true }).click();
 await expect(page.getByRole('heading', { name: /Не зачтено: \d+ из 10/ })).toBeVisible();
 await expect(page.getByText(/правильно:/).first()).toBeVisible();

 await page.goto('/training/ios');
 await expect(page.getByRole('heading', { name: 'Тренажёр команд IOS', exact: true })).toBeVisible();
 await page.getByRole('button', { name: /Базовая настройка коммутатора/ }).click();
 await page.getByLabel('Команды', { exact: true }).fill('SW1(config)#hostname SW1');
 await page.getByRole('button', { name: 'Проверить', exact: true }).click();
 await expect(page.getByRole('heading', { name: /Есть замечания · \d+ %/ })).toBeVisible();

 await page.goto('/training/labs');
 await expect(page.getByRole('heading', { name: 'Лабораторные Packet Tracer', exact: true })).toBeVisible();
 await expect(page.getByRole('heading', { name: 'Что сделать', exact: true })).toBeVisible();
 await page.getByLabel('Описание: что сделали и как проверяли').fill('Собрал схему, пинги внутри VLAN проходят, между VLAN нет.');
 await page.locator('input[type=file]').setInputFiles({ name: 'club.pkt', mimeType: 'application/octet-stream', buffer: Buffer.from('pkt-e2e') });
 await page.getByRole('button', { name: 'Отправить на проверку', exact: true }).click();
 await expect(page.getByText('Работа отправлена на проверку руководителю.', { exact: true })).toBeVisible();
 await expect(page.locator('.lab-sub').getByText('На проверке', { exact: true })).toBeVisible();

 await page.setViewportSize({ width: 390, height: 844 });
 await page.goto('/training/ios');
 await expect.poll(() => overflowing(page)).toEqual([]);
 expect(errors).toEqual([]);
});
