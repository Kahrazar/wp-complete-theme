<?php
/** Keep business placeholders consistent when exporting the design. */
if (!isset($args[0]) || !is_dir($args[0])) {
    WP_CLI::error('A valid export directory is required.');
}
$path = rtrim($args[0], '/\\') . '/content/design-content.xml';
$xml = file_get_contents($path);
if ($xml === false) {
    WP_CLI::error('Cannot read the exported WXR.');
}
$xml = strtr($xml, array(
    '{{email address}}' => '{{EI_WEBSITE_EMAIL}}', '{email address}' => '{{EI_WEBSITE_EMAIL}}',
    '{{email}}' => '{{EI_WEBSITE_EMAIL}}', '{email}' => '{{EI_WEBSITE_EMAIL}}',
    '{{physical address}}' => '{{EI_WEBSITE_EXACT_ADDRESS}}', '{physical address}' => '{{EI_WEBSITE_EXACT_ADDRESS}}',
));
$xml = preg_replace('/(?<!\{)\{(EI_[A-Z_]+)\}(?!\})/', '{{$1}}', $xml);
if (file_put_contents($path, $xml) === false) {
    WP_CLI::error('Cannot save normalized placeholders.');
}
WP_CLI::success('Business placeholders now use {{EI_*}} tokens.');
