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
<link rel="stylesheet" href="style.css?v=3">
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
        <input id="q" type="search" placeholder="タイトルで検索" autocomplete="off" enterkeyhint="search">
      </div>
      <button class="help-btn" id="helpBtn" title="ショートカット一覧 (?)" aria-label="ショートカット一覧">?</button>
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
      <p class="sub pc-only">PCなら Ctrl + ＋ で追加、スクショを Ctrl + V で貼り付けても始められます</p>
    </div>
  </main>

  <button class="fab" id="addBtn" aria-label="レシピを追加" title="レシピを追加 (Ctrl + ＋)">＋</button>

  <!-- 詳細 -->
  <div class="modal" id="viewModal" hidden>
    <div class="sheet view">
      <div class="sheet-head">
        <button class="icon-btn" data-close aria-label="閉じる">✕</button>
        <div class="nav">
          <button class="icon-btn" id="prevBtn" aria-label="前のレシピ" title="前のレシピ (←)">‹</button>
          <span class="pos" id="viewPos"></span>
          <button class="icon-btn" id="nextBtn" aria-label="次のレシピ" title="次のレシピ (→)">›</button>
        </div>
        <button class="btn small" id="editBtn" title="編集 (E)">編集</button>
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
        <button type="button" class="icon-btn" data-close aria-label="閉じる">✕</button>
        <h3 id="editHeading">レシピを追加</h3>
        <span class="head-gap"></span>
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
      <div class="sheet-foot">
        <button class="btn primary block" id="saveBtn">保存<span class="kbd-hint">Ctrl + S</span></button>
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

  <!-- ショートカット一覧 -->
  <div class="modal" id="helpModal" hidden>
    <div class="sheet help">
      <div class="sheet-head">
        <button class="icon-btn" data-close aria-label="閉じる">✕</button>
        <h3>ショートカット</h3>
        <span class="head-gap"></span>
      </div>
      <div class="edit-body">
        <h4>どこでも</h4>
        <dl class="keys">
          <dt><kbd>Ctrl</kbd>+<kbd>＋</kbd></dt><dd>レシピを追加</dd>
          <dt><kbd>Ctrl</kbd>+<kbd>V</kbd></dt><dd>スクショを貼り付けて追加</dd>
          <dt><kbd>Ctrl</kbd>+<kbd>K</kbd> / <kbd>/</kbd></dt><dd>検索</dd>
          <dt><kbd>Esc</kbd></dt><dd>閉じる・検索をクリア</dd>
          <dt><kbd>?</kbd></dt><dd>この一覧</dd>
        </dl>
        <h4>一覧</h4>
        <dl class="keys">
          <dt><kbd>N</kbd></dt><dd>レシピを追加</dd>
          <dt><kbd>1</kbd>〜<kbd>9</kbd></dt><dd>カテゴリで絞り込み（もう一度で解除）</dd>
          <dt><kbd>0</kbd></dt><dd>絞り込みをすべて解除</dd>
          <dt><kbd>C</kbd></dt><dd>カテゴリの管理</dd>
          <dt><kbd>Enter</kbd></dt><dd>先頭のレシピを開く（検索中も）</dd>
        </dl>
        <h4>レシピを見ているとき</h4>
        <dl class="keys">
          <dt><kbd>←</kbd> <kbd>→</kbd></dt><dd>前・次のレシピ</dd>
          <dt><kbd>E</kbd></dt><dd>編集</dd>
          <dt><kbd>Delete</kbd></dt><dd>削除</dd>
        </dl>
        <h4>追加・編集中</h4>
        <dl class="keys">
          <dt><kbd>Ctrl</kbd>+<kbd>S</kbd></dt><dd>保存</dd>
          <dt><kbd>Ctrl</kbd>+<kbd>V</kbd></dt><dd>画像を貼り付け</dd>
        </dl>
        <p class="hint">Mac は Ctrl の代わりに ⌘ でも使えます。スマホでは、レシピ画面を左右にスワイプすると前後のレシピに移れます。</p>
      </div>
    </div>
  </div>

  <!-- 画像拡大 -->
  <div class="lightbox" id="lightbox" hidden><img alt=""></div>

  <div class="toast" id="toast" hidden></div>
  <script src="app.js?v=3"></script>
<?php endif; ?>
</body>
</html>
