import {test,expect} from '@playwright/test';
test('landing calculator and public request reach the admin inbox',async({page})=>{
  const errors:string[]=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('http://landing');
  await expect(page.getByRole('heading',{level:1})).toContainText('Клуб играет.');
  await page.getByRole('button',{name:'Пауза анимации',exact:true}).click();
  await page.getByRole('button',{name:'03 Результат',exact:true}).click();await expect(page.locator('#demo-title')).toHaveText('Результат перед глазами');
  await page.setViewportSize({width:1440,height:1000});await page.screenshot({path:'test-results/landing-desktop.png'});
  await page.locator('#pc-count').fill('100');await page.locator('#pc-count').blur();await expect(page.locator('#standard-price')).toHaveText('$500');
  await page.getByRole('button',{name:'Рассчитать обслуживание',exact:true}).click();await expect(page.getByRole('dialog')).toContainText('$500 / месяц');await page.getByRole('link',{name:'Обсудить этот тариф →'}).click();
  const form=page.locator('#lead-form');await expect(form.locator('[name=pc_count]')).toHaveValue('100');
  const club='Landing E2E '+Date.now();await form.getByLabel('Ваше имя',{exact:true}).fill('Test Owner');await form.getByLabel('Название клуба',{exact:true}).fill(club);await form.getByLabel('Телефон, Telegram или email',{exact:true}).fill('landing@example.test');await form.locator('[name=consent]').check();
  await form.getByRole('button',{name:'Отправить запрос →'}).click();await expect(page.locator('#lead-status')).toContainText('Запрос сохранён');
  await page.goto('http://nginx/login');await page.getByLabel('Email',{exact:true}).fill('e2e@example.test');await page.getByLabel('Пароль',{exact:true}).fill('Isolated-E2E-Password-2026');await page.getByRole('button',{name:'Войти',exact:true}).click();await expect(page.getByRole('heading',{name:'Дашборд клуба',exact:true})).toBeVisible();
  await page.goto('http://nginx/connection-requests');const lead=page.locator('article').filter({hasText:club});await expect(lead).toContainText('landing@example.test');await lead.getByRole('combobox').selectOption('contacted');await expect(page.getByText('Статус запроса обновлён.',{exact:true})).toBeVisible();await page.reload();await expect(page.locator('article').filter({hasText:club}).getByRole('combobox')).toHaveValue('contacted');
  expect(errors).toEqual([]);
});
test('landing mobile, reduced motion and failed submission retain input',async({page})=>{
  await page.emulateMedia({reducedMotion:'reduce'});await page.setViewportSize({width:390,height:844});await page.goto('http://landing');await expect(page.locator('#motion-toggle')).toBeDisabled();
  await page.getByRole('button',{name:'Меню',exact:true}).click();await page.getByRole('navigation',{name:'Основная навигация'}).getByRole('link',{name:'Подключение',exact:true}).click();await expect(page.getByRole('button',{name:'Меню',exact:true})).toHaveAttribute('aria-expanded','false');
  await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBeTruthy();
  const form=page.locator('#lead-form');await form.getByLabel('Ваше имя',{exact:true}).fill('Test');await form.getByLabel('Название клуба',{exact:true}).fill('Retry club');await form.getByLabel('Телефон, Telegram или email',{exact:true}).fill('retry@example.test');await form.locator('[name=consent]').check();
  await page.route('**/api/connection-requests',route=>route.fulfill({status:503,contentType:'application/json',body:'{}'}));await form.getByRole('button',{name:'Отправить запрос →'}).click();await expect(page.locator('#lead-status')).toContainText('Не удалось сохранить');await expect(form.getByLabel('Название клуба',{exact:true})).toHaveValue('Retry club');
  await form.scrollIntoViewIfNeeded();await page.screenshot({path:'test-results/landing-form-mobile.png'});await page.evaluate(()=>scrollTo(0,0));await page.screenshot({path:'test-results/landing-mobile.png'});
});
test('landing content stays available without JavaScript',async({browser})=>{
  const context=await browser.newContext({javaScriptEnabled:false});const page=await context.newPage();await page.goto('http://landing');await expect(page.getByRole('heading',{level:1})).toBeVisible();await expect(page.getByRole('link',{name:'Войти',exact:true})).toHaveAttribute('href','https://admin.cplusclub.tech/login');await expect(page.locator('#lead-form noscript')).toBeVisible();expect(await page.locator('#lead-form noscript').evaluate(el=>el.innerHTML)).toContain('включите JavaScript');await context.close();
});
