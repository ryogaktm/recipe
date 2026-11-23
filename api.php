<?php
ini_set('display_errors', '0');
// 致命的エラーもJSONで返して、画面に原因が出るようにする
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
        echo json_encode(['error' => 'サーバーエラー: ' . $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')'], JSON_UNESCAPED_UNICODE);
    }
});
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($data) { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function fail($msg, $code = 400) { http_response_code($code); out(['error' => $msg]); }

$me = current_user();
if (!$me) fail('ログインが必要です', 401);
$action = $_GET['action'] ?? 'list';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) fail('画面が古くなっています。再読み込みしてください', 403);
}
session_write_close();

$lock = with_lock($action !== 'list');
$uid        = $me['id'];
$recipes    = load_user($uid, 'recipes');
$categories = load_user($uid, 'categories');
$catIds     = array_column($categories, 'id');

function clean_name($s, $max) {
    $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
    return mb_substr($s, 0, $max);
}
function new_id($prefix) { return $prefix . bin2hex(random_bytes(6)); }
function find_index($list, $id) {
    foreach ($list as $i => $r) if (isset($r['id']) && $r['id'] === $id) return $i;
    return -1;
}
function delete_images($uid, $files) {
    foreach ($files as $f) {
        $p = UPLOAD_DIR . "/$uid/" . basename($f);
        if ($f !== '' && is_file($p)) @unlink($p);
        if ($f !== '' && is_file(thumb_path($uid, $f))) @unlink(thumb_path($uid, $f));
    }
}
function public_user($u) { return ['id' => $u['id'], 'name' => $u['name']]; }
function user_name($id) {
    static $map = null;
    if ($map === null) { $map = []; foreach (load_json('users') as $u) $map[$u['id']] = $u['name']; }
    return $map[$id] ?? '（退会したユーザー）';
}
function share_url($owner, $token) {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $base = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . cookie_path();
    return $base . 'share.php?s=' . $owner . '.' . $token;
}
// 自分のレシピに画像ごとコピー（共有されたレシピの保存）
function copy_recipe_to($uid, $ownerId, $src, &$recipes, &$categories) {
    $srcCats = load_user($ownerId, 'categories');
    $names = [];
    foreach ($src['categories'] as $cid) foreach ($srcCats as $c) if ($c['id'] === $cid) $names[] = $c['name'];
    $myCats = [];
    foreach ($names as $n) {
        $found = null;
        foreach ($categories as $c) if (mb_strtolower($c['name']) === mb_strtolower($n)) $found = $c;
        if (!$found) { $found = ['id' => new_id('c'), 'name' => $n]; $categories[] = $found; }
        $myCats[] = $found['id'];
    }
    $dir = user_upload_dir($uid);
    $images = [];
    foreach ($src['images'] as $f) {
        $from = UPLOAD_DIR . "/$ownerId/" . basename($f);
        if ($f === '' || !is_file($from)) { $images[] = ''; continue; }
        $ext = pathinfo($f, PATHINFO_EXTENSION);
        $name = bin2hex(random_bytes(12)) . '.' . $ext;
        if (copy($from, "$dir/$name")) $images[] = $name;
    }
    while ($images && end($images) === '') array_pop($images);
    if (!array_filter($images)) fail('画像をコピーできませんでした');
    // 補正済み画像（文字用）も一緒にコピー
    $fix = [];
    foreach (($src['fix'] ?? []) as $orig => $ff) {
        $k = array_search($orig, $src['images'], true);
        if ($k === false || !isset($images[$k]) || $images[$k] === '') continue;
        if ($ff === $orig) { $fix[$images[$k]] = $images[$k]; continue; }
        $from = UPLOAD_DIR . "/$ownerId/" . basename($ff);
        if (!is_file($from)) continue;
        $name = bin2hex(random_bytes(12)) . '.' . pathinfo($ff, PATHINFO_EXTENSION);
        if (copy($from, "$dir/$name")) $fix[$images[$k]] = $name;
    }
    $now = date('c');
    $rec = ['id' => new_id('r'), 'title' => $src['title'], 'memo' => $src['memo'] ?? '', 'level' => (int)($src['level'] ?? 0), 'rating' => (int)($src['rating'] ?? 0), 'categories' => array_values(array_unique($myCats)),
            'images' => $images, 'fix' => $fix, 'created' => $now, 'updated' => $now, 'from' => $ownerId];
    array_unshift($recipes, $rec);
    return $rec;
}
function strip_private($r) { unset($r['share_token']); return $r; }

switch ($action) {
case 'list':
    // 自分宛てに共有されたレシピ
    $inbox = [];
    $cache = [];
    foreach (load_json('shares') as $s) {
        if ($s['to'] !== $uid) continue;
        if (!isset($cache[$s['from']])) {
            $cache[$s['from']] = ['r' => load_user($s['from'], 'recipes'), 'c' => load_user($s['from'], 'categories')];
        }
        $i = find_index($cache[$s['from']]['r'], $s['recipe']);
        if ($i < 0) continue;
        $r = $cache[$s['from']]['r'][$i];
        $cn = [];
        foreach ($r['categories'] as $cid) foreach ($cache[$s['from']]['c'] as $c) if ($c['id'] === $cid) $cn[] = $c['name'];
        $inbox[] = ['share_id' => $s['id'], 'from' => ['id' => $s['from'], 'name' => user_name($s['from'])],
                    'created' => $s['created'], 'seen' => !empty($s['seen']),
                    'recipe' => ['id' => $r['id'], 'title' => $r['title'], 'memo' => $r['memo'] ?? '', 'level' => (int)($r['level'] ?? 0), 'rating' => (int)($r['rating'] ?? 0), 'images' => $r['images'], 'fix' => $r['fix'] ?? [], 'category_names' => $cn]];
    }
    $res = ['me' => ['id' => $uid, 'name' => $me['name'], 'admin' => !empty($me['admin'])],
            'recipes' => array_map('strip_private', $recipes), 'categories' => $categories, 'inbox' => $inbox,
            // サーバーでWebPのサムネイルが作れるか（作れなければブラウザ側はJPEGで送る）
            'webp_ok' => function_exists('imagecreatefromwebp') && function_exists('gd_info') && !empty(gd_info()['WebP Support'])];
    if (!empty($me['admin'])) {
        $res['settings'] = app_settings();
        $res['user_count'] = count(load_json('users'));
    }
    out($res);

case 'save_recipe':
    $id    = (string)($_POST['id'] ?? '');
    $title = clean_name($_POST['title'] ?? '', 100);
    if ($title === '') fail('タイトルを入力してください');
    // 備考・メモ（改行OK、2000文字まで）
    $memo = str_replace(["\r\n", "\r"], "\n", trim((string)($_POST['memo'] ?? '')));
    $memo = mb_substr($memo, 0, 2000);
    // 難易度 1=やさしい 2=ふつう 3=むずかしい（0=未設定）、おすすめ度 0〜5
    $level  = max(0, min(3, (int)($_POST['level'] ?? 0)));
    $rating = max(0, min(5, (int)($_POST['rating'] ?? 0)));
    $cats = array_values(array_unique(array_intersect((array)($_POST['categories'] ?? []), $catIds)));

    $idx = $id !== '' ? find_index($recipes, $id) : -1;
    if ($id !== '' && $idx < 0) fail('レシピが見つかりません', 404);
    $old  = $idx >= 0 ? $recipes[$idx]['images'] : [];
    $keep = array_values(array_intersect((array)($_POST['keep'] ?? []), $old));

    $added = [];
    $mimeExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $files = $_FILES['images'] ?? null;
    if ($files && is_array($files['name'])) {
        $dir = user_upload_dir($uid);
        foreach ($files['name'] as $i => $_) {
            $err = $files['error'][$i];
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            if ($err !== UPLOAD_ERR_OK || $files['size'][$i] > MAX_UPLOAD_BYTES) {
                delete_images($uid, $added);
                fail('画像のアップロードに失敗しました（サイズが大きすぎる可能性があります）');
            }
            $mime = detect_image_mime($files['tmp_name'][$i]);
            if (!isset($mimeExt[$mime])) { delete_images($uid, $added); fail('対応していない画像形式です'); }
            $name = bin2hex(random_bytes(12)) . '.' . $mimeExt[$mime];
            if (!move_uploaded_file($files['tmp_name'][$i], "$dir/$name")) {
                delete_images($uid, $added); fail('画像の保存に失敗しました', 500);
            }
            $added[] = $name;
        }
    }
    // 文字用の補正済み画像: fixfor[] = "k:ファイル名" / "n:番号" と fixfile[] が対応。fixsame[] は「補正なし（元のまま）」
    $fixmap = $idx >= 0 ? (array)($recipes[$idx]['fix'] ?? []) : [];
    $fixAdded = [];
    $keyToOrig = function ($key) use ($keep, $added) {
        if (str_starts_with($key, 'k:') && in_array(substr($key, 2), $keep, true)) return substr($key, 2);
        if (str_starts_with($key, 'n:') && isset($added[(int)substr($key, 2)])) return $added[(int)substr($key, 2)];
        return null;
    };
    $ff = $_FILES['fixfile'] ?? null;
    $fixfor = (array)($_POST['fixfor'] ?? []);
    if ($ff && is_array($ff['name'])) {
        $dir = user_upload_dir($uid);
        foreach ($ff['name'] as $i => $_) {
            if ($ff['error'][$i] !== UPLOAD_ERR_OK || $ff['size'][$i] > MAX_UPLOAD_BYTES) continue;
            $orig = isset($fixfor[$i]) ? $keyToOrig($fixfor[$i]) : null;
            if ($orig === null) continue;
            $mime = detect_image_mime($ff['tmp_name'][$i]);
            if (!isset($mimeExt[$mime])) continue;
            $name = bin2hex(random_bytes(12)) . '.' . $mimeExt[$mime];
            if (!move_uploaded_file($ff['tmp_name'][$i], "$dir/$name")) continue;
            $fixAdded[] = $name;
            if (isset($fixmap[$orig]) && $fixmap[$orig] !== $orig) delete_images($uid, [$fixmap[$orig]]);
            $fixmap[$orig] = $name;
        }
    }
    foreach ((array)($_POST['fixsame'] ?? []) as $key) {
        $orig = $keyToOrig($key);
        if ($orig === null) continue;
        if (isset($fixmap[$orig]) && $fixmap[$orig] !== $orig) delete_images($uid, [$fixmap[$orig]]);
        $fixmap[$orig] = $orig;
    }
    // 並び順: order[] = "k:ファイル名"（既存）/ "n:番号"（今回追加分）
    // 先頭3枠（材料・作り方・表紙）は "e:" で空きにできる
    $images = [];
    foreach ((array)($_POST['order'] ?? []) as $o) {
        if ($o === 'e:') { if (count($images) < 3) $images[] = ''; continue; }
        $f = null;
        if (str_starts_with($o, 'k:') && in_array(substr($o, 2), $keep, true)) $f = substr($o, 2);
        elseif (str_starts_with($o, 'n:') && isset($added[(int)substr($o, 2)])) $f = $added[(int)substr($o, 2)];
        if ($f !== null && !in_array($f, $images, true)) $images[] = $f;
    }
    foreach (array_merge($keep, $added) as $f) if (!in_array($f, $images, true)) $images[] = $f; // 漏れ防止
    while ($images && end($images) === '') array_pop($images);
    if (!array_filter($images)) fail('画像を1枚以上添付してください');

    // 画像が無くなったものの補正画像は消す
    $dropFix = [];
    foreach ($fixmap as $orig => $ffn) {
        if (!in_array($orig, $images, true)) { if ($ffn !== $orig) $dropFix[] = $ffn; unset($fixmap[$orig]); }
    }
    delete_images($uid, $dropFix);
    $now = date('c');
    if ($idx >= 0) {
        $recipes[$idx] = array_merge($recipes[$idx], ['fix' => $fixmap, 'title' => $title, 'memo' => $memo, 'level' => $level, 'rating' => $rating, 'categories' => $cats, 'images' => $images, 'updated' => $now]);
        $saved = $recipes[$idx];
    } else {
        $saved = ['id' => new_id('r'), 'title' => $title, 'memo' => $memo, 'level' => $level, 'rating' => $rating, 'categories' => $cats, 'images' => $images, 'fix' => $fixmap, 'created' => $now, 'updated' => $now];
        array_unshift($recipes, $saved);
    }
    save_user($uid, 'recipes', $recipes);
    delete_images($uid, array_diff($old, $keep));
    out(['recipe' => strip_private($saved)]);

case 'delete_recipe':
    $rid = (string)($_POST['id'] ?? '');
    $idx = find_index($recipes, $rid);
    if ($idx < 0) fail('レシピが見つかりません', 404);
    $imgs = recipe_files($recipes[$idx]);
    array_splice($recipes, $idx, 1);
    save_user($uid, 'recipes', $recipes);
    delete_images($uid, $imgs);
    save_json('shares', array_values(array_filter(load_json('shares'), function ($s) use ($uid, $rid) {
        return !($s['from'] === $uid && $s['recipe'] === $rid);
    })));
    out(['ok' => true]);

// ----- カテゴリ -----
case 'add_category':
    $name = clean_name($_POST['name'] ?? '', 30);
    if ($name === '') fail('カテゴリ名を入力してください');
    foreach ($categories as $c) if (mb_strtolower($c['name']) === mb_strtolower($name)) out(['category' => $c]);
    $cat = ['id' => new_id('c'), 'name' => $name];
    $categories[] = $cat;
    save_user($uid, 'categories', $categories);
    out(['category' => $cat]);

case 'rename_category':
    $idx  = find_index($categories, (string)($_POST['id'] ?? ''));
    $name = clean_name($_POST['name'] ?? '', 30);
    if ($idx < 0) fail('カテゴリが見つかりません', 404);
    if ($name === '') fail('カテゴリ名を入力してください');
    $categories[$idx]['name'] = $name;
    save_user($uid, 'categories', $categories);
    out(['category' => $categories[$idx]]);

case 'delete_category':
    $id  = (string)($_POST['id'] ?? '');
    $idx = find_index($categories, $id);
    if ($idx < 0) fail('カテゴリが見つかりません', 404);
    array_splice($categories, $idx, 1);
    foreach ($recipes as &$r) $r['categories'] = array_values(array_diff($r['categories'], [$id]));
    unset($r);
    save_user($uid, 'categories', $categories);
    save_user($uid, 'recipes', $recipes);
    out(['ok' => true]);

case 'move_category':
    $idx = find_index($categories, (string)($_POST['id'] ?? ''));
    $to  = $idx + ((($_POST['dir'] ?? '') === 'up') ? -1 : 1);
    if ($idx < 0 || $to < 0 || $to >= count($categories)) out(['categories' => $categories]);
    $tmp = $categories[$idx]; $categories[$idx] = $categories[$to]; $categories[$to] = $tmp;
    save_user($uid, 'categories', $categories);
    out(['categories' => $categories]);

// ----- 共有 -----
case 'share_info':
    $idx = find_index($recipes, (string)($_POST['id'] ?? $_GET['id'] ?? ''));
    if ($idx < 0) fail('レシピが見つかりません', 404);
    $r = $recipes[$idx];
    $users = [];
    foreach (load_json('shares') as $s) {
        if ($s['from'] === $uid && $s['recipe'] === $r['id']) $users[] = ['share_id' => $s['id'], 'name' => user_name($s['to'])];
    }
    out(['link' => !empty($r['share_token']) ? share_url($uid, $r['share_token']) : null, 'users' => $users]);

case 'share_link_create':
    $idx = find_index($recipes, (string)($_POST['id'] ?? ''));
    if ($idx < 0) fail('レシピが見つかりません', 404);
    if (empty($recipes[$idx]['share_token'])) {
        $recipes[$idx]['share_token'] = bin2hex(random_bytes(16));
        save_user($uid, 'recipes', $recipes);
    }
    out(['link' => share_url($uid, $recipes[$idx]['share_token'])]);

case 'share_link_delete':
    $idx = find_index($recipes, (string)($_POST['id'] ?? ''));
    if ($idx < 0) fail('レシピが見つかりません', 404);
    unset($recipes[$idx]['share_token']);
    save_user($uid, 'recipes', $recipes);
    out(['ok' => true]);

case 'share_user_add':
    $idx = find_index($recipes, (string)($_POST['id'] ?? ''));
    if ($idx < 0) fail('レシピが見つかりません', 404);
    $to = find_user(load_json('users'), 'name', trim((string)($_POST['name'] ?? '')));
    if (!$to) fail('そのユーザー名は見つかりません');
    if ($to['id'] === $uid) fail('自分には送れません');
    $shares = load_json('shares');
    foreach ($shares as $s) {
        if ($s['from'] === $uid && $s['to'] === $to['id'] && $s['recipe'] === $recipes[$idx]['id']) fail($to['name'] . ' さんにはもう共有しています');
    }
    $s = ['id' => new_id('s'), 'from' => $uid, 'to' => $to['id'], 'recipe' => $recipes[$idx]['id'], 'created' => date('c')];
    $shares[] = $s;
    save_json('shares', $shares);
    out(['share_id' => $s['id'], 'name' => $to['name']]);

case 'share_user_remove':
    // 送った側は共有の取り消し、受け取った側は一覧から外す
    $sid = (string)($_POST['share_id'] ?? '');
    $shares = load_json('shares');
    $n = count($shares);
    $shares = array_values(array_filter($shares, function ($s) use ($sid, $uid) {
        return !($s['id'] === $sid && ($s['from'] === $uid || $s['to'] === $uid));
    }));
    if (count($shares) === $n) fail('共有が見つかりません', 404);
    save_json('shares', $shares);
    out(['ok' => true]);

case 'inbox_seen':
    $shares = load_json('shares');
    foreach ($shares as &$s) if ($s['to'] === $uid) $s['seen'] = true;
    unset($s);
    save_json('shares', $shares);
    out(['ok' => true]);

case 'import':
    // 共有されたレシピを自分のレシピとして保存（画像もコピー）
    $owner = (string)($_POST['owner'] ?? '');
    $rid   = (string)($_POST['recipe'] ?? '');
    $token = (string)($_POST['token'] ?? '');
    if (!valid_uid($owner) || $owner === $uid) fail('保存できません');
    $src = null;
    foreach (load_user($owner, 'recipes') as $r) if ($r['id'] === $rid) $src = $r;
    if (!$src) fail('レシピが見つかりません（共有が取り消された可能性があります）', 404);
    $allowed = $token !== '' && !empty($src['share_token']) && hash_equals($src['share_token'], $token);
    if (!$allowed) foreach (load_json('shares') as $s) {
        if ($s['from'] === $owner && $s['to'] === $uid && $s['recipe'] === $rid) $allowed = true;
    }
    if (!$allowed) fail('このレシピを見る権限がありません', 403);
    $rec = copy_recipe_to($uid, $owner, $src, $recipes, $categories);
    save_user($uid, 'categories', $categories);
    save_user($uid, 'recipes', $recipes);
    out(['recipe' => $rec]);

// ----- アカウント -----
case 'change_password':
    $cur = (string)($_POST['current'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    if (!password_verify($cur, $me['hash'])) fail('今のパスワードが違います');
    if (strlen($new) < 4) fail('新しいパスワードは4文字以上にしてください');
    $users = load_json('users');
    foreach ($users as &$u) if ($u['id'] === $uid) { $u['hash'] = password_hash($new, PASSWORD_DEFAULT); }
    unset($u);
    save_json('users', $users);
    out(['ok' => true]);

case 'settings_save':
    if (empty($me['admin'])) fail('管理者だけが変更できます', 403);
    $set = app_settings();
    $set['signup_open'] = ($_POST['signup_open'] ?? '') === '1';
    $set['invite_code'] = mb_substr(trim((string)($_POST['invite_code'] ?? '')), 0, 40);
    save_json('settings', $set);
    out(['settings' => $set]);

default:
    fail('不明な操作です', 404);
}
