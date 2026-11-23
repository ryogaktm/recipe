# RECIPES — レシピ画像ボックス

レシピ本やサイトのキャプチャ画像を、タイトルとカテゴリ（複数）で管理するWebアプリです。
PHP 7.3 以上。DB不要（data/ にJSONで保存）。ホーム画面に追加するとアプリのように使えます。

## 機能
- ユーザー登録・ログイン（ユーザーごとにレシピ・カテゴリが分かれる）
- 共有：リンクで共有（ログインなしで閲覧）／ユーザー名を指定して送る（相手は自分のレシピに保存できる）
- 最初に登録したユーザーが管理者。「アカウント → 管理設定」で新規登録の受付・招待コードを変更

## 自動デプロイ
main に push すると GitHub Actions が FTP で `/public_html/recipe/` にアップロードします
（Secrets: FTP_SERVER / FTP_USERNAME / FTP_PASSWORD）。
サーバー上の data/ と uploads/ はデプロイ対象外なので、保存したデータは消えません。

## 構成
- index.php … 画面・ログイン/登録
- api.php   … 保存・削除・カテゴリ・共有
- image.php … 画像配信（本人・共有先・共有リンクのみ）
- share.php … 共有リンクのページ
- manifest.webmanifest / sw.js / icons/ … ホーム画面に追加（PWA）
- data/     … users.json / shares.json / settings.json / u/<ユーザーID>/
- uploads/  … u<ユーザーID>/ ごとの画像
