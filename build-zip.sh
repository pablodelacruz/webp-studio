#!/usr/bin/env bash
#
# Construye el archivo que carga el Dockerfile.
#
# Existe porque el zip anterior se quedó obsoleto en silencio: tinha los fixes de
# la sesion anterior, no los de hoy, y nada avisaba. Si reconstruias el stack con
# ese zip instalabas el plugin roto. Este script ademas verifica el lint antes de
# empaquetar, para que un error de sintaxis nunca llegue a la imagen.
#
# El archivo se llama webp-studio.zip, pero la carpeta de DENTRO sigue llamandose
# webp-studio/ a proposito: docker-compose.yml monta esa ruta exacta
# sobre el contenedor. Renombrar la carpeta haria que el montaje apunte a un
# directorio inexistente y Docker crearia uno vacio en silencio.
#
# Uso:
#   ./build-zip.sh            construye el archivo
#   ./build-zip.sh --check    solo informa si el archivo esta desactualizado

set -euo pipefail

PROJECT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
SRC_DIR="${PROJECT_DIR}/webp-studio"
ZIP_PATH="${PROJECT_DIR}/webp-studio.zip"

if [ ! -d "$SRC_DIR" ]; then
  echo "No existe el directorio del plugin: $SRC_DIR" >&2
  exit 1
fi

# -----------------------------------------------------------------
# Opciones
# -----------------------------------------------------------------
WITH_TESTS=0
case "${1:-}" in
  --check)     ACTION=check ;;
  --with-tests) WITH_TESTS=1 ;;
  "")          ACTION=build ;;
  *) echo "Opción desconocida: $1" >&2; exit 2 ;;
esac

# -----------------------------------------------------------------
# Lint: un error de sintaxis no debe llegar a la imagen
# -----------------------------------------------------------------
echo "Verificando sintaxis PHP..."
failures=0
while IFS= read -r file; do
  if ! php -l "$file" >/dev/null 2>&1; then
    echo "  FALLA: ${file#${PROJECT_DIR}/}" >&2
    php -l "$file" 2>&1 | sed 's/^/    /' >&2
    failures=$(( failures + 1 ))
  fi
done < <( find "$SRC_DIR" -name '*.php' -type f )

if [ "$failures" -gt 0 ]; then
  echo "$failures archivo(s) con errores de sintaxis. No se empaqueta nada." >&2
  exit 1
fi

echo "  OK, $( find "$SRC_DIR" -name '*.php' -type f | wc -l ) archivos sin errores."

# -----------------------------------------------------------------
# ¿Esta desactualizado?
# -----------------------------------------------------------------
stale() {
  [ ! -f "$ZIP_PATH" ] && return 0
  local newest
  newest=$( find "$SRC_DIR" -type f -newer "$ZIP_PATH" -print -quit )
  [ -n "$newest" ]
}

if [ "$ACTION" = "check" ]; then
  if stale; then
    echo "DESACTUALIZADO: hay archivos mas nuevos que $ZIP_PATH"
    find "$SRC_DIR" -type f -newer "$ZIP_PATH" -printf '  %P\n' | sort
    exit 1
  fi
  echo "Al dia."
  exit 0
fi

# -----------------------------------------------------------------
# Empaquetado
# -----------------------------------------------------------------
BUILD_DIR="$( mktemp -d )"
trap 'rm -rf "$BUILD_DIR"' EXIT

cp -R "$SRC_DIR" "${BUILD_DIR}/webp-studio"

# Sin residuos de desarrollo: el directorio no debe entrar en la imagen.
find "${BUILD_DIR}/webp-studio" \
  \( -name '.DS_Store' -o -name '*.log' -o -name '.git' -o -name 'node_modules' \) \
  -exec rm -rf {} + 2>/dev/null || true

# tests/ is dropped on purpose. It is roughly a fifth of the archive, it only ever runs
# through wp-cli eval-file, and a production server has no reason to carry it. The tests
# stay in webp-studio/tests/ and are run from the checkout during development.
# --with-tests keeps them, which is useful when sending a build to someone debugging.
if [ "$WITH_TESTS" -eq 0 ]; then
  rm -rf "${BUILD_DIR}/webp-studio/tests"
fi

rm -f "$ZIP_PATH"
# -X quita timestamps y permisos variables: mismo contenido, mismo archivo.
( cd "$BUILD_DIR" && zip -qrX "$ZIP_PATH" webp-studio )

echo
echo "Construido: $( basename "$ZIP_PATH" ) ($( du -h "$ZIP_PATH" | cut -f1 ))"
echo "  carpeta interna: webp-studio/"
echo "  archivos: $( unzip -Z1 "$ZIP_PATH" | grep -c '\.' )"
[ "$WITH_TESTS" -eq 0 ] && echo "  tests incluidos: no"