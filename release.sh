#!/usr/bin/env bash
#
# Cierra el desarrollo y produce el instalable.
#
# Un comando hace todo lo que hay que hacer antes de entregar:
#   1. verifica la sintaxis de los PHP
#   2. corre la suite de tests dentro del contenedor
#   3. sincroniza la versión entre el plugin y el readme
#   4. genera el zip
#   5. comprueba que el zip quedó completo y no lleva basura
#
# Si algo falla en 1 o 2, no se genera nada. Un instalable roto es peor que no tener ninguno.
#
# Uso:
#   ./release.sh                     verifica y empaqueta la versión actual
#   ./release.sh --patch             2.1.0 -> 2.1.1   solo correcciones
#   ./release.sh --minor             2.1.0 -> 2.2.0   funciones nuevas
#   ./release.sh --major             2.1.0 -> 3.0.0   cambio incompatible
#   ./release.sh --version 2.2.0     fija la versión a mano
#   ./release.sh --check             solo verifica, no genera nada
#   ./release.sh --with-tests        incluye los tests en el paquete
#   ./release.sh --notes "texto"     agrega una línea al changelog de esta versión

set -euo pipefail

PROJECT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
SRC="${PROJECT_DIR}/webp-studio"
MAIN="${SRC}/webp-studio.php"
README="${SRC}/readme.txt"
CONTAINER="wpimgplugin-wordpress-1"
SERVICE="wordpress"

MODE="build"
BUMP=""
NEW_VERSION=""
NOTES=""
WITH_TESTS=""

# Single pass with shift: --version and --notes carry a value, so a plain
# `for arg in "$@"` leaves that value to be read as an unknown option.
while [ $# -gt 0 ]; do
  case "$1" in
    --patch)      MODE="bump"; BUMP="patch"; shift ;;
    --minor)      MODE="bump"; BUMP="minor"; shift ;;
    --major)      MODE="bump"; BUMP="major"; shift ;;
    --version)
      MODE="bump"
      NEW_VERSION="${2:-}"
      [ -n "$NEW_VERSION" ] || { echo "--version necesita un valor (2.2.0)" >&2; exit 2; }
      shift 2 ;;
    --notes)
      MODE="bump"
      NOTES="${2:-}"
      [ -n "$NOTES" ] || { echo "--notes necesita un valor" >&2; exit 2; }
      shift 2 ;;
    --check)      MODE="check"; shift ;;
    --with-tests) WITH_TESTS="--with-tests"; shift ;;
    -h|--help)
      sed -n '3,25p' "$0" | sed 's/^# \{0,1\}//'
      exit 0 ;;
    *) echo "Opción desconocida: $1" >&2; exit 2 ;;
  esac
done

say()  { printf '%s\n' "$*"; }
fail() { printf '\n  ✗ %s\n' "$*" >&2; exit 1; }

current_version() {
  grep -m1 ' \* Version:' "$MAIN" | sed 's/.*Version:[[:space:]]*//' | tr -d ' '
}

# -----------------------------------------------------------------
# 1. Sintaxis
# -----------------------------------------------------------------
say ""
say "── Sintaxis ─────────────────────────────────────"
lint_fail=0
while IFS= read -r file; do
  name="${file#${PROJECT_DIR}/}"
  if output=$(php -l "$file" 2>&1); then
    printf '  ok    %s\n' "$name"
  else
    printf '  FALLA %s\n' "$name"
    printf '%s\n' "$output" | sed 's/^/        /'
    lint_fail=$(( lint_fail + 1 ))
  fi
done < <( find "$SRC" -name '*.php' -type f | sort )

[ "$lint_fail" -gt 0 ] && fail "${lint_fail} archivo(s) con errores de sintaxis. No se empaqueta."
say "  $( find "$SRC" -name '*.php' -type f | wc -l ) archivos limpios"

# -----------------------------------------------------------------
# 2. Tests (solo si el contenedor está en marcha)
# -----------------------------------------------------------------
say ""
say "── Tests ────────────────────────────────────────"
if docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null | grep -q true; then
  if out=$( docker compose --project-directory "$PROJECT_DIR" -f "${PROJECT_DIR}/docker-compose.yml" \
              exec -T "$SERVICE" \
              wp eval-file /var/www/html/wp-content/plugins/webp-studio/tests/verify-runtime.php 2>&1 ); then
    summary=$( printf '%s' "$out" | grep -E '^RESULT:' || true )
    printf '  %s\n' "${summary:-  sin línea de resumen}"
    if printf '%s' "$summary" | grep -qE '[1-9][0-9]* failed'; then
      fail "La suite tiene fallos. No se empaqueta."
    fi
  else
    fail "Los tests no se pudieron ejecutar. No se empaqueta."
  fi
else
  say "  (contenedor apagado: los tests se saltan)"
  say "  Para verlos: docker compose up -d wordpress && ./release.sh --check"
fi

# -----------------------------------------------------------------
# 3. Versión
# -----------------------------------------------------------------
CUR="$( current_version )"
say ""
say "── Versión ─────────────────────────────────────"

if [ "$MODE" = "check" ]; then
  readme_ver=$( grep -m1 '^Stable tag:' "$README" | sed 's/.*tag:[[:space:]]*//' | tr -d ' ' )
  say "  plugin : ${CUR}"
  say "  readme : ${readme_ver}"
  [ "$CUR" = "$readme_ver" ] || fail "El plugin dice ${CUR} y el readme dice ${readme_ver}."
  say "  ok, sincronizadas"
else
  if [ -z "$NEW_VERSION" ]; then
    IFS='.' read -r ma mi pa <<< "$CUR"
    case "$BUMP" in
      major) NEW_VERSION="$(( ma + 1 )).0.0" ;;
      minor) NEW_VERSION="${ma}.$(( mi + 1 )).0" ;;
      patch) NEW_VERSION="${ma}.${mi}.$(( pa + 1 ))" ;;
      *)     NEW_VERSION="$CUR" ;;
    esac
  fi

  if ! printf '%s' "$NEW_VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    fail "Versión inválida: ${NEW_VERSION}. Se espera algo como 2.1.0"
  fi
  if [ "$NEW_VERSION" = "$CUR" ]; then
    say "  sin cambios: ${CUR}"
  else
    sed -i "s/^ \* Version:[[:space:]]*.*/ * Version:           ${NEW_VERSION}/" "$MAIN"
    sed -i "s/^Stable tag:.*/Stable tag: ${NEW_VERSION}/" "$README"
    cp "$README" "${PROJECT_DIR}/readme.txt"
    say "  ${CUR}  ->  ${NEW_VERSION}"
  fi

  # Encabezado de changelog, para que quede ordenada al revés como en wordpress.org.
  if [ -n "$NOTES" ] && [ "$NEW_VERSION" != "$CUR" ]; then
    python3 - "$README" "$NEW_VERSION" "$NOTES" <<'PY'
import sys
path, version, note = sys.argv[1], sys.argv[2], sys.argv[3]
text = open(path, encoding='utf-8').read()
marker = '== Changelog ==\n'
if marker in text:
    head, _, tail = text.partition(marker)
    text = head + marker + '\n= %s =\n* %s\n' % (version, note) + tail
    open(path, 'w', encoding='utf-8').write(text)
else:
    text += '\n\n== Changelog ==\n\n= %s =\n* %s\n' % (version, note)
    open(path, 'w', encoding='utf-8').write(text)
PY
    cp "$README" "${PROJECT_DIR}/readme.txt"
    say "  changelog: ${NOTES}"
  fi
fi

[ "$MODE" = "check" ] && { say ""; say "  Verificación completa. Nada que hacer."; exit 0; }

# -----------------------------------------------------------------
# 4 y 5. Empaquetar y verificar
# -----------------------------------------------------------------
say ""
say "── Paquete ─────────────────────────────────────"
"${PROJECT_DIR}/build-zip.sh" $WITH_TESTS >/dev/null || fail "build-zip.sh falló."
ZIP="${PROJECT_DIR}/webp-studio.zip"

# Lo que tiene que estar sí o sí.
required="webp-studio/webp-studio.php
webp-studio/readme.txt
webp-studio/uninstall.php
webp-studio/includes/class-wp-image-webp-converter-admin.php
webp-studio/includes/class-wp-image-webp-converter-queue.php
webp-studio/assets/css/webp-studio-admin.css
webp-studio/assets/js/webp-studio-admin.js"

contents=$( unzip -Z1 "$ZIP" )
missing=""
while IFS= read -r item; do
  [ -z "$item" ] && continue
  printf '%s' "$contents" | grep -qxF "$item" || missing="${missing}${item}"$'\n'
done <<< "$required"

if [ -n "$missing" ]; then
  say ""
  say "  El zip no tiene:"
  printf '%s' "$missing" | sed 's/^/    /'
  fail "Paquete incompleto."
fi
say "  7 archivos obligatorios presentes"

# Lo que no tiene que estar.
junk=$( printf '%s' "$contents" | grep -E '\.env|\.DS_Store|\.git/|node_modules|composer\.(json|lock)|\.zip$' || true )
[ -n "$junk" ] && fail "El zip trae archivos que no deberían:${junk}\n$( printf '%s' "$junk" | sed 's/^/    /' )"
say "  sin archivos de desarrollo ni secretos"

size=$( du -h "$ZIP" | cut -f1 )
say ""
say "── Listo ───────────────────────────────────────"
say "  webp-studio.zip   ${size}   versión $( current_version )"
say ""
say "  Instalarlo:"
say "    Panel de WordPress  ->  Plugins > Añadir > Subir plugin"
say "    SSH                 ->  unzip webp-studio.zip en wp-content/plugins/"
say "                         wp plugin activate webp-studio --path=/var/www/html"
say ""