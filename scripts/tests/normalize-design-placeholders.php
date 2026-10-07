<?php
class WP_CLI
{
    public static function error($message) { throw new RuntimeException($message); }
    public static function success($message) {}
}
$directory = sys_get_temp_dir() . '/complete-theme-text-' . bin2hex(random_bytes(8));
mkdir($directory . '/content', 0700, true);
$path = $directory . '/content/design-content.xml';
try {
    $original = '<p>{email} {{email}} {email address} {{email address}} {physical address} {{physical address}} {EI_WEBSITE_SLOGAN} {{EI_WEBSITE_BUSINESS}}</p>{copyright} {year} {site-title}';
    file_put_contents($path, $original);
    $args = array($directory);
    require dirname(__DIR__) . '/normalize-design-placeholders.php';
    $normalized = file_get_contents($path);
    if (substr_count($normalized, '{{EI_WEBSITE_EMAIL}}') !== 4
        || substr_count($normalized, '{{EI_WEBSITE_EXACT_ADDRESS}}') !== 2
        || strpos($normalized, '{{EI_WEBSITE_SLOGAN}}') === false
        || strpos($normalized, '{{EI_WEBSITE_BUSINESS}}') === false
        || strpos($normalized, '{copyright} {year} {site-title}') === false) {
        throw new RuntimeException('Normalize missing brackets and policy tokens while retaining native footer tokens.');
    }
    require dirname(__DIR__) . '/normalize-design-placeholders.php';
    if (file_get_contents($path) !== $normalized) {
        throw new RuntimeException('Placeholder normalization must be idempotent.');
    }
    echo "Template placeholder normalization passed.\n";
} finally {
    unlink($path);
    rmdir($directory . '/content');
    rmdir($directory);
}
