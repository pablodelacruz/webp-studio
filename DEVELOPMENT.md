# WebP Studio — guía de desarrollo e instalación

Este repositorio contiene dos cosas distintas que conviene no mezclar:

| | Qué es | Para quién |
|---|---|---|
| `webp-studio/` | **El plugin.** Código fuente PHP/JS/CSS. | Desarrollo |
| `webp-studio.zip` | **El plugin empaquetado.** Es lo que se instala. | Producción |

El zip se genera desde el directorio. **Nunca edites el zip**, edita `webp-studio/` y vuelve a
empaquetar.

---

## 1. Levantar el entorno de desarrollo

Un solo comando:

```bash
./reset-rebuild.sh
```

Eso reconstruye la imagen, borra los volúmenes Docker (de ahí que la biblioteca empiece vacía),
instala WordPress, activa el plugin e importa las imágenes semilla.

**Necesitás Docker y Docker Compose.** Nada más.

Lo que hace, en orden:

1. `docker compose down -v` — borra los volúmenes. Es destructivo **a propósito**.
2. Reconstruye la imagen e instala el plugin desde `webp-studio.zip`.
3. Genera `wp-config.php` con salts aleatorios a partir de `.env`.
4. Instala WordPress con el usuario y contraseña de `env-template.txt`.
5. Activa Redis Object Cache.
6. Activa el plugin **webp-studio**.
7. Importa las 12 imágenes semilla, ya convertidas a WebP.

### Cuándo correrlo

- Cambiaste código del plugin y querés un entorno limpio.
- Algo quedó en estado raro.
- Probás una instalación desde cero.

**No lo corras si estás viendo contenido real en el sitio de desarrollo**: el volumen se
borra entero.

### Volumen destruido, ¿y las imágenes?

Las 12 semilla se regeneran solas. Si además querés importar el set extra de
`seed-images/user-uploads/`:

```bash
./seed-images/seed.sh --with-user
```

Importa las 38 imágenes de `seed-images/user-uploads/`. Ojo con esto: **ese directorio no
se trackea en git**, así que un clone nuevo llega sin él y el comando no encuentra nada que
importar. Ahora te avisa en vez de fingir que funcionó, pero el set sigue siendo tuyo: copiá
tus fixtures a `seed-images/user-uploads/` antes de correrlo.

---

## 2. Iterar en desarrollo

Durante el desarrollo **no necesitás re-ejecutar `reset-rebuild.sh`**. El directorio del
plugin está montado dentro del contenedor:

```
./webp-studio  →  /var/www/html/wp-content/plugins/webp-studio   (solo lectura)
```

Edrás un `.php`, guardás, y el cambio ya está activo. Para recargar PHP en cada petición,
reiniciá el contenedor:

```bash
docker compose restart wordpress
```

Y para correr los tests:

```bash
docker compose exec -T wordpress \
  wp eval-file /var/www/html/wp-content/plugins/webp-studio/tests/verify-runtime.php
```

Debe terminar con `RESULT: 178 passed, 0 failed`.

---

## 3. Empaquetar el plugin

```bash
./build-zip.sh
```

Verifica la sintaxis de los 4 archivos PHP y **no construye nada si algo falla**. Después
genera `webp-studio.zip`.

Para comprobar si el zip quedó viejo:

```bash
./build-zip.sh --check
```

Sale con código 1 si hay archivos más nuevos que el zip. Conviene correrlo antes de desplegar.

---

## 4. Instalar en un servidor nuevo

### Lo que se lleva

Solo el zip. **No** el repositorio, ni el `docker-compose.yml`, ni `.env`.

```
webp-studio.zip
```

Son 108 KB y no depende de nada externo: ni CDN, ni Composer, ni librerías de terceros.
Requiere WordPress 5.0+ y PHP 7.0+.

### Método A — por el panel de WordPress (el más simple)

1. Entra a **Plugins → Añadir plugin → Subir plugin**.
2. Elegís `webp-studio.zip`.
3. **Instalar ahora** y **Activar**.

Listo. No hay nada que configurar más.

### Método B — por SSH o FTP

```bash
# Descomprimir directamente en la carpeta de plugins
cd /var/www/html/wp-content/plugins
unzip -p /ruta/al/webp-studio.zip > /dev/null   # solo para verificar
unzip /ruta/al/webp-studio.zip
wp plugin activate webp-studio --path=/var/www/html
```

El zip contiene una carpeta `webp-studio/`. **El nombre de la carpeta importa**: tiene que
coincidir con el slug del plugin o WordPress no lo va a reconocer.

### Método C — por WP-CLI

```bash
wp plugin install /ruta/al/webp-studio.zip --activate --path=/var/www/html
```

### Verificar que quedó bien

```bash
wp plugin list --path=/var/www/html | grep webp
```

Debe decir `webp-studio  active`.

---

## 5. Configuración inicial

En **Ajustes → WebP Studio**:

| Opción | Por defecto | Qué hace |
|---|---|---|
| Convertir al subir | Activado | Cada JPEG/PNG/GIF nuevo se convierte a WebP |
| Calidad | 80 | Medido en imágenes reales: ahorra ~40% |
| Eliminar original | Activado | Verificado y diferido, nunca en el acto (ver abajo) |
| Registro de actividad | Activado | Cada conversión y cada omisión, con su motivo |

### Sobre "Eliminar imagen original"

Es la opción que más conviene entender antes de activarla en producción.

WordPress guarda las URLs de imagen como **texto plano** dentro del contenido de los posts.
Una imagen pegada en un artículo sigue apuntando a su `.jpg` aunque el plugin diga que ya es
WebP. Por eso el plugin **nunca borra en el acto**:

```
1. Convertir        → genera el .webp, el original QUEDA
2. Escanear         → registra qué sigue referenciándose
3. Reescribir       → el contenido pasa a apuntar al .webp
4. Verificar y borrar → solo ahora, y solo si puede demostrarlo
```

Si el paso 2 o 3 no se corrieron, el paso 4 **no borra nada** y te dice por qué.

Si preferís no complicarte: **desactivá esa opción**. Los dos archivos conviven, nada se rompe
nunca, y a cambio pagás disco. Medido en imágenes reales: ~40% más de disco a calidad 80,
~47% a calidad 70.

### Si el servidor no puede generar WebP

El plugin lo detecta y lo dice en la pantalla en vez de fallar en silencio. Necesita que GD o
Imagick tengan soporte WebP.

---

## 6. Cosas que este repositorio tiene y no van a producción

| Qué es | Por qué está |
|---|---|
| `docker-compose.yml`, `Dockerfile`, `.env`, `wp-config.php` | Entorno de desarrollo |
| `reset-rebuild.sh`, `build-zip.sh`, `seed-images/` | Reproducibilidad |
| `analysis/` | Conectores de solo lectura a bases de datos reales |
| `seed-images/user-uploads/` | Fixtures operator-locales, 15 MB, no trackeados en git |

Nada de esto está en `webp-studio.zip`. El zip contiene solo el plugin.

---

## 7. Seguridad

- `.env` y `wp-config.php` están en `.gitignore`. `wp-config.php` se genera con salts
  aleatorios: **nunca lo commitees**.
- `analysis/.env` contiene credenciales de staging. Está ignorado y con permisos `600`.
- Las imágenes semilla vienen de [Lorem Picsum](https://picsum.photos) (fotos de Unsplash,
  licencia que permite uso libre pero no redistribución comercial). Si vas a publicar,
  reemplazalas por material propio.