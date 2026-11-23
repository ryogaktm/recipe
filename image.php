<?php
// 画像配信：本人・共有リンク・自分宛ての共有のときだけ見られる
// （uploads/ への直接アクセスは .htaccess で禁止）
require __DIR__ . '/config.php';
$viewer = current_user();
session_write_close();

$owner = (string)($_GET['o'] ?? '');
$f     = basename((string)($_GET['f'] ?? ''));
$token = (string)($_GET['t'] ?? '');
if (!valid_uid($owner) || !preg_match('/^[a-f0-9]{24}\.(jpg|png|webp|gif)$/', $f, $m)) { http_response_code(404); exit; }
$path = UPLOAD_DIR . "/$owner/$f";
if (!is_file($path)) { http_response_code(404); exit; }
if (!can_view_image($viewer, $owner, $f, $token)) { http_response_code(403); exit; }

$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
$type = $types[$m[1]];
// s=t ならサムネイル（なければ作る。作れなければ元画像）
if (($_GET['s'] ?? '') === 't' && $m[1] !== 'gif') {
    $tp = thumb_path($owner, $f);
    if (is_file($tp) || make_thumb($path, $tp)) { $path = $tp; $type = 'image/jpeg'; }
}
header('Content-Type: ' . $type);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
readfile($path);
