<?php
// ===================== 設定 =====================
// 環境変数があればそちらを優先します（秘密情報をコードに直接書かない運用向け）。
// 環境変数名: LINE_CHANNEL_SECRET / LINE_CHANNEL_ACCESS_TOKEN / LINE_PUBLIC_BASE_URL
$channelSecret = getenv('LINE_CHANNEL_SECRET') ?: 'ここにチャネルシークレット';
$accessToken   = getenv('LINE_CHANNEL_ACCESS_TOKEN') ?: 'ここにチャネルアクセストークン';

// 画像を一時保存する公開ディレクトリ（www以下など）
// 例: /home/アカウント名/www/line_images/
$saveDir = __DIR__ . '/line_images/';
$publicBaseUrl = rtrim(getenv('LINE_PUBLIC_BASE_URL') ?: 'https://あなたのドメイン/line_images/', '/') . '/'; // 公開URLのベース

// 保存した画像を自動削除するまでの時間（秒）。LINE のサーバーが画像を取得し終える余裕を見て既定は1時間。
$imageRetentionSeconds = 3600;

// ディレクトリがなければ作成
if (!is_dir($saveDir) && !mkdir($saveDir, 0755, true) && !is_dir($saveDir)) {
    error_log('[line-webhook] 画像保存ディレクトリを作成できません: ' . $saveDir);
    http_response_code(500);
    exit;
}

// 公開ディレクトリの保護: 一覧表示防止と、置かれたファイルがスクリプトとして実行されないようにする
// （.htaccess は Apache 系でのみ有効。それ以外のサーバーでは各サーバーの設定で同様に保護してください）
if (!file_exists($saveDir . 'index.html')) {
    file_put_contents($saveDir . 'index.html', '');
}
if (!file_exists($saveDir . '.htaccess')) {
    file_put_contents($saveDir . '.htaccess',
        "Options -ExecCGI\n" .
        "<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|asp|aspx|jsp|sh)$\">\n" .
        "  Require all denied\n" .
        "</FilesMatch>\n");
}

// 保存期限を過ぎた画像を削除（リクエストのたびに掃除するので cron は不要）
cleanupOldImages($saveDir, $imageRetentionSeconds);

// ===================== 署名検証 =====================
$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';
$body      = file_get_contents('php://input');

if (empty($signature) || empty($body)) {
    http_response_code(400);
    exit;
}

$hash = base64_encode(hash_hmac('sha256', $body, $channelSecret, true));
if (!hash_equals($hash, $signature)) {
    http_response_code(400);
    exit;
}

// ===================== イベント処理 =====================
$data = json_decode($body, true);
if (empty($data['events']) || !is_array($data['events'])) {
    http_response_code(200);
    exit;
}

foreach ($data['events'] as $event) {
    if (($event['type'] ?? '') !== 'message' || ($event['message']['type'] ?? '') !== 'image') {
        continue;
    }

    $replyToken = $event['replyToken'] ?? '';
    $messageId  = $event['message']['id'] ?? '';
    if ($replyToken === '' || $messageId === '') {
        continue;
    }

    // 画像をダウンロード
    $image = downloadLineImage($messageId, $accessToken);
    if ($image === false) {
        replyText($replyToken, $accessToken, '画像の取得に失敗しました');
        continue;
    }
    $imageBinary = $image['binary'];

    // 一時保存（ファイル名はメッセージIDを使用。パスとして安全な文字だけに制限する）
    $safeId = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$messageId);
    if ($safeId === '') {
        continue;
    }
    $ext      = imageExtension($imageBinary, $image['contentType']);
    $filename = $safeId . '.' . $ext;
    $savePath = $saveDir . $filename;
    if (file_put_contents($savePath, $imageBinary) === false) {
        error_log('[line-webhook] 画像の保存に失敗しました: ' . $savePath);
        replyText($replyToken, $accessToken, '画像の保存に失敗しました');
        continue;
    }

    $imageUrl = $publicBaseUrl . $filename;

    // プレビュー画像（LINE の仕様: 最大240×240・JPEG・1MB以下）を GD が使える場合は生成する。
    // 生成できない環境では従来どおり元画像をプレビューにも使う。
    $previewUrl = $imageUrl;
    $previewFilename = $safeId . '_preview.jpg';
    if (createPreviewImage($imageBinary, $saveDir . $previewFilename)) {
        $previewUrl = $publicBaseUrl . $previewFilename;
    }

    // 画像を返信
    replyImage($replyToken, $accessToken, $imageUrl, $previewUrl);
}

http_response_code(200);

// ===================== 関数 =====================
function cleanupOldImages($dir, $retentionSeconds) {
    if ($retentionSeconds <= 0) {
        return;
    }
    $files = glob($dir . '*');
    if ($files === false) {
        return;
    }
    $limit = time() - $retentionSeconds;
    foreach ($files as $file) {
        // 画像ファイルだけを対象にする（index.html / .htaccess は消さない）
        if (!is_file($file) || !preg_match('/\.(jpg|jpeg|png)$/i', $file)) {
            continue;
        }
        if (filemtime($file) < $limit) {
            unlink($file);
        }
    }
}

function imageExtension($binary, $contentType) {
    if (strpos($contentType, 'image/png') !== false) {
        return 'png';
    }
    if (strpos($contentType, 'image/jpeg') !== false) {
        return 'jpg';
    }
    // Content-Type が取れなかった場合は中身から判定する
    if (function_exists('finfo_buffer')) {
        $mime = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $binary);
        if ($mime === 'image/png') {
            return 'png';
        }
    }
    return 'jpg';
}

function createPreviewImage($binary, $previewPath) {
    if (!function_exists('imagecreatefromstring')) {
        return false; // GD が無い環境では生成しない
    }
    $src = @imagecreatefromstring($binary);
    if ($src === false) {
        return false;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(240 / max($w, 1), 240 / max($h, 1), 1.0);
    $nw = max(1, (int)floor($w * $scale));
    $nh = max(1, (int)floor($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    if ($dst === false) {
        imagedestroy($src);
        return false;
    }
    // 透明部分は白で塗る（JPEG には透明色がないため）
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefill($dst, 0, 0, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $ok = imagejpeg($dst, $previewPath, 85);
    imagedestroy($src);
    imagedestroy($dst);
    return $ok;
}

function downloadLineImage($messageId, $accessToken) {
    $ch = curl_init("https://api-data.line.me/v2/bot/message/{$messageId}/content");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $binary = curl_exec($ch);
    if ($binary === false) {
        error_log('[line-webhook] 画像ダウンロードで cURL エラー: ' . curl_error($ch));
    }
    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($httpCode !== 200 || $binary === false) {
        error_log('[line-webhook] 画像ダウンロード失敗 HTTP ' . $httpCode);
        return false;
    }
    return ['binary' => $binary, 'contentType' => $contentType];
}

function replyImage($replyToken, $accessToken, $originalUrl, $previewUrl) {
    $payload = [
        'replyToken' => $replyToken,
        'messages' => [
            [
                'type' => 'image',
                'originalContentUrl' => $originalUrl,
                'previewImageUrl'    => $previewUrl,
            ]
        ]
    ];
    sendReply($payload, $accessToken);
}

function replyText($replyToken, $accessToken, $text) {
    $payload = [
        'replyToken' => $replyToken,
        'messages' => [
            ['type' => 'text', 'text' => $text]
        ]
    ];
    sendReply($payload, $accessToken);
}

function sendReply($payload, $accessToken) {
    $ch = curl_init('https://api.line.me/v2/bot/message/reply');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $result = curl_exec($ch);
    if ($result === false) {
        error_log('[line-webhook] 返信送信で cURL エラー: ' . curl_error($ch));
    } else {
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode < 200 || $httpCode >= 300) {
            error_log('[line-webhook] 返信送信失敗 HTTP ' . $httpCode . ': ' . $result);
        }
    }
    curl_close($ch);
}
