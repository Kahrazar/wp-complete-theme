#!/usr/bin/env bash

set -Eeuo pipefail

#
# Permite ejecutar WP-CLI como root dentro del contenedor.
#
export WP_CLI_ALLOW_ROOT=1

WP_PATH="/var/www/html"
EXPORT_DIR="${1:-/tmp/design-export}"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
COLOR_NORMALIZER="${SCRIPT_DIR}/normalize-design-colors.php"
PLACEHOLDER_NORMALIZER="${SCRIPT_DIR}/normalize-design-placeholders.php"

WP=(
    wp
    --path="${WP_PATH}"
    --allow-root
)

echo "========================================"
echo " Exportación del diseño Kadence"
echo "========================================"
echo "WordPress: ${WP_PATH}"
echo "Destino:   ${EXPORT_DIR}"
echo

#
# 1. Validaciones iniciales.
#
"${WP[@]}" core is-installed

for NORMALIZER in "${COLOR_NORMALIZER}" "${PLACEHOLDER_NORMALIZER}"; do
    if [[ ! -f "${NORMALIZER}" ]]; then
        echo "ERROR: falta ${NORMALIZER}; copie la carpeta scripts completa." >&2
        exit 1
    fi
done

if ! "${WP[@]}" theme is-active kadence; then
    echo "ERROR: Kadence no está activo." >&2
    exit 1
fi

#
# 2. Recrear la carpeta de exportación.
#
rm -rf "${EXPORT_DIR}"

mkdir -p \
    "${EXPORT_DIR}/content" \
    "${EXPORT_DIR}/theme" \
    "${EXPORT_DIR}/media" \
    "${EXPORT_DIR}/config" \
    "${EXPORT_DIR}/metadata"

#
# 3. Obtener información del sitio.
#
DB_PREFIX="$("${WP[@]}" db prefix)"
SOURCE_URL="$("${WP[@]}" option get home)"
WP_CONTENT_DIR="$("${WP[@]}" eval 'echo WP_CONTENT_DIR;')"

echo "Prefijo DB: ${DB_PREFIX}"
echo "URL origen: ${SOURCE_URL}"
echo

#
# 4. Obtener los IDs del contenido visual.
#
# No incluimos productos, pedidos, reservas ni otros Custom Post Types
# creados por WooCommerce o plugins de booking.
#
POST_IDS="$(
    "${WP[@]}" db query "
        SELECT ID
        FROM ${DB_PREFIX}posts
        WHERE post_type IN (
            'page',
            'post',
            'attachment',
            'nav_menu_item',
            'wp_block',
            'wp_navigation',
            'wp_template',
            'wp_template_part',
            'wp_global_styles',
            'custom_css'
        )
        ORDER BY ID;
    " \
        --skip-column-names |
        paste -sd, -
)"

if [[ -z "${POST_IDS}" ]]; then
    echo "ERROR: no se encontró contenido para exportar." >&2
    exit 1
fi

POST_COUNT="$(
    printf '%s' "${POST_IDS}" |
    tr ',' '\n' |
    wc -l
)"

echo "Registros de contenido encontrados: ${POST_COUNT}"

#
# 5. Exportar páginas, bloques, menús y attachments como WXR.
#
"${WP[@]}" export \
    --stdout \
    --post__in="${POST_IDS}" \
    --with_attachments \
    --skip_comments \
    --max_file_size=-1 \
    > "${EXPORT_DIR}/content/design-content.xml"

echo "Contenido exportado."

#
# 6. Exportar configuración visual de Kadence.
#
"${WP[@]}" eval '
$mods = get_theme_mods();

echo wp_json_encode(
    $mods,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
);
' > "${EXPORT_DIR}/theme/kadence-theme-mods.json"

echo "Theme Mods exportados."

#
# 7. Exportar Additional CSS.
#
"${WP[@]}" eval '
$css = wp_get_custom_css();

echo is_string($css) ? $css : "";
' > "${EXPORT_DIR}/theme/custom-css.css"

echo "CSS adicional exportado."

# Mantener los colores conocidos del template conectados a las variables runtime.
# No reemplaza colores personalizados ni defaults de gradientes/sombras inactivos.
"${WP[@]}" eval-file "${COLOR_NORMALIZER}" "${EXPORT_DIR}"
"${WP[@]}" eval-file "${PLACEHOLDER_NORMALIZER}" "${EXPORT_DIR}"

#
# 8. Empaquetar la Media Library.
#
if [[ -d "${WP_CONTENT_DIR}/uploads" ]]; then
    tar -czf \
        "${EXPORT_DIR}/media/uploads.tar.gz" \
        -C "${WP_CONTENT_DIR}" \
        uploads

    echo "Media Library exportada."
else
    echo "ADVERTENCIA: no existe ${WP_CONTENT_DIR}/uploads."
fi

#
# 9. Exportar estructura lógica del sitio.
#
"${WP[@]}" eval '
$front_id = (int) get_option("page_on_front");
$posts_id = (int) get_option("page_for_posts");
$shop_id  = (int) get_option("woocommerce_shop_page_id");

$menu_locations = get_theme_mod("nav_menu_locations", []);
$menus = [];

foreach ($menu_locations as $location => $menu_id) {
    $menu = wp_get_nav_menu_object($menu_id);

    $menus[$location] = [
        "original_id" => (int) $menu_id,
        "name"        => $menu ? $menu->name : "",
        "slug"        => $menu ? $menu->slug : "",
    ];
}

$logo_id   = (int) get_theme_mod("custom_logo");
$logo_file = $logo_id
    ? get_post_meta($logo_id, "_wp_attached_file", true)
    : "";

$data = [
    "source_url"         => home_url(),
    "locale"             => get_locale(),
    "permalink_structure"=> get_option("permalink_structure"),
    "show_on_front"      => get_option("show_on_front"),

    "front_page" => [
        "original_id" => $front_id,
        "slug" => $front_id
            ? get_post_field("post_name", $front_id)
            : "",
    ],

    "posts_page" => [
        "original_id" => $posts_id,
        "slug" => $posts_id
            ? get_post_field("post_name", $posts_id)
            : "",
    ],

    "shop_page" => [
        "original_id" => $shop_id,
        "slug" => $shop_id
            ? get_post_field("post_name", $shop_id)
            : "",
    ],

    "custom_logo" => [
        "original_id" => $logo_id,
        "file"        => $logo_file,
    ],

    "menu_locations" => $menus,
];

echo wp_json_encode(
    $data,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
);
' > "${EXPORT_DIR}/config/site-structure.json"

echo "Estructura del sitio exportada."

#
# 10. Exportar metadatos técnicos.
#
"${WP[@]}" core version \
    > "${EXPORT_DIR}/metadata/wordpress-version.txt"

"${WP[@]}" theme get kadence \
    --fields=name,status,version \
    --format=json \
    > "${EXPORT_DIR}/metadata/kadence.json"

"${WP[@]}" plugin get kadence-blocks \
    --fields=name,status,version \
    --format=json \
    > "${EXPORT_DIR}/metadata/kadence-blocks.json" \
    2>/dev/null || true

printf '%s\n' "${SOURCE_URL}" \
    > "${EXPORT_DIR}/metadata/source-url.txt"

printf '%s\n' "${DB_PREFIX}" \
    > "${EXPORT_DIR}/metadata/database-prefix.txt"

#
# 11. Mostrar resultado.
#
echo
echo "========================================"
echo " Exportación completada"
echo "========================================"
echo

find "${EXPORT_DIR}" \
    -maxdepth 3 \
    -type f \
    -printf '%p - %s bytes\n'
