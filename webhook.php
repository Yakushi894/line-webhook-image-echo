<?php
// ===================== 設定 =====================
$channelSecret = 'ここにチャネルシークレット';
$accessToken   = 'ここにチャネルアクセストークン';

// 画像を一時保存する公開ディレクトリ（www以下など）
// 例: /home/アカウント名/www/line_images/
$saveDir = __DIR__ . '/line_images/';
$publicBaseUrl = 'https://あなたのドメイン/line_images/'; // 公開URLのベース

// ディレクトリがなければ作成
if (!is_dir($saveDir)) {
    mkdir($saveDir, 0755, true);
}

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
if (empty($data['events'])) {
    http_response_code(200);
    exit;
}

foreach ($data['events'] as $event) {
    if ($event['type'] !== 'message' || ($event['message']['type'] ?? '') !== 'image') {
        continue;
    }

    $replyToken = $event['replyToken'];
    $messageId  = $event['message']['id'];

    // 画像をダウンロード
    $imageBinary = downloadLineImage($messageId, $accessToken);
    if ($imageBinary === false) {
        replyText($replyToken, $accessToken, '画像の取得に失敗しました');
        continue;
    }

    // 一時保存（ファイル名はメッセージIDを使用）
    $filename = $messageId . '.jpg';
    $savePath = $saveDir . $filename;
    file_put_contents($savePath, $imageBinary);

    $imageUrl = $publicBaseUrl . $filename;

    // 画像を返信
    replyImage($replyToken, $accessToken, $imageUrl, $imageUrl);
}

http_response_code(200);

// ===================== 関数 =====================
function downloadLineImage($messageId, $accessToken) {
    $ch = curl_init("https://api-data.line.me/v2/bot/message/{$messageId}/content");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $binary = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode === 200 && $binary !== false) ? $binary : false;
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
    curl_exec($ch);
    curl_close($ch);
}
