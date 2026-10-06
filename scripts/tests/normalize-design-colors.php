<?php
/** Run with PHP CLI; WordPress is not needed for this export-file regression test. */

class WP_CLI
{
    public static function error($message) { throw new RuntimeException($message); }
    public static function success($message) {}
}

function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
// Match WordPress's block-comment serializer, including JSON escape preservation.
function serialize_block_attributes($attributes)
{
    return strtr(wp_json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), array(
        '\\\\' => '\\u005c', '--' => '\\u002d\\u002d', '<' => '\\u003c',
        '>' => '\\u003e', '&' => '\\u0026', '\\"' => '\\u0022',
    ));
}
function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function normalize_export($directory)
{
    $args = array($directory);
    require dirname(__DIR__) . '/normalize-design-colors.php';
}

$directory = sys_get_temp_dir() . '/complete-theme-colors-' . bin2hex(random_bytes(8));
mkdir($directory . '/content', 0700, true);
mkdir($directory . '/theme', 0700, true);
$paths = array(
    $directory . '/content/design-content.xml',
    $directory . '/theme/kadence-theme-mods.json',
    $directory . '/theme/custom-css.css',
);

try {
    $contact = array(
        'uniqueID' => '68_867119-89',
        'fields' => array(array('label' => 'Correo --> <tag> & "quoted" \\ path', 'type' => 'email', 'required' => true)),
        'submit' => array(array(
            'color' => '#ffffff', 'background' => 'palette2', 'colorHover' => '',
            'backgroundHover' => '#253b35', 'backgroundHoverOpacity' => 0.88,
            'gradient' => array('#999999'), 'boxShadow' => array(false, '#000000'),
        )),
    );
    $unrelated = $contact;
    $unrelated['uniqueID'] = 'custom-form';
    $unrelated_comment = '<!-- wp:kadence/form ' . serialize_block_attributes($unrelated) . ' -->';
    $known_css = '.single-product div.product .woocommerce-tabs ul.tabs li.active {'
        . 'border-bottom: 3px solid #293a35;} '
        . '.single-product div.product .woocommerce-tabs ul.tabs {border-bottom: 1px solid #293a35;}';
    $custom_css = '.custom-widget {border-bottom: 3px solid #293a35; color: #CAFE00;}';
    $footer = '<!-- wp:html -->' . "\n" . '<style>.site-footer-wrap{background: #cad2c3;}</style>'
        . "\n" . '<!-- /wp:html -->';
    $custom_footer = '<style>.site-footer-wrap{background: #CAFE00;}</style>';
    $form_html = '<form class="kb-form"><button class="kb-forms-submit">Enviar</button></form>';
    file_put_contents($paths[0], '<rss><channel><content><![CDATA['
        . '<!-- wp:kadence/form ' . serialize_block_attributes($contact) . ' -->' . $form_html . '<!-- /wp:kadence/form -->'
        . $unrelated_comment . $footer . $custom_footer . $known_css . $custom_css . ']]></content></channel></rss>');
    $mods = array(
        'product_archive_content_background' => array('desktop' => array('color' => '#ffffff')),
        'header_cart_total_color' => array('color' => 'palette9', 'hover' => '#ffffff'),
        'header_cart_total_background' => array('color' => 'palette2', 'hover' => 'palette2'),
        'unrelated_component' => array('color' => '#ffffff', 'customColor' => '#CAFE00'),
    );
    file_put_contents($paths[1], json_encode($mods));
    file_put_contents($paths[2], $known_css . $custom_css);

    normalize_export($directory);
    $result = file_get_contents($paths[0]);
    preg_match('~<!-- wp:kadence/form (\{[^\r\n]*?\}) -->~', $result, $match);
    $actual = json_decode($match[1], true);
    check(json_last_error() === JSON_ERROR_NONE, 'Normalized block attributes must remain valid JSON.');
    check(strpos($match[1], '-->') === false, 'Custom labels must not terminate the block comment.');
    check(strpos($match[1], '<') === false && strpos($match[1], '&') === false, 'Custom markup characters must use WordPress block escaping.');
    check(strpos($match[1], '\\u0022quoted\\u0022') !== false, 'Embedded quotes must use WordPress block escaping.');
    check(strpos($match[1], '\\u002d\\u002dbrand-on-accent') !== false, 'CSS variable double hyphens must use WordPress block escaping.');
    $on_accent = 'var(--brand-on-accent, var(--global-palette3))';
    $theme_on_accent = 'var(--brand-on-accent, #ffffff)';
    check($actual['submit'][0]['color'] === $on_accent, 'Contact normal foreground must follow on-accent.');
    check($actual['submit'][0]['colorHover'] === $on_accent, 'Contact hover foreground must follow on-accent.');
    check($actual['submit'][0]['backgroundHover'] === 'palette2', 'Contact hover background must use the accent slot.');
    check($actual['submit'][0]['backgroundHoverOpacity'] === 1, 'Mapped Contact hover must remain opaque.');
    check($actual['fields'] === $contact['fields'], 'Contact fields and validation must be retained.');
    check($actual['submit'][0]['gradient'] === $contact['submit'][0]['gradient'], 'Inactive gradient defaults must be retained.');
    check($actual['submit'][0]['boxShadow'] === $contact['submit'][0]['boxShadow'], 'Inactive shadow defaults must be retained.');
    check(strpos($result, $form_html) !== false, 'Serialized form HTML must be retained.');
    check(strpos($result, $unrelated_comment) !== false, 'Other forms must be retained byte-for-byte.');
    check(strpos($result, '#cad2c3') === false, 'Legacy footer background must be removed.');
    check(strpos($result, $custom_footer) !== false, 'Customized footer backgrounds must be retained.');
    check(substr_count($result, 'var(--global-palette6)') === 2, 'Both WXR WooCommerce tab borders must use palette6.');
    check(strpos($result, $custom_css) !== false, 'Unrelated CSS using the same old color must be retained.');
    $actual_mods = json_decode(file_get_contents($paths[1]), true);
    check($actual_mods['product_archive_content_background']['desktop']['color'] === 'palette9', 'Product white surface must use palette9.');
    check($actual_mods['header_cart_total_color'] === array('color' => $theme_on_accent, 'hover' => $theme_on_accent), 'Cart foreground states must match their accent background.');
    check(strpos($theme_on_accent, 'palette') === false, 'Theme CSS vars must avoid the Kadence 1.5.2 palette-slug substring behavior.');
    check($actual_mods['unrelated_component'] === $mods['unrelated_component'], 'Unrelated theme colors must be retained.');
    check(substr_count(file_get_contents($paths[2]), 'var(--global-palette6)') === 2, 'Additional CSS must normalize both WooCommerce borders.');
    check(strpos(file_get_contents($paths[2]), $custom_css) !== false, 'Additional CSS must preserve custom selectors.');

    $first_hashes = array_map('md5_file', $paths);
    normalize_export($directory);
    check(array_map('md5_file', $paths) === $first_hashes, 'A second normalization must not change any output.');

    // Known component IDs with intentional custom colors are not legacy defaults.
    $contact['submit'][0]['color'] = '#CAFE00';
    $contact['submit'][0]['backgroundHover'] = '#ABCDEF';
    $contact['submit'][0]['colorHover'] = '#123456';
    file_put_contents($paths[0], '<!-- wp:kadence/form ' . serialize_block_attributes($contact) . ' -->');
    $mods['header_cart_total_color']['color'] = '#CAFE00';
    $mods['header_cart_total_background']['hover'] = 'palette10';
    file_put_contents($paths[1], json_encode($mods));
    normalize_export($directory);
    preg_match('~<!-- wp:kadence/form (\{[^\r\n]*\}) -->~', file_get_contents($paths[0]), $match);
    $actual = json_decode($match[1], true);
    check($actual === $contact, 'Custom Contact foreground and hover colors must be retained.');
    $actual_mods = json_decode(file_get_contents($paths[1]), true);
    check($actual_mods['header_cart_total_color']['color'] === '#CAFE00', 'Custom cart foreground must be retained.');
    check($actual_mods['header_cart_total_color']['hover'] === '#ffffff', 'Cart hover on another palette slot must be retained.');
    echo "Template color normalization: legacy mappings, serialization, custom colors, and idempotency passed.\n";
} finally {
    foreach ($paths as $path) {
        if (is_file($path)) { unlink($path); }
    }
    rmdir($directory . '/content');
    rmdir($directory . '/theme');
    rmdir($directory);
}
