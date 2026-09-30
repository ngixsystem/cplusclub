import {test,expect} from '@playwright/test';

test('create with six characters, edit and confirm user deletion',async({page})=>{
 await page.goto('/login');await page.getByLabel('Email',{exact:true}).fill('e2e@example.test');await page.getByLabel('Пароль',{exact:true}).fill('Isolated-E2E-Password-2026');await page.getByRole('button',{name:'Войти',exact:true}).click();
 await expect(page.getByRole('heading',{name:'Дашборд клуба'})).toBeVisible();await page.goto('/users');
 const email='user-'+Date.now()+'@example.test';
 await page.getByLabel('Имя',{exact:true}).fill('Created client');await page.getByLabel('Email',{exact:true}).fill(email);await page.getByLabel('Пароль',{exact:true}).fill('abc123');await page.getByRole('combobox',{name:'Роль',exact:true}).selectOption('representative');await page.getByRole('button',{name:'Создать пользователя'}).click();
 const card=page.getByRole('region',{name:'Пользователь '+email,exact:true});
 await expect(card).toBeVisible();await card.getByRole('button',{name:'Редактировать'}).click();await card.getByLabel('Имя',{exact:true}).fill('Updated client');await card.getByLabel('Новый пароль',{exact:false}).fill('new123');await card.getByRole('button',{name:'Сохранить изменения'}).click();
 await expect(card.getByRole('heading')).toContainText('Updated client');await card.getByRole('button',{name:'Удалить',exact:true}).click();await card.getByRole('button',{name:'Отмена',exact:true}).click();await expect(card).toBeVisible();await card.getByRole('button',{name:'Удалить',exact:true}).click();await card.getByRole('button',{name:'Подтвердить удаление'}).click();await expect(card).toHaveCount(0);
});
