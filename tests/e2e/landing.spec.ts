import {test,expect} from '@playwright/test';

test('classic landing keeps calculator and login available', async ({page}) => {
  const errors:string[]=[];
  page.on('pageerror', e=>errors.push(e.message));
  await page.goto('http://landing');
  await expect(page.getByRole('heading',{level:1})).toContainText('Ваш клуб.');
  await expect(page.getByRole('link',{name:'Войти',exact:true})).toHaveAttribute('href','https://admin.cplusclub.tech/login');
  await page.locator('#pc-count').fill('100');
  await page.locator('#pc-count').blur();
  await expect(page.locator('#standard-price')).toHaveText('$500');
  await page.getByRole('button',{name:'Рассчитать обслуживание',exact:true}).click();
  await expect(page.getByRole('dialog')).toContainText('$500 / месяц');
  await page.keyboard.press('Escape');
  await expect(page.getByRole('dialog')).not.toBeVisible();
  await page.setViewportSize({width:390,height:844});
  await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBeTruthy();
  expect(errors).toEqual([]);
});
