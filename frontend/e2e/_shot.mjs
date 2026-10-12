import { chromium } from '@playwright/test';

const OUT = process.env.PASADA_OUT;
const browser = await chromium.launch();
const page = await (await browser.newContext({ viewport: { width: 390, height: 780 } })).newPage();
await page.goto('http://demo.localhost:5183/login');
await page.locator('input[type=email]').fill('admin@plataforma.com');
await page.locator('input[type=password]').fill(process.env.PASADA_PASSWORD);
await page.locator('form button[type=submit]').first().click();
await page.waitForURL('**/dashboard');
const result = {};
for (const [name, route] of [['ventas', '/billing'], ['usuarios', '/users'], ['matriculas', '/enrollments'], ['auditoria', '/audit'], ['certificados', '/certificates']]) {
  await page.goto('http://demo.localhost:5183' + route);
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(700);
  const table = page.locator('main table.stack-table').last();
  await table.scrollIntoViewIfNeeded();
  await page.mouse.wheel(0, 120);
  await page.waitForTimeout(300);
  if (name === 'auditoria') await page.locator('main tbody tr').first().click();
  await page.waitForTimeout(300);
  await page.screenshot({ path: `${OUT}/movil-${name}.png` });
  result[name] = await page.evaluate(() => ({
    desborde: document.documentElement.scrollWidth - innerWidth,
    tablaMasAncha: [...document.querySelectorAll('main table')].some((t) => t.scrollWidth > t.parentElement.clientWidth + 2),
  }));
}
// La campana
await page.setViewportSize({ width: 1366, height: 760 });
await page.goto('http://demo.localhost:5183/dashboard');
await page.waitForTimeout(2500);
await page.locator('button[aria-label^="Avisos"]').click();
await page.waitForTimeout(400);
await page.screenshot({ path: `${OUT}/campana.png`, clip: { x: 940, y: 300, width: 426, height: 460 } });
result.campana = await page.locator('#lista-de-avisos').innerText();
console.log(JSON.stringify(result, null, 1));
await browser.close();
