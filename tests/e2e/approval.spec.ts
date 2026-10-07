import {test,expect,Page} from '@playwright/test';

async function login(page:Page,email:string){
 await page.goto('/login');await page.getByLabel('Email',{exact:true}).fill(email);
 await page.getByLabel('Пароль',{exact:true}).fill('Isolated-E2E-Password-2026');
 await page.getByRole('button',{name:'Войти',exact:true}).click();await expect(page.getByRole('heading',{name:'Дашборд клуба',exact:true})).toBeVisible();
}
test('club owner approves a quote and accepts completed work',async({page,browser})=>{
 test.setTimeout(150000);
 await login(page,'e2e@example.test');
 await page.goto('/tickets');await page.getByRole('button',{name:'+ Добавить'}).click();
 await page.getByRole('combobox',{name:'Клуб',exact:true}).selectOption({label:'E2E approval'});
 await page.getByLabel('Описание',{exact:true}).fill('Approval E2E '+Date.now());
 await page.getByRole('button',{name:'Сохранить',exact:true}).click();await expect(page.getByRole('heading',{name:/Заявка #/})).toBeVisible();
 const url=page.url();
 for(const status of ['accepted','working']){await page.getByRole('combobox',{name:'Следующий статус',exact:true}).selectOption(status);await page.getByRole('button',{name:'Сохранить переход'}).click();await expect(page.getByText('Статус изменён, запись добавлена в историю.',{exact:true})).toBeVisible();}
 await page.getByText('Предложить работы на согласование',{exact:true}).click();
 await page.getByLabel('Предлагаемые работы',{exact:true}).fill('Replace cable');
 await page.getByLabel('Стоимость в минимальных единицах (100 = 1)',{exact:true}).fill('12300');
 await page.getByLabel('Ожидаемый срок',{exact:true}).fill('One day');
 await page.getByRole('button',{name:'Отправить на согласование',exact:true}).click();
 await expect(page.getByText('Предложение отправлено владельцу клуба.',{exact:true})).toBeVisible();
 const context=await browser.newContext({baseURL:process.env.BASE_URL||'http://nginx'});const client=await context.newPage();
 await login(client,'approval@example.test');await client.goto(url);
 await expect(client.getByLabel('Выполненные работы',{exact:true})).toHaveCount(0);
 await client.getByRole('button',{name:'Отклонить предложение',exact:true}).click();
 await expect(client.getByText('Укажите причину отказа.',{exact:true})).toBeVisible();
 await client.getByRole('button',{name:'Согласовать работы и стоимость',exact:true}).click();
 await expect(client.getByText('Решение сохранено.',{exact:true})).toBeVisible();
 await page.reload();
 for(const label of ['Выполненные работы','Результат проверки','Причина','Решение'])await page.getByLabel(label,{exact:true}).fill('Completed and tested');
 await page.getByRole('combobox',{name:'Следующий статус',exact:true}).selectOption('resolved');await page.getByRole('button',{name:'Сохранить переход'}).click();
 await expect(page.getByRole('heading',{name:'Подтверждение результата',exact:true})).toBeVisible();
 await expect(page.locator('option[value="closed"]')).toHaveCount(0);
 await client.reload();await client.setViewportSize({width:390,height:844});
 await expect(client.getByRole('button',{name:'Принять результат и закрыть',exact:true})).toBeVisible();
 expect(await client.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBeTruthy();
 await client.screenshot({path:'test-results/approval-mobile.png',fullPage:true});
 await client.getByRole('button',{name:'Принять результат и закрыть',exact:true}).click();
 await expect(client.getByText('Решена → Закрыта',{exact:true})).toBeVisible();
 await client.goto('/reports');
 await expect(client.locator(`.report-table a[href="${new URL(url).pathname}"]`)).toBeVisible();
 await expect(client.getByText('Расходы не внесены',{exact:true}).first()).toBeVisible();
 await client.getByRole('combobox',{name:'Клуб отчёта',exact:true}).selectOption({label:'E2E approval'});
 await client.getByRole('button',{name:'Применить фильтры',exact:true}).click();
 await expect(client).toHaveURL(/club_id=/);
 const csvUrl=await client.getByRole('link',{name:'Скачать CSV',exact:true}).getAttribute('href');
 expect(csvUrl).toContain('club_id=');
 const csv=await client.request.get(csvUrl!);expect(csv.ok()).toBeTruthy();expect(await csv.text()).toContain('Approval E2E');
 expect(await client.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBeTruthy();
 await client.screenshot({path:'test-results/reports-mobile.png',fullPage:true});
 await client.setViewportSize({width:1440,height:1000});await client.screenshot({path:'test-results/reports-desktop.png',fullPage:true});
 await client.getByRole('button',{name:'Трудозатраты и расходы',exact:true}).click();
 await expect(client.getByText('По выбранным условиям записей нет.',{exact:true})).toBeVisible();
 await context.close();
 // Two logins in this scenario share the real 5/minute IP limit with the other specs.
 // Keep the production limiter enabled and let its window expire before continuing.
 await page.waitForTimeout(61000);
});
