<?php
// ===== 設定 =====
// ログイン用パスワード。空文字ならログインなしで使えます。
// password_hash() で作ったハッシュ（$2y$...）を入れてもOK。
const APP_PASSWORD = '';

const DATA_DIR   = __DIR__ . '/data';
const UPLOAD_DIR = __DIR__ . '/uploads';
const MAX_UPLOAD_BYTES = 20 * 1024 * 1024; // 1枚あたりの上限

// ===== 古いPHP（7.x）向けの互換関数 =====
if (!function_exists('str_starts_with')) {
    function str_starts_with($h, $n) { return $n === '' || strncmp($h, $n, strlen($n)) === 0; }
}
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $len = null) {
        preg_match_all('/./us', $s, $m);
        return implode('', array_slice($m[0], $start, $len));
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s) { return strtolower($s); }
}
function detect_image_mime($path) {
    if (class_exists('finfo')) return (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $i = @getimagesize($path);
    return $i ? $i['mime'] : '';
}

// ===== 共通関数 =====
function auth_ok() {
    return APP_PASSWORD === '' || !empty($_SESSION['recipe_auth']);
}
function check_password($pw) {
    if (str_starts_with(APP_PASSWORD, '$2y$')) return password_verify($pw, APP_PASSWORD);
    return hash_equals(APP_PASSWORD, $pw);
}
function csrf_token() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function ensure_dirs() {
    // data/ と uploads/ は外から直接見られないようにする（.htaccess を自動作成）
    $deny = "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
          . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
    foreach ([DATA_DIR, UPLOAD_DIR] as $d) {
        if (!is_dir($d)) mkdir($d, 0775, true);
        if (!is_file("$d/.htaccess")) file_put_contents("$d/.htaccess", $deny);
    }
}
function load_json($name) {
    $f = DATA_DIR . "/$name.json";
    if (!is_file($f)) return [];
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
function save_json($name, $data) {
    $f = DATA_DIR . "/$name.json";
    $tmp = $f . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    rename($tmp, $f);
}
