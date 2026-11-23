<?php
require __DIR__ . '/config.php';
session_start();

$loginError = '';
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}
if (APP_PASSWORD !== '' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (check_password((string)$_POST['password'])) {
        session_regenerate_id(true);
        $_SESSION['recipe_auth'] = true;
        header('Location: ./');
        exit;
    }
    $loginError = 'パスワードが違います';
}
$authed = auth_ok();
$token = csrf_token();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= htmlspecialchars($token) ?>">
<meta name="theme-color" content="#2f9a5b">
<title>RECIPES</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=M+PLUS+Rounded+1c:wght@500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=1">
</head>
<body>
<?php if (!$authed): ?>
  <main class="login">
    <form method="post" class="login-card">
      <h1 class="logo">RECIPES</h1>
      <input type="password" name="password" placeholder="パスワード" autofocus required>
      <?php if ($loginError): ?><p class="err"><?= htmlspecialchars($loginError) ?></p><?php endif; ?>
      <button class="btn primary">ひらく</button>
    </form>
  </main>
<?php else: ?>
  <header class="top">
    <div class="top-inner">
      <h1 class="logo">RECIPES</h1>
      <div class="search">
        <input id="q" type="search" placeholder="タイトルで検索" autocomplete="off">
      </div>
      <?php if (APP_PASSWORD !== ''): ?><a class="logout" href="?logout=1" title="ログアウト">⎋</a><?php endif; ?>
    </div>
    <nav class="chips-bar" id="filterBar"></nav>
  </header>

  <main class="wrap">
    <p class="count" id="count"></p>
    <div class="grid" id="grid"></div>
    <div class="empty" id="empty" hidden>
      <p>まだレシピがありません</p>
      <p class="sub">右下の ＋ から、レシピのキャプチャ画像を追加しましょう</p>
    </div>
  </main>

  <button class="fab" id="addBtn" aria-label="レシピを追加">＋</button>

  <!-- 詳細 -->
  <div class="modal" id="viewModal" hidden>
    <div class="sheet view">
      <div class="sheet-head">
        <button class="icon-btn" data-close>✕</button>
        <div class="spacer"></div>
        <button class="btn small" id="editBtn">編集</button>
      </div>
      <div class="view-body">
        <h2 id="viewTitle"></h2>
        <div class="chips" id="viewCats"></div>
        <div class="view-images" id="viewImages"></div>
        <button class="btn danger-text" id="deleteBtn">このレシピを削除</button>
      </div>
    </div>
  </div>

  <!-- 追加・編集 -->
  <div class="modal" id="editModal" hidden>
    <form class="sheet edit" id="editForm">
      <div class="sheet-head">
        <button type="button" class="icon-btn" data-close>✕</button>
        <h3 id="editHeading">レシピを追加</h3>
        <button class="btn primary small" id="saveBtn">保存</button>
      </div>
      <div class="edit-body">
        <label class="field-label">タイトル</label>
        <input id="fTitle" class="input" maxlength="100" placeholder="例：ふわふわパンケーキ" required>

        <label class="field-label">画像 <span class="hint">（複数OK・貼り付け/ドラッグでも追加できます）</span></label>
        <div class="drop" id="drop">
          <div class="thumbs" id="thumbs"></div>
          <label class="add-img">
            <input type="file" id="fFiles" accept="image/*" multiple hidden>
            <span>＋<br>画像を追加</span>
          </label>
        </div>

        <label class="field-label">カテゴリ <span class="hint">（タップで選択・複数OK）</span></label>
        <div class="chips selectable" id="fCats"></div>
        <div class="new-cat">
          <input id="newCat" class="input" maxlength="30" placeholder="新しいカテゴリ">
          <button type="button" class="btn small" id="newCatBtn">追加</button>
        </div>
      </div>
    </form>
  </div>

  <!-- カテゴリ管理 -->
  <div class="modal" id="catModal" hidden>
    <div class="sheet cats">
      <div class="sheet-head">
        <button class="icon-btn" data-close>✕</button>
        <h3>カテゴリの管理</h3>
        <div class="spacer"></div>
      </div>
      <div class="edit-body">
        <div class="new-cat">
          <input id="mgrNew" class="input" maxlength="30" placeholder="新しいカテゴリ">
          <button type="button" class="btn small primary" id="mgrAdd">追加</button>
        </div>
        <ul class="cat-list" id="catList"></ul>
      </div>
    </div>
  </div>

  <!-- 画像拡大 -->
  <div class="lightbox" id="lightbox" hidden><img alt=""></div>

  <div class="toast" id="toast" hidden></div>
  <script src="app.js?v=1"></script>
<?php endif; ?>
</body>
</html>
