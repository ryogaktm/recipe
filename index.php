<?php
require __DIR__ . '/config.php';
ensure_dirs();

// ログイン後に戻る先（共有ページからのログインなど）
function safe_return($r) {
    return (is_string($r) && preg_match('/^share\.php\?s=u[a-f0-9]{12}\.[a-f0-9]{32}$/', $r)) ? $r : './';
}
$return = safe_return($_GET['return'] ?? $_POST['return'] ?? '');

if (isset($_GET['logout'])) {
    logout_user();
    header('Location: ./');
    exit;
}

$me = current_user();
$mode = ($_GET['mode'] ?? $_POST['mode'] ?? 'login') === 'register' ? 'register' : 'login';
$error = '';
$nameValue = '';
$settings = app_settings();

if (!$me && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $nameValue = trim((string)($_POST['name'] ?? ''));
    $pw = (string)($_POST['password'] ?? '');
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        $error = '画面が古くなっていました。もう一度お試しください';
    } elseif ($mode === 'login') {
        $u = find_user(load_json('users'), 'name', $nameValue);
        if ($u && password_verify($pw, $u['hash'])) {
            login_user($u);
            header('Location: ' . $return);
            exit;
        }
        $error = 'ユーザー名かパスワードが違います';
    } else {
        $lock = with_lock();
        $users = load_json('users');
        $first = count($users) === 0;
        if (!$first && !$settings['signup_open']) {
            $error = '現在、新規登録は受け付けていません';
        } elseif (!$first && $settings['invite_code'] !== '' && !hash_equals($settings['invite_code'], trim((string)($_POST['invite'] ?? '')))) {
            $error = '招待コードが違います';
        } elseif (!preg_match('/^[A-Za-z0-9_\-]{3,20}$/', $nameValue)) {
            $error = 'ユーザー名は半角英数字（_ - も可）3〜20文字にしてください';
        } elseif (strlen($pw) < 4) {
            $error = 'パスワードは4文字以上にしてください';
        } elseif (find_user($users, 'name', $nameValue)) {
            $error = 'そのユーザー名はもう使われています';
        } else {
            $u = ['id' => 'u' . bin2hex(random_bytes(6)), 'name' => $nameValue, 'hash' => password_hash($pw, PASSWORD_DEFAULT),
                  'admin' => $first, 'created' => date('c'), 'tokens' => []];
            $users[] = $u;
            save_json('users', $users);
            if ($first) migrate_legacy_data($u['id']);
            fclose($lock);
            login_user($u);
            header('Location: ' . $return);
            exit;
        }
        fclose($lock);
    }
}
$token = csrf_token();
$noUsers = count(load_json('users')) === 0;
if ($noUsers) $mode = 'register';
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= $h($token) ?>">
<meta name="theme-color" content="#2f9a5b">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="RECIPES">
<title>RECIPES</title>
<link rel="manifest" href="manifest.webmanifest?v=2">
<link rel="icon" type="image/png" href="icons/favicon-64.png?v=2">
<link rel="apple-touch-icon" href="icons/apple-touch-icon.png?v=2">
<link rel="stylesheet" href="style.css?v=11">
</head>
<body>
<?php if (!$me): ?>
  <main class="login">
    <form method="post" class="login-card" autocomplete="on">
      <img class="login-icon" src="icons/icon-192.png?v=2" alt="" width="96" height="96">
      <h1 class="logo">RECIPES</h1>
      <?php if ($noUsers): ?>
        <p class="login-note">はじめに管理者アカウントを作ります</p>
      <?php else: ?>
        <div class="auth-tabs">
          <a href="?mode=login<?= $return !== './' ? '&return=' . urlencode($return) : '' ?>" class="<?= $mode === 'login' ? 'on' : '' ?>">ログイン</a>
          <?php if ($settings['signup_open']): ?>
            <a href="?mode=register<?= $return !== './' ? '&return=' . urlencode($return) : '' ?>" class="<?= $mode === 'register' ? 'on' : '' ?>">新規登録</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <input type="hidden" name="csrf" value="<?= $h($token) ?>">
      <input type="hidden" name="mode" value="<?= $h($mode) ?>">
      <input type="hidden" name="return" value="<?= $h($return) ?>">
      <input type="text" name="name" placeholder="ユーザー名" value="<?= $h($nameValue) ?>" autocapitalize="off" autocorrect="off" spellcheck="false" autocomplete="username" required>
      <input type="password" name="password" placeholder="パスワード<?= $mode === 'register' ? '（4文字以上）' : '' ?>" autocomplete="<?= $mode === 'register' ? 'new-password' : 'current-password' ?>" required>
      <?php if ($mode === 'register' && !$noUsers && $settings['invite_code'] !== ''): ?>
        <input type="text" name="invite" placeholder="招待コード" autocapitalize="off" required>
      <?php endif; ?>
      <?php if ($error): ?><p class="err"><?= $h($error) ?></p><?php endif; ?>
      <button class="btn primary"><?= $mode === 'register' ? '登録してはじめる' : 'ログイン' ?></button>
      <?php if ($mode === 'register'): ?><p class="login-note small">ユーザー名は半角英数字3〜20文字。共有のときに相手に伝える名前になります。</p><?php endif; ?>
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
      <button class="avatar" id="accountBtn" aria-label="アカウント" title="アカウント">
        <?= $h(mb_strtoupper(mb_substr($me['name'], 0, 1))) ?><span class="dot" id="inboxDot" hidden></span>
      </button>
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
        <div class="own-only head-actions">
          <button class="icon-btn share-ic" id="shareBtn" aria-label="共有" title="共有 (S)"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M7 8l5-5 5 5M5 13v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/></svg></button>
          <button class="btn small" id="editBtn" title="編集 (E)">編集</button>
        </div>
      </div>
      <div class="view-body">
        <p class="from-line inbox-only" id="viewFrom"></p>
        <h2 id="viewTitle"></h2>
        <div class="chips" id="viewCats"></div>
        <div class="meta-row" id="viewMeta"></div>
        <div class="memo-box" id="viewMemo" hidden><span class="memo-label">備考・メモ</span><div class="memo-text" id="viewMemoText"></div></div>
        <div class="view-images" id="viewImages"></div>
        <div class="inbox-only inbox-actions">
          <button class="btn primary block" id="importBtn">自分のレシピに保存</button>
          <button class="btn danger-text" id="dismissBtn">共有された一覧から外す</button>
        </div>
        <button class="btn danger-text own-only" id="deleteBtn">このレシピを削除</button>
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

        <label class="field-label">画像 <span class="hint">（枠をタップ・貼り付け・ドラッグで追加）</span></label>
        <input type="file" id="slotFile" accept="image/*" hidden>
        <div class="slots" id="slots"></div>

        <label class="field-label"><span class="slot-label r3 inline">4〜 イメージ</span> <span class="hint">（完成写真など・何枚でも）</span></label>
        <div class="drop" id="drop">
          <div class="thumbs" id="thumbs"></div>
          <label class="add-img">
            <input type="file" id="fFiles" accept="image/*" multiple hidden>
            <span>＋<br>イメージを追加</span>
          </label>
        </div>

        <div class="two-col">
          <div>
            <label class="field-label">難易度</label>
            <div class="seg" id="fLevel" role="radiogroup" aria-label="難易度">
              <button type="button" data-v="1" class="lv1">やさしい</button><button type="button" data-v="2" class="lv2">ふつう</button><button type="button" data-v="3" class="lv3">むずかしい</button>
            </div>
          </div>
          <div>
            <label class="field-label">おすすめ度</label>
            <div class="stars-input" id="fRating" role="radiogroup" aria-label="おすすめ度">
              <button type="button" data-v="1" aria-label="1">★</button><button type="button" data-v="2" aria-label="2">★</button><button type="button" data-v="3" aria-label="3">★</button><button type="button" data-v="4" aria-label="4">★</button><button type="button" data-v="5" aria-label="5">★</button>
            </div>
          </div>
        </div>

        <label class="field-label" for="fMemo">備考・メモ <span class="hint">（コツ・アレンジ・出典URLなど）</span></label>
        <textarea id="fMemo" class="input textarea" rows="4" maxlength="2000" placeholder="例：砂糖は大さじ1に減らすとちょうどいい"></textarea>

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

  <!-- トリミング -->
  <div class="modal crop-modal" id="cropModal" hidden>
    <div class="crop-wrap">
      <div class="crop-top">
        <button type="button" class="btn small crop-cancel" id="cropCancel">キャンセル</button>
        <b id="cropTitle">トリミング</b>
        <button type="button" class="btn small primary" id="cropDone">完了</button>
      </div>
      <div class="crop-stage" id="cropStage">
        <div class="crop-canvas" id="cropCanvas">
          <canvas id="cropImg"></canvas>
          <div class="crop-box" id="cropBox">
            <span class="grid-v"></span><span class="grid-h"></span>
            <i data-h="nw"></i><i data-h="n"></i><i data-h="ne"></i><i data-h="e"></i>
            <i data-h="se"></i><i data-h="s"></i><i data-h="sw"></i><i data-h="w"></i>
          </div>
        </div>
      </div>
      <div class="crop-tools">
        <div class="adj">
          <label class="adj-row"><span>影を消す</span><input type="range" id="adj_shadow" min="0" max="100" step="1" value="0"><b id="adjv_shadow">0</b></label>
          <label class="adj-row"><span>明るさ</span><input type="range" id="adj_bright" min="-50" max="50" step="1" value="0"><b id="adjv_bright">0</b></label>
          <label class="adj-row"><span>くっきり</span><input type="range" id="adj_contrast" min="-30" max="60" step="1" value="0"><b id="adjv_contrast">0</b></label>
        </div>
        <div class="crop-actions">
          <button type="button" class="btn small" id="cropAuto">✨ 自動補正</button>
          <button type="button" class="btn small" id="cropRotate">↻ 回転</button>
          <button type="button" class="btn small" id="cropReset">元に戻す</button>
        </div>
      </div>
    </div>
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
          <dt><kbd>I</kbd></dt><dd>自分のレシピ ⇄ 共有された を切り替え</dd>
          <dt><kbd>Enter</kbd></dt><dd>先頭のレシピを開く（検索中も）</dd>
        </dl>
        <h4>レシピを見ているとき</h4>
        <dl class="keys">
          <dt><kbd>←</kbd> <kbd>→</kbd></dt><dd>前・次のレシピ</dd>
          <dt><kbd>E</kbd></dt><dd>編集</dd>
          <dt><kbd>S</kbd></dt><dd>共有</dd>
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

  <!-- 共有 -->
  <div class="modal" id="shareModal" hidden>
    <div class="sheet small-sheet">
      <div class="sheet-head">
        <button class="icon-btn" data-close aria-label="閉じる">✕</button>
        <h3>共有</h3>
        <span class="head-gap"></span>
      </div>
      <div class="edit-body">
        <p class="share-title" id="shareTitle"></p>
        <h4 class="sec">リンクで共有</h4>
        <p class="hint">リンクを知っている人は、ログインなしで見られます。LINEやメールで送るときに。</p>
        <div id="linkNone"><button class="btn primary" id="linkCreate">共有リンクを作る</button></div>
        <div id="linkSome" hidden>
          <div class="link-row"><input class="input" id="linkUrl" readonly><button class="btn small" id="linkCopy">コピー</button></div>
          <div class="link-actions">
            <button class="btn small primary" id="linkSend" hidden>送る…</button>
            <button class="btn small ghost-red" id="linkStop">リンクを止める</button>
          </div>
        </div>
        <h4 class="sec">ユーザーに送る</h4>
        <p class="hint">相手の「共有された」に届き、自分のレシピに保存できます。</p>
        <form class="new-cat" id="sendForm">
          <input class="input" id="sendName" placeholder="相手のユーザー名" autocapitalize="off" autocorrect="off" spellcheck="false">
          <button class="btn small primary">送る</button>
        </form>
        <ul class="share-users" id="shareUsers"></ul>
      </div>
    </div>
  </div>

  <!-- アカウント -->
  <div class="modal" id="accountModal" hidden>
    <div class="sheet small-sheet">
      <div class="sheet-head">
        <button class="icon-btn" data-close aria-label="閉じる">✕</button>
        <h3>アカウント</h3>
        <span class="head-gap"></span>
      </div>
      <div class="edit-body">
        <div class="me-row"><span class="avatar big" id="meAvatar"></span><div><b id="meName"></b><p class="hint" id="meRole"></p></div></div>
        <button class="menu-item" id="goInbox">共有されたレシピ<span class="badge-n" id="inboxCount"></span></button>
        <button class="menu-item" id="goCats">カテゴリの管理</button>
        <button class="menu-item pc-only-flex" id="goHelp">ショートカット一覧</button>
        <details class="menu-details">
          <summary class="menu-item">パスワードを変える</summary>
          <form id="pwForm" class="stack-form">
            <input class="input" type="password" id="pwCur" placeholder="今のパスワード" autocomplete="current-password" required>
            <input class="input" type="password" id="pwNew" placeholder="新しいパスワード（4文字以上）" autocomplete="new-password" required>
            <button class="btn primary">変更する</button>
          </form>
        </details>
        <details class="menu-details" id="adminBox" hidden>
          <summary class="menu-item">管理設定</summary>
          <form id="adminForm" class="stack-form">
            <label class="check"><input type="checkbox" id="setSignup"> 新規登録を受け付ける</label>
            <label class="field-label">招待コード <span class="hint">（空なら誰でも登録できます）</span></label>
            <input class="input" id="setInvite" maxlength="40" placeholder="例：family2026">
            <p class="hint" id="userCount"></p>
            <button class="btn primary">保存</button>
          </form>
        </details>
        <div class="install-hint" id="installHint">
          <b>アプリのように使う</b>
          <p class="hint" id="installText">iPhone：共有ボタン →「ホーム画面に追加」／ Android・PC：ブラウザのメニュー →「アプリをインストール」</p>
          <button class="btn small primary" id="installBtn" hidden>ホーム画面に追加</button>
        </div>
        <a class="menu-item logout-item" href="?logout=1">ログアウト</a>
      </div>
    </div>
  </div>

  <!-- 画像拡大 -->
  <div class="lightbox" id="lightbox" hidden><img alt=""></div>

  <div class="toast" id="toast" hidden></div>
  <script src="app.js?v=14"></script>
<?php endif; ?>
</body>
</html>
