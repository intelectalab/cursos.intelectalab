/**
 * Colores de cada academia. La hoja de estilos usa dos paletas (brand-* y
 * accent-*, en index.css); aquí se arman sus tonos a partir de un color y se
 * reemplazan en toda la aplicación. Sin colores propios quedan los de IntelectaLab.
 */

export interface Appearance {
  primary: string | null;
  accent: string | null;
  login_title: string | null;
  login_text: string | null;
  login_background_url: string | null;
  // Pie del sitio público: texto (dirección, RUC…), teléfono, WhatsApp y redes sociales
  footer: SiteFooter;
}

export const SOCIAL_NETWORKS = ['facebook', 'instagram', 'tiktok', 'youtube', 'linkedin'] as const;
export type SocialNetwork = (typeof SOCIAL_NETWORKS)[number];

export const SOCIAL_LABEL: Record<SocialNetwork, string> = { facebook: 'Facebook', instagram: 'Instagram', tiktok: 'TikTok', youtube: 'YouTube', linkedin: 'LinkedIn' };

export interface SiteFooter {
  text: string | null;
  phone: string | null;
  // Solo dígitos, con el código del país
  whatsapp: string | null;
  social: Partial<Record<SocialNetwork, string>>;
}

type Rgb = [number, number, number];

const SHADES = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950] as const;

// Cuánto del color va en cada tono: hasta el tono base se mezcla con blanco; después, con negro
const PRIMARY_MIX: Record<number, number> = { 50: 0.06, 100: 0.14, 200: 0.28, 300: 0.46, 400: 0.66, 500: 0.82, 600: 0.92, 700: 1, 800: -0.28, 900: -0.52, 950: -0.66 };
const ACCENT_MIX: Record<number, number> = { 50: 0.07, 100: 0.14, 200: 0.3, 300: 0.52, 400: 0.76, 500: 1, 600: -0.15, 700: -0.3, 800: -0.45, 900: -0.58, 950: -0.72 };

/** Contraste mínimo del texto blanco sobre el color principal (WCAG AA); lo exige también el servidor */
export const MIN_CONTRAST = 4.5;

export const DEFAULT_PRIMARY = '#084586';
export const DEFAULT_ACCENT = '#0ea5e9';

export const isHex = (value: string) => /^#[0-9a-fA-F]{6}$/.test(value);

const toRgb = (hex: string): Rgb => [1, 3, 5].map((start) => parseInt(hex.slice(start, start + 2), 16)) as Rgb;
const toHex = (rgb: Rgb) => `#${rgb.map((channel) => Math.round(Math.min(255, Math.max(0, channel))).toString(16).padStart(2, '0')).join('')}`;

function mix(hex: string, amount: number): string {
  const rgb = toRgb(hex);
  // Positivo: el color sobre blanco. Negativo: el color oscurecido hacia negro
  return toHex(amount >= 0 ? (rgb.map((channel) => 255 + (channel - 255) * amount) as Rgb) : (rgb.map((channel) => channel * (1 + amount)) as Rgb));
}

function luminance(hex: string): number {
  const [r, g, b] = toRgb(hex).map((channel) => {
    const value = channel / 255;
    return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

export const contrastWithWhite = (hex: string) => 1.05 / (luminance(hex) + 0.05);

/** El color, o uno más oscuro del mismo tono si el texto blanco no se lee bien encima. */
export function readable(hex: string): string {
  let rgb = toRgb(hex.toLowerCase());
  for (let step = 0; step < 40 && contrastWithWhite(toHex(rgb)) < MIN_CONTRAST; step++) {
    rgb = rgb.map((channel) => Math.floor(channel * 0.94)) as Rgb;
  }
  return toHex(rgb);
}

export function palette(base: string, kind: 'brand' | 'accent'): Record<string, string> {
  const table = kind === 'brand' ? PRIMARY_MIX : ACCENT_MIX;
  return Object.fromEntries(SHADES.map((shade) => [`--color-${kind}-${shade}`, mix(base, table[shade])]));
}

/** Pone (o quita, con null) los colores de la academia en toda la aplicación. */
export function applyTheme(primary: string | null, accent: string | null): void {
  const style = document.documentElement.style;
  for (const kind of ['brand', 'accent'] as const) {
    const base = kind === 'brand' ? primary : accent;
    const values = base && isHex(base) ? palette(base, kind) : null;
    for (const shade of SHADES) {
      const name = `--color-${kind}-${shade}`;
      if (values) style.setProperty(name, values[name]);
      else style.removeProperty(name);
    }
  }
}

const hue = (rgb: Rgb) => {
  const [r, g, b] = rgb.map((channel) => channel / 255);
  const max = Math.max(r, g, b);
  const min = Math.min(r, g, b);
  if (max === min) return 0;
  const delta = max - min;
  const value = max === r ? ((g - b) / delta) % 6 : max === g ? (b - r) / delta + 2 : (r - g) / delta + 4;
  return (value * 60 + 360) % 360;
};

const saturation = (rgb: Rgb) => {
  const max = Math.max(...rgb);
  const min = Math.min(...rgb);
  return max === 0 ? 0 : (max - min) / max;
};

/**
 * Los colores con más presencia en el logo, del más usado al menos usado.
 * Descarta el fondo (casi blanco, casi negro o transparente) y los grises.
 */
export async function logoColors(url: string, count = 4): Promise<string[]> {
  const image = new Image();
  image.crossOrigin = 'anonymous';
  image.src = url;
  await image.decode();

  const size = 64;
  const canvas = document.createElement('canvas');
  canvas.width = size;
  canvas.height = size;
  const context = canvas.getContext('2d', { willReadFrequently: true });
  if (!context) return [];
  context.drawImage(image, 0, 0, size, size);
  const { data } = context.getImageData(0, 0, size, size);

  // Grupos de colores parecidos: se cuentan los píxeles y se promedia cada grupo
  const buckets = new Map<string, { total: number; sum: Rgb }>();
  for (let index = 0; index < data.length; index += 4) {
    const rgb: Rgb = [data[index], data[index + 1], data[index + 2]];
    const light = (Math.max(...rgb) + Math.min(...rgb)) / 2;
    if (data[index + 3] < 200 || light > 240 || light < 18 || saturation(rgb) < 0.18) continue;
    const key = rgb.map((channel) => channel >> 5).join(',');
    const bucket = buckets.get(key) ?? { total: 0, sum: [0, 0, 0] };
    bucket.total += 1;
    bucket.sum = bucket.sum.map((value, channel) => value + rgb[channel]) as Rgb;
    buckets.set(key, bucket);
  }

  const colors: Rgb[] = [];
  for (const bucket of [...buckets.values()].sort((a, b) => b.total - a.total)) {
    const rgb = bucket.sum.map((value) => value / bucket.total) as Rgb;
    // Solo colores distintos entre sí (otro tono)
    if (colors.every((other) => Math.min(Math.abs(hue(other) - hue(rgb)), 360 - Math.abs(hue(other) - hue(rgb))) > 24)) colors.push(rgb);
    if (colors.length >= count) break;
  }
  return colors.map(toHex);
}

/**
 * Hasta tres combinaciones de principal y acento con los colores del logo.
 * El principal se oscurece si hace falta para que el texto blanco se lea.
 */
export function suggestions(colors: string[]): { primary: string; accent: string }[] {
  if (colors.length === 0) return [];
  const [first, second = mix(colors[0], 0.6), third] = colors;
  const options = [
    { primary: readable(first), accent: second },
    { primary: readable(second), accent: first },
    { primary: readable(mix(first, -0.4)), accent: third ?? first },
  ];
  // Sin combinaciones repetidas
  return options.filter((option, index) => options.findIndex((other) => other.primary === option.primary && other.accent === option.accent) === index);
}
