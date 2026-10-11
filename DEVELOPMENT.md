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

### El pipeline automático

No hace falta compilar ni subir nada a mano. Publicás con un tag:

```bash
./release.sh --patch --notes "lo que cambia en esta versión"
git push origin main
git push origin vX.Y.Z
```

Y a partir del tag, el workflow hace:

```
tag vX.Y.Z
  └─ 1. Skylos (gate)      ← si falla, el job muere y NO se publica nada
       └─ 2. build-zip.sh
            └─ 3. release publicado con el zip adjunto
```

Lo define `.github/workflows/release.yml`. Cuatro cosas que conviene saber:

**Lo dispara un tag, no un push a `main`.** La protección de rama pide que los cambios
lleguen por pull request. Con el release atado a `main` habría que elegir entre dejar la
protección en modo consultivo o saltarse el gate. Con tag, publicar no toca `main`.

**El tag manda, y se verifica.** Antes de construir, el workflow compara el tag contra
el header `Version:` del plugin. Si no coinciden, el job falla en vez de publicar un
release mal etiquetado. Por eso `release.sh` sigue siendo la única forma de cambiar la
versión: si editás el header a mano, el tag va a quedar desfasado y el workflow te va a
avisar con un error claro.

**El análisis va antes que el empaquetado, a propósito.** Publicar un artefacto que no
pasó el análisis sería peor que no publicar.

**Las notas salen de tu changelog** en `webp-studio/readme.txt`, y solo caen a notas
autogeneradas si esa versión todavía no tiene sección.

Podés ver el resultado en la pestaña Actions del repo.

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

### Estado en GitHub

Habilitado en el repositorio:

| Función | Qué hace |
|---|---|
| Protección de `main` | Exige 1 revisión en PRs. Los admins pueden pushear directo (`enforce_admins` desactivado). |
| Secret scanning + push protection | Bloquea el push si detecta credenciales. |
| Dependabot | Alertas y actualizaciones de seguridad. |
| CodeQL | Análisis estático. |
| Private vulnerability reporting | Reportes privados en `/security/advisories/new`. |

Dos cosas que conviene saber, porque pueden dar una falsa sensación de cobertura:

**CodeQL no puede analizar PHP.** No es algo que se pueda configurar: GitHub lo dice
explícitamente, "CodeQL does not support languages that are not listed above. This
includes, but is not limited to, PHP", y descarta en silencio los lenguajes no
soportados. Si ponés `php` en la matriz de un workflow propio, no da error: el job
simplemente no aparece. Acá CodeQL sólo cubre `javascript-typescript` y `actions`.

Por lo tanto los **cero alertas en PHP no significan que esté limpio: significan que
nadie lo miró con un analizador**. No hay migracion que lo arregle.

**Skylos es el único análisis estático sobre el PHP**, y su gate bloquea la
publicación. Eso lo vuelve estructural: si Skylos deja de correr, el plugin queda sin
análisis de seguridad en el pipeline.

**Skylos sí cubre PHP**, con dead-code y security. `quality` no está soportado para
PHP, así que no lo pide. Los falsos positivos que se detectaron a mano están
suprimidos en `pyproject.toml`, con el motivo escrito al lado de cada uno. Ojo:
suprimir `SKY-D215` desactiva en todo el repo la detección de borrado arbitrario de
archivos, que es la clase de bug que este plugin ya tuvo una vez.

### Para reportar una vulnerabilidad

https://github.com/pablodelacruz/webp-studio/security/advisories/new