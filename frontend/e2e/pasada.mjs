// Pasada por todo el sistema: entra con cada rol, visita cada pantalla y sus pestañas, y anota
// errores de consola, peticiones fallidas, desbordes de ancho y textos sospechosos. Deja capturas.
// Uso (desde frontend/): PASADA_PASSWORD=<clave demo> PASADA_OUT=<carpeta> node e2e/pasada.mjs [rol…]
// Para verla en pantalla: agrega PASADA_VER=1
import { chromium } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const ACADEMY = process.env.PASADA_ACADEMY ?? 'http://demo.localhost:5183';
const CENTRAL = process.env.PASADA_CENTRAL ?? 'http://localhost:5183';
const PASSWORD = process.env.PASADA_PASSWORD ?? '';
const OUT = process.env.PASADA_OUT ?? 'pasada';
const only = process.argv.slice(2);

const ROLES = [
  { name: 'publico', base: ACADEMY, email: null, routes: ['/login', '/signup', '/forgot-password', '/catalog', '/verify', '/reclamaciones', '/reclamaciones/seguimiento', '/p/terminos-y-condiciones'] },
  {
    name: 'admin',
    base: ACADEMY,
    email: 'admin@plataforma.com',
    routes: ['/dashboard', '/courses', '/students', '/teachers', '/enrollments', '/question-banks', '/crm', '/billing', '/promotions', '/certificates', '/certificates/design', '/support', '/claims', '/reports', '/users', '/roles', '/settings', '/settings/plan', '/pages', '/audit', '/account'],
  },
  { name: 'alumno', base: ACADEMY, email: 'alumno@plataforma.com', routes: ['/dashboard', '/my-courses', '/available-courses', '/my-orders', '/my-certificates', '/help', '/account'] },
  { name: 'profesor', base: ACADEMY, email: process.env.PASADA_PROFESOR ?? 'docente.prueba.muz9avm9@demo.test', routes: ['/dashboard', '/courses', '/account'] },
  { name: 'empresa', base: ACADEMY, email: 'rita.responsable@demo.test', routes: ['/dashboard', '/company', '/account'] },
  { name: 'plataforma', base: CENTRAL, email: 'plataforma@intelectalab.test', loginPath: '/platform', routes: ['/platform', '/platform/plans', '/platform/payments'] },
  { name: 'central', base: CENTRAL, email: null, routes: ['/register'] },
];

// Enlaces de detalle que se siguen desde los listados: uno por patrón
const DETAILS = [
  /^\/courses\/[0-9a-f-]{36}$/,
  /^\/courses\/[0-9a-f-]{36}\/preview$/,
  /^\/students\/[0-9a-f-]{36}$/,
  /^\/teachers\/[0-9a-f-]{36}$/,
  /^\/crm\/accounts\/[0-9a-f-]{36}$/,
  /^\/pages\/[0-9a-f-]{36}$/,
  /^\/my-courses\/[0-9a-f-]{36}$/,
  /^\/my-orders\/[0-9a-f-]{36}$/,
  /^\/catalog\/[0-9a-f-]{36}$/,
  /^\/catalog\/packages\/[0-9a-f-]{36}$/,
  /^\/checkout\/[0-9a-f-]{36}$/,
  /^\/platform\/tenants\/[0-9a-f-]{36}$/,
];

const SUSPECT = /\bundefined\b|\bNaN\b|\[object Object\]|Invalid Date|\bnull\b/;

fs.mkdirSync(OUT, { recursive: true });
const findings = [];
const visited = [];

const slug = (text) => text.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '').slice(0, 60) || 'inicio';

async function settle(page) {
  // Al cambiar de pestaña la petición sale un instante después del clic: primero se le da tiempo a empezar
  await page.waitForTimeout(250);
  await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
  // Y se espera a que la pantalla deje de decir que está cargando
  await page.waitForFunction(() => !/Cargando…|Cargando\.\.\./.test(document.body.innerText), null, { timeout: 8000 }).catch(() => {});
  await page.waitForTimeout(300);
}

async function inspect(page, role, label, events) {
  await settle(page);

  const info = await page.evaluate((suspect) => {
    const main = document.querySelector('main') ?? document.body;
    const text = main.innerText;
    const hit = text.match(new RegExp(suspect));
    const context = hit ? text.slice(Math.max(0, hit.index - 50), hit.index + 50).replace(/\s+/g, ' ') : null;
    return {
      title: document.title,
      h1: document.querySelector('h1')?.innerText ?? null,
      overflow: document.documentElement.scrollWidth - window.innerWidth,
      suspect: context,
      loading: /Cargando…|Cargando\.\.\./.test(text),
      errorState: /Verifica tu conexión|No se pudo cargar|Ocurrió un error/.test(text) ? text.match(/[^.\n]*(Verifica tu conexión|No se pudo cargar|Ocurrió un error)[^.\n]*/)?.[0] : null,
      unlabeled: [...main.querySelectorAll('button')].filter((b) => !b.innerText.trim() && !b.getAttribute('aria-label') && !b.title).length,
      brokenImages: [...document.images].filter((img) => img.complete && img.naturalWidth === 0 && img.src).map((img) => img.src.slice(-60)),
    };
  }, SUSPECT.source);

  const file = `${role}__${slug(label)}.png`;
  await page.screenshot({ path: path.join(OUT, file) }).catch(() => {});
  visited.push({ role, label, url: page.url().replace(/^https?:\/\/[^/]+/, ''), h1: info.h1, file });

  const note = (kind, detail) => findings.push({ role, where: label, kind, detail });
  if (info.overflow > 2) note('desborde', `${info.overflow}px más ancho que la ventana`);
  if (info.suspect) note('texto', info.suspect);
  if (info.errorState) note('estado de error', info.errorState.trim());
  if (info.loading) note('carga', 'la pantalla seguía diciendo «Cargando…» después de 8 segundos');
  if (info.unlabeled) note('accesibilidad', `${info.unlabeled} botón(es) sin texto ni etiqueta`);
  for (const src of info.brokenImages) note('imagen rota', src);
  for (const event of events.splice(0)) note(event.kind, event.detail);
}

async function crawl(browser, role) {
  const context = await browser.newContext({ viewport: { width: 1366, height: 820 }, locale: 'es-PE' });
  const page = await context.newPage();
  const events = [];

  page.on('console', (message) => {
    if (message.type() === 'error' && !/Failed to load resource/.test(message.text())) events.push({ kind: 'consola', detail: message.text().slice(0, 300) });
  });
  page.on('pageerror', (error) => events.push({ kind: 'excepción', detail: String(error).slice(0, 300) }));
  page.on('response', (response) => {
    const url = response.url();
    if (response.status() >= 400 && url.includes('/api/')) events.push({ kind: `HTTP ${response.status()}`, detail: `${response.request().method()} ${url.replace(/^https?:\/\/[^/]+/, '')}` });
  });

  if (role.email) {
    await page.goto(role.base + (role.loginPath ?? '/login'));
    await page.locator('input[type=email]').fill(role.email);
    await page.locator('input[type=password]').fill(PASSWORD);
    await page.locator('form button[type=submit]').first().click();
    await page.waitForFunction((loginPath) => !document.querySelector('input[type=password]') || location.pathname !== loginPath, role.loginPath ?? '/login', { timeout: 15000 }).catch(() => {});
    await settle(page);
    if (await page.locator('input[type=password]').count()) {
      findings.push({ role: role.name, where: 'inicio de sesión', kind: 'acceso', detail: `no se pudo entrar como ${role.email}` });
      await context.close();
      return;
    }
    events.splice(0);
  }

  const queue = [...role.routes];
  const seenPatterns = new Set();
  const done = new Set();

  while (queue.length) {
    const route = queue.shift();
    if (done.has(route)) continue;
    done.add(route);

    await page.goto(role.base + route).catch((error) => events.push({ kind: 'navegación', detail: String(error).slice(0, 200) }));
    await inspect(page, role.name, route, events);

    // Si la ruta redirige, la persona no tiene esa pantalla
    const landed = new URL(page.url()).pathname;
    if (landed !== route && !route.includes(':')) findings.push({ role: role.name, where: route, kind: 'redirección', detail: `terminó en ${landed}` });

    // Filtros desplegables con buscador: deben abrir, listar opciones y filtrar al escribir
    const combo = page.locator('main [role=combobox]').first();
    if (await combo.count()) {
      await combo.click({ timeout: 3000 }).catch(() => {});
      const options = page.locator('[role=listbox] [role=option]');
      const total = await options.count();
      if (total === 0) findings.push({ role: role.name, where: route, kind: 'filtro', detail: 'el desplegable no mostró opciones' });
      else {
        await page.screenshot({ path: path.join(OUT, `${role.name}__filtro-${slug(route)}.png`) }).catch(() => {});
        await page.keyboard.type('zzzz');
        if ((await options.count()) !== 0) findings.push({ role: role.name, where: route, kind: 'filtro', detail: 'el buscador del desplegable no filtró' });
      }
      await page.keyboard.press('Escape');
      events.splice(0);
    }

    // Pestañas de la pantalla
    const tabs = page.locator('main [role=tab]');
    const count = Math.min(await tabs.count(), 10);
    for (let index = 1; index < count; index++) {
      const tab = tabs.nth(index);
      const name = ((await tab.innerText().catch(() => '')) || `pestaña ${index + 1}`).trim().replace(/\s+/g, ' ').slice(0, 30);
      await tab.click({ timeout: 3000 }).catch(() => {});
      await inspect(page, role.name, `${route} › ${name}`, events);
    }

    // Detalles enlazados desde aquí
    const links = await page.evaluate(() => [...document.querySelectorAll('a[href^="/"]')].map((a) => a.getAttribute('href').split('?')[0]));
    for (const pattern of DETAILS) {
      if (seenPatterns.has(pattern.source)) continue;
      const match = links.find((href) => pattern.test(href));
      if (match) {
        seenPatterns.add(pattern.source);
        queue.push(match);
      }
    }
  }

  // Lo mismo en teléfono: solo se mide el ancho
  await page.setViewportSize({ width: 390, height: 800 });
  for (const route of role.routes) {
    await page.goto(role.base + route).catch(() => {});
    await settle(page);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    if (overflow > 2) {
      findings.push({ role: role.name, where: route, kind: 'desborde en teléfono', detail: `${overflow}px` });
      await page.screenshot({ path: path.join(OUT, `${role.name}__movil-${slug(route)}.png`) }).catch(() => {});
    }
  }
  events.splice(0);

  await context.close();
}

// Con PASADA_VER=1 el navegador se abre a la vista y va más despacio, para seguir la pasada
const visible = process.env.PASADA_VER === '1';
// A la vista se usa el Chrome instalado en el equipo: el Chromium de Playwright no siempre puede abrir ventana
const browser = await chromium.launch(visible ? { headless: false, slowMo: 350, channel: process.env.PASADA_NAVEGADOR ?? 'chrome' } : {});
for (const role of ROLES) {
  if (only.length && !only.includes(role.name)) continue;
  await crawl(browser, role);
  console.log(`· ${role.name}: listo`);
}
await browser.close();

fs.writeFileSync(path.join(OUT, 'resultado.json'), JSON.stringify({ visited, findings }, null, 2));
console.log(`\nPantallas visitadas: ${visited.length}`);
console.log(`Hallazgos: ${findings.length}`);
for (const finding of findings) console.log(`- [${finding.role}] ${finding.where} · ${finding.kind}: ${finding.detail}`);
