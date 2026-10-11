# AGENTS.md

Guidance for AI agents and developers working in this repository. Read this before
changing anything.

## Qué es esto

Plugin de WordPress que convierte imágenes subidas a WebP con un runner en segundo
plano. PHP puro, sin dependencias de Composer, sin build step. `webp-studio/` es el
plugin distribuible; todo lo demás es andamiaje de desarrollo.

## El pipeline: un push a `main` publica un release

```
git push a main
  └─ 1. Skylos (gate)      ← si falla, el job muere y NO se publica nada
       └─ 2. build-zip.sh
            └─ 3. release publicado con el zip adjunto
```

Definido en `.github/workflows/release.yml`. Las piezas que importan:

- **El gate va primero a propósito.** Publicar un artefacto que no pasó el análisis
  es peor que no publicar.
- **La versión se lee del header del plugin**, no de un tag ni del número de run.
  `webp-studio/webp-studio.php` → `Version: 2.1.3` → tag `v2.1.3`.
  `release.sh` sigue siendo la única fuente de verdad.
- **La publicación es idempotente.** Si el tag ya existe, reemplaza el asset con
  `--clobber` en vez de fallar. Un push sin bump de versión no rompe nada.
- **Las notas salen del changelog** de `webp-studio/readme.txt`, con fallback a notas
  autogeneradas si esa versión todavía no tiene sección.

## Reglas que no se rompen

- **Nunca commitees `.env`, `wp-config.php` ni `analysis/.env`.** `wp-config.php` lo
  genera `reset-rebuild.sh` con salts aleatorios. Secret scanning con push protection
  está activo y bloquea el push si detecta algo.
- **No uses `git add .`.** Stagea rutas exactas. Hay archivos locales grandes y no
  trackeados (`skylos/`, `seed-images/*.jpg`) que se colarían si fueras a ciegas.
- **Las actions van pineadas a SHA completo**, no a tags. Skylos reporta las
  referencias móviles como `SKY-D292`, así que con tags el gate señalaría su propio
  workflow en cada push. Para actualizar una action, cambia el SHA y el comentario.
- **`skylos/` es un clon local de una herramienta de terceros**, no una dependencia.
  Está gitignorado a propósito. Si el agente necesita auditar, ejecútalo contra un
  checkout limpio, no contra el directorio de trabajo.

## Cómo verificar el comportamiento del gate

Medir sobre el directorio de trabajo da resultados **falsos**: el clon local de
`skylos/` mete ~34 hallazgos que CI nunca verá. Para predecir CI:

```bash
git archive HEAD | tar -x -C /tmp/clean   # checkout limpio
cd /tmp/clean && skylos . --confidence 60 --danger --json > r.json
skylos cicd gate --input r.json --summary
```

Los falsos positivos conocidos están suprimidos en `pyproject.toml` con el motivo
escrito. Si los quitas, aparecen 3 hallazgos que ya se revisaron a mano.

## Comandos

```bash
./build-zip.sh              # empaqueta (verifica sintaxis PHP antes)
./build-zip.sh --check      # solo informa si el zip quedó viejo
./release.sh --check        # verifica que plugin y readme digan la misma versión
./release.sh --bump patch   # sube versión + changelog en ambos archivos
./reset-rebuild.sh          # reconstruye el stack Docker desde cero
./seed-images/seed.sh --download
```

## Trampas conocidas

- **`release.sh` mantiene dos archivos en sincronía**: el header `Version:` del plugin
  y el `Stable tag:` de `webp-studio/readme.txt`. Si subes la versión a mano en uno
  solo, `./release.sh --check` falla y el release sale con notas autogeneradas.
- **`seed-images/seed.sh --download` necesita el contenedor levantado**, porque
  regenera el PNG y el GIF con GD vía `docker exec`. Correlo después de
  `docker compose up`.
- **`seed-images/user-uploads/` no se trackea** (38 archivos, 15 MB). En un clone
  nuevo está vacío y `--with-user` avisa en vez de no-op silencioso.
- **`webp-studio.zip` no se commitea**: lo regenera `build-zip.sh`. La carpeta interna
  del zip debe seguir llamándose `webp-studio/` porque `docker-compose.yml` monta
  esa ruta exacta; renombrarla hace que Docker cree un directorio vacío en silencio.
- **`pyproject.toml` no es un proyecto Python.** Existe solo por
  `[tool.skylos] ignore`. Efecto colateral: GitHub intenta construir el grafo de
  dependencias pip y no encuentra nada.
- **La rama por defecto es `main`.** hay protección de rama con 1 revisión requerida,
  pero `enforce_admins` está desactivado, así que los admins pueden pushear directo.

## Estado de seguridad del repositorio

Habilitado: protección de `main`, private vulnerability reporting, secret scanning con
push protection, Dependabot, y CodeQL.

Pendiente: **CodeQL no está analizando PHP.** El default setup de GitHub detectó
`javascript-typescript` y `actions`, pero no `php`, que es el 90% del plugin. Los
cero alertas en PHP no significan que esté limpio, significan que nunca se escaneó.
Skylos sí cubre PHP (dead-code y security; `quality` no está soportado para PHP).