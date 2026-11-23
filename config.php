<?php
// ===== 設定 =====
// 新規登録の受付や招待コードは、最初に登録したユーザー（管理者）が
// アプリ内の「アカウント → 管理設定」から変更できます。
const DATA_DIR   = __DIR__ . '/data';
const UPLOAD_DIR = __DIR__ . '/uploads';
const MAX_UPLOAD_BYTES = 20 * 1024 * 1024; // 1枚あたりの上限
const REMEMBER_DAYS = 90;                    // ログイン状態を保つ日数

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
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper($s) { return strtoupper($s); }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s) { return strtolower($s); }
}
function detect_image_mime($path) {
    if (class_exists('finfo')) return (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $i = @getimagesize($path);
    return $i ? $i['mime'] : '';
}

// ===== ファイル保存 =====
function deny_htaccess($dir) {
    if (!is_file("$dir/.htaccess")) {
        file_put_contents("$dir/.htaccess",
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
          . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
}
function ensure_dirs() {
    foreach ([DATA_DIR, UPLOAD_DIR] as $d) {
        if (!is_dir($d)) mkdir($d, 0775, true);
        deny_htaccess($d);
    }
}
function read_json_file($f) {
    if (!is_file($f)) return [];
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
function write_json_file($f, $data) {
    $dir = dirname($f);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    rename($tmp, $f);
}
// 全体（ユーザー一覧・共有・設定）
function load_json($name)        { return read_json_file(DATA_DIR . "/$name.json"); }
function save_json($name, $data) { write_json_file(DATA_DIR . "/$name.json", $data); }
// ユーザーごと（レシピ・カテゴリ）
function valid_uid($uid) { return is_string($uid) && preg_match('/^u[a-f0-9]{12}$/', $uid); }
function user_file($uid, $name) { return DATA_DIR . "/u/$uid/$name.json"; }
function load_user($uid, $name) { return valid_uid($uid) ? read_json_file(user_file($uid, $name)) : []; }
function save_user($uid, $name, $data) { if (valid_uid($uid)) write_json_file(user_file($uid, $name), $data); }
function user_upload_dir($uid) {
    $d = UPLOAD_DIR . "/$uid";
    if (!is_dir($d)) mkdir($d, 0775, true);
    return $d;
}
function with_lock($exclusive = true) {
    ensure_dirs();
    $lock = fopen(DATA_DIR . '/.lock', 'c');
    flock($lock, $exclusive ? LOCK_EX : LOCK_SH);
    return $lock;
}

// ===== 設定（管理者が画面から変更） =====
function app_settings() {
    return array_merge(['signup_open' => true, 'invite_code' => ''], load_json('settings'));
}

// ===== ユーザー・ログイン =====
function find_user($users, $key, $value) {
    foreach ($users as $u) if (isset($u[$key]) && mb_strtolower($u[$key]) === mb_strtolower($value)) return $u;
    return null;
}
function start_session() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('RECIPESSID');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(['lifetime' => 0, 'path' => cookie_path(), 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
    } else {
        session_set_cookie_params(0, cookie_path() . '; samesite=Lax', '', $https, true);
    }
    session_start();
}
function cookie_path() {
    $p = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $p === '' ? '/' : $p . '/';
}
function set_remember_cookie($value, $expires) {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    if (PHP_VERSION_ID >= 70300) {
        setcookie('recipes_remember', $value, ['expires' => $expires, 'path' => cookie_path(), 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
    } else {
        setcookie('recipes_remember', $value, $expires, cookie_path() . '; samesite=Lax', '', $https, true);
    }
}
// ログイン中のユーザー（いなければ null）。「ログインしたまま」のクッキーも見る。
function current_user() {
    static $cached = false;
    if ($cached !== false) return $cached;
    start_session();
    $users = load_json('users');
    $uid = $_SESSION['uid'] ?? null;
    if (!$uid && !empty($_COOKIE['recipes_remember'])) {
        $parts = explode(':', (string)$_COOKIE['recipes_remember'], 2);
        if (count($parts) === 2 && valid_uid($parts[0])) {
            $u = find_user($users, 'id', $parts[0]);
            $h = hash('sha256', $parts[1]);
            if ($u && in_array($h, $u['tokens'] ?? [], true)) {
                session_regenerate_id(true);
                $_SESSION['uid'] = $u['id'];
                $uid = $u['id'];
            }
        }
    }
    $cached = $uid ? find_user($users, 'id', $uid) : null;
    if ($uid && !$cached) unset($_SESSION['uid']);
    return $cached;
}
function login_user($user) {
    start_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = $user['id'];
    // ログイン状態を保つトークン（ハッシュだけ保存）
    $token = bin2hex(random_bytes(24));
    $lock = with_lock();
    $users = load_json('users');
    foreach ($users as &$u) {
        if ($u['id'] === $user['id']) {
            $u['tokens'] = array_slice(array_merge([hash('sha256', $token)], $u['tokens'] ?? []), 0, 10);
        }
    }
    unset($u);
    save_json('users', $users);
    fclose($lock);
    set_remember_cookie($user['id'] . ':' . $token, time() + REMEMBER_DAYS * 86400);
}
function logout_user() {
    start_session();
    $user = current_user();
    if ($user && !empty($_COOKIE['recipes_remember'])) {
        $parts = explode(':', (string)$_COOKIE['recipes_remember'], 2);
        $h = hash('sha256', $parts[1] ?? '');
        $lock = with_lock();
        $users = load_json('users');
        foreach ($users as &$u) if ($u['id'] === $user['id']) $u['tokens'] = array_values(array_diff($u['tokens'] ?? [], [$h]));
        unset($u);
        save_json('users', $users);
        fclose($lock);
    }
    set_remember_cookie('', time() - 3600);
    $_SESSION = [];
    session_destroy();
}
function csrf_token() {
    start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

// 最初のユーザーに、1人用だった頃のデータ（data/recipes.json と uploads/*）を引き継ぐ
function migrate_legacy_data($uid) {
    $legacyR = DATA_DIR . '/recipes.json';
    $legacyC = DATA_DIR . '/categories.json';
    if (!is_file($legacyR) && !is_file($legacyC)) return;
    $dir = user_upload_dir($uid);
    foreach (scandir(UPLOAD_DIR) ?: [] as $f) {
        if (preg_match('/^[a-f0-9]{24}\.(jpg|png|webp|gif)$/', $f)) rename(UPLOAD_DIR . "/$f", "$dir/$f");
    }
    if (is_file($legacyR)) { save_user($uid, 'recipes', read_json_file($legacyR)); rename($legacyR, $legacyR . '.migrated'); }
    if (is_file($legacyC)) { save_user($uid, 'categories', read_json_file($legacyC)); rename($legacyC, $legacyC . '.migrated'); }
}

// 画像を見てよいか：自分の画像 / 共有リンク / 自分宛てに共有されたレシピの画像
function recipe_files($r) {
    return array_merge($r['images'], array_values($r['fix'] ?? []));
}
function can_view_image($viewer, $owner, $file, $token) {
    if ($viewer && $viewer['id'] === $owner) return true;
    $recipes = load_user($owner, 'recipes');
    if ($token !== '') {
        foreach ($recipes as $r) {
            if (!empty($r['share_token']) && hash_equals($r['share_token'], $token) && in_array($file, recipe_files($r), true)) return true;
        }
    }
    if ($viewer) {
        $ids = [];
        foreach (load_json('shares') as $s) if ($s['from'] === $owner && $s['to'] === $viewer['id']) $ids[] = $s['recipe'];
        foreach ($recipes as $r) if (in_array($r['id'], $ids, true) && in_array($file, recipe_files($r), true)) return true;
    }
    return false;
}

// ===== サムネイル（一覧用の小さい画像） =====
// 一覧・編集画面では元画像の代わりにこれを使うので、スクロールが軽くなる。
// 初めて表示されたときに作って uploads/<ユーザー>/t/ に保存する。GDが無いサーバーでは元画像を使う。
const THUMB_W = 480;
const THUMB_H = 640; // 3:4（一覧カードと同じ比率。縦長スクショは上の部分を使う）
function thumb_path($owner, $file) {
    return UPLOAD_DIR . "/$owner/t/" . pathinfo($file, PATHINFO_FILENAME) . '.jpg';
}
function make_thumb($src, $dst) {
    if (!function_exists('imagecreatetruecolor')) return false;
    $info = @getimagesize($src);
    if (!$info) return false;
    list($w, $h) = $info;
    if ($w * $h > 40000000) return false;          // 大きすぎる画像はメモリ節約のため作らない
    @ini_set('memory_limit', '256M');
    switch ($info['mime']) {
        case 'image/jpeg': $im = @imagecreatefromjpeg($src); break;
        case 'image/png':  $im = @imagecreatefrompng($src); break;
        case 'image/gif':  $im = @imagecreatefromgif($src); break;
        case 'image/webp': $im = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false; break;
        default: $im = false;
    }
    if (!$im) return false;
    // 3:4 に切り抜き（縦長は上から、横長は中央）
    if ($h / $w > THUMB_H / THUMB_W) { $cw = $w; $ch = (int)round($w * THUMB_H / THUMB_W); $cx = 0; }
    else { $ch = $h; $cw = (int)round($h * THUMB_W / THUMB_H); $cx = (int)(($w - $cw) / 2); }
    $tw = min(THUMB_W, $cw); $th = (int)round($tw * THUMB_H / THUMB_W);
    $out = imagecreatetruecolor($tw, $th);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255)); // 透過PNGは白背景に
    imagecopyresampled($out, $im, 0, 0, $cx, 0, $tw, $th, $cw, $ch);
    if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0775, true);
    $tmp = $dst . '.' . bin2hex(random_bytes(3)) . '.tmp';
    $ok = imagejpeg($out, $tmp, 84) && rename($tmp, $dst);
    imagedestroy($im); imagedestroy($out);
    return $ok;
}
