import {test,expect} from '@playwright/test';
test('cleaning checklist and before/after multiple photo uploads',async({page})=>{
  await page.goto('/login');await page.getByLabel('Email',{exact:true}).fill('e2e@example.test');await page.getByLabel('Пароль',{exact:true}).fill('Isolated-E2E-Password-2026');await page.getByRole('button',{name:'Войти',exact:true}).click();await expect(page.getByRole('heading',{name:'Дашборд клуба',exact:true})).toBeVisible();
  await page.goto('/tickets');await page.getByRole('button',{name:'+ Добавить'}).click();await page.getByRole('button',{name:'Заявка на чистку ПК',exact:true}).click();await page.getByRole('button',{name:'Сохранить',exact:true}).click();await expect(page.getByRole('heading',{name:'Чистка ПК',exact:true})).toBeVisible();
  await expect(page.getByLabel('Причина',{exact:true})).toHaveCount(0);
  const base64='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4XcAAAAASUVORK5CYII=';
  const before=page.locator('.cleaning-photos>section').nth(0),after=page.locator('.cleaning-photos>section').nth(1);
  await before.locator('input[type=file]').setInputFiles(['before-1.png','before-2.png'].map(name=>({name,mimeType:'image/png',buffer:Buffer.from(base64,'base64')})));
  await before.getByRole('button',{name:'Прикрепить',exact:true}).click();await expect(before.locator('.attachment-gallery img')).toHaveCount(2);await expect(before.getByRole('button',{name:'Прикрепить',exact:true})).toBeDisabled();
  const transfer=await page.evaluateHandle(b64=>{const dt=new DataTransfer();dt.items.add(new File([Uint8Array.from(atob(b64),c=>c.charCodeAt(0))],'after.png',{type:'image/png'}));return dt;},base64);
  await after.locator('.dropzone').dispatchEvent('drop',{dataTransfer:transfer});await after.getByRole('button',{name:'Прикрепить',exact:true}).click();await expect(after.locator('.attachment-gallery img')).toHaveCount(1);
  await page.getByLabel('Корпус и фильтры очищены',{exact:true}).check();await page.getByLabel('Результат проверки',{exact:true}).fill('ПК включается, вентиляторы работают');
  for(const status of ['accepted','working','resolved']){await page.getByRole('combobox',{name:'Следующий статус',exact:true}).selectOption(status);await page.getByRole('button',{name:'Сохранить переход',exact:true}).click();await expect(page.getByText('Статус изменён, запись добавлена в историю.',{exact:true})).toBeVisible();}
  await page.reload();await expect(before.locator('.attachment-gallery img')).toHaveCount(2);await expect(after.locator('.attachment-gallery img')).toHaveCount(1);await expect(page.getByLabel('Корпус и фильтры очищены',{exact:true})).toBeChecked();
  await before.getByRole('button',{name:'Увеличить before-1.png',exact:true}).click();
  const viewer=page.getByRole('dialog',{name:'Просмотр фотографий'});
  await expect(viewer).toBeVisible();await expect(viewer).toContainText('Фото 1 из 2');
  await viewer.getByRole('button',{name:'Следующее фото',exact:true}).click();await expect(viewer).toContainText('Фото 2 из 2');
  await viewer.getByRole('button',{name:'Увеличить масштаб',exact:true}).click();await expect(viewer.getByRole('button',{name:'150%',exact:true})).toBeVisible();
  const area=viewer.locator('.photo-viewer');const bounds=await area.boundingBox();
  await page.mouse.move(bounds!.x+bounds!.width*.7,bounds!.y+bounds!.height*.5);await page.mouse.down();await page.mouse.move(bounds!.x+bounds!.width*.3,bounds!.y+bounds!.height*.3,{steps:5});await page.mouse.up();
  await expect.poll(()=>area.evaluate(el=>el.scrollLeft)).toBeGreaterThan(0);
  await expect(area).not.toHaveClass(/dragging/);
  await page.keyboard.press('ArrowLeft');await expect(viewer).toContainText('Фото 1 из 2');
  await page.keyboard.press('Escape');await expect(viewer).not.toBeVisible();await expect(before.getByRole('button',{name:'Увеличить before-1.png',exact:true})).toBeFocused();
  await page.setViewportSize({width:390,height:844});await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBeTruthy();await page.screenshot({path:'test-results/cleaning-mobile.png',fullPage:true});
});
