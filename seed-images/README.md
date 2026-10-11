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

## `user-uploads/`: fixtures locales, no reproducibles

Las 12 imágenes de la raíz son el set canónico: reproducibles, con procedencia y licencia
documentadas arriba. `user-uploads/` es otra cosa y conviene no confundirla.

Son 38 imágenes (15 MB) que **no se trackean en git** (ver `.gitignore`). No entran al
repositorio por peso y por procedencia mixta:

- 19 capturas de una aplicación, con numeración propia (`01-login.jpg` … `18-seed-detalle.jpg`).
- 19 con nombre de descarga de Unsplash o Wallhaven (`wallhaven-*`, `*-unsplash-scaled.jpg`,
  `big-test-scaled.jpg`).

**No se pueden volver a descargar.** Aunque los nombres del segundo grupo sugieran que
basta con guardar las URLs, los 38 archivos son recodificaciones de GD
(`gd-jpeg v1.0, quality = 92`), no los originales: parecen exportados desde WordPress.
Bajarlos de Unsplash otra vez devuelve bytes distintos —distinta compresión, distintas
dimensiones, distinto hash—, así que un manifiesto de URLs no reproduce lo que hay hoy.

### Qué aportan que las 12 base no cubren

No es el mismo eje:

- Las **12 base** ejercitan *formatos*: JPEG, PNG con canal alfa y GIF de paleta, que son
  las ramas de conversión del plugin.
- **`user-uploads/`** aporta *volumen y realismo*: archivos de hasta 1,6 MB frente a ~180 KB
  de media, y **17 de ellos con el sufijo `-scaled`**, que es exactamente el nombre que
  WordPress genera al escalar una subida. Eso simula el caso "imagen ya escalada por
  WordPress", un camino real de regresión.

Por eso el set extra se importa solo bajo demanda:

```bash
./seed-images/seed.sh --with-user
```

En un clone nuevo la carpeta no existe y el comando avisa en vez de importar el set base
en silencio. Para usarlo, copiá tus fixtures a `seed-images/user-uploads/` antes.

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