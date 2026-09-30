import { test, expect } from '@playwright/test';

test('dashboard refresh, shift detail, stale data and mobile layout', async ({ page }) => {
  const shift = {id:'-12',operator:'Test operator',start:'2026-09-30 07:00:00',end:null,total:100,cash:80,card:15,qr:5};
  let polls = 0;
  await page.route('**/clubs/*/dashboard', async route => {
    polls++;
    await route.fulfill({json:{connected:true,shifts:[{...shift,total:polls>1?150:100}],computers:[{name:'01',online:true,busy:false}],total_pcs:1,online_pcs:1,updated_at:'2026-09-30T07:00:00Z',stale:polls>2,error:polls>2?'Источник недоступен':null,period_start:'2026-09-24',period_end:'2026-09-30'}});
  });
  await page.route('**/clubs/*/dashboard/shifts/*', route => route.fulfill({json:{chart:{labels:['07','08'],values:[40,60]},detail:{cash_sales:80,cash_refund:-5},stale:false}}));
  await page.goto('/login');
  await page.getByLabel('Email',{exact:true}).fill('e2e@example.test');
  await page.getByLabel('Пароль',{exact:true}).fill('Isolated-E2E-Password-2026');
  await page.getByRole('button',{name:'Войти',exact:true}).click();
  await expect(page.getByRole('heading',{name:'Дашборд клуба'})).toBeVisible();
  await expect(page.getByRole('heading',{name:'Test operator',exact:true})).toBeVisible();
  const navigations: string[]=[];
  page.on('framenavigated', frame=>{if(frame===page.mainFrame())navigations.push(frame.url());});
  await expect.poll(()=>polls,{timeout:22000}).toBeGreaterThan(1);
  await expect(page.getByText('150 UZS',{exact:true}).first()).toBeVisible();
  expect(navigations).toHaveLength(0);
  await page.getByRole('button',{name:'Подробнее',exact:true}).click();
  await expect(page.getByText('Возвраты наличными',{exact:true})).toBeVisible();
  await expect(page.getByRole('alert')).toContainText('Источник недоступен');
  await page.setViewportSize({width:390,height:844});
  await expect(page.getByRole('heading',{name:'Дашборд клуба'})).toBeVisible();
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBeTruthy();
  await page.evaluate(()=>window.scrollTo(0,0));
  await page.screenshot({path:'test-results/dashboard-mobile.png',fullPage:true});
  await page.setViewportSize({width:1440,height:1000});
  await page.evaluate(()=>window.scrollTo(0,0));
  await page.evaluate(()=>document.documentElement.setAttribute('data-theme','dark'));
  await expect(page.locator('.metric-grid article').first()).toHaveCSS('background-color','rgb(45, 50, 65)');
  await page.screenshot({path:'test-results/dashboard-desktop.png',fullPage:true});
  await page.evaluate(()=>document.documentElement.setAttribute('data-theme','light'));
  await expect(page.locator('.metric-grid article').first()).toHaveCSS('background-color','rgb(255, 255, 255)');
  await page.screenshot({path:'test-results/dashboard-light.png',fullPage:true});
});
