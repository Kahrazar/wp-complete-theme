<?php
/**
 * Preserve the runtime color contract when exporting the known design bundle.
 * Run through WP-CLI: wp eval-file scripts/normalize-design-colors.php EXPORT_DIR
 * This deliberately leaves unrelated custom colors and inactive defaults alone.
 */

if (!isset($args[0]) || !is_dir($args[0])) {
    WP_CLI::error('A valid export directory is required.');
}

$export_dir = rtrim($args[0], '/\\');
$on_accent = 'var(--brand-on-accent, var(--global-palette3))';
// Kadence theme 1.5.2 treats any value containing "palette" as a palette slug.
// Use a hex fallback in theme mods; nested palette variables are safe in Blocks.
$theme_on_accent = 'var(--brand-on-accent, #ffffff)';
$is_white = static function ($color) {
    return in_array(strtolower((string) $color), array('#ffffff', '#fff', 'palette9'), true);
};

$normalize_css = static function ($css) {
    // Only the two known WooCommerce tab rules use this legacy border color.
    return preg_replace_callback(
        '~(\.single-product div\.product \.woocommerce-tabs ul\.tabs(?: li\.active)?\s*\{)([^}]*)(\})~',
        static function ($match) {
            return $match[1] . preg_replace(
                '~(border-bottom:\s*(?:1|3)px solid )#293a35(;?)~i',
                '$1var(--global-palette6)$2',
                $match[2]
            ) . $match[3];
        },
        $css
    );
};

$xml_path = $export_dir . '/content/design-content.xml';
$xml = file_get_contents($xml_path);
if ($xml === false) {
    WP_CLI::error('Cannot read the exported WXR.');
}

// Remove the complete legacy HTML block, avoiding an empty block in the editor.
$xml = preg_replace(
    '~<!-- wp:html -->\s*<style>\.site-footer-wrap\{background: #cad2c3;\}</style>\s*<!-- /wp:html -->~',
    '',
    $xml
);
$xml = $normalize_css($xml);
$xml = preg_replace_callback(
    '~(<!-- wp:kadence/form )(\{[^\r\n]*?\})( -->)~',
    static function ($match) use ($on_accent, $is_white) {
        $attributes = json_decode($match[2], true);
        if (!is_array($attributes)
            || ($attributes['uniqueID'] ?? '') !== '68_867119-89'
            || ($attributes['submit'][0]['background'] ?? '') !== 'palette2') {
            return $match[0];
        }
        $submit = &$attributes['submit'][0];
        if ($is_white($submit['color'] ?? '')) {
            $submit['color'] = $on_accent;
        }
        if (strtolower($submit['backgroundHover'] ?? '') === '#253b35') {
            $submit['backgroundHover'] = 'palette2';
            $submit['backgroundHoverOpacity'] = 1;
            if (empty($submit['colorHover']) || $is_white($submit['colorHover'])) {
                $submit['colorHover'] = $on_accent;
            }
        }
        // Keep labels and CSS variables safe inside WordPress block comments.
        return $match[1] . serialize_block_attributes($attributes) . $match[3];
    },
    $xml
);
if (file_put_contents($xml_path, $xml) === false) {
    WP_CLI::error('Cannot save normalized WXR colors.');
}

$mods_path = $export_dir . '/theme/kadence-theme-mods.json';
$mods = json_decode(file_get_contents($mods_path), true);
if (!is_array($mods)) {
    WP_CLI::error('Invalid exported Kadence theme mods.');
}
if ($is_white($mods['product_archive_content_background']['desktop']['color'] ?? '')
    && ($mods['product_archive_content_background']['desktop']['color'] ?? '') !== 'palette9') {
    $mods['product_archive_content_background']['desktop']['color'] = 'palette9';
}
foreach (array('color', 'hover') as $state) {
    if (($mods['header_cart_total_background'][$state] ?? '') === 'palette2'
        && $is_white($mods['header_cart_total_color'][$state] ?? '')) {
        $mods['header_cart_total_color'][$state] = $theme_on_accent;
    }
}
if (file_put_contents($mods_path, wp_json_encode($mods, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
    WP_CLI::error('Cannot save normalized Kadence colors.');
}

$css_path = $export_dir . '/theme/custom-css.css';
$css = file_get_contents($css_path);
if ($css === false || file_put_contents($css_path, $normalize_css($css)) === false) {
    WP_CLI::error('Cannot normalize Additional CSS colors.');
}
WP_CLI::success('Known template colors now follow the runtime palette contract.');
