<?php
require __DIR__ . '/config.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

function out($data) { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $msg, int $code = 400) { http_response_code($code); out(['error' => $msg]); }

if (!auth_ok()) fail('ログインが必要です', 401);
$action = $_GET['action'] ?? 'list';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) fail('不正なリクエストです', 403);
}
session_write_close();
ensure_dirs();

$lock = fopen(DATA_DIR . '/.lock', 'c');
flock($lock, $action === 'list' ? LOCK_SH : LOCK_EX);

$recipes    = load_json('recipes');
$categories = load_json('categories');
$catIds     = array_column($categories, 'id');

function clean_name(string $s, int $max): string {
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return mb_substr($s, 0, $max);
}
function new_id(string $prefix): string { return $prefix . bin2hex(random_bytes(6)); }
function find_index(array $list, string $id): int {
    foreach ($list as $i => $r) if ($r['id'] === $id) return $i;
    return -1;
}
function delete_images(array $files): void {
    foreach ($files as $f) {
        $p = UPLOAD_DIR . '/' . basename($f);
        if (is_file($p)) @unlink($p);
    }
}

switch ($action) {
case 'list':
    out(['recipes' => $recipes, 'categories' => $categories]);

case 'save_recipe':
    $id    = (string)($_POST['id'] ?? '');
    $title = clean_name((string)($_POST['title'] ?? ''), 100);
    if ($title === '') fail('タイトルを入力してください');
    $cats = array_values(array_unique(array_intersect((array)($_POST['categories'] ?? []), $catIds)));

    $idx = $id !== '' ? find_index($recipes, $id) : -1;
    if ($id !== '' && $idx < 0) fail('レシピが見つかりません', 404);
    $old  = $idx >= 0 ? $recipes[$idx]['images'] : [];
    $keep = array_values(array_intersect((array)($_POST['keep'] ?? []), $old));

    // 新しい画像を保存
    $added = [];
    $mimeExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $files = $_FILES['images'] ?? null;
    if ($files && is_array($files['name'])) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        foreach ($files['name'] as $i => $_) {
            $err = $files['error'][$i];
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            if ($err !== UPLOAD_ERR_OK || $files['size'][$i] > MAX_UPLOAD_BYTES) {
                delete_images($added);
                fail('画像のアップロードに失敗しました（サイズが大きすぎる可能性があります）');
            }
            $mime = $finfo->file($files['tmp_name'][$i]);
            if (!isset($mimeExt[$mime])) { delete_images($added); fail('対応していない画像形式です'); }
            $name = bin2hex(random_bytes(12)) . '.' . $mimeExt[$mime];
            if (!move_uploaded_file($files['tmp_name'][$i], UPLOAD_DIR . '/' . $name)) {
                delete_images($added); fail('画像の保存に失敗しました', 500);
            }
            $added[] = $name;
        }
    }
    // 並び順: order[] = "k:ファイル名"（既存）/ "n:番号"（今回追加分）
    $images = [];
    foreach ((array)($_POST['order'] ?? []) as $o) {
        if (str_starts_with($o, 'k:') && in_array(substr($o, 2), $keep, true)) $images[] = substr($o, 2);
        elseif (str_starts_with($o, 'n:') && isset($added[(int)substr($o, 2)])) $images[] = $added[(int)substr($o, 2)];
    }
    $images = array_values(array_unique(array_merge($images, $keep, $added))); // 漏れ防止
    if (!$images) fail('画像を1枚以上添付してください');

    $now = date('c');
    if ($idx >= 0) {
        $recipes[$idx] = array_merge($recipes[$idx], [
            'title' => $title, 'categories' => $cats, 'images' => $images, 'updated' => $now,
        ]);
        $saved = $recipes[$idx];
    } else {
        $saved = ['id' => new_id('r'), 'title' => $title, 'categories' => $cats,
                  'images' => $images, 'created' => $now, 'updated' => $now];
        array_unshift($recipes, $saved);
    }
    save_json('recipes', $recipes);
    delete_images(array_diff($old, $keep));
    out(['recipe' => $saved]);

case 'delete_recipe':
    $idx = find_index($recipes, (string)($_POST['id'] ?? ''));
    if ($idx < 0) fail('レシピが見つかりません', 404);
    $imgs = $recipes[$idx]['images'];
    array_splice($recipes, $idx, 1);
    save_json('recipes', $recipes);
    delete_images($imgs);
    out(['ok' => true]);

case 'add_category':
    $name = clean_name((string)($_POST['name'] ?? ''), 30);
    if ($name === '') fail('カテゴリ名を入力してください');
    foreach ($categories as $c) if (mb_strtolower($c['name']) === mb_strtolower($name)) out(['category' => $c]);
    $cat = ['id' => new_id('c'), 'name' => $name];
    $categories[] = $cat;
    save_json('categories', $categories);
    out(['category' => $cat]);

case 'rename_category':
    $idx  = find_index($categories, (string)($_POST['id'] ?? ''));
    $name = clean_name((string)($_POST['name'] ?? ''), 30);
    if ($idx < 0) fail('カテゴリが見つかりません', 404);
    if ($name === '') fail('カテゴリ名を入力してください');
    $categories[$idx]['name'] = $name;
    save_json('categories', $categories);
    out(['category' => $categories[$idx]]);

case 'delete_category':
    $id  = (string)($_POST['id'] ?? '');
    $idx = find_index($categories, $id);
    if ($idx < 0) fail('カテゴリが見つかりません', 404);
    array_splice($categories, $idx, 1);
    foreach ($recipes as &$r) $r['categories'] = array_values(array_diff($r['categories'], [$id]));
    unset($r);
    save_json('categories', $categories);
    save_json('recipes', $recipes);
    out(['ok' => true]);

case 'move_category':
    $idx = find_index($categories, (string)($_POST['id'] ?? ''));
    $to  = $idx + ((($_POST['dir'] ?? '') === 'up') ? -1 : 1);
    if ($idx < 0 || $to < 0 || $to >= count($categories)) out(['categories' => $categories]);
    [$categories[$idx], $categories[$to]] = [$categories[$to], $categories[$idx]];
    save_json('categories', $categories);
    out(['categories' => $categories]);

default:
    fail('不明な操作です', 404);
}
