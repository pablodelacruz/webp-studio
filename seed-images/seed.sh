#!/usr/bin/env bash
#
# Importa las imágenes semilla en WordPress.
#
# Se invoca automáticamente desde reset-rebuild.sh al final, porque ese script borra los
# volúmenes Docker (docker compose down -v) y con ellos la carpeta uploads/ de WordPress.
#
# Uso:
#   ./seed-images/seed.sh              importa las imágenes ya presentes en esta carpeta
#   ./seed-images/seed.sh --download   vuelve a descargarlas de Lorem Picsum y luego importa
#   ./seed-images/seed.sh --force      reimporta aunque la biblioteca ya tenga imágenes

set -euo pipefail

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_DIR="$( dirname "$SCRIPT_DIR" )"

CONTAINER="${WP_CONTAINER:-wpimgplugin-wordpress-1}"
SITE_URL="${WP_HOME:-}"

DOWNLOAD=0
FORCE=0
WITH_USER=0

for arg in "$@"; do
  case "$arg" in
    --download) DOWNLOAD=1 ;;
    --force)    FORCE=1 ;;
    --with-user) WITH_USER=1 ;;
    *) echo "Opción desconocida: $arg" >&2; exit 2 ;;
  esac
done

# -----------------------------------------------------------------
# Descarga
# -----------------------------------------------------------------
# IDs fijos de Lorem Picsum (fotos de Unsplash, licencia libre). Ver README.md.
download_images() {
  echo "Descargando imágenes semilla..."

  declare -A IMAGES=(
    [01-paisaje]=1015/1200/800
    [02-montana]=1016/1400/900
    [03-desierto]=1018/1600/1000
    [04-costa]=1019/1200/800
    [05-bosque]=1043/1400/900
    [06-edificio]=1076/1200/800
    [07-urbes]=180/1600/1000
    [08-abstracto]=1067/1400/900
    [09-oscuro]=1074/1200/800
    [10-texto]=1084/1600/1000
  )

  for name in "${!IMAGES[@]}"; do
    printf "  %-16s " "${name}.jpg"
    if curl -sfL --max-time 30 -o "$SCRIPT_DIR/${name}.jpg" \
        "https://picsum.photos/id/${IMAGES[$name]}"; then
      echo "ok"
    else
      echo "falló" >&2
      return 1
    fi
  done

  # El PNG y el GIF se generan con GD dentro del contenedor, no se descargan.
  generate_with_gd

  echo "Descargadas $(find "$SCRIPT_DIR" -maxdepth 1 -name '*.jpg' | wc -l) fotos."
}

generate_with_gd() {
  docker exec "$CONTAINER" php -r '
    $im = imagecreatetruecolor( 1200, 800 );
    imagesavealpha( $im, true );
    imagealphablending( $im, false );
    imagefill( $im, 0, 0, imagecolorallocatealpha( $im, 0, 122, 204, 80 ) );
    imagealphablending( $im, true );
    imagefilledellipse( $im, 400, 400, 340, 340, imagecolorallocate( $im, 255, 138, 0 ) );
    imagefilledrectangle( $im, 660, 220, 1080, 620, imagecolorallocate( $im, 34, 197, 94 ) );
    imagepng( $im, "/tmp/wps-seed-logo.png" );

    $p = imagecreate( 900, 600 );
    imagecolorallocate( $p, 246, 246, 246 );
    imagecolorallocate( $p, 37, 99, 235 );
    imagefilledrectangle( $p, 0, 0, 899, 599, imagecolorallocate( $p, 246, 246, 246 ) );
    imagefilledellipse( $p, 300, 300, 300, 300, imagecolorallocate( $p, 37, 99, 235 ) );
    imagegif( $p, "/tmp/wps-seed-icono.gif" );
  ' 2>/dev/null

  docker cp "$CONTAINER:/tmp/wps-seed-logo.png" "$SCRIPT_DIR/11-logo.png" >/dev/null 2>&1 || true
  docker cp "$CONTAINER:/tmp/wps-seed-icono.gif" "$SCRIPT_DIR/12-icono.gif" >/dev/null 2>&1 || true
  docker exec "$CONTAINER" rm -f /tmp/wps-seed-logo.png /tmp/wps-seed-icono.gif >/dev/null 2>&1 || true
}

# -----------------------------------------------------------------
# Importación
# -----------------------------------------------------------------
container_is_up() {
  docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null | grep -q true
}

import_images() {
  local files
  # user-uploads/ holds the operator's own screenshots as regression fixtures. They
  # are excluded unless explicitly asked for, so a reset stays fast and the curated
  # set stays the default.
  local extra=()
  if [ "$WITH_USER" -eq 1 ]; then
    mapfile -t extra < <(find "$SCRIPT_DIR/user-uploads" -maxdepth 1 \
      \( -name '*.jpg' -o -name '*.png' -o -name '*.gif' \) 2>/dev/null | sort)
  fi

  # The printf has to be conditional. Unconditional, it emits a bare newline whenever
  # $extra is empty, which mapfile turns into one empty array element: the loop then tries
  # to import an empty path and every run reported one phantom failure.
  mapfile -t files < <(
    {
      find "$SCRIPT_DIR" -maxdepth 1 \( -name '*.jpg' -o -name '*.png' -o -name '*.gif' \) | sort
      if [ "${#extra[@]}" -gt 0 ]; then
        printf '%s\n' "${extra[@]}"
      fi
    } | sort
  )

  if [ "${#files[@]}" -eq 0 ]; then
    echo "No hay imágenes para importar en $SCRIPT_DIR." >&2
    return 1
  fi

  if ! container_is_up; then
    echo "El contenedor $CONTAINER no está en marcha. Levanta el stack primero." >&2
    return 1
  fi

  # wp media import necesita un archivo accesible al contenedor.
  local tmp="/tmp/wps-seed-$$"
  docker exec "$CONTAINER" mkdir -p "$tmp"

  local imported=0 skipped=0 failed=0

  for file in "${files[@]}"; do
    docker cp "$file" "$CONTAINER:$tmp/$( basename "$file" )" >/dev/null 2>&1 || {
      failed=$(( failed + 1 )); continue
    }

    local out
    if [ -n "$SITE_URL" ]; then
      out=$( docker exec "$CONTAINER" wp media import "$tmp/$( basename "$file" )" \
             --user=1 --porcelain --path=/var/www/html --url="$SITE_URL" 2>/dev/null || true )
    else
      out=$( docker exec "$CONTAINER" wp media import "$tmp/$( basename "$file" )" \
             --user=1 --porcelain --path=/var/www/html 2>/dev/null || true )
    fi

    if [[ "$out" =~ ^[0-9]+$ ]]; then
      imported=$(( imported + 1 ))
    elif [[ "$out" == *"already exists"* ]]; then
      skipped=$(( skipped + 1 ))
    else
      failed=$(( failed + 1 ))
    fi
  done

  docker exec "$CONTAINER" rm -rf "$tmp" >/dev/null 2>&1 || true

  echo "Imágenes semilla: ${imported} importadas, ${skipped} ya existían, ${failed} fallaron."

  if [ "$failed" -gt 0 ]; then
    return 1
  fi
}

# -----------------------------------------------------------------
main() {
  [ "$DOWNLOAD" -eq 1 ] && download_images

  if [ "$FORCE" -eq 0 ]; then
    local current
    current=$( docker exec "$CONTAINER" wp eval \
      'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=\"attachment\"");' \
      --path=/var/www/html 2>/dev/null | tr -dc '0-9' || echo 0 )

    if [ "${current:-0}" -gt 0 ]; then
      echo "La biblioteca ya tiene ${current} adjuntos. Usa --force para importar igualmente."
      return 0
    fi
  fi

  import_images
}

main "$@"