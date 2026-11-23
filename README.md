# RECIPES — レシピ画像ボックス

レシピ本やサイトのキャプチャ画像を、タイトルとカテゴリ（複数）で管理するシンプルなサイトです。
PHP 8 以上。DB不要（data/ にJSONで保存）。

## 設置
1. このフォルダごとサーバーにアップロード
2. `data/` と `uploads/` に書き込み権限を付ける（例: 775 または 777）
3. ブラウザで開くだけ

## パスワードをかける場合
`config.php` の `APP_PASSWORD` に設定（空ならログインなし）。
画像は `image.php` 経由で配信され、ログインしていないと見られません。

## 構成
- index.php … 画面
- api.php   … 保存・削除・カテゴリ操作
- image.php … 画像配信
- data/     … recipes.json / categories.json（バックアップはここと uploads/ をコピー）
- uploads/  … 画像本体
- .user.ini … アップロード上限（サーバーによっては管理画面で設定）

※ Apache 以外（nginx 等）の場合は data/ と uploads/ への直接アクセスを設定で禁止してください。
