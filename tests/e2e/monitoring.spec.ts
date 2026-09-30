import {test,expect} from '@playwright/test';
test('PC icons show thermal states, refresh without reload and fit mobile',async({page})=>{
 let polls=0;
 await page.route('**/monitoring/clubs/*',route=>{polls++;return route.fulfill({json:{pcs:[
  {id:1,name:'PC-01',zone:'Standard',online:true,cpu_temp:45,gpu_temp:48,severity:'normal'},
  {id:2,name:'PC-02',zone:'VIP',online:true,cpu_temp:50,gpu_temp:79,severity:'warning'},
  {id:3,name:'PC-03',zone:'VIP',online:true,cpu_temp:polls>1?85:80,gpu_temp:60,severity:'critical'},
  {id:4,name:'PC-04',online:false,cpu_temp:null,gpu_temp:null,severity:'offline'},
  {id:5,name:'PC-05',online:true,cpu_temp:null,gpu_temp:null,severity:'unknown'},
 ],error:null,connection_updated_at:new Date().toISOString(),updated_at:new Date().toISOString(),template:{warning:50,critical:79,hold_seconds:0}}});});
 await page.goto('/login');await page.getByLabel('Email',{exact:true}).fill('e2e@example.test');await page.getByLabel('Пароль',{exact:true}).fill('Isolated-E2E-Password-2026');await page.getByRole('button',{name:'Войти',exact:true}).click();await expect(page.getByRole('heading',{name:'Дашборд клуба'})).toBeVisible();await page.goto('/monitoring');
 await expect(page.locator('.pc-tile.normal')).toHaveCount(1);await expect(page.locator('.pc-tile.warning')).toHaveCount(1);await expect(page.locator('.pc-tile.critical')).toHaveCount(1);await expect(page.locator('.pc-tile.offline')).toContainText('Нет данных');
 await expect.poll(()=>polls,{timeout:22000}).toBeGreaterThan(1);await expect(page.locator('.pc-tile.critical')).toContainText('85 °C');
 await page.getByRole('combobox',{name:'Показать',exact:true}).selectOption('critical');await expect(page.locator('.pc-tile')).toHaveCount(1);await page.getByRole('combobox',{name:'Показать',exact:true}).selectOption('all');
 await page.setViewportSize({width:390,height:844});expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBeTruthy();await page.screenshot({path:'test-results/monitoring-mobile.png',fullPage:true});
 await page.setViewportSize({width:1440,height:1000});await page.evaluate(()=>window.scrollTo(0,0));await page.screenshot({path:'test-results/monitoring-desktop.png',fullPage:true});
});
