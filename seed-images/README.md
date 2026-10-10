# Imágenes semilla

Imágenes de relleno para que el entorno de desarrollo arranque siempre con contenido, en
lugar de una biblioteca vacía. `reset-rebuild.sh` las importa automáticamente después de cada
reinicio, porque el script borra los volúmenes Docker y con ellos la carpeta `uploads/`.

## Origen y licencia

| Archivo | Origen | Licencia |
| --- | --- | --- |
| `01-paisaje.jpg` … `10-texto.jpg` | [Lorem Picsum](https://picsum.photos), que sirve fotos de Unsplash por ID fijo | [Unsplash License](https://unsplash.com/license): uso gratuito, sin atribución obligatoria, sin uso comercial directo |
| `11-logo.png` | Generada con GD para este repositorio | propia del proyecto |
| `12-icono.gif` | Generada con GD para este repositorio | propia del proyecto |

Las fotos de Picsum son imágenes reales de Unsplash con licencia libre. Si este proyecto llegara
a publicarse, conviene sustituirlas por material propio o de dominio público, porque la
licencia de Unsplash no cubre la redistribución como producto.

Los IDs de Picsum son fijos (`/id/1015/1200/800`), así que el contenido es reproducible: volver
a descargarlos da los mismos archivos.

## Por qué hay formatos distintos

El conversor a WebP trata cada formato de forma distinta, y el conjunto está elegido para
ejercitar esos caminos:

- **JPEG** (10 archivos): ruta normal, imagen truecolor.
- **PNG con canal alfa** (`11-logo.png`): comprueba que la transparencia sobrevive a WebP.
- **GIF de paleta** (`12-icono.gif`): GD no puede codificar imágenes de paleta a WebP y
  escribe un archivo de 0 bytes. El plugin las promueve a truecolor antes de convertir; sin
  ese camino, este archivo fallaría.

## Qué hace `reset-rebuild.sh` con ellas

Las copia al contenedor y las importa como adjuntos con `wp media import`. Después quedan en
WordPress como attachments normales, indistinguibles de las que subiría un usuario.

Para reimportarlas sin reiniciar todo:

```bash
./seed-images/seed.sh
```

Para regenerarlas desde cero:

```bash
./seed-images/seed.sh --download
```