import { expect, test } from '@playwright/test';
import { ADMIN_EMAIL, ADMIN_PASSWORD, Api, apiContext, registerStudent, signIn, stamp } from './support';

// Cinco recorridos de punta a punta sobre lo que no puede fallar al publicar una versión.
// Cada uno prepara sus datos por la API y comprueba el resultado en pantalla.

test('acceso: rechaza una clave incorrecta, entra con la correcta y cierra sesión', async ({ page }) => {
  await signIn(page, ADMIN_EMAIL, 'clave-incorrecta-e2e');
  await expect(page.getByRole('alert')).toContainText('credenciales');

  await page.getByLabel('Contraseña', { exact: true }).fill(ADMIN_PASSWORD);
  await page.locator('form button[type=submit]').click();

  await expect(page).toHaveURL(/\/dashboard$/);
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  // El título de la pestaña nombra la pantalla y la academia
  await expect(page).toHaveTitle(/ · /);

  await page.getByRole('button', { name: 'Cerrar sesión' }).click();
  await expect(page).toHaveURL(/\/login/);
});

test('compra con cupón: el descuento se aplica y la orden queda pendiente de pago', async ({ page, baseURL }) => {
  const request = await apiContext(baseURL!);
  const mark = stamp();
  const admin = await Api.admin(request);
  const { course } = await admin.publishedCourse(`E2E Compra ${mark}`, { price: 100 });
  const code = `E2E${mark}`;
  await admin.post('/promotions', {
    name: `E2E ${mark}`,
    code,
    discount_type: 'PERCENT',
    discount_value: 50,
    once_per_customer: true,
    applies_to: 'SELECTED',
    items: [{ type: 'COURSE', id: course }],
  });
  const buyer = await registerStudent(request, mark);

  try {
    await signIn(page, buyer.email, buyer.password);
    await expect(page).toHaveURL(/\/(dashboard|available-courses)/);

    await page.goto(`/checkout/${course}`);
    await expect(page.getByRole('heading', { name: 'Confirma tu compra' })).toBeVisible();

    await page.getByRole('button', { name: '¿Tienes un cupón de descuento?' }).click();
    await page.getByLabel('Código del cupón').fill(code);
    await page.getByRole('button', { name: 'Aplicar' }).click();
    await expect(page.getByRole('button', { name: 'Quitar cupón' })).toBeVisible();
    await expect(page.getByText('50.00').first()).toBeVisible();

    await page.getByRole('button', { name: 'Confirmar compra' }).click();

    await expect(page).toHaveURL(/\/my-orders\//);
    await expect(page.getByText('Pendiente de pago').first()).toBeVisible();
    await expect(page.getByText(code).first()).toBeVisible();
  } finally {
    await admin.archiveCourse(course);
  }
});

test('cuestionario: lo marcado se recupera al retomar el intento y se califica al enviar', async ({ page, baseURL }) => {
  const request = await apiContext(baseURL!);
  const mark = stamp();
  const admin = await Api.admin(request);
  const bank: string = (await admin.post('/question-banks', { name: `E2E ${mark}` })).id;
  const question: string = (
    await admin.post(`/question-banks/${bank}/questions`, {
      type: 'TRUE_FALSE',
      statement: 'Una hoja de cálculo sirve para ordenar datos.',
      config: { answer: true },
    })
  ).id;
  const { course } = await admin.publishedCourse(`E2E Cuestionario ${mark}`, {}, async (id, section) => {
    const quiz: string = (
      await admin.post(`/courses/${id}/sections/${section}/activities`, { type: 'QUIZ', title: 'Evaluación E2E', passing_percent: 50, max_attempts: 2 })
    ).id;
    await admin.put(`/courses/${id}/activities/${quiz}/questions`, { items: [{ question_id: question, points: 1 }] });
  });
  const student = await registerStudent(request, mark);
  await admin.post(`/courses/${course}/enrollments`, { student_ids: [student.id] });

  try {
    await signIn(page, student.email, student.password);
    await expect(page).toHaveURL(/\/dashboard$/);

    await page.goto(`/my-courses/${course}`);
    await page.getByRole('button', { name: /Evaluación E2E/ }).click();
    await page.getByRole('button', { name: 'Comenzar' }).click();

    const answer = page.getByRole('radio', { name: 'Verdadero' });
    const saved = page.waitForResponse((response) => response.url().includes('/draft') && response.request().method() === 'PUT');
    await answer.check();
    expect((await saved).ok()).toBeTruthy();

    // Cierra y vuelve: el intento sigue abierto con lo que ya había marcado
    await page.reload();
    await page.getByRole('button', { name: /Evaluación E2E/ }).click();
    await page.getByRole('button', { name: 'Continuar el intento' }).click();
    await expect(answer).toBeChecked();

    await page.getByRole('button', { name: 'Enviar respuestas' }).click();
    await expect(page.getByText('Aprobaste')).toBeVisible();
  } finally {
    await admin.archiveCourse(course);
  }
});

test('certificado: al completar el curso, el alumno encuentra su certificado', async ({ page, baseURL }) => {
  const request = await apiContext(baseURL!);
  const mark = stamp();
  const title = `E2E Certificado ${mark}`;
  const admin = await Api.admin(request);
  const { course } = await admin.publishedCourse(title, { issues_certificate: true });
  const student = await registerStudent(request, mark);
  await admin.post(`/courses/${course}/enrollments`, { student_ids: [student.id] });

  try {
    await signIn(page, student.email, student.password);
    await expect(page).toHaveURL(/\/dashboard$/);

    await page.goto(`/my-courses/${course}`);
    await page.getByRole('button', { name: /Bienvenida/ }).click();
    // La única lección queda completada al abrirla: el curso llega al 100 %
    await expect(page.getByText('100% · 1 de 1')).toBeVisible();

    await page.goto('/my-certificates');
    await expect(page.getByText(title)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Descargar PDF' }).or(page.getByRole('link', { name: 'Descargar PDF' })).first()).toBeVisible();
  } finally {
    await admin.archiveCourse(course);
  }
});

test('licencia: pagada la venta a una empresa, se asigna un cupo a un trabajador', async ({ page, baseURL }) => {
  const request = await apiContext(baseURL!);
  const mark = stamp();
  const admin = await Api.admin(request);
  const { course } = await admin.publishedCourse(`E2E Licencias ${mark}`, { price: 150 });
  const company: string = (await admin.post('/crm/companies', { name: `E2E Empresa ${mark}` })).id;
  const quotation: string = (
    await admin.post('/quotations', { company_id: company, items: [{ course_id: course, quantity: 2, unit_price: 100 }] })
  ).id;
  await admin.post(`/quotations/${quotation}/send`);
  const order: string = (await admin.post(`/quotations/${quotation}/accept`)).order.id;
  await admin.post(`/orders/${order}/confirm-payment`, { method: 'TRANSFER' });
  const worker = { first_name: 'Trabajador', last_name: mark, email: `e2e.trab.${mark.toLowerCase()}@prueba.test` };
  await admin.post(`/corporate-accounts/${company}/members`, {
    ...worker,
    password: `E2e-${mark}-${Math.random().toString(36).slice(2, 10)}`,
    is_manager: false,
  });

  try {
    await signIn(page, ADMIN_EMAIL, ADMIN_PASSWORD);
    await expect(page).toHaveURL(/\/dashboard$/);

    await page.goto(`/crm/accounts/${company}`);
    await expect(page.getByText('0 de 2 asignadas')).toBeVisible();

    await page.getByRole('button', { name: 'Asignar', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Asignar licencias' });
    await dialog.getByText(worker.email).click();
    await dialog.getByRole('button', { name: 'Asignar 1' }).click();

    await expect(page.getByText('1 de 2 asignadas')).toBeVisible();
    await expect(page.getByText(`Trabajador ${mark}`).first()).toBeVisible();
  } finally {
    await admin.archiveCourse(course);
  }
});
