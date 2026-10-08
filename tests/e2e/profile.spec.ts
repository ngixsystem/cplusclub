import {test,expect} from '@playwright/test';
test('profile avatar and password change',async({page})=>{
  await page.goto('/login');await page.getByLabel('Email',{exact:true}).fill('e2e@example.test');await page.getByLabel('Пароль',{exact:true}).fill('Isolated-E2E-Password-2026');await page.getByRole('button',{name:'Войти',exact:true}).click();await expect(page.getByRole('heading',{name:'Дашборд клуба',exact:true})).toBeVisible();
  await page.goto('/profile');await expect(page.getByRole('heading',{name:'Настройки профиля',exact:true})).toBeVisible();
  await page.getByLabel('Новое изображение').setInputFiles({name:'avatar.png',mimeType:'image/png',buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4XcAAAAASUVORK5CYII=','base64')});
  await page.getByRole('button',{name:'Сохранить аватарку'}).click();await expect(page.getByText('Аватарка обновлена.',{exact:true})).toBeVisible();
  await expect.poll(()=>page.getByAltText('Ваша аватарка').evaluate((el:HTMLImageElement)=>el.complete&&el.naturalWidth>0)).toBeTruthy();
  await page.setViewportSize({width:390,height:844});await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBeTruthy();await page.screenshot({path:'test-results/profile-mobile.png',fullPage:true});
  await page.getByRole('button',{name:'Удалить аватарку'}).click();await expect(page.getByText('Аватарка удалена.',{exact:true})).toBeVisible();
  await page.getByLabel('Текущий пароль',{exact:true}).fill('Isolated-E2E-Password-2026');await page.getByLabel('Новый пароль',{exact:true}).fill('Profile-test-2026');await page.getByLabel('Подтверждение пароля',{exact:true}).fill('Profile-test-2026');await page.getByRole('button',{name:'Сменить пароль',exact:true}).click();await expect(page).toHaveURL(/\/login$/);
  await page.getByLabel('Email',{exact:true}).fill('e2e@example.test');await page.getByLabel('Пароль',{exact:true}).fill('Profile-test-2026');await page.getByRole('button',{name:'Войти',exact:true}).click();await expect(page.getByRole('heading',{name:'Дашборд клуба',exact:true})).toBeVisible();
  await page.goto('/profile');await page.getByLabel('Текущий пароль',{exact:true}).fill('Profile-test-2026');await page.getByLabel('Новый пароль',{exact:true}).fill('Isolated-E2E-Password-2026');await page.getByLabel('Подтверждение пароля',{exact:true}).fill('Isolated-E2E-Password-2026');await page.getByRole('button',{name:'Сменить пароль',exact:true}).click();await expect(page).toHaveURL(/\/login$/);
});
