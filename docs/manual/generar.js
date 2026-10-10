// Genera el manual de uso por rol en Word a partir de contenido.js.
// Uso: npm install (una sola vez) y luego `node generar.js`.
const fs = require('fs');
const path = require('path');
const {
  AlignmentType,
  BorderStyle,
  Document,
  Footer,
  Header,
  HeadingLevel,
  LevelFormat,
  PageBreak,
  PageNumber,
  Packer,
  Paragraph,
  ShadingType,
  Table,
  TableCell,
  TableOfContents,
  TableRow,
  TextRun,
  WidthType,
} = require('docx');

const manual = require('./contenido');

const AZUL = '084586';
const CELESTE = 'E8F1FB';
const GRIS = '475569';
// Ancho útil de una hoja A4 con márgenes de 2,5 cm, en DXA
const ANCHO = 9026;
const FUENTE = 'Calibri';

const borde = { style: BorderStyle.SINGLE, size: 4, color: 'CBD5E1' };
const bordes = { top: borde, bottom: borde, left: borde, right: borde };

const texto = (contenido, opciones = {}) => new TextRun({ text: contenido, font: FUENTE, size: 21, ...opciones });

function parrafo(contenido, opciones = {}) {
  return new Paragraph({ spacing: { after: 140, line: 300 }, children: [texto(contenido)], ...opciones });
}

function celda(contenido, ancho, cabecera) {
  return new TableCell({
    width: { size: ancho, type: WidthType.DXA },
    borders: bordes,
    margins: { top: 80, bottom: 80, left: 110, right: 110 },
    shading: cabecera ? { type: ShadingType.CLEAR, color: 'auto', fill: AZUL } : undefined,
    children: [new Paragraph({ children: [texto(contenido, cabecera ? { bold: true, color: 'FFFFFF', size: 20 } : { size: 20 })] })],
  });
}

function tabla(cabeceras, filas, porcentajes) {
  const anchos = (porcentajes ?? cabeceras.map(() => 100 / cabeceras.length)).map((p) => Math.round((ANCHO * p) / 100));
  // El redondeo no debe descuadrar la suma
  anchos[anchos.length - 1] += ANCHO - anchos.reduce((a, b) => a + b, 0);

  return new Table({
    width: { size: ANCHO, type: WidthType.DXA },
    columnWidths: anchos,
    rows: [
      new TableRow({ tableHeader: true, children: cabeceras.map((c, i) => celda(c, anchos[i], true)) }),
      ...filas.map((fila) => new TableRow({ cantSplit: true, children: fila.map((c, i) => celda(c, anchos[i], false)) })),
    ],
  });
}

// Cada lista numerada necesita su propia referencia para volver a empezar en 1
let listas = 0;
const numeraciones = [
  {
    reference: 'vinetas',
    levels: [{ level: 0, format: LevelFormat.BULLET, text: '•', alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 560, hanging: 280 } } } }],
  },
];

function bloque([tipo, a, b, c]) {
  switch (tipo) {
    case 'p':
      return [parrafo(a)];
    case 'h':
      return [new Paragraph({ heading: HeadingLevel.HEADING_2, children: [new TextRun({ text: a, font: FUENTE })] })];
    case 'lista':
      return a.map((item) => new Paragraph({ numbering: { reference: 'vinetas', level: 0 }, spacing: { after: 80, line: 290 }, children: [texto(item)] }));
    case 'pasos': {
      const reference = `pasos-${++listas}`;
      numeraciones.push({
        reference,
        levels: [{ level: 0, format: LevelFormat.DECIMAL, text: '%1.', alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 560, hanging: 360 } } } }],
      });
      return a.map((item) => new Paragraph({ numbering: { reference, level: 0 }, spacing: { after: 80, line: 290 }, children: [texto(item)] }));
    }
    case 'nota':
      return [
        new Paragraph({
          spacing: { before: 120, after: 200, line: 290 },
          indent: { left: 200, right: 200 },
          shading: { type: ShadingType.CLEAR, color: 'auto', fill: CELESTE },
          border: { left: { style: BorderStyle.SINGLE, size: 18, color: AZUL, space: 8 } },
          children: [texto('Nota. ', { bold: true, color: AZUL }), texto(a)],
        }),
      ];
    case 'tabla':
      return [tabla(a, b, c), new Paragraph({ spacing: { after: 160 }, children: [] })];
    default:
      throw new Error(`Bloque desconocido: ${tipo}`);
  }
}

const portada = [
  new Paragraph({ spacing: { before: 3200 }, children: [] }),
  new Paragraph({ spacing: { after: 200 }, children: [texto('IntelectaLab', { bold: true, color: AZUL, size: 30 })] }),
  new Paragraph({
    spacing: { after: 240 },
    border: { bottom: { style: BorderStyle.SINGLE, size: 12, color: AZUL, space: 10 } },
    children: [texto(manual.titulo, { bold: true, size: 64, color: '0F172A' })],
  }),
  new Paragraph({ spacing: { after: 1200 }, children: [texto(manual.producto, { size: 26, color: GRIS })] }),
  new Paragraph({ spacing: { after: 80 }, children: [texto(`Versión ${manual.version}`, { bold: true, size: 24 })] }),
  new Paragraph({ children: [texto(manual.fecha, { size: 24, color: GRIS })] }),
  new Paragraph({ children: [new PageBreak()] }),
];

const control = [
  new Paragraph({ heading: HeadingLevel.HEADING_2, children: [new TextRun({ text: 'Historial de versiones', font: FUENTE })] }),
  parrafo('Este manual se actualiza con cada cambio del sistema. La tabla indica qué cubre cada versión.'),
  tabla(['Versión', 'Fecha', 'Qué cambió'], manual.historial, [14, 18, 68]),
  new Paragraph({ spacing: { before: 400 }, heading: HeadingLevel.HEADING_2, children: [new TextRun({ text: 'Contenido', font: FUENTE })] }),
  new TableOfContents('Contenido', { hyperlink: true, headingStyleRange: '1-2' }),
  parrafo('Si el índice aparece vacío al abrir el documento, haz clic derecho sobre él y elige «Actualizar campo».', { spacing: { before: 200 } }),
];

const cuerpo = manual.capitulos.flatMap((capitulo, indice) => [
  new Paragraph({ heading: HeadingLevel.HEADING_1, pageBreakBefore: true, children: [new TextRun({ text: `${indice + 1}. ${capitulo.titulo}`, font: FUENTE })] }),
  ...capitulo.bloques.flatMap(bloque),
]);

const documento = new Document({
  creator: 'IntelectaLab',
  title: manual.titulo,
  description: manual.producto,
  features: { updateFields: true },
  styles: {
    default: { document: { run: { font: FUENTE, size: 21 } } },
    paragraphStyles: [
      { id: 'Heading1', name: 'Heading 1', basedOn: 'Normal', next: 'Normal', quickFormat: true, run: { font: FUENTE, size: 36, bold: true, color: AZUL }, paragraph: { spacing: { after: 240 }, outlineLevel: 0 } },
      { id: 'Heading2', name: 'Heading 2', basedOn: 'Normal', next: 'Normal', quickFormat: true, run: { font: FUENTE, size: 26, bold: true, color: '0F172A' }, paragraph: { spacing: { before: 320, after: 140 }, keepNext: true, outlineLevel: 1 } },
    ],
  },
  numbering: { config: numeraciones },
  sections: [
    {
      properties: { page: { size: { width: 11906, height: 16838 }, margin: { top: 1440, bottom: 1440, left: 1440, right: 1440 } } },
      headers: {
        default: new Header({
          children: [new Paragraph({ alignment: AlignmentType.RIGHT, children: [texto(`${manual.titulo} · ${manual.producto}`, { size: 16, color: GRIS })] })],
        }),
      },
      footers: {
        default: new Footer({
          children: [
            new Paragraph({
              alignment: AlignmentType.CENTER,
              children: [texto(`Versión ${manual.version} · Página `, { size: 16, color: GRIS }), new TextRun({ children: [PageNumber.CURRENT], font: FUENTE, size: 16, color: GRIS })],
            }),
          ],
        }),
      },
      children: [...portada, ...control, ...cuerpo],
    },
  ],
});

const destino = path.join(__dirname, '..', 'manual-de-uso-por-rol.docx');

Packer.toBuffer(documento).then((buffer) => {
  fs.writeFileSync(destino, buffer);
  console.log(`Manual generado: ${destino} (${manual.capitulos.length} capítulos, versión ${manual.version})`);
});
