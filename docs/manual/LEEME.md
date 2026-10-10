# Manual de uso por rol

El manual que se entrega es `docs/manual-de-uso-por-rol.docx`. No se edita a mano: se genera desde esta carpeta.

- `contenido.js`: todo el texto del manual, por capítulos (uno por rol).
- `generar.js`: arma el documento de Word con ese contenido.

## Actualizarlo

Con cada cambio del sistema que afecte lo que ve o hace una persona:

1. Edita `contenido.js`: corrige los pasos del rol afectado.
2. Sube `version`, cambia `fecha` y agrega una fila a `historial` con lo que cambió.
3. Genera el documento:

```bash
cd docs/manual
npm install   # solo la primera vez
node generar.js
```

Si una función deja de estar pendiente, quítala del último capítulo («Lo que todavía no está disponible»).
