import { expect, test } from '@playwright/test';
import { ADMIN_EMAIL, ADMIN_PASSWORD, Api, apiContext, registerStudent, signIn, stamp } from './support';

// Recorridos de la operación diaria: cobrar en cuotas, reembolsar, atender soporte, el Libro de
// Reclamaciones y las cotizaciones. Los de soporte y cotizaciones entran con las cuentas de Soporte
// y de Ventas de la academia de demostración (las crea `php artisan db:seed`), con la clave de la demo.

const SUPPORT_EMAIL = process.env.E2E_SUPPORT_EMAIL ?? 'soporte@plataforma.com';
const SALES_EMAIL = process.env.E2E_SALES_EMAIL ?? 'ventas@plataforma.com';

// Día de hoy más unos días, en formato AAAA-MM-DD
const inDays = (days: number) => new Date(Date.now() + days * 86_400_000).toISOString().slice(0, 10);

test('cuotas: el alumno entra con la primera y el personal confirma la segunda', async ({ page, baseURL }) => {
  const request = await apiContext(baseURL!);
  const mark = stamp();
  const admin = await Api.admin(request);
  const { course } = await admin.publishedCourse(`E2E Cuotas ${mark}`, { price: 300 });
  const buyer = await registerStudent(request, mark);
  const student = await Api.login(request, buyer.email, buyer.password);
  const order: string = (await student.post('/orders', { course_id: course })).id;
  await admin.put(`/orders/${order}/installments`, {
    installments: [
      { amount: 200, due_date: inDays(2) },
      { amount: 100, due_date: inDays(32) },
    ],
  });
  await admin.post(`/orders/${order}/confirm-payment`, { method: 'TRANSFER' });

  try {
    // El alumno ya tiene acceso y ve lo que le falta pagar
    await signIn(page, buyer.email, buyer.password);
    await expect(page).toHaveURL(/\/(dashboard|available-courses)/);
    await page.goto(`/my-orders/${order}`);
    await expect(page.getByText('En cuotas').first()).toBeVisible();
    await expect(page.getByText('Ya tienes acceso al curso')).toBeVisible();
    await expect(page.getByText(/La cuota 2 es de/)).toBeVisible();
    await page.getByRole('button', { name: 'Cerrar sesión' }).click();

    // El personal confirma la segunda cuota desde la orden y queda pagada
    await signIn(page, ADMIN_EMAIL, ADMIN_PASSWORD);
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.goto(`/billing?order=${order}`);
    const dialog = page.getByRole('dialog').filter({ hasText: 'Orden N.º' });
    await dialog.getByRole('button', { name: 'Confirmar cuota 2' }).click();
    await dialog.getByRole('button', { name: 'Confirmar pago', exact: true }).click();
    await expect(dialog.getByText('Pagado S/ 300.00')).toBeVisible();
    await expect(dialog.getByRole('button', { name: /Confirmar cuota/ })).toHaveCount(0);
  } finally {
    await admin.archiveCourse(course);
  }
});

test('reembolso: el personal devuelve una compra y el alumno pierde el acceso', async ({ page, baseURL }) => {
  const request = await apiContext(baseURL!);
  const mark = stamp();
  const admin = await Api.admin(request);
  const { course } = await admin.publishedCourse(`E2E Reembolso ${mark}`, { price: 80 });
  const buyer = await registerStudent(request, mark);
  const student = await Api.login(request, buyer.email, buyer.password);
  const order: string = (await student.post('/orders', { course_id: course })).id;
  await admin.post(`/orders/${order}/confirm-payment`, { method: 'YAPE' });

  try {
    await signIn(page, ADMIN_EMAIL, ADMIN_PASSWORD);
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.goto(`/billing?order=${order}`);
    const dialog = page.getByRole('dialog').filter({ hasText: 'Orden N.º' });
    await dialog.getByRole('button', { name: 'Reembolsar', exact: true }).click();
    // Un pago recibido por fuera: la pantalla avisa que el dinero lo devuelve la academia
    await expect(dialog.getByText('devuelve tú el dinero')).toBeVisible();
    await dialog.locator('textarea').fill('Recorrido automático: el alumno pidió la devolución.');
    await dialog.getByRole('button', { name: /^Reembolsar S\// }).click();
    await expect(dialog.getByText('Reembolsada').first()).toBeVisible();
    await dialog.getByRole('button', { name: 'Cerrar', exact: true }).first().click();
    await page.getByRole('button', { name: 'Cerrar sesión' }).click();

    // El alumno ve su compra reembolsada y ya no tiene el curso
    await signIn(page, buyer.email, buyer.password);
    await expect(page).toHaveURL(/\/(dashboard|available-courses)/);
    await page.goto(`/my-orders/${order}`);
    await expect(page.getByText(/Esta compra fue reembolsada/)).toBeVisible();
    await page.goto('/my-courses');
    await expect(page.getByText(`E2E Reembolso ${mark}`)).toHaveCount(0);
  } finally {
    await admin.archiveCourse(course);
  }
});

test('soporte: la consulta de un alumno llega a la bandeja de Soporte y su respuesta vuelve al alumno', async ({ page, baseURL }) => {
  const request = await apiContext(baseURL!);
  const mark = stamp();
  const buyer = await registerStudent(request, mark);
  const student = await Api.login(request, buyer.email, buyer.password);
  const subject = `E2E consulta ${mark}`;
  const ticket: string = (await student.post('/tickets', { subject, category: 'OTHER', body: 'No encuentro mi certificado.' })).id;

  // Quien atiende entra con el rol Soporte, no como administrador
  await signIn(page, SUPPORT_EMAIL, ADMIN_PASSWORD);
  await expect(page).toHaveURL(/\/dashboard$/);
  await page.goto('/support');
  await expect(page.getByRole('heading', { level: 1, name: 'Soporte' })).toBeVisible();
  await expect(page.getByText(subject).first()).toBeVisible();
  // Soporte no administra la academia: no tiene el menú de usuarios
  await expect(page.getByRole('link', { name: 'Usuarios' })).toHaveCount(0);

  const support = await Api.login(request, SUPPORT_EMAIL, ADMIN_PASSWORD);
  await support.post(`/tickets/${ticket}/messages`, { body: `Respuesta E2E ${mark}: ya lo tienes en «Mis certificados».` });
  await page.getByRole('button', { name: 'Cerrar sesión' }).click();

  await signIn(page, buyer.email, buyer.password);
  await expect(page).toHaveURL(/\/(dashboard|available-courses)/);
  await page.goto('/help');
  await expect(page.getByText(subject).first()).toBeVisible();
});

test('reclamo: una hoja registrada se consulta con su código y su documento', async ({ page, baseURL }) => {
  const request = await apiContext(baseURL!);
  const mark = stamp();
  const docNumber = String(10_000_000 + Math.floor(Math.random() * 89_999_999));
  const response = await request.post('/api/v1/claims', {
    headers: { Accept: 'application/json' },
    data: {
      type: 'RECLAMO',
      first_name: 'Prueba',
      last_name: mark,
      doc_type: 'DNI',
      doc_number: docNumber,
      address: 'Av. de Prueba 123, Lima',
      email: `e2e.${mark.toLowerCase()}@prueba.test`,
      good_type: 'SERVICE',
      good_description: `E2E curso ${mark}`,
      detail: 'Recorrido automático: el curso no abre desde ayer.',
      request: 'Que se restablezca el acceso.',
      accepted: true,
    },
  });
  expect(response.ok(), `registro del reclamo: ${response.status()} ${await response.text()}`).toBeTruthy();
  const code: string = (await response.json()).data.code;

  await page.goto('/reclamaciones/seguimiento');
  await page.getByLabel('Código de la hoja').fill(code);
  await page.getByLabel('Número de documento').fill(docNumber);
  await page.getByRole('button', { name: 'Consultar' }).click();
  await expect(page.getByText(code).first()).toBeVisible();
  await expect(page.getByText(`E2E curso ${mark}`).first()).toBeVisible();

  // Con otro documento la hoja no se muestra
  await page.goto('/reclamaciones/seguimiento');
  await page.getByLabel('Código de la hoja').fill(code);
  await page.getByLabel('Número de documento').fill('00000000');
  await page.getByRole('button', { name: 'Consultar' }).click();
  await expect(page.getByText(`E2E curso ${mark}`)).toHaveCount(0);
});

test('cotización: Ventas ve la cotización enviada a una empresa y su venta al aceptarse', async ({ page, baseURL }) => {
  const request = await apiContext(baseURL!);
  const mark = stamp();
  const admin = await Api.admin(request);
  const { course } = await admin.publishedCourse(`E2E Cotización ${mark}`, { price: 150 });
  const companyName = `E2E Empresa ${mark}`;
  const company: string = (await admin.post('/crm/companies', { name: companyName })).id;
  const quotation: string = (await admin.post('/quotations', { company_id: company, items: [{ course_id: course, quantity: 3, unit_price: 120 }] })).id;
  await admin.post(`/quotations/${quotation}/send`);

  try {
    // Quien vende entra con el rol Ventas, no como administrador
    await signIn(page, SALES_EMAIL, ADMIN_PASSWORD);
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.goto('/crm');
    await page.getByRole('tab', { name: 'Cotizaciones' }).click();
    const row = page.getByRole('row').filter({ hasText: companyName });
    await expect(row.getByText('Enviada')).toBeVisible();
    await expect(row.getByText('360.00')).toBeVisible();

    await admin.post(`/quotations/${quotation}/accept`);
    await page.reload();
    await page.getByRole('tab', { name: 'Cotizaciones' }).click();
    await expect(page.getByRole('row').filter({ hasText: companyName }).getByText('Aceptada')).toBeVisible();
  } finally {
    await admin.archiveCourse(course);
  }
});
