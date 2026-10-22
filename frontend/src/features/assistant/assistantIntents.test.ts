import { describe, expect, it } from 'vitest';
import { cleanTerm, detectPeriod, detectWeekday, normalize, parseIntent, stripFillers } from './assistantIntents';
import { findGuides } from './howto';
import { aliasKey, searchCatalog, shouldLearn, type CatalogItem } from './search';

// Jueves 22 de octubre de 2026, 10:00 en Lima
const NOW = new Date('2026-10-22T15:00:00Z');
const LIMA = 'America/Lima';
const parse = (text: string) => parseIntent(text, LIMA, NOW);

describe('normalización', () => {
  it('quita tildes, signos y espacios de más', () => {
    expect(normalize('  ¿Cuánto   VENDIMOS hoy?! ')).toBe('cuanto vendimos hoy');
  });

  it('quita las muletillas del inicio, aunque sean varias', () => {
    expect(stripFillers('hola por favor dime cuanto vendimos hoy')).toBe('cuanto vendimos hoy');
  });

  it('limpia el término de búsqueda', () => {
    expect(cleanTerm('hay curso de power bi')).toBe('power bi');
    expect(cleanTerm('que cursos hay')).toBe('');
  });
});

describe('periodos en la hora de la academia', () => {
  it('hoy por defecto', () => {
    expect(detectPeriod('cuanto vendimos', LIMA, NOW)).toMatchObject({ key: 'today', from: '2026-10-22', to: '2026-10-22' });
  });

  it('ayer, últimos 7 días y esta semana (desde el lunes)', () => {
    expect(detectPeriod('ventas de ayer', LIMA, NOW)).toMatchObject({ from: '2026-10-21', to: '2026-10-21' });
    expect(detectPeriod('ultimos 7 dias', LIMA, NOW)).toMatchObject({ from: '2026-10-16', to: '2026-10-22' });
    expect(detectPeriod('esta semana', LIMA, NOW)).toMatchObject({ from: '2026-10-19', to: '2026-10-22' });
  });

  it('este mes y el mes pasado', () => {
    expect(detectPeriod('este mes', LIMA, NOW)).toMatchObject({ from: '2026-10-01', to: '2026-10-22' });
    expect(detectPeriod('mes pasado', LIMA, NOW)).toMatchObject({ from: '2026-09-01', to: '2026-09-30' });
  });

  it('usa la fecha de la academia aunque en UTC ya sea otro día', () => {
    // 23:30 del 22 en Lima ya es 23 en UTC
    expect(detectPeriod('hoy', LIMA, new Date('2026-10-23T04:30:00Z')).from).toBe('2026-10-22');
  });
});

describe('día de la semana', () => {
  it('reconoce el nombre del día, hoy y mañana (jueves 22 de octubre)', () => {
    expect(detectWeekday('quien esta disponible el lunes', LIMA, NOW)).toBe(1);
    expect(detectWeekday('horarios del miércoles', LIMA, NOW)).toBe(3);
    expect(detectWeekday('quien da clase hoy', LIMA, NOW)).toBe(4);
    expect(detectWeekday('y mañana', LIMA, NOW)).toBe(5);
    expect(detectWeekday('quien esta disponible', LIMA, NOW)).toBeNull();
  });

  it('la pregunta de horarios lleva el día', () => {
    expect(parse('¿Quién está disponible el lunes?')).toMatchObject({ intent: 'schedule', weekday: 1 });
  });
});

describe('intenciones', () => {
  const cases: [string, string][] = [
    // help
    ['hola', 'help'],
    ['¿Qué puedes hacer?', 'help'],
    // howto
    ['¿Cómo confirmo un pago?', 'howto'],
    ['como matriculo a un alumno', 'howto'],
    ['¿Cómo anulo una venta?', 'howto'],
    // sales
    ['¿Cuánto vendimos hoy?', 'sales'],
    ['ventas de hoy', 'sales'],
    ['ingresos del mes pasado', 'sales'],
    // top
    ['lo más vendido del mes pasado', 'top'],
    ['cursos más vendidos esta semana', 'top'],
    // enrollments
    ['¿Cuántos se matricularon esta semana?', 'enrollments'],
    ['matrículas nuevas de este mes', 'enrollments'],
    // alerts
    ['¿Qué está pendiente?', 'alerts'],
    ['cuotas vencidas', 'alerts'],
    ['matrículas por vencer', 'alerts'],
    ['¿Cómo va mi plan?', 'alerts'],
    // schedule
    ['¿Quién está disponible el lunes?', 'schedule'],
    ['¿Cuándo es mi clase en vivo?', 'schedule'],
    // mine
    ['¿Cuánto me falta pagar?', 'mine'],
    ['mis certificados', 'mine'],
    // student
    ['¿Qué debe Luis Vega?', 'student'],
    ['que cursos tiene Ana Torres', 'student'],
    // course
    ['¿Cuántos alumnos tiene Excel para finanzas?', 'course'],
    ['ventas de Excel', 'course'],
    ['¿Cómo va Excel?', 'course'],
    // search
    ['¿Hay curso de Power BI?', 'search'],
    ['¿Cuánto cuesta Excel?', 'search'],
    ['¿Qué cursos hay?', 'search'],
  ];

  it.each(cases)('«%s» → %s', (text, intent) => {
    expect(parse(text).intent).toBe(intent);
  });

  it('distingue el reporte de la ficha: «ventas de hoy» frente a «ventas de Excel»', () => {
    expect(parse('ventas de hoy')).toMatchObject({ intent: 'sales', period: { key: 'today' } });
    expect(parse('ventas de Excel')).toMatchObject({ intent: 'course', term: 'excel' });
  });

  it('distingue la guía del reporte: «cómo matriculo» frente a «cuántos se matricularon»', () => {
    expect(parse('como matriculo').intent).toBe('howto');
    expect(parse('cuantos se matricularon hoy').intent).toBe('enrollments');
  });

  it('extrae el término y el periodo', () => {
    expect(parse('¿Hay curso de Power BI?').term).toBe('power bi');
    expect(parse('¿Qué debe Luis Vega?').term).toBe('luis vega');
    expect(parse('lo más vendido del mes pasado').period).toMatchObject({ key: 'lastMonth', from: '2026-09-01' });
  });

  it('cualquier otra frase es una búsqueda', () => {
    expect(parse('marketing digital')).toMatchObject({ intent: 'search', term: 'marketing digital' });
  });
});

describe('guías', () => {
  const all = () => true;

  it('encuentra la guía por sus palabras clave', () => {
    expect(findGuides('como confirmo un pago', all)[0].title).toBe('Confirmar un pago recibido');
    expect(findGuides('como anulo una venta', all)[0].title).toBe('Reembolsar una compra');
  });

  it('no ofrece guías de módulos que el rol no puede usar', () => {
    const student = (permission: string | string[]) => [permission].flat().some((code) => ['orders.create', 'tickets.self'].includes(code));
    expect(findGuides('como confirmo un pago', student).map((guide) => guide.title)).not.toContain('Confirmar un pago recibido');
  });
});

describe('búsqueda con puntaje', () => {
  const item = (id: string, title: string, category: string | null = null): CatalogItem => ({ type: 'COURSE', id, title, summary: null, category, price: '100.00', status: 'PUBLISHED' });
  const items = [item('1', 'Excel para finanzas', 'Datos'), item('2', 'Power BI desde cero', 'Datos'), item('3', 'Marketing digital', 'Marketing')];

  it('el nombre que empieza con el término va primero', () => {
    expect(searchCatalog('power', items)[0].item.id).toBe('2');
  });

  it('encuentra palabras a medio escribir y por categoría', () => {
    expect(searchCatalog('exc', items)[0].item.id).toBe('1');
    expect(searchCatalog('datos', items).map((result) => result.item.id)).toEqual(['1', '2']);
  });

  it('un alias aprendido gana a todo', () => {
    expect(searchCatalog('hojas de calculo', items, { 'hojas de calculo': ['1'] })[0].item.id).toBe('1');
  });

  it('sin término lista todo en orden alfabético', () => {
    expect(searchCatalog('', items).map((result) => result.item.title)).toEqual(['Excel para finanzas', 'Marketing digital', 'Power BI desde cero']);
  });
});

describe('aprendizaje de sinónimos', () => {
  it('aprende solo si lo buscado no está en el nombre', () => {
    expect(shouldLearn('lapicero', 'BOLÍGRAFO PILOT')).toBe(true);
    expect(shouldLearn('Hojas de cálculo', 'Excel para finanzas')).toBe(true);
    expect(shouldLearn('excel', 'Excel para finanzas')).toBe(false);
    expect(shouldLearn('a', 'Excel para finanzas')).toBe(false);
  });

  it('usa la misma clave que el servidor, sin tildes ni signos', () => {
    expect(aliasKey('¿Hojas de cálculo?')).toBe('hojas de calculo');
    expect(aliasKey('power-bi')).toBe('power bi');
  });
});

