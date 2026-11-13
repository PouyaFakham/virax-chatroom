<?php
/**
 * Admin login captcha.
 *
 * Rendered as SVG on purpose: no GD/Imagick dependency, no font files to ship,
 * and it stays crisp on every screen. The plain code is never written to the
 * session — only a password hash — so a leaked session file cannot be turned
 * back into a solvable challenge.
 */
require_once 'config.php';

$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no I/O/0/1
$code = '';
for ($i = 0; $i < 5; $i++) {
    $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
}

$_SESSION['admin_captcha'] = [
    'hash' => password_hash($code, PASSWORD_DEFAULT),
    'exp'  => time() + 300,
    'at'   => microtime(true),
];

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$w = 220;
$h = 64;
$palette = ['#A5B4FC', '#93C5FD', '#C4B5FD', '#FCA5A5', '#FCD34D', '#6EE7B7', '#F9A8D4'];

$svg  = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="کد امنیتی">';
$svg .= '<defs>';
$svg .= '<linearGradient id="vxCapBg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#0B1220"/><stop offset="0.55" stop-color="#111A2E"/><stop offset="1" stop-color="#1E293B"/></linearGradient>';
$svg .= '<linearGradient id="vxCapEdge" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#6366F1" stop-opacity="0.55"/><stop offset="1" stop-color="#22D3EE" stop-opacity="0.25"/></linearGradient>';
$svg .= '</defs>';
$svg .= '<rect x="0.75" y="0.75" width="' . ($w - 1.5) . '" height="' . ($h - 1.5) . '" rx="13" fill="url(#vxCapBg)" stroke="url(#vxCapEdge)" stroke-width="1.5"/>';

// Speckle: a light dusting of dots makes naive thresholding less useful while
// staying invisible to a human reader.
for ($i = 0; $i < 64; $i++) {
    $cx = random_int(4, $w - 4);
    $cy = random_int(4, $h - 4);
    $r  = random_int(1, 2) / 2;
    $op = random_int(6, 18) / 100;
    $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="#E2E8F0" opacity="' . $op . '"/>';
}

// Two soft interference curves, kept away from the glyph centres.
for ($i = 0; $i < 3; $i++) {
    $y1 = random_int(10, $h - 10);
    $y2 = random_int(10, $h - 10);
    $c1 = random_int(30, 90);
    $c2 = random_int(130, 190);
    $col = $palette[array_rand($palette)];
    $svg .= '<path d="M2 ' . $y1 . ' C ' . $c1 . ' ' . random_int(0, $h) . ', ' . $c2 . ' ' . random_int(0, $h) . ', ' . ($w - 2) . ' ' . $y2 . '" fill="none" stroke="' . $col . '" stroke-width="1.1" opacity="0.22"/>';
}

$slot = ($w - 34) / 5;
for ($i = 0; $i < 5; $i++) {
    $x   = 24 + ($slot * $i) + random_int(-2, 2);
    $y   = 43 + random_int(-3, 3);
    $rot = random_int(-15, 15);
    $col = $palette[array_rand($palette)];
    // A one-pixel dark offset under each glyph gives the flat letters a little depth.
    $svg .= '<text x="' . ($x + 1) . '" y="' . ($y + 1.4) . '" transform="rotate(' . $rot . ' ' . $x . ' ' . $y . ')"'
          . ' font-family="Consolas, \'Courier New\', monospace" font-size="31" font-weight="700"'
          . ' fill="#020617" opacity="0.35" letter-spacing="1">' . htmlspecialchars($code[$i], ENT_QUOTES, 'UTF-8') . '</text>';
    $svg .= '<text x="' . $x . '" y="' . $y . '" transform="rotate(' . $rot . ' ' . $x . ' ' . $y . ')"'
          . ' font-family="Consolas, \'Courier New\', monospace" font-size="31" font-weight="700"'
          . ' fill="' . $col . '" opacity="0.96" letter-spacing="1">' . htmlspecialchars($code[$i], ENT_QUOTES, 'UTF-8') . '</text>';
}

$svg .= '</svg>';
echo $svg;
