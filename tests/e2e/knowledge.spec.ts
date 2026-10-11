import { test, expect } from '@playwright/test';

test.beforeEach(async ({ page }) => {
 await page.goto('/login');
 await page.getByLabel('Email', { exact: true }).fill('e2e@example.test');
 await page.getByLabel('Пароль', { exact: true }).fill('Isolated-E2E-Password-2026');
 await page.getByRole('button', { name: 'Войти', exact: true }).click();
 await expect(page).not.toHaveURL(/\/login$/);
 await page.goto('/knowledge');
 await expect(page.getByRole('heading', { name: 'База знаний', exact: true })).toBeVisible();
});

test('search, catalogue filters, empty state and source details', async ({ page }) => {
 const search = page.getByRole('searchbox');
 await search.fill('синий экран');
 await expect(page.getByRole('link', { name: /Синий экран: собрать/ })).toBeVisible();
 await search.fill('PXE-E32');
 await expect(page.getByRole('link', { name: /PXE-E32 \/ TFTP/ })).toBeVisible();
 await search.fill('no-such-article-unique-123');
 await expect(page.getByRole('heading', { name: 'Ничего не найдено' })).toBeVisible();
 await page.getByRole('button', { name: 'Сбросить фильтры' }).click();
 await page.getByRole('button', { name: 'Каталог CCBoot', exact: true }).click();
 await search.fill('TFTP');
 await page.getByRole('link', { name: /TFTP Open Timeout Error/ }).click();
 await expect(page.getByRole('link', { name: 'Открыть материал CCBoot ↗' })).toHaveAttribute('href', 'https://www.ccboot.com/wikis-tftp-open-timeout-error.htm');
 await expect(page.getByRole('link', { name: 'Открыть материал CCBoot ↗' })).toHaveAttribute('rel', 'noopener noreferrer');
});

test('bookmarks and checklist persist; mobile and both themes fit', async ({ page }) => {
 const errors: string[] = [];
 page.on('pageerror', e => errors.push(e.message));
 await page.getByRole('searchbox').fill('Super Client');
 await page.getByRole('button', { name: 'Инструкции', exact: true }).click();
 await page.getByRole('link', { name: /Super Client: обновить образ/ }).click();
 await page.getByRole('button', { name: '☆ В избранное', exact: true }).click();
 await page.getByLabel('Super Client отключён', { exact: true }).check();
 await page.reload();
 await expect(page.getByRole('button', { name: '★ Сохранено', exact: true })).toBeVisible();
 await expect(page.getByLabel('Super Client отключён', { exact: true })).toBeChecked();
 await page.setViewportSize({ width: 390, height: 844 });
 await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
 await page.evaluate(() => window.scrollTo(0, 0));
 await page.screenshot({ path: 'test-results/knowledge-article-mobile.png', fullPage: true });
 await page.getByRole('link', { name: '← База знаний', exact: true }).click();
 await page.getByRole('button', { name: /Избранное · 1/ }).click();
 await expect(page.locator('.kb-card')).toHaveCount(1);
 await page.getByRole('button', { name: /Избранное · 1/ }).click();
 await page.getByRole('searchbox').fill('');
 await page.getByRole('button', { name: 'Все', exact: true }).click();
 for (const theme of ['dark', 'light']) {
  await page.evaluate(t => { document.documentElement.dataset.theme = t; }, theme);
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
  await page.screenshot({ path: `test-results/knowledge-${theme}-mobile.png`, fullPage: true });
 }
 await page.setViewportSize({ width: 1440, height: 1000 });
 await page.evaluate(() => { document.documentElement.dataset.theme = 'dark'; });
 await page.screenshot({ path: 'test-results/knowledge-dark-desktop.png', fullPage: true });
 expect(errors).toEqual([]);
});
