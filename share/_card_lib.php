<?php
// share/_card_lib.php
// Tiny OG-image renderer (PNG) for Telegram shares.
// Uses GD if available; otherwise returns a minimal PNG.

if (!defined('FastCore')) { define('FastCore', true); }

function vx_card_base_url(): string {
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  $scheme = $https ? 'https' : 'http';
  $host = (string)($_SERVER['HTTP_HOST'] ?? '');
  if ($host === '') $host = 'greenfarm.lol';
  return $scheme . '://' . $host;
}

function vx_card_send_png($im): void {
  header('Content-Type: image/png');
  header('Cache-Control: public, max-age=300');
  if (is_resource($im) || ($im instanceof GdImage)) {
    imagepng($im);
    imagedestroy($im);
  } else {
    // 1x1 transparent
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMB/ej3W2wAAAAASUVORK5CYII=');
  }
  exit;
}

function vx_card_font(): ?string {
  $candidates = [
    __DIR__ . '/DejaVuSans.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
  ];
  foreach ($candidates as $p) {
    if (is_file($p) && is_readable($p)) return $p;
  }
  return null;
}

function vx_card_draw_text($im, int $x, int $y, string $text, int $size, int $color, bool $bold=false): void {
  $font = vx_card_font();
  if ($font && function_exists('imagettftext')) {
    $angle = 0;
    $yy = $y;
    // TTF baseline: y is baseline; adjust slightly
    imagettftext($im, $size, $angle, $x, $yy, $color, $font, $text);
    if ($bold) {
      imagettftext($im, $size, $angle, $x+1, $yy, $color, $font, $text);
    }
    return;
  }

  // Fallback bitmap font
  imagestring($im, 5, $x, $y-14, $text, $color);
}

function vx_card_make_bg(int $w, int $h): mixed {
  if (!function_exists('imagecreatetruecolor')) return null;
  $im = imagecreatetruecolor($w, $h);
  imagealphablending($im, true);
  imagesavealpha($im, true);
  $bg = imagecolorallocate($im, 6, 10, 22);
  imagefilledrectangle($im, 0, 0, $w, $h, $bg);

  // Soft gradient blobs
  $c1 = imagecolorallocatealpha($im, 0, 243, 255, 110);
  $c2 = imagecolorallocatealpha($im, 124, 92, 255, 115);
  imagefilledellipse($im, (int)($w*0.18), (int)($h*0.15), (int)($w*0.9), (int)($h*0.9), $c1);
  imagefilledellipse($im, (int)($w*0.85), (int)($h*0.80), (int)($w*0.95), (int)($h*0.95), $c2);

  // Card panel
  $panel = imagecolorallocatealpha($im, 12, 18, 40, 18);
  $bd = imagecolorallocatealpha($im, 255, 255, 255, 90);
  imagefilledrectangle($im, 56, 58, $w-56, $h-58, $panel);
  imagerectangle($im, 56, 58, $w-56, $h-58, $bd);
  return $im;
}

function vx_card_clip(string $s, int $max=64): string {
  $s = trim($s);
  if ($s === '') return '';
  if (function_exists('mb_strlen') && mb_strlen($s, 'UTF-8') > $max) {
    return mb_substr($s, 0, $max-1, 'UTF-8') . '…';
  }
  if (strlen($s) > $max) return substr($s, 0, $max-1) . '…';
  return $s;
}
