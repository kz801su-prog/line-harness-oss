<?php
// ============================================================
// LINE Harness  /  worker/process.php
// キューからメッセージを取り出し、LINE Messaging API で送信する
// XServer cron設定例:
//   */5 * * * * php /home/kz801xs/sya-cho.blog/public_html/line-harness/worker/process.php
// ダッシュボードからの手動実行: GET ?worker_key=HARNESS_API_KEY
// ============================================================
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config.php';

// ── 認証（Web経由の場合） ─────────────────────────────────────
$is_web = php_sapi_name() !== 'cli';
if ($is_web) {
    $provided_key = $_GET['worker_key'] ?? '';
    if ($provided_key !== HARNESS_API_KEY) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ── DB接続 ───────────────────────────────────────────────────
try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                   DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    $msg = 'DB接続エラー: ' . $e->getMessage();
    if ($is_web) {
        echo json_encode(['status' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE);
    } else {
        error_log('[LINE Harness Worker] ' . $msg);
    }
    exit(1);
}

// ── 送信待ちメッセージをバッチで取得 ─────────────────────────
$st = $pdo->prepare(
    "SELECT * FROM message_queue
     WHERE status = 'pending' AND retry_count < ?
     ORDER BY queued_at ASC LIMIT ?"
);
$st->execute([MAX_RETRY, WORKER_BATCH]);
$queue = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$queue) {
    echo json_encode(['status' => 'ok', 'processed' => 0, 'message' => '送信待ちメッセージなし'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── LINE Messaging API 送信関数 ───────────────────────────────
function send_line_broadcast(array $messages): array {
    $payload = json_encode(['messages' => $messages], JSON_UNESCAPED_UNICODE);
    $ch = curl_init(LINE_API_BROADCAST);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Bearer ' . LINE_TOKEN,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body      = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        return ['ok' => false, 'code' => 0, 'error' => 'cURL: ' . $curl_err];
    }
    $decoded = json_decode($body, true);
    return [
        'ok'      => ($http_code === 200),
        'code'    => $http_code,
        'body'    => $decoded,
        'msg_id'  => $decoded['sentMessages'][0]['id'] ?? null,
    ];
}

// ── LINE メッセージオブジェクトを構築 ─────────────────────────
function build_line_messages(array $row): array {
    $messages = [['type' => 'text', 'text' => $row['body_text']]];

    if ($row['media_url']) {
        if ($row['media_type'] === 'image') {
            $messages[] = [
                'type'               => 'image',
                'originalContentUrl' => $row['media_url'],
                'previewImageUrl'    => $row['media_url'],
            ];
        } elseif ($row['media_type'] === 'video') {
            // プレビュー画像 = mp4 → _thumb.jpg に変換（実際にはサムネを用意してください）
            $thumb = preg_replace('/\.\w+$/', '_thumb.jpg', $row['media_url']);
            $messages[] = [
                'type'               => 'video',
                'originalContentUrl' => $row['media_url'],
                'previewImageUrl'    => $thumb,
            ];
        }
    }
    return $messages;
}

// ── キューを処理 ─────────────────────────────────────────────
$results = ['sent' => 0, 'failed' => 0, 'details' => []];
$now_dt  = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');

foreach ($queue as $row) {
    // 処理中にステータス更新（二重処理防止）
    $pdo->prepare("UPDATE message_queue SET status='processing' WHERE id=? AND status='pending'")
        ->execute([$row['id']]);

    $messages = build_line_messages($row);
    $result   = send_line_broadcast($messages);

    if ($result['ok']) {
        $pdo->prepare(
            "UPDATE message_queue SET status='sent', sent_at=?, line_msg_id=?, error_msg=NULL WHERE id=?"
        )->execute([$now_dt, $result['msg_id'], $row['id']]);
        $results['sent']++;
        $results['details'][] = ['id' => $row['id'], 'status' => 'sent'];
    } else {
        $new_retry = $row['retry_count'] + 1;
        $new_status = $new_retry >= MAX_RETRY ? 'failed' : 'pending';
        $err = $result['error'] ?? json_encode($result['body'], JSON_UNESCAPED_UNICODE);
        $pdo->prepare(
            "UPDATE message_queue SET status=?, retry_count=?, error_msg=? WHERE id=?"
        )->execute([$new_status, $new_retry, $err, $row['id']]);
        $results['failed']++;
        $results['details'][] = ['id' => $row['id'], 'status' => $new_status, 'error' => $err];
    }
}

$output = [
    'status'    => 'ok',
    'processed' => count($queue),
    'sent'      => $results['sent'],
    'failed'    => $results['failed'],
    'timestamp' => $now_dt,
    'details'   => $results['details'],
];

if ($is_web) {
    echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} else {
    error_log('[LINE Harness Worker] processed=' . $output['processed']
              . ' sent=' . $output['sent'] . ' failed=' . $output['failed']);
}
