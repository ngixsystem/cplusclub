import {test,expect} from '@playwright/test';
test('supplied logo loads on login in both themes and mobile',async({page})=>{
  await page.goto('/login');
  const logo=page.getByRole('img',{name:'C+club',exact:true});
  await expect.poll(()=>logo.evaluate((img:HTMLImageElement)=>img.complete&&img.naturalWidth>0)).toBeTruthy();
  await page.setViewportSize({width:390,height:844});
  for(const theme of ['light','dark']) {
    await page.evaluate(t=>document.documentElement.dataset.theme=t,theme);
    await expect(logo).toBeVisible();
    await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBeTruthy();
    await page.screenshot({path:`test-results/logo-${theme}.png`,fullPage:true});
  }
});
