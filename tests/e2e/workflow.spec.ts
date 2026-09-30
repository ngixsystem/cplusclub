import {test,expect} from '@playwright/test';
test('club → PC → ticket → assignment → work → close → history',async({page})=>{
  await page.goto('/login');
  await page.getByLabel('Email',{exact:true}).fill('e2e@example.test');
  await page.getByLabel('Пароль',{exact:true}).fill('Isolated-E2E-Password-2026');
  await page.getByRole('button',{name:'Войти',exact:true}).click();
  await expect(page.getByRole('heading',{name:'Дашборд клуба',exact:true})).toBeVisible();
  await page.goto('/clubs');await page.getByRole('button',{name:'+ Добавить'}).click();
  const name='E2E '+Date.now();await page.getByLabel('Название',{exact:true}).fill(name);await page.getByLabel('Адрес',{exact:true}).fill('Test Tashkent');await page.getByRole('button',{name:'Сохранить',exact:true}).click();await expect(page.getByText('Клуб добавлен.',{exact:true})).toBeVisible();
  await page.goto('/equipment');await page.getByRole('button',{name:'+ Добавить'}).click();await page.getByRole('combobox',{name:'Клуб',exact:true}).selectOption({label:name});await page.getByLabel('Название',{exact:true}).fill('PC-01');await page.getByLabel('Номер места',{exact:true}).fill('01');await page.getByRole('button',{name:'Сохранить',exact:true}).click();await expect(page.getByText('Оборудование добавлено.',{exact:true})).toBeVisible();
  await page.goto('/tickets');await page.getByRole('button',{name:'+ Добавить'}).click();await page.getByRole('combobox',{name:'Клуб',exact:true}).selectOption({label:name});await page.getByLabel('Описание',{exact:true}).fill('E2E: PC does not boot');await page.getByRole('button',{name:'Сохранить',exact:true}).click();await expect(page.getByRole('heading',{name:/Заявка #/})).toBeVisible();
  await page.locator('input[type="file"]').setInputFiles({name:'ticket-photo.png',mimeType:'image/png',buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4XcAAAAASUVORK5CYII=','base64')});
  await page.getByRole('button',{name:'Прикрепить',exact:true}).click();
  const photo=page.getByRole('img',{name:'ticket-photo.png',exact:true});
  await expect(photo).toBeVisible();
  await expect.poll(()=>photo.evaluate((img:HTMLImageElement)=>img.complete&&img.naturalWidth>0)).toBeTruthy();
  await page.getByRole('combobox',{name:'Исполнитель',exact:true}).selectOption({label:'E2E Owner'});await page.getByRole('button',{name:'Назначить',exact:true}).click();await expect(page.getByText('Исполнитель назначен.',{exact:true})).toBeVisible();
  for(const status of ['accepted','working']){await page.getByRole('combobox',{name:'Следующий статус',exact:true}).selectOption(status);await page.getByRole('button',{name:'Сохранить переход'}).click();await expect(page.getByText('Статус изменён, запись добавлена в историю.',{exact:true})).toBeVisible();}
  await page.getByLabel('Выполненные работы',{exact:true}).fill('Replaced network cable');await page.getByLabel('Результат проверки',{exact:true}).fill('Boot and CS2 passed');await page.getByLabel('Причина',{exact:true}).fill('Broken cable');await page.getByLabel('Решение',{exact:true}).fill('Cable replaced');await page.getByRole('combobox',{name:'Следующий статус',exact:true}).selectOption('resolved');await page.getByRole('button',{name:'Сохранить переход'}).click();
  await expect(page.getByRole('combobox',{name:'Следующий статус',exact:true}).locator('option[value="closed"]')).toBeAttached();await page.getByRole('combobox',{name:'Следующий статус',exact:true}).selectOption('closed');await page.getByRole('button',{name:'Сохранить переход'}).click();await expect(page.getByText('Решена → Закрыта',{exact:true})).toBeVisible();
  await page.setViewportSize({width:390,height:844});await expect(page.getByRole('heading',{name:'История',exact:true})).toBeVisible();
});
