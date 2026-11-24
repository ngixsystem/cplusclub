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
 await expect(course.getByText(/37 видеоуроков/)).toBeVisible();
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
