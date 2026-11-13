<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$name = trim((string)$site_brand_fa);
if ($name === '') $name = 'چت‌روم';
$short = mb_substr($name, 0, 12, 'UTF-8');
$tag = trim((string)($brand_tagline ?? ''));
if ($tag === '') $tag = 'گفتگوی امن و بلادرنگ';

$iconBase = 'assets/brand';
$v = defined('VEYRAX_ASSET_VERSION') ? VEYRAX_ASSET_VERSION : '1';

$manifest = [
    'name' => $name,
    'short_name' => $short,
    'description' => $tag,
    'lang' => 'fa',
    'dir' => 'rtl',
    'start_url' => 'index.php',
    'scope' => './',
    'display' => 'standalone',
    'display_override' => ['standalone', 'minimal-ui', 'browser'],
    'orientation' => 'portrait-primary',
    'background_color' => '#0b1020',
    'theme_color' => '#0b1020',
    'categories' => ['social', 'communication'],
    'icons' => [
        ['src' => $iconBase . '/favicon-192.png?v=' . $v, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $iconBase . '/favicon-512.png?v=' . $v, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $iconBase . '/favicon-192.png?v=' . $v, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => $iconBase . '/favicon-512.png?v=' . $v, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'shortcuts' => [
        [
            'name' => 'گفتگوی عمومی',
            'short_name' => 'گفتگو',
            'url' => 'index.php',
            'icons' => [['src' => $iconBase . '/favicon-96.png?v=' . $v, 'sizes' => '96x96', 'type' => 'image/png']],
        ],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
