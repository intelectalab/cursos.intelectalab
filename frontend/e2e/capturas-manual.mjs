// Capturas de pantalla para el manual de uso por rol: entra con cada rol, visita sus pantallas,
// pestañas y fichas, y guarda una imagen de cada una en docs/manual/capturas.
// Uso (desde frontend/): CAPTURAS_PASSWORD=<clave demo> node e2e/capturas-manual.mjs [rol…]
// Después: `node generar.js` en docs/manual vuelve a armar el Word con las imágenes nuevas.
import { chromium } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const ACADEMY = process.env.CAPTURAS_ACADEMY ?? 'http://demo.localhost:5183';
const CENTRAL = process.env.CAPTURAS_CENTRAL ?? 'http://localhost:5183';
const PASSWORD = process.env.CAPTURAS_PASSWORD ?? '';
const OUT = process.env.CAPTURAS_OUT ?? path.join('..', 'docs', 'manual', 'capturas');
const only = process.argv.slice(2);

// Pantallas con un paso previo: desplazarse hasta una sección o abrir una ventana
const scroll = (text) => ({ scroll: text });
const click = (selector) => ({ click: selector });
const button = (text) => click(`main button:has-text("${text}")`);

const ROLES = [
  {
    name: 'visitante',
    base: ACADEMY,
    email: null,
    routes: ['/', '/login', '/signup', '/forgot-password', '/catalog', '/verify', '/reclamaciones', '/reclamaciones/seguimiento', '/p/terminos-y-condiciones'],
  },
  {
    name: 'alumno',
    base: ACADEMY,
    email: 'alumno@plataforma.com',
    find: { '/my-courses': 'Excel para finanzas' },
    routes: ['/dashboard', '/my-courses', '/available-courses', '/my-orders', '/my-certificates', '/help', '/account'],
  },
  {
    name: 'docente',
    base: ACADEMY,
    email: process.env.CAPTURAS_DOCENTE ?? 'rosa.compradora@demo.test',
    routes: ['/dashboard', '/my-schedule', '/courses', '/question-banks', '/account'],
  },
  {
    name: 'admin',
    base: ACADEMY,
    email: 'admin@plataforma.com',
    find: { '/courses': 'Excel para finanzas', '/schedules': 'Rosa Quispe' },
    routes: ['/dashboard', '/panel', '/courses', '/students', '/teachers', '/schedules', '/enrollments', '/question-banks', '/crm', '/billing', '/promotions', '/certificates', '/certificates/design', '/support', '/claims', '/reports', '/users', '/roles', '/settings', '/settings/plan', '/settings/emails', '/pages', '/audit', '/account'],
    extras: [
      { route: '/courses', name: 'nuevo-curso', steps: [button('Nuevo curso')] },
      { route: '/students', name: 'nuevo-alumno', steps: [button('Nuevo alumno')] },
      { route: '/teachers', name: 'nuevo-docente', steps: [button('Nuevo docente')] },
      { route: '/enrollments', name: 'matricular', steps: [button('Matricular alumnos')] },
      { route: '/question-banks', name: 'importar-preguntas', steps: [button('Importar')] },
      { route: '/users', name: 'nuevo-usuario', steps: [button('Nuevo usuario')] },
      { route: '/users', name: 'importar-personas', steps: [button('Importar')] },
      { route: '/roles', name: 'nuevo-rol', steps: [button('Nuevo rol')] },
      { route: '/users', name: 'restablecer-contrasena', steps: [button('Editar'), click('[role=dialog] button:has-text("Restablecer contraseña")')] },
      { route: '/pages', name: 'nueva-pagina', steps: [button('Nueva página')] },
      { route: '/billing', name: 'orden', steps: [click('button[aria-label^="Ver la orden"]')] },
      { route: '/settings', name: 'tipo-de-cambio', steps: [scroll('Tipo de cambio')] },
      { route: '/settings', name: 'cobros-en-linea', steps: [scroll('Cobros en línea')] },
      { route: '/settings', name: 'errores-de-pasarelas', steps: [scroll('Últimos errores de las pasarelas'), click('button:has-text("Últimos errores de las pasarelas")')] },
      { route: '/settings', name: 'feriados', steps: [scroll('Feriados')] },
      { route: '/settings/emails', name: 'editor', steps: [scroll('Datos que puedes usar')] },
      { route: '/dashboard', name: 'campana', steps: [click('button[aria-label*="viso"]')] },
      { route: '/schedules', name: 'por-dia', steps: [click('[role=tab]:has-text("Por día")')] },
      { route: '/panel', name: 'ventas', steps: [click('[role=tab]:has-text("Ventas")')] },
      { route: '/panel', name: 'personas', steps: [click('[role=tab]:has-text("Personas")')] },
      { route: '/panel', name: 'atencion', steps: [click('[role=tab]:has-text("Atención")')] },
    ],
  },
  { name: 'soporte', base: ACADEMY, email: 'soporte@plataforma.com', routes: ['/dashboard', '/support', '/claims', '/students', '/enrollments', '/billing', '/account'] },
  { name: 'ventas', base: ACADEMY, email: 'ventas@plataforma.com', routes: ['/dashboard', '/crm', '/billing', '/promotions', '/students', '/reports', '/account'] },
  { name: 'empresa', base: ACADEMY, email: 'rita.responsable@demo.test', routes: ['/dashboard', '/company', '/account'] },
  {
    name: 'plataforma',
    base: CENTRAL,
    email: 'plataforma@intelectalab.test',
    loginPath: '/platform',
    routes: ['/platform/panel', '/platform', '/platform/plans', '/platform/payments', '/platform/emails'],
    extras: [
      { route: '/platform', name: 'nueva-academia', steps: [click('button:has-text("Nueva academia")')] },
      { route: '/platform/plans', name: 'cobros-en-linea', steps: [scroll('Cobros en línea')] },
    ],
  },
  { name: 'registro', base: CENTRAL, email: null, routes: ['/', '/register'] },
];

// Fichas que se abren desde los listados: una por tipo
const DETAILS = [
  /^\/courses\/[0-9a-f-]{36}$/,
  /^\/courses\/[0-9a-f-]{36}\/preview$/,
  /^\/students\/[0-9a-f-]{36}$/,
  /^\/teachers\/[0-9a-f-]{36}$/,
  /^\/schedules\/[0-9a-f-]{36}$/,
  /^\/crm\/accounts\/[0-9a-f-]{36}$/,
  /^\/pages\/[0-9a-f-]{36}$/,
  /^\/my-courses\/[0-9a-f-]{36}$/,
  /^\/my-orders\/[0-9a-f-]{36}$/,
  /^\/catalog\/[0-9a-f-]{36}$/,
  /^\/catalog\/packages\/[0-9a-f-]{36}$/,
  /^\/checkout\/[0-9a-f-]{36}$/,
  /^\/platform\/tenants\/[0-9a-f-]{36}$/,
];

fs.mkdirSync(OUT, { recursive: true });
const shots = [];

const slug = (text) =>
  text
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[0-9a-f]{8}-[0-9a-f-]{27}/g, 'ficha')
    .replace(/[^a-z0-9]+/gi, '-')
    .replace(/^-|-$/g, '')
    .toLowerCase()
    .slice(0, 70) || 'inicio';

async function settle(page) {
  await page.waitForTimeout(250);
  await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
  await page.waitForFunction(() => !/Cargando…|Cargando\.\.\./.test(document.body.innerText), null, { timeout: 8000 }).catch(() => {});
  // Las pantallas entran con una animación: se espera a que termine
  await page.waitForTimeout(700);
}

async function shoot(page, role, label) {
  const file = `${role}__${slug(label)}.jpg`;
  await page.screenshot({ path: path.join(OUT, file), type: 'jpeg', quality: 88 });
  const h1 = await page.evaluate(() => document.querySelector('h1')?.innerText ?? null);
  // Sin título no es una pantalla del rol (por ejemplo, «no tienes acceso»): no sirve para el manual
  if (!h1) {
    fs.rmSync(path.join(OUT, file), { force: true });
    return;
  }
  shots.push({ role, label, file, h1, url: page.url().replace(/^https?:\/\/[^/]+/, '') });
}

async function capture(browser, role) {
  const context = await browser.newContext({ viewport: { width: 1366, height: 800 }, locale: 'es-PE', deviceScaleFactor: 1 });
  const page = await context.newPage();

  if (role.email) {
    await page.goto(role.base + (role.loginPath ?? '/login'));
    await page.locator('input[type=email]').fill(role.email);
    await page.locator('input[type=password]').fill(PASSWORD);
    await page.locator('form button[type=submit]').first().click();
    await page.waitForFunction((loginPath) => !document.querySelector('input[type=password]') || location.pathname !== loginPath, role.loginPath ?? '/login', { timeout: 15000 }).catch(() => {});
    await settle(page);
    if (await page.locator('input[type=password]').count()) {
      console.log(`  ! no se pudo entrar como ${role.email}`);
      await context.close();
      return;
    }
  }

  const queue = [...role.routes];
  const seenPatterns = new Set();
  const done = new Set();

  while (queue.length) {
    const route = queue.shift();
    if (done.has(route)) continue;
    done.add(route);

    await page.goto(role.base + route).catch(() => {});
    await settle(page);

    // Si la ruta redirige, ese rol no tiene esa pantalla: no se captura
    if (new URL(page.url()).pathname !== route) continue;
    await shoot(page, role.name, route);

    const tabs = page.locator('main [role=tab]');
    const count = Math.min(await tabs.count(), 10);
    for (let index = 1; index < count; index++) {
      const tab = tabs.nth(index);
      const name = ((await tab.innerText().catch(() => '')) || `pestaña ${index + 1}`).trim().replace(/\s+/g, ' ').replace(/\s*\d+$/, '').slice(0, 30);
      await tab.click({ timeout: 3000 }).catch(() => {});
      await settle(page);
      await shoot(page, role.name, `${route} ${name}`);
    }

    // Donde la lista empieza por datos de los recorridos, se busca el curso de muestra antes de elegir su ficha
    if (role.find?.[route]) {
      await page.locator('main input[placeholder^="Buscar"]').first().fill(role.find[route]).catch(() => {});
      await settle(page);
      await page.waitForTimeout(600);
    }

    // Se prefieren las fichas con datos de muestra a las que dejaron los recorridos automáticos («E2E …»)
    // Y, si la pantalla no tiene buscador, primero la que nombra el dato buscado
    const links = await page.evaluate((needle) =>
      [...document.querySelectorAll('a[href^="/"]')]
        .map((a) => {
          const text = (a.closest('article, li, tr') ?? a).innerText;
          return { href: a.getAttribute('href').split('?')[0], test: /E2E|Prueba MV|Prueba MU/.test(text), wanted: needle !== null && text.includes(needle) };
        })
        .sort((a, b) => Number(b.wanted) - Number(a.wanted) || Number(a.test) - Number(b.test))
        .map((link) => link.href),
    role.find?.[route] ?? null);
    for (const pattern of DETAILS) {
      if (seenPatterns.has(pattern.source)) continue;
      const match = links.find((href) => pattern.test(href));
      if (match) {
        seenPatterns.add(pattern.source);
        queue.push(match);
      }
    }
  }

  for (const extra of role.extras ?? []) {
    await page.goto(role.base + extra.route).catch(() => {});
    await settle(page);
    let ok = true;
    for (const step of extra.steps) {
      try {
        // El margen deja el título de la sección por debajo de la barra superior, que queda fija
        if (step.scroll)
          await page.getByText(step.scroll, { exact: true }).first().evaluate((node) => {
            node.style.scrollMarginTop = '110px';
            node.scrollIntoView({ block: 'start' });
          });
        if (step.click) await page.locator(step.click).first().click({ timeout: 4000 });
        await settle(page);
      } catch {
        ok = false;
        console.log(`  ! ${role.name} ${extra.route} · ${extra.name}: no se encontró ${step.scroll ?? step.click}`);
        break;
      }
    }
    if (ok) await shoot(page, role.name, `${extra.route} ${extra.name}`);
  }

  await context.close();
}

const browser = await chromium.launch();
for (const role of ROLES) {
  if (only.length && !only.includes(role.name)) continue;
  await capture(browser, role);
  console.log(`· ${role.name}: listo`);
}
await browser.close();

// El índice conserva las capturas de los roles que no se repitieron en esta corrida
const indexPath = path.join(OUT, 'indice.json');
const previous = fs.existsSync(indexPath) ? JSON.parse(fs.readFileSync(indexPath, 'utf8')) : [];
const roles = new Set(shots.map((shot) => shot.role));
fs.writeFileSync(indexPath, JSON.stringify([...previous.filter((shot) => !roles.has(shot.role)), ...shots], null, 2));
console.log(`\nCapturas: ${shots.length} en ${OUT}`);
