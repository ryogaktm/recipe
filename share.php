<?php
// 共有リンクのページ（ログインなしで見られる）
require __DIR__ . '/config.php';
$viewer = current_user();
$token = csrf_token();
session_write_close();

$s = (string)($_GET['s'] ?? '');
$recipe = null;
$owner = '';
$key = '';
if (preg_match('/^(u[a-f0-9]{12})\.([a-f0-9]{32})$/', $s, $m)) {
    $owner = $m[1]; $key = $m[2];
    foreach (load_user($owner, 'recipes') as $r) {
        if (!empty($r['share_token']) && hash_equals($r['share_token'], $key)) $recipe = $r;
    }
}
$ownerName = '';
if ($recipe) foreach (load_json('users') as $u) if ($u['id'] === $owner) $ownerName = $u['name'];
$cats = [];
if ($recipe) foreach (load_user($owner, 'categories') as $c) if (in_array($c['id'], $recipe['categories'], true)) $cats[] = $c['name'];
if (!$recipe) http_response_code(404);
header('X-Robots-Tag: noindex');
$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES); };
$img = function ($f) use ($owner, $key, $h) { return 'image.php?o=' . $h($owner) . '&amp;f=' . $h($f) . '&amp;t=' . $h($key); };
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="csrf-token" content="<?= $h($token) ?>">
<meta name="theme-color" content="#2f9a5b">
<title><?= $recipe ? $h($recipe['title']) . ' | RECIPES' : 'RECIPES' ?></title>
<link rel="icon" type="image/png" href="icons/favicon-64.png?v=2">
<link rel="apple-touch-icon" href="icons/apple-touch-icon.png?v=2">
<link href="https://fonts.googleapis.com/css2?family=M+PLUS+Rounded+1c:wght@500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=6">
</head>
<body class="share-page">
  <header class="top">
    <div class="top-inner">
      <a class="logo" href="./">RECIPES</a>
    </div>
  </header>
  <main class="wrap share-wrap">
  <?php if (!$recipe): ?>
    <div class="empty"><p>このレシピは見つかりません</p><p class="sub">共有が止められたか、削除された可能性があります</p></div>
  <?php else: ?>
    <p class="from-line"><?= $h($ownerName) ?> さんが共有したレシピ</p>
    <h2 class="share-h"><?= $h($recipe['title']) ?></h2>
    <?php if ($cats): ?><div class="chips"><?php foreach ($cats as $c): ?><span class="chip"><?= $h($c) ?></span><?php endforeach; ?></div><?php endif; ?>
    <div class="view-images share-images">
      <?php $roles = ['材料', '作り方', '表紙']; foreach ($recipe['images'] as $i => $f): if ($f === '') continue; ?>
        <figure class="img-block"><figcaption class="img-role r<?= min($i, 3) ?>"><?= $roles[$i] ?? 'イメージ' ?></figcaption><img src="<?= $img($f) ?>" alt="" loading="lazy"></figure>
      <?php endforeach; ?>
    </div>
    <div class="share-cta">
      <?php if ($viewer && $viewer['id'] === $owner): ?>
        <p class="hint">これはあなたのレシピです</p>
        <a class="btn primary block linkbtn" href="./">RECIPESをひらく</a>
      <?php elseif ($viewer): ?>
        <button class="btn primary block" id="saveBtn">自分のレシピに保存</button>
        <p class="hint" id="msg"></p>
      <?php else: ?>
        <a class="btn primary block linkbtn" href="./?return=<?= urlencode('share.php?s=' . $s) ?>">ログインして自分のレシピに保存</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  </main>
  <div class="lightbox" id="lightbox" hidden><img alt=""></div>
<script>
{
  const lb = document.getElementById('lightbox');
  document.querySelectorAll('.share-images img').forEach(i => i.addEventListener('click', () => { lb.firstElementChild.src = i.src; lb.hidden = false; lb.scrollTop = 0; }));
  lb.addEventListener('click', () => lb.hidden = true);
  const btn = document.getElementById('saveBtn');
  if (btn) btn.addEventListener('click', async () => {
    btn.disabled = true; btn.textContent = '保存中…';
    const fd = new FormData();
    fd.append('owner', <?= json_encode($owner) ?>);
    fd.append('recipe', <?= json_encode($recipe['id'] ?? '') ?>);
    fd.append('token', <?= json_encode($key) ?>);
    try {
      const res = await fetch('api.php?action=import', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content } });
      const j = await res.json();
      if (!res.ok || j.error) throw new Error(j.error || 'エラー');
      btn.textContent = '保存しました'; 
      document.getElementById('msg').innerHTML = '<a href="./">RECIPESで見る</a>';
    } catch (e) {
      btn.disabled = false; btn.textContent = '自分のレシピに保存';
      document.getElementById('msg').textContent = e.message;
    }
  });
}
</script>
</body>
</html>
