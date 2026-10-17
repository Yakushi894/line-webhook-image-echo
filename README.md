# LINE Webhook Image Echo

LINE Messaging API の Webhook を受け取り、送られてきた画像をそのまま返信するシンプルな PHP スクリプトです。

## 使い方

1. `webhook.php` の設定部分を自分の値に書き換える。
   - `$channelSecret`
   - `$accessToken`
   - `$publicBaseUrl`（画像を公開するURLのベース）

2. サーバにアップロードし、`line_images` ディレクトリが作成可能な状態にする。

3. LINE Developers の Webhook URL にこの PHP の URL を設定。

4. 画像を送ると、同じ画像が返信されます。

## 注意

- 画像はサーバ上に保存されます。不要になったら削除してください。
- 公開ディレクトリに保存するため、セキュリティに注意してください。
