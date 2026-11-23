<?php
// 画像はログイン状態を確認してから配信（uploads/ への直接アクセスは .htaccess で禁止）
require __DIR__ . '/config.php';
session_start();
if (!auth_ok()) { http_response_code(403); exit; }
session_write_close();

$f = basename((string)($_GET['f'] ?? ''));
if (!preg_match('/^[a-f0-9]{24}\.(jpg|png|webp|gif)$/', $f, $m) || !is_file(UPLOAD_DIR . "/$f")) {
    http_response_code(404); exit;
}
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
header('Content-Type: ' . $types[$m[1]]);
header('Content-Length: ' . filesize(UPLOAD_DIR . "/$f"));
header('Cache-Control: private, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
readfile(UPLOAD_DIR . "/$f");
