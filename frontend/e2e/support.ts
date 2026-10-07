import { expect, request as playwrightRequest, type APIRequestContext, type Page } from '@playwright/test';

export const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'admin@plataforma.com';
export const ADMIN_PASSWORD = process.env.E2E_PASSWORD ?? '';

/**
 * Conexión a la API de la academia. Node no resuelve los nombres *.localhost
 * (el navegador sí), así que en local se conecta a 127.0.0.1 y envía el
 * nombre de la academia en la cabecera Host, que es de donde la API la toma.
 */
export async function apiContext(baseURL: string): Promise<APIRequestContext> {
  const url = new URL(baseURL);
  if (!url.hostname.endsWith('.localhost')) return playwrightRequest.newContext({ baseURL });

  const host = url.host;
  url.hostname = '127.0.0.1';
  return playwrightRequest.newContext({ baseURL: url.origin, extraHTTPHeaders: { Host: host } });
}

// Los datos de cada recorrido llevan una marca propia para no chocar con corridas anteriores
export const stamp = () => Date.now().toString(36).toUpperCase();

/** Cliente de la API con la sesión de una persona: prepara los datos que el recorrido necesita. */
export class Api {
  private constructor(
    private readonly request: APIRequestContext,
    private readonly token: string,
  ) {}

  static async login(request: APIRequestContext, email: string, password: string): Promise<Api> {
    const response = await request.post('/api/v1/auth/login', { data: { email, password }, headers: { Accept: 'application/json' } });
    expect(response.ok(), `inicio de sesión de ${email}: ${response.status()}`).toBeTruthy();
    return new Api(request, (await response.json()).data.token);
  }

  static admin(request: APIRequestContext): Promise<Api> {
    expect(ADMIN_PASSWORD, 'Falta la variable E2E_PASSWORD con la contraseña del administrador').not.toBe('');
    return Api.login(request, ADMIN_EMAIL, ADMIN_PASSWORD);
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  private async send(method: 'get' | 'post' | 'put', path: string, data?: unknown): Promise<any> {
    const response = await this.request[method](`/api/v1${path}`, {
      data,
      headers: { Accept: 'application/json', Authorization: `Bearer ${this.token}` },
    });
    expect(response.ok(), `${method.toUpperCase()} ${path}: ${response.status()} ${await response.text()}`).toBeTruthy();
    return (await response.json()).data;
  }

  get(path: string) {
    return this.send('get', path);
  }

  post(path: string, data?: unknown) {
    return this.send('post', path, data);
  }

  put(path: string, data?: unknown) {
    return this.send('put', path, data);
  }

  /** Curso publicado con una lección de texto. Devuelve el curso y su sección. */
  async publishedCourse(title: string, attributes: Record<string, unknown> = {}, beforePublish?: (course: string, section: string) => Promise<void>) {
    const course: string = (await this.post('/courses', { title, language: 'es', ...attributes })).id;
    const section: string = (await this.post(`/courses/${course}/sections`, { title: 'Módulo 1' })).id;
    await this.post(`/courses/${course}/sections/${section}/resources`, { type: 'TEXT', title: 'Bienvenida', body: 'Texto de la lección.' });
    await beforePublish?.(course, section);
    await this.post(`/courses/${course}/publish`);
    return { course, section };
  }

  /** Al terminar: el curso de prueba deja de aparecer en el catálogo. */
  archiveCourse(course: string) {
    return this.post(`/courses/${course}/archive`);
  }
}

export interface Person {
  email: string;
  password: string;
  name: string;
}

/** Cuenta nueva de alumno, creada por el registro público. */
export async function registerStudent(request: APIRequestContext, mark: string): Promise<Person & { id: string }> {
  const person = { email: `e2e.${mark.toLowerCase()}@prueba.test`, password: `E2e-${mark}-${Math.random().toString(36).slice(2, 10)}`, name: `Prueba ${mark}` };
  const response = await request.post('/api/v1/auth/register', {
    headers: { Accept: 'application/json' },
    data: {
      first_name: 'Prueba',
      last_name: mark,
      email: person.email,
      password: person.password,
      password_confirmation: person.password,
      accept_terms: true,
    },
  });
  expect(response.ok(), `registro de ${person.email}: ${response.status()} ${await response.text()}`).toBeTruthy();
  return { ...person, id: (await response.json()).data.user.id };
}

export async function signIn(page: Page, email: string, password: string) {
  await page.goto('/login');
  await page.getByLabel('Correo electrónico').fill(email);
  await page.getByLabel('Contraseña', { exact: true }).fill(password);
  await page.locator('form button[type=submit]').click();
}
