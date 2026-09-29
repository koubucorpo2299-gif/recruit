# 応募受付（Xserver）設置手順

採用ページの応募フォームは、Xserver 上の `apply.php` にデータを送り、Xserver のメールサーバーから
①塾への通知メール（履歴書・職務経歴書を添付）②応募者への確認メール を送ります。EmailJS は使いません。

## 1. 送信元のメールアカウントを作る（Xserver）
サーバーパネル →「メールアカウント設定」→ `recruit@beducate.jp` を作成（パスワードは任意。受信はしないので放置でOK）。
別のアドレスを使う場合は `apply.php` 冒頭の `MAIL_FROM` を同じ値に変更してください。

## 2. 届きやすくする設定（推奨）
サーバーパネルの「DKIM設定」（メール関連）が使える場合は、`beducate.jp` で有効にしてください。
SPF は Xserver が自動で設定します。それでも Outlook で迷惑メールに入る場合は、`recruit@beducate.jp` を「安全な差出人」に登録してください。

## 3. apply.php をアップロード
FTP（またはサーバーパネルの「ファイルマネージャ」）で、`beducate.jp` の公開フォルダ（`public_html`）の下に
`recruit-api` フォルダを作り、その中へ `apply.php` を置きます。
最終的に `https://beducate.jp/recruit-api/apply.php` で届く形にします。
（PHP は 8.0 以上。Xserver の標準設定で問題ありません。）

## 4. 動作確認
ブラウザで `https://beducate.jp/recruit-api/apply.php` を開き、`{"ok":false,"error":"method"}` と出れば設置成功です。
（エラー画面や真っ白の場合は、置き場所・PHPのバージョンを確認）

## 5. 設定値（apply.php 冒頭）
| 定数 | 内容 |
|---|---|
| `ADMIN_TO` | 応募通知の送信先（塾）。現在 manabiya.b-study@outlook.jp |
| `MAIL_FROM` | 送信元。Xserver に作ったアドレス |
| `ALLOWED_ORIGINS` | 応募を受け付けるページ。`https://recruit.beducate.jp` のみ |
| `MAX_FILE_BYTES` | 添付1ファイルの上限（5MB） |

## 仕組み（安全対策）
- 送信元ページの限定（CORS）、入力・添付のサーバー側検証（形式・容量・中身の種類）
- ボット対策（ハニーポット・表示直後の送信の破棄）、同一IP/同一メールアドレスの回数制限
- 応募者のメールアドレス等にヘッダー注入ができない作りにしています
